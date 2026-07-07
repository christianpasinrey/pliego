<?php

// tests/Unit/Oracle/PixelDiffTest.php
declare(strict_types=1);

use PliegoOracle\PixelDiff;

/**
 * M9-T5: unit coverage for the oracle's pixel-comparison metric/mask logic, against tiny
 * hand-built synthetic PNGs -- fully hermetic (no Chrome/Ghostscript/node involved, see
 * PixelDiff's own docblock), which is what keeps this file part of the ordinary `pest` run
 * rather than the separate oracle CI job. Fixture PNGs are built with a local, pure-PHP,
 * filter-type-0-only encoder (mirrors PngImageTest.php's buildMinimalPng() convention for the
 * decoder side) -- deliberately NOT DiffPngEncoder, which only ever emits the diff
 * visualization's own red/orange/gray palette, not arbitrary RGB test fixtures.
 */

/** @param callable(int $x, int $y): array{0: int, 1: int, 2: int} $pixelAt */
function oracleBuildRgbPng(int $width, int $height, callable $pixelAt): string
{
    $scanlines = '';
    for ($y = 0; $y < $height; $y++) {
        $scanlines .= "\x00"; // filter type 0 (None)
        for ($x = 0; $x < $width; $x++) {
            [$r, $g, $b] = $pixelAt($x, $y);
            $scanlines .= chr($r) . chr($g) . chr($b);
        }
    }
    $idat = zlib_encode($scanlines, ZLIB_ENCODING_DEFLATE);
    if ($idat === false) {
        throw new RuntimeException('zlib_encode failed while building the oracle test fixture.');
    }
    $ihdr = pack('N', $width) . pack('N', $height) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0);
    $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', $ihdr) . $chunk('IDAT', $idat) . $chunk('IEND', '');
}

/** Uniform-color WxH RGB PNG. */
function oracleSolidPng(int $width, int $height, int $r, int $g, int $b): string
{
    return oracleBuildRgbPng($width, $height, static fn(int $x, int $y): array => [$r, $g, $b]);
}

it('reports 0% diff for two byte-identical images', function () {
    $png = oracleSolidPng(8, 6, 100, 150, 200);
    $result = PixelDiff::compare($png, $png);

    expect($result->width)->toBe(8);
    expect($result->height)->toBe(6);
    expect($result->diffPercent)->toBe(0.0);
    expect($result->mask)->toBe(str_repeat("\x00", 8 * 6));
});

it('reports the exact percentage for a solid differing block, with no antialias masking involved', function () {
    // 10x10, uniform gray(128) in both; B has a 4x4 block (rows 3-6, cols 3-6) bumped to
    // gray(160) -- a delta of 32: above the diff threshold (24) but comfortably under the
    // antialias neighbor threshold (48) both at the block's interior AND its boundary (the
    // boundary pixels' only "edge" is against the surrounding 128, also delta 32) -- so nothing
    // gets masked and the metric should equal exactly 16/100 = 16%.
    $a = oracleSolidPng(10, 10, 128, 128, 128);
    $b = oracleBuildRgbPng(10, 10, function (int $x, int $y): array {
        $inBlock = $x >= 3 && $x <= 6 && $y >= 3 && $y <= 6;
        $v = $inBlock ? 160 : 128;
        return [$v, $v, $v];
    });

    $result = PixelDiff::compare($a, $b);

    expect($result->diffPercent)->toBe(16.0);
    expect(substr_count($result->mask, "\x01"))->toBe(16);
    expect(substr_count($result->mask, "\x02"))->toBe(0);
});

it('masks out a diff that falls on a hard edge (antialiasing zone) in either source image', function () {
    // 5x3: image A is a hard black/white vertical edge at column 1|2 (cols 0-1 black, cols 2-4
    // white) -- columns 1 and 2 are therefore "edge zone" locations in A (neighbor delta 255 > 48
    // antialias threshold). Image B is identical except pixel (2,0) is gray(200) instead of
    // white(255) -- a delta of 55 (> 24 diff threshold) that would normally count, but (2,0) is
    // exactly one of A's edge-zone locations, so it must be masked instead: diffPercent stays 0.
    $a = oracleBuildRgbPng(5, 3, static fn(int $x, int $y): array => $x <= 1 ? [0, 0, 0] : [255, 255, 255]);
    $b = oracleBuildRgbPng(5, 3, static function (int $x, int $y): array {
        if ($x === 2 && $y === 0) {
            return [200, 200, 200];
        }
        return $x <= 1 ? [0, 0, 0] : [255, 255, 255];
    });

    $result = PixelDiff::compare($a, $b);

    expect($result->diffPercent)->toBe(0.0);
    expect(substr_count($result->mask, "\x01"))->toBe(0);
    expect(substr_count($result->mask, "\x02"))->toBe(1);
});

it('normalizes to the overlapping top-left region when the two images have different dimensions', function () {
    // A is 4x4 uniform gray(50); B is 6x6, its top-left 4x4 sub-region matching A exactly and an
    // extra border of white(255) beyond it (row/col index >= 4) -- min(w,h) crops to exactly A's
    // 4x4, so the extra border must never be visited/counted.
    $a = oracleSolidPng(4, 4, 50, 50, 50);
    $b = oracleBuildRgbPng(6, 6, static fn(int $x, int $y): array => ($x < 4 && $y < 4) ? [50, 50, 50] : [255, 255, 255]);

    $result = PixelDiff::compare($a, $b);

    expect($result->width)->toBe(4);
    expect($result->height)->toBe(4);
    expect($result->diffPercent)->toBe(0.0);
});

it('exposes the cropped background RGB (image A) sized to the compared overlap, for the diff.png encoder', function () {
    $a = oracleSolidPng(3, 2, 10, 20, 30);
    $b = oracleSolidPng(3, 2, 10, 20, 30);

    $result = PixelDiff::compare($a, $b);

    expect(strlen($result->backgroundRgb))->toBe(3 * 2 * 3);
    expect($result->backgroundRgb)->toBe(str_repeat(chr(10) . chr(20) . chr(30), 6));
});
