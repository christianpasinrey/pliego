<?php

// tests/Unit/Pdf/PdfCanvasTest.php
declare(strict_types=1);

use Pliego\Css\Value\Color;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;
use Pliego\Page\PaperSize;
use Pliego\Pdf\FontRegistry;
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
    $canvas = new PdfCanvas($writer, $registry, PaperSize::A4, 0.0, 0.0);
    $canvas->beginPage();
    $draw($canvas, $font);
    $canvas->endPage();
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
