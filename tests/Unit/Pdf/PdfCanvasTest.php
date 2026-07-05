<?php

// tests/Unit/Pdf/PdfCanvasTest.php
declare(strict_types=1);

use Pliego\Css\Value\Color;
use Pliego\Image\ImageLoader;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;
use Pliego\Page\PaperSize;
use Pliego\Pdf\FontRegistry;
use Pliego\Pdf\ImageRegistry;
use Pliego\Pdf\PdfCanvas;
use Pliego\Pdf\PdfWriter;
use Pliego\Text\FontCatalog;
use Pliego\Text\TtfFont;

function renderOnePage(callable $draw): string
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $writer = new PdfWriter($stream);
    $writer->begin();
    $catalog = FontCatalog::withDefaults();
    $font = $catalog->select('default', 400, false)->font;
    $registry = new FontRegistry($writer, $catalog);
    $images = new ImageRegistry($writer, new ImageLoader());
    $canvas = new PdfCanvas($writer, $registry, $images, PaperSize::A4, 0.0, 0.0);
    $canvas->beginPage();
    $draw($canvas, $font);
    $canvas->endPage();
    $images->flushAll();
    $registry->flushAll();
    $writer->finish();
    rewind($stream);
    return (string) stream_get_contents($stream);
}

it('embeds a Type0 Identity-H font with the used glyph widths', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->fillText(new TextFragment(new Rect(10, 10, 50, 19.2), 'Hola', 24.4, 16.0, new Color(0, 0, 0), 'default:400:normal', false));
    });
    expect($pdf)->toContain('/Subtype /Type0')->toContain('/Encoding /Identity-H')
        ->toContain('/Subtype /CIDFontType2')->toContain('/FontFile2');
});
it('writes text as hex CIDs of the glyph ids', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->fillText(new TextFragment(new Rect(10, 10, 20, 19.2), 'A', 24.4, 16.0, new Color(0, 0, 0), 'default:400:normal', false));
    });
    $font = TtfFont::fromFile(__DIR__ . '/../../../resources/fonts/DejaVuSans.ttf');
    $expectedHex = sprintf('%04X', $font->glyphId(0x41));
    expect($pdf)->toContain("<$expectedHex> Tj");
});
it('flips the Y axis and converts px to pt for rectangles', function () {
    // rect y=0 px (top) con altura 100px => en PDF: y = (1122.52-100)*0.75 pt
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->fillRect(new Rect(0, 0, 200, 100), new Color(255, 0, 0));
    });
    $expectedY = (PaperSize::A4->heightPx() - 100.0) * 0.75;
    expect($pdf)->toContain(sprintf('0.00 %.2F 150.00 75.00 re', $expectedY));
    expect($pdf)->toContain('1.000 0.000 0.000 rg');
});
it('strokes a horizontal line with RG stroke color, w line width in pt, and m/l/S path ops', function () {
    // y=20px horizontal (x:10..60px), grosor 0.8px => todo x0.75 con flip vertical de Y.
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->strokeLine(10.0, 20.0, 60.0, 20.0, 0.8, new Color(0, 0, 0));
    });
    $expectedY = (PaperSize::A4->heightPx() - 20.0) * 0.75;
    expect($pdf)->toContain('0.000 0.000 0.000 RG');
    expect($pdf)->toContain(sprintf('%.2F w', 0.8 * 0.75));
    expect($pdf)->toContain(sprintf('%.2F %.2F m %.2F %.2F l S', 7.50, $expectedY, 45.00, $expectedY));
});
it('flips Y and scales px to pt for both endpoints of a diagonal stroked line', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->strokeLine(0.0, 0.0, 100.0, 200.0, 1.0, new Color(255, 0, 0));
    });
    $expectedY1 = PaperSize::A4->heightPx() * 0.75;
    $expectedY2 = (PaperSize::A4->heightPx() - 200.0) * 0.75;
    expect($pdf)->toContain('1.000 0.000 0.000 RG');
    expect($pdf)->toContain(sprintf('%.2F %.2F m %.2F %.2F l S', 0.00, $expectedY1, 75.00, $expectedY2));
});

// M3-T4: image XObjects, drawn via ImageRegistry.

it('draws an image via q wPt 0 0 hPt xPt yPt cm /ImN Do Q, with the flipped-Y bottom-left origin', function () {
    $imagePath = __DIR__ . '/../../../resources/images/tiny.jpg';
    $rect = new Rect(10.0, 20.0, 40.0, 30.0);
    $pdf = renderOnePage(function (PdfCanvas $canvas) use ($rect, $imagePath): void {
        $canvas->drawImage($rect, $imagePath);
    });

    $expectedX = $rect->x * 0.75;
    $expectedY = (PaperSize::A4->heightPx() - $rect->y - $rect->height) * 0.75;
    $expectedW = $rect->width * 0.75;
    $expectedH = $rect->height * 0.75;
    expect($pdf)->toContain(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q', $expectedW, $expectedH, $expectedX, $expectedY));
    expect($pdf)->toContain('/Type /XObject')->toContain('/Subtype /Image')
        ->toContain('/ColorSpace /DeviceRGB')->toContain('/Filter /DCTDecode');
});

it('applies the canvas content offset (offsetX/offsetY) to a drawn image, same as fillRect/fillText', function () {
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $writer = new PdfWriter($stream);
    $writer->begin();
    $catalog = FontCatalog::withDefaults();
    $registry = new FontRegistry($writer, $catalog);
    $images = new ImageRegistry($writer, new ImageLoader());
    $canvas = new PdfCanvas($writer, $registry, $images, PaperSize::A4, 100.0, 200.0);
    $imagePath = __DIR__ . '/../../../resources/images/tiny.jpg';
    $rect = new Rect(10.0, 20.0, 40.0, 30.0);

    $canvas->beginPage();
    $canvas->drawImage($rect, $imagePath);
    $canvas->endPage();
    $images->flushAll();
    $registry->flushAll();
    $writer->finish();
    rewind($stream);
    $pdf = (string) stream_get_contents($stream);

    $expectedX = ($rect->x + 100.0) * 0.75;
    $expectedY = (PaperSize::A4->heightPx() - ($rect->y + 200.0) - $rect->height) * 0.75;
    $expectedW = $rect->width * 0.75;
    $expectedH = $rect->height * 0.75;
    expect($pdf)->toContain(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q', $expectedW, $expectedH, $expectedX, $expectedY));
});

it('dedups repeated drawImage() calls for the same imageKey into a single XObject definition (2 Do, 1 image object)', function () {
    $imagePath = __DIR__ . '/../../../resources/images/tiny.jpg';
    $pdf = renderOnePage(function (PdfCanvas $canvas) use ($imagePath): void {
        $canvas->drawImage(new Rect(0.0, 0.0, 10.0, 10.0), $imagePath);
        $canvas->drawImage(new Rect(20.0, 20.0, 10.0, 10.0), $imagePath);
    });

    expect(substr_count($pdf, '/Subtype /Image'))->toBe(1);
    expect(substr_count($pdf, '/Im1 Do'))->toBe(2);
});

it('registers the image XObject as a page resource, distinct from font (F*) and deferred-form (XO*) resources', function () {
    $imagePath = __DIR__ . '/../../../resources/images/tiny.jpg';
    $pdf = renderOnePage(function (PdfCanvas $canvas) use ($imagePath): void {
        $canvas->drawImage(new Rect(0.0, 0.0, 10.0, 10.0), $imagePath);
    });
    expect($pdf)->toContain('/XObject <<')->toContain('/Im1');
});

// M2-T7: margin-box painting — page-absolute placement (bypasses the content-area offset).

it('draws text at page-absolute px coordinates, ignoring the canvas content offset', function () {
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $writer = new PdfWriter($stream);
    $writer->begin();
    $catalog = FontCatalog::withDefaults();
    $registry = new FontRegistry($writer, $catalog);
    $images = new ImageRegistry($writer, new ImageLoader());
    // Nonzero offsetX/offsetY (simulates a real Engine content margin) must NOT shift a margin
    // box: margin boxes live in the margin, not the content area.
    $canvas = new PdfCanvas($writer, $registry, $images, PaperSize::A4, 100.0, 200.0);
    $canvas->beginPage();
    $canvas->fillTextAtPage(10.0, 20.0, 'A', 10.0, new Color(0x55, 0x55, 0x55), 'default:400:normal');
    $canvas->endPage();
    $registry->flushAll();
    $writer->finish();
    rewind($stream);
    $pdf = (string) stream_get_contents($stream);

    $font = TtfFont::fromFile(__DIR__ . '/../../../resources/fonts/DejaVuSans.ttf');
    $expectedHex = sprintf('%04X', $font->glyphId(0x41));
    $expectedX = 10.0 * 0.75;
    $expectedBaseline = (PaperSize::A4->heightPx() - 20.0) * 0.75;

    expect($pdf)->toContain('0.333 0.333 0.333 rg'); // #555555 = 85/255
    expect($pdf)->toContain(sprintf('%.2F %.2F Td <%s> Tj', $expectedX, $expectedBaseline, $expectedHex));
});

it('places a deferred XObject via q/cm/Do/Q at page-absolute px coordinates and registers it as a page resource', function () {
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $writer = new PdfWriter($stream);
    $writer->begin();
    $catalog = FontCatalog::withDefaults();
    $registry = new FontRegistry($writer, $catalog);
    $images = new ImageRegistry($writer, new ImageLoader());
    $canvas = new PdfCanvas($writer, $registry, $images, PaperSize::A4, 0.0, 0.0);
    $ref = $writer->defer(50.0, 10.0, [], fn(int $totalPages): string => '');

    $canvas->beginPage();
    $canvas->placeXObject($ref, 20.0, 40.0); // x=20px, bottom edge y=40px (page-absolute, top-left origin)
    $canvas->endPage();
    $writer->writeDeferred(1);
    $registry->flushAll();
    $writer->finish();
    rewind($stream);
    $pdf = (string) stream_get_contents($stream);

    $expectedX = 20.0 * 0.75;
    $expectedY = (PaperSize::A4->heightPx() - 40.0) * 0.75;
    expect($pdf)->toContain(sprintf('q 1 0 0 1 %.2F %.2F cm /%s Do Q', $expectedX, $expectedY, $ref->name));
    expect($pdf)->toContain("/XObject << /{$ref->name} {$ref->objectId} 0 R >>");
});
