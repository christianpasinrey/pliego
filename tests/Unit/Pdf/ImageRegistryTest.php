<?php

// tests/Unit/Pdf/ImageRegistryTest.php
declare(strict_types=1);

use Pliego\Image\ImageLoader;
use Pliego\Pdf\ImageRegistry;
use Pliego\Pdf\PdfWriter;

const IMG_JPEG_FIXTURE = __DIR__ . '/../../../resources/images/tiny.jpg';
const IMG_RGBA_PNG_FIXTURE = __DIR__ . '/../../../resources/images/tiny-rgba-paeth.png';

/** Mirrors FontRegistryTest's renderRegistryPdf(): resolve images -> addPage(pageResources()) -> flushAll(). */
function renderImageRegistryPdf(callable $draw): string
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $writer = new PdfWriter($stream);
    $writer->begin();
    $registry = new ImageRegistry($writer, new ImageLoader());
    $draw($registry);
    $writer->addPage(595.0, 842.0, '', [], $registry->pageResources());
    $registry->flushAll();
    $writer->finish();
    rewind($stream);
    return (string) stream_get_contents($stream);
}

it('gives each distinct image its own resource name in first-use order, memoized by imageKey', function () {
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $writer = new PdfWriter($stream);
    $writer->begin();
    $registry = new ImageRegistry($writer, new ImageLoader());

    $first = $registry->xobjectFor(IMG_JPEG_FIXTURE);
    $second = $registry->xobjectFor(IMG_RGBA_PNG_FIXTURE);
    $firstAgain = $registry->xobjectFor(IMG_JPEG_FIXTURE);

    expect($firstAgain)->toBe($first); // lazy + memoized por imageKey (misma foto en 2 sitios = 1 XObject)
    expect($first->name)->toBe('Im1');
    expect($second->name)->toBe('Im2');

    $resources = $registry->pageResources();
    expect($resources)->toBe(['Im1' => $first->objectId, 'Im2' => $second->objectId]);
});

it('writes a JPEG XObject with Width/Height/ColorSpace/BitsPerComponent/Filter and the raw DCTDecode bytes', function () {
    $pdf = renderImageRegistryPdf(function (ImageRegistry $registry): void {
        $registry->xobjectFor(IMG_JPEG_FIXTURE);
    });

    expect($pdf)->toContain('/Type /XObject')
        ->toContain('/Subtype /Image')
        ->toContain('/Width 4')
        ->toContain('/Height 3')
        ->toContain('/ColorSpace /DeviceRGB')
        ->toContain('/BitsPerComponent 8')
        ->toContain('/Filter /DCTDecode');
    expect($pdf)->toContain((string) file_get_contents(IMG_JPEG_FIXTURE));
    expect($pdf)->not->toContain('/SMask'); // JPEG fixture has no alpha
});

it('writes an SMask as its own gray XObject BEFORE the RGBA image object, referenced via /SMask N 0 R', function () {
    $pdf = renderImageRegistryPdf(function (ImageRegistry $registry): void {
        $registry->xobjectFor(IMG_RGBA_PNG_FIXTURE);
    });

    expect($pdf)->toContain('/ColorSpace /DeviceGray'); // the smask's own colorspace
    expect($pdf)->toContain('/ColorSpace /DeviceRGB');   // the main image's colorspace
    preg_match('/\/SMask (\d+) 0 R/', $pdf, $m);
    expect($m)->not->toBeEmpty();
    $smaskId = (int) $m[1];

    // The SMask object dict/stream must appear EARLIER in the byte stream than the main image
    // object that references it (both are written inside the same flushAll() call).
    $smaskObjOffset = strpos($pdf, "\n$smaskId 0 obj\n");
    $smaskRefOffset = strpos($pdf, "/SMask $smaskId 0 R");
    expect($smaskObjOffset)->not->toBeFalse();
    expect($smaskRefOffset)->not->toBeFalse();
    expect($smaskObjOffset)->toBeLessThan($smaskRefOffset);
});

it('does not write an SMask for an opaque (non-alpha) image', function () {
    $pdf = renderImageRegistryPdf(function (ImageRegistry $registry): void {
        $registry->xobjectFor(IMG_JPEG_FIXTURE);
    });
    expect($pdf)->not->toContain('/SMask');
});

it('writes exactly one XObject definition even when the same imageKey is requested twice', function () {
    $pdf = renderImageRegistryPdf(function (ImageRegistry $registry): void {
        $registry->xobjectFor(IMG_JPEG_FIXTURE);
        $registry->xobjectFor(IMG_JPEG_FIXTURE);
    });
    expect(substr_count($pdf, '/Subtype /Image'))->toBe(1);
});
