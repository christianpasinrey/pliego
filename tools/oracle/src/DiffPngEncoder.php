<?php

// tools/oracle/src/DiffPngEncoder.php
declare(strict_types=1);

namespace PliegoOracle;

/**
 * M9-T5: a minimal, write-only PNG encoder (signature + IHDR + one IDAT + IEND, color type 2 /
 * truecolor RGB, 8-bit, filter type 0 "None" per scanline, no interlace) -- the mirror image of
 * Image\PngImage's read path, but deliberately NOT that class or any addition to it: this is
 * tool-only code (the oracle's diff.png artifact), never part of pliego's runtime PDF-rendering
 * pipeline, so it has no business living under src/ or being covered by deptrac's src/-only
 * graph (see deptrac.yaml's `paths: [src]`). Encoding, not decoding, is also a strictly simpler
 * problem here: a single uncompressed-then-deflated IDAT is enough, no need for the four
 * PNG filter heuristics real encoders use to shrink output size -- diff.png is a debugging
 * artifact uploaded once per CI run, not a shipped asset.
 *
 * Renders PixelDiff's DiffResult as: the first source image's own luminance (grayscale, so the
 * underlying page content stays legible) as the background, with counted diffs (mask byte 0x01)
 * painted solid red and antialias-masked near-misses (mask byte 0x02) painted solid orange --
 * distinguishing "this is why the fixture failed" from "this is edge noise the oracle already
 * ignored" at a glance in the uploaded artifact.
 */
final class DiffPngEncoder
{
    private const string SIGNATURE = "\x89PNG\r\n\x1a\n";
    private const string COUNTED_DIFF_COLOR = "\xff\x00\x00"; // red
    private const string MASKED_DIFF_COLOR = "\xff\xa5\x00"; // orange

    /**
     * @param string $backgroundRgb tightly-packed row-major RGB bytes (3/pixel, no padding),
     *                               exactly $width * $height * 3 bytes long (the same overlap
     *                               region DiffResult was computed over).
     * @param string $mask           DiffResult::$mask (see its own docblock).
     */
    public static function encode(int $width, int $height, string $backgroundRgb, string $mask): string
    {
        $scanlines = '';
        for ($y = 0; $y < $height; $y++) {
            $scanlines .= "\x00"; // filter type 0 (None) for this row
            $rowOffset = $y * $width * 3;
            $maskRowOffset = $y * $width;
            for ($x = 0; $x < $width; $x++) {
                $flag = $mask[$maskRowOffset + $x];
                if ($flag === "\x01") {
                    $scanlines .= self::COUNTED_DIFF_COLOR;
                    continue;
                }
                if ($flag === "\x02") {
                    $scanlines .= self::MASKED_DIFF_COLOR;
                    continue;
                }
                $base = $rowOffset + $x * 3;
                $r = ord($backgroundRgb[$base]);
                $g = ord($backgroundRgb[$base + 1]);
                $b = ord($backgroundRgb[$base + 2]);
                // ITU-R BT.601 luma (same weights used elsewhere in the codebase's docs for
                // "grayscale" -- good enough for a debug overlay, no colorimetry claims made).
                $gray = (int) round(0.299 * $r + 0.587 * $g + 0.114 * $b);
                $scanlines .= chr($gray) . chr($gray) . chr($gray);
            }
        }

        $idatData = zlib_encode($scanlines, ZLIB_ENCODING_DEFLATE);
        if ($idatData === false) {
            throw new \RuntimeException('DiffPngEncoder: zlib_encode failed while compressing diff.png scanlines.');
        }

        $ihdr = pack('N', $width) . pack('N', $height) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0);

        return self::SIGNATURE
            . self::chunk('IHDR', $ihdr)
            . self::chunk('IDAT', $idatData)
            . self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
