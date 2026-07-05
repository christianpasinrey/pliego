<?php

declare(strict_types=1);

use Pliego\Image\ImageException;
use Pliego\Image\JpegImage;

/**
 * Builds a minimal (fake) JPEG byte string with just enough markers to reach an SOF segment:
 * SOI, then one SOFn marker carrying the given dims/components — no actual entropy-coded scan
 * data, since the decoder only needs to read headers. `$marker` is the SOF marker byte (0xC0
 * baseline, 0xC1 extended-sequential, 0xC2 progressive).
 */
function buildMinimalJpeg(int $marker, int $width, int $height, int $components): string
{
    $sof = chr(0xFF) . chr($marker)
        . pack('n', 8 + 3 * $components) // Lf: precision+height+width+components + 3 bytes/component
        . chr(8)                          // precision
        . pack('n', $height)
        . pack('n', $width)
        . chr($components)
        . str_repeat("\x01\x11\x00", $components); // component info (id, sampling, quant table)

    return "\xFF\xD8" . $sof;
}

it('reads dims and DeviceRGB color space from a real baseline JPEG fixture', function () {
    $path = __DIR__ . '/../../../resources/images/tiny.jpg';
    $bytes = (string) file_get_contents($path);
    $image = JpegImage::fromBytes($bytes);

    expect($image->widthPx())->toBe(4);
    expect($image->heightPx())->toBe(3);

    $data = $image->pdfData();
    expect($data->filter)->toBe('DCTDecode');
    expect($data->colorSpace)->toBe('DeviceRGB');
    expect($data->bitsPerComponent)->toBe(8);
    expect($data->smaskBytes)->toBeNull();
    // Whole file is embedded verbatim for DCTDecode passthrough.
    expect($data->bytes)->toBe($bytes);
});

it('reads dims/components from a synthetic baseline (SOF0) segment', function () {
    $image = JpegImage::fromBytes(buildMinimalJpeg(0xC0, 10, 20, 3));
    expect($image->widthPx())->toBe(10);
    expect($image->heightPx())->toBe(20);
    expect($image->pdfData()->colorSpace)->toBe('DeviceRGB');
});

it('reads dims/components from a synthetic extended-sequential (SOF1) segment', function () {
    $image = JpegImage::fromBytes(buildMinimalJpeg(0xC1, 5, 6, 1));
    expect($image->widthPx())->toBe(5);
    expect($image->heightPx())->toBe(6);
    expect($image->pdfData()->colorSpace)->toBe('DeviceGray');
});

it('reads dims/components from a synthetic progressive (SOF2) segment', function () {
    $image = JpegImage::fromBytes(buildMinimalJpeg(0xC2, 100, 50, 3));
    expect($image->widthPx())->toBe(100);
    expect($image->heightPx())->toBe(50);
    expect($image->pdfData()->colorSpace)->toBe('DeviceRGB');
});

it('maps a single component to DeviceGray', function () {
    $image = JpegImage::fromBytes(buildMinimalJpeg(0xC0, 1, 1, 1));
    expect($image->pdfData()->colorSpace)->toBe('DeviceGray');
});

it('throws ImageException for CMYK (4-component) JPEGs', function () {
    JpegImage::fromBytes(buildMinimalJpeg(0xC0, 1, 1, 4));
})->throws(ImageException::class, 'CMYK');

it('throws ImageException when the SOI marker is missing', function () {
    JpegImage::fromBytes("not a jpeg at all");
})->throws(ImageException::class);

it('throws ImageException when no SOF marker is ever found', function () {
    // SOI followed only by an APP0/JFIF segment, no SOF.
    $app0 = "\xFF\xE0" . pack('n', 16) . "JFIF\x00\x01\x02\x00\x00\x01\x00\x01\x00\x00";
    JpegImage::fromBytes("\xFF\xD8" . $app0);
})->throws(ImageException::class);
