<?php

declare(strict_types=1);

use Pliego\Image\ImageException;
use Pliego\Image\ImageLoader;
use Pliego\Image\JpegImage;
use Pliego\Image\PngImage;

beforeEach(function (): void {
    $this->loader = new ImageLoader();
});

it('detects a JPEG by its magic bytes and delegates to JpegImage', function () {
    $image = $this->loader->load(__DIR__ . '/../../../resources/images/tiny.jpg');
    expect($image)->toBeInstanceOf(JpegImage::class);
    expect($image->widthPx())->toBe(4);
});

it('detects a PNG by its magic bytes and delegates to PngImage', function () {
    $image = $this->loader->load(__DIR__ . '/../../../resources/images/tiny-rgba-paeth.png');
    expect($image)->toBeInstanceOf(PngImage::class);
    expect($image->widthPx())->toBe(3);
});

it('throws ImageException for an unsupported format', function () {
    $path = tempnam(sys_get_temp_dir(), 'gif') . '.gif';
    file_put_contents($path, 'GIF89a' . str_repeat("\x00", 20));
    try {
        $this->loader->load($path);
    } finally {
        unlink($path);
    }
})->throws(ImageException::class);

it('throws ImageException when the file cannot be read', function () {
    $this->loader->load(__DIR__ . '/../../../resources/images/does-not-exist.png');
})->throws(ImageException::class);

it('memoizes: loading the same path twice returns the SAME DecodedImage instance', function () {
    $path = __DIR__ . '/../../../resources/images/tiny.jpg';
    $first = $this->loader->load($path);
    $second = $this->loader->load($path);
    expect($second)->toBe($first);
});

it('does not memoize across different paths', function () {
    $jpg = $this->loader->load(__DIR__ . '/../../../resources/images/tiny.jpg');
    $png = $this->loader->load(__DIR__ . '/../../../resources/images/tiny-rgba-paeth.png');
    expect($png)->not->toBe($jpg);
});

it('serves the cached instance even if the file is deleted after the first load (closes build/paint TOCTOU)', function () {
    $source = __DIR__ . '/../../../resources/images/tiny.jpg';
    $path = tempnam(sys_get_temp_dir(), 'img') . '.jpg';
    copy($source, $path);

    $first = $this->loader->load($path);
    unlink($path);

    $second = $this->loader->load($path);
    expect($second)->toBe($first);
});
