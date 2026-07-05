<?php

declare(strict_types=1);

use Pliego\Image\ImageException;
use Pliego\Image\PngImage;

function pngChunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

/**
 * Builds a minimal valid PNG: signature + IHDR (with the given bit depth/color type/interlace)
 * + IDAT (zlib-compressed $rawFiltered scanlines, or a single empty IDAT when omitted) + IEND.
 * Enough for the decoder to reach (and, for the error-path tests, fail on) IHDR validation
 * without needing a full real image.
 */
function buildMinimalPng(
    int $width,
    int $height,
    int $bitDepth,
    int $colorType,
    int $interlace = 0,
    ?string $rawFiltered = null,
): string {
    $ihdr = pack('N', $width) . pack('N', $height) . chr($bitDepth) . chr($colorType) . chr(0) . chr(0) . chr($interlace);
    $idatData = zlib_encode($rawFiltered ?? '', ZLIB_ENCODING_DEFLATE);
    if ($idatData === false) {
        throw new RuntimeException('zlib_encode failed while building the test fixture.');
    }

    return "\x89PNG\r\n\x1a\n"
        . pngChunk('IHDR', $ihdr)
        . pngChunk('IDAT', $idatData)
        . pngChunk('IEND', '');
}

it('decodes the hand-crafted 3x2 RGBA fixture and unfilters Paeth byte-for-byte', function () {
    $path = __DIR__ . '/../../../resources/images/tiny-rgba-paeth.png';
    $image = PngImage::fromBytes((string) file_get_contents($path));

    expect($image->widthPx())->toBe(3);
    expect($image->heightPx())->toBe(2);

    $data = $image->pdfData();
    expect($data->filter)->toBe('FlateDecode');
    expect($data->colorSpace)->toBe('DeviceRGB');
    expect($data->bitsPerComponent)->toBe(8);
    expect($data->smaskBytes)->not->toBeNull();

    // Known true pixel values baked into the fixture (see gen_fixtures script / M3-T1 report):
    // row0 = (255,0,0,255) (0,255,0,128) (0,0,255,0) -- filter None
    // row1 = (10,20,30,40) (50,60,70,80) (90,100,110,120) -- filter Paeth
    $expectedRgb = pack('C*', 255, 0, 0, 0, 255, 0, 0, 0, 255, 10, 20, 30, 50, 60, 70, 90, 100, 110);
    $expectedAlpha = pack('C*', 255, 128, 0, 40, 80, 120);

    expect(zlib_decode($data->bytes))->toBe($expectedRgb);
    expect(zlib_decode((string) $data->smaskBytes))->toBe($expectedAlpha);
});

it('decodes a GD-generated truecolor RGB PNG (no alpha) with dims and no smask', function () {
    // imagecreatetruecolor() without imagesavealpha() writes a plain RGB (color type 2) PNG,
    // not a palette image — GD has no direct API for writing a true grayscale (color type 0)
    // PNG, so this exercises the non-alpha RGB path instead.
    $im = imagecreatetruecolor(5, 4);
    $color = imagecolorallocate($im, 128, 128, 128);
    imagefill($im, 0, 0, $color === false ? 0 : $color);
    $path = tempnam(sys_get_temp_dir(), 'png') . '.png';
    imagepng($im, $path);
    imagedestroy($im);

    try {
        $image = PngImage::fromBytes((string) file_get_contents($path));
        expect($image->widthPx())->toBe(5);
        expect($image->heightPx())->toBe(4);
        expect($image->pdfData()->colorSpace)->toBe('DeviceRGB');
        expect($image->pdfData()->smaskBytes)->toBeNull();
    } finally {
        unlink($path);
    }
})->skip(!extension_loaded('gd'), 'ext-gd not available');

it('decodes a GD-generated truecolor RGBA PNG with alpha into an smask', function () {
    $im = imagecreatetruecolor(6, 5);
    imagesavealpha($im, true);
    imagealphablending($im, false);
    $transparent = imagecolorallocatealpha($im, 10, 20, 30, 64); // gd alpha: 0 opaque .. 127 transparent
    imagefill($im, 0, 0, $transparent === false ? 0 : $transparent);
    $path = tempnam(sys_get_temp_dir(), 'png') . '.png';
    imagepng($im, $path);
    imagedestroy($im);

    try {
        $image = PngImage::fromBytes((string) file_get_contents($path));
        expect($image->widthPx())->toBe(6);
        expect($image->heightPx())->toBe(5);
        expect($image->pdfData()->colorSpace)->toBe('DeviceRGB');
        expect($image->pdfData()->smaskBytes)->not->toBeNull();
    } finally {
        unlink($path);
    }
})->skip(!extension_loaded('gd'), 'ext-gd not available');

it('throws ImageException for a missing PNG signature', function () {
    PngImage::fromBytes('not a png at all, definitely not');
})->throws(ImageException::class);

it('throws ImageException for bit depths other than 8', function () {
    PngImage::fromBytes(buildMinimalPng(1, 1, bitDepth: 16, colorType: 2));
})->throws(ImageException::class);

it('throws ImageException for palette (color type 3) PNGs', function () {
    PngImage::fromBytes(buildMinimalPng(1, 1, bitDepth: 8, colorType: 3));
})->throws(ImageException::class);

it('throws ImageException for unsupported color types (e.g. gray+alpha, type 4)', function () {
    PngImage::fromBytes(buildMinimalPng(1, 1, bitDepth: 8, colorType: 4));
})->throws(ImageException::class);

it('throws ImageException for interlaced (Adam7) PNGs', function () {
    PngImage::fromBytes(buildMinimalPng(1, 1, bitDepth: 8, colorType: 2, interlace: 1));
})->throws(ImageException::class);

it('throws ImageException when there is no IDAT chunk at all', function () {
    $ihdr = pack('N', 1) . pack('N', 1) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0);
    PngImage::fromBytes("\x89PNG\r\n\x1a\n" . pngChunk('IHDR', $ihdr) . pngChunk('IEND', ''));
})->throws(ImageException::class);
