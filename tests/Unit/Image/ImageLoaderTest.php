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

it('memoizes via realpath: two different path spellings for the same file share one DecodedImage (M5-T1)', function () {
    $dir = __DIR__ . '/../../../resources/images';
    $plain = $this->loader->load($dir . '/tiny.jpg');
    $dotted = $this->loader->load($dir . '/./tiny.jpg'); // resolves to the identical real file
    expect($dotted)->toBe($plain);
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

it('serves the cached instance by ORIGINAL path spelling after deletion, even when raw path and realpath differ (M5-T1 regression)', function () {
    // Reviewer reproduced the original defect with an NTFS junction: load an image through a path
    // that resolves via a junction/symlink (raw path !== realpath), delete the underlying target,
    // then reload through the SAME raw spelling. With a realpath-ONLY cache key, the first load
    // stores the DecodedImage under the RESOLVED key; once the target is gone, realpath() fails
    // and the lookup falls back to the raw path -- a key that was never written -- so it's a
    // cache miss and ImageException is thrown, breaking the documented TOCTOU guarantee (the
    // cached instance should be served instead). Junctions/symlinks need elevated permissions on
    // Windows CI, so this test reproduces the identical "raw path !== realpath" condition with a
    // plain './' path segment instead -- no OS-specific setup required, same defect.
    $dir = sys_get_temp_dir() . '/pliego-imgloader-toctou-' . uniqid();
    mkdir($dir);
    copy(__DIR__ . '/../../../resources/images/tiny.jpg', $dir . '/tiny.jpg');
    $path = $dir . '/./tiny.jpg'; // raw path differs from its own realpath() (the './' collapses)

    $first = $this->loader->load($path);
    unlink($dir . '/tiny.jpg');

    $second = $this->loader->load($path); // same raw spelling as the first call
    expect($second)->toBe($first);

    rmdir($dir);
});
