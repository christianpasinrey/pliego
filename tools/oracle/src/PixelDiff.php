<?php

// tools/oracle/src/PixelDiff.php
declare(strict_types=1);

namespace PliegoOracle;

use Pliego\Image\PngImage;

/**
 * M9-T5 (the "oráculo Chrome" pixel comparator): decodes two PNGs -- a Chrome screenshot and a
 * Ghostscript rasterization of pliego's own PDF output, at the same nominal DPI -- and computes
 * the % of pixels that genuinely differ between them, per the brief's spec:
 *
 *   1. Per-pixel max channel delta (max(|dR|,|dG|,|dB|)) over 24 counts as "different".
 *   2. An antialiasing mask suppresses false positives along edges: a pixel is excluded from the
 *      metric if, in EITHER source image, any of its 8 neighbors differs from it by more than 48
 *      (a strong local edge in that image -- exactly where a 1-engine-vs-1-engine rendering
 *      disagreement over which side of an edge a partially-covered pixel belongs to is expected
 *      and NOT a real layout/paint bug). This is a per-*location* check against each image's own
 *      neighborhood, independent of whether the two images actually differ there -- see
 *      isEdgeZone()'s docblock.
 *
 * Deliberately reuses Image\PngImage (M3's pure-PHP decoder, already battle-tested against real
 * Chrome-produced and Ghostscript-produced PNGs elsewhere in this repo) instead of writing a
 * second one here -- this tool only needs read access to the same RGB byte planes PngImage
 * already reconstructs for embedding into a PDF (see decode()'s docblock for how it recovers
 * those bytes from PngImage's public API without a decoder-internal hook).
 *
 * Reused ALSO by the Pest suite (tests/Unit/Oracle/PixelDiffTest.php) against tiny synthetic
 * PNGs -- this class has no dependency on Ghostscript/Chrome/the filesystem, only on decoded PNG
 * bytes, so it is fully unit-testable without the oracle's runtime prerequisites (node,
 * playwright, ghostscript) being present. That test suite is what keeps the CI PHP job hermetic
 * per this task's constraints -- the thresholds/calibration live only in the oracle's own run
 * (composer oracle / .github/workflows/oracle.yml), never in `pest`.
 */
final class PixelDiff
{
    private const int DEFAULT_DELTA_THRESHOLD = 24;
    private const int DEFAULT_ANTIALIAS_NEIGHBOR_THRESHOLD = 48;

    public static function compare(
        string $pngBytesA,
        string $pngBytesB,
        int $deltaThreshold = self::DEFAULT_DELTA_THRESHOLD,
        int $antialiasNeighborThreshold = self::DEFAULT_ANTIALIAS_NEIGHBOR_THRESHOLD,
    ): DiffResult {
        [$widthA, $heightA, $rgbA] = self::decode($pngBytesA);
        [$widthB, $heightB, $rgbB] = self::decode($pngBytesB);

        // Height normalization (brief): pliego's rasterization is a full page, Chrome's
        // full-page screenshot is exactly the content height -- neither is "wrong", they just
        // don't necessarily agree on trailing whitespace at the page's bottom edge. Comparing
        // only the overlapping top-left region (both width AND height, not just height -- a
        // sub-pixel width mismatch is also possible, see run.php's docblock on 793.7px vs 794px)
        // means a page that's merely "taller on one side" never registers as 100% different.
        $width = min($widthA, $widthB);
        $height = min($heightA, $heightB);
        $total = $width * $height;

        $strideA = $widthA * 3;
        $strideB = $widthB * 3;

        $mask = str_repeat("\x00", max($total, 0));
        $background = '';
        $diffCount = 0;

        for ($y = 0; $y < $height; $y++) {
            $rowA = $y * $strideA;
            $rowB = $y * $strideB;
            $maskRow = $y * $width;
            for ($x = 0; $x < $width; $x++) {
                $pa = self::pixelAt($rgbA, $rowA, $x);
                $pb = self::pixelAt($rgbB, $rowB, $x);
                $background .= chr($pa[0]) . chr($pa[1]) . chr($pa[2]);
                if (self::channelDelta($pa, $pb) <= $deltaThreshold) {
                    continue;
                }
                if (
                    self::isEdgeZone($rgbA, $widthA, $heightA, $strideA, $x, $y, $antialiasNeighborThreshold)
                    || self::isEdgeZone($rgbB, $widthB, $heightB, $strideB, $x, $y, $antialiasNeighborThreshold)
                ) {
                    $mask[$maskRow + $x] = "\x02";
                    continue;
                }
                $mask[$maskRow + $x] = "\x01";
                $diffCount++;
            }
        }

        $percent = $total > 0 ? ($diffCount / $total) * 100.0 : 0.0;
        return new DiffResult($width, $height, $percent, $mask, $background);
    }

    /**
     * Reconstructs a flat, tightly-packed row-major RGB byte plane (3 bytes/pixel, no
     * padding/filter bytes, no PDF wrapping) for a PNG file's bytes, by decoding through
     * PngImage and inflating its pdfData()->bytes back out.
     *
     * PngImage's public contract only exposes decoded pixels shaped for PDF embedding (an
     * already-deflated DeviceRGB/DeviceGray plane + an optional separate SMask plane, see its own
     * docblock) -- never a raw decoded-pixels accessor, because that's all the engine's paint
     * path has ever needed. zlib_decode() on ->bytes recovers EXACTLY the unfiltered pixel bytes
     * PngImage reconstructed internally (see its rgbaPdfData()/fromBytes(), which deflate()
     * those same bytes to build ->bytes) -- an extra round-trip, but it means this tool needs no
     * new decoder-internal API surface on a class that's otherwise fully specified by M3.
     * DeviceGray sources (color type 0 gray PNGs -- not what Chrome/Ghostscript ever emit, but
     * exercised by this file's own unit tests) are expanded to RGB in place (each gray byte
     * repeated 3x) so downstream comparison code has exactly one pixel shape to deal with.
     *
     * @return array{0: int, 1: int, 2: string} [width, height, rgb bytes]
     */
    private static function decode(string $pngBytes): array
    {
        $image = PngImage::fromBytes($pngBytes);
        $data = $image->pdfData();
        $raw = zlib_decode($data->bytes);
        if ($raw === false) {
            throw new \RuntimeException('PixelDiff: could not inflate decoded PNG pixel data.');
        }
        if ($data->colorSpace === 'DeviceGray') {
            $expanded = '';
            $len = strlen($raw);
            for ($i = 0; $i < $len; $i++) {
                $expanded .= $raw[$i] . $raw[$i] . $raw[$i];
            }
            $raw = $expanded;
        }
        return [$image->widthPx(), $image->heightPx(), $raw];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function pixelAt(string $rgb, int $rowOffset, int $x): array
    {
        $base = $rowOffset + $x * 3;
        return [ord($rgb[$base]), ord($rgb[$base + 1]), ord($rgb[$base + 2])];
    }

    /** @param array{0: int, 1: int, 2: int} $a @param array{0: int, 1: int, 2: int} $b */
    private static function channelDelta(array $a, array $b): int
    {
        $dr = abs($a[0] - $b[0]);
        $dg = abs($a[1] - $b[1]);
        $db = abs($a[2] - $b[2]);
        return max($dr, $dg, $db);
    }

    /**
     * True when ($x, $y)'s pixel, WITHIN THIS ONE IMAGE, has an 8-neighbor whose color differs
     * from it by more than $threshold -- i.e. this location sits on a strong local edge in this
     * image (a sharp border, text stroke, etc). This is checked independently against image A and
     * image B (see compare()'s call site, `||`-combined) because the two engines can legitimately
     * place the antialiased "half-covered" pixel of the same edge on slightly different sides of
     * it; masking based on EITHER image's own edge geometry (not requiring both to agree on
     * exactly which pixel is the edge) is what actually suppresses that class of false positive.
     */
    private static function isEdgeZone(string $rgb, int $width, int $height, int $stride, int $x, int $y, int $threshold): bool
    {
        $center = self::pixelAt($rgb, $y * $stride, $x);
        for ($dy = -1; $dy <= 1; $dy++) {
            $ny = $y + $dy;
            if ($ny < 0 || $ny >= $height) {
                continue;
            }
            $rowOffset = $ny * $stride;
            for ($dx = -1; $dx <= 1; $dx++) {
                if ($dx === 0 && $dy === 0) {
                    continue;
                }
                $nx = $x + $dx;
                if ($nx < 0 || $nx >= $width) {
                    continue;
                }
                $neighbor = self::pixelAt($rgb, $rowOffset, $nx);
                if (self::channelDelta($center, $neighbor) > $threshold) {
                    return true;
                }
            }
        }
        return false;
    }
}
