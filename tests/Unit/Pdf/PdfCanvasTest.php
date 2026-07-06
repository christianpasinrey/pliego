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

// M6-T5: ExtGState (ISO 32000-1 §8.4.5) — /GSn gs before an op whose Color has alpha != 1,
// wrapped in q/Q; dedup by value; transparent paints nothing.

it('emits a /GSn gs inside q/Q for a fillRect with alpha < 1, and registers a /ca /CA ExtGState dict', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->fillRect(new Rect(0, 0, 100, 50), new Color(255, 0, 0, 0.5));
    });
    expect($pdf)->toContain('q');
    expect($pdf)->toContain('/GS1 gs');
    expect($pdf)->toContain('Q');
    expect($pdf)->toContain('/Type /ExtGState');
    expect($pdf)->toContain('/ca 0.500');
    expect($pdf)->toContain('/CA 0.500');
    expect($pdf)->toContain('1.000 0.000 0.000 rg');
});

it('does NOT emit a gs op or wrap in q/Q for a fully opaque color (alpha null)', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->fillRect(new Rect(0, 0, 100, 50), new Color(255, 0, 0));
    });
    expect($pdf)->not->toContain('gs');
    expect($pdf)->not->toContain('/ExtGState');
});

it('dedups two elements sharing the same alpha value into a SINGLE ExtGState object', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->fillRect(new Rect(0, 0, 10, 10), new Color(255, 0, 0, 0.5));
        $canvas->fillRect(new Rect(20, 20, 10, 10), new Color(0, 0, 255, 0.5));
    });
    expect(substr_count($pdf, '/Type /ExtGState'))->toBe(1);
    expect(substr_count($pdf, '/GS1 gs'))->toBe(2);
});

it('registers TWO distinct ExtGState objects for two different alpha values', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->fillRect(new Rect(0, 0, 10, 10), new Color(255, 0, 0, 0.5));
        $canvas->fillRect(new Rect(20, 20, 10, 10), new Color(0, 0, 255, 0.25));
    });
    expect(substr_count($pdf, '/Type /ExtGState'))->toBe(2);
    expect($pdf)->toContain('/GS1 gs')->toContain('/GS2 gs');
    expect($pdf)->toContain('/ca 0.500')->toContain('/ca 0.250');
});

it('registers the ExtGState resources in the page /Resources dict', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->fillRect(new Rect(0, 0, 10, 10), new Color(255, 0, 0, 0.5));
    });
    expect($pdf)->toContain('/ExtGState <<')->toContain('/GS1');
});

it('paints NOTHING for a fully transparent fillRect (alpha 0) — no re/f op at all', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->fillRect(new Rect(0, 0, 100, 50), new Color(0, 0, 0, 0.0));
    });
    expect($pdf)->not->toContain(' re f');
    expect($pdf)->not->toContain('/ExtGState');
});

it('emits a gs op for a strokeLine with alpha < 1', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->strokeLine(0.0, 0.0, 10.0, 0.0, 1.0, new Color(0, 0, 0, 0.5));
    });
    expect($pdf)->toContain('/GS1 gs')->toContain('/ca 0.500');
});

it('emits a gs op for fillText when the color has alpha < 1', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->fillText(new TextFragment(new Rect(10, 10, 50, 19.2), 'Hola', 24.4, 16.0, new Color(0, 0, 0, 0.5), 'default:400:normal', false));
    });
    expect($pdf)->toContain('/GS1 gs')->toContain('/ca 0.500')->toContain('Tj');
});

it('paints NOTHING for fillText when the color is fully transparent (alpha 0) — no glyphs registered either', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->fillText(new TextFragment(new Rect(10, 10, 50, 19.2), 'Hola', 24.4, 16.0, new Color(0, 0, 0, 0.0), 'default:400:normal', false));
    });
    expect($pdf)->not->toContain('Tj');
    expect($pdf)->not->toContain('/ExtGState');
});

it('combines opacity 0.5 over an rgba(0,0,255,0.5) color into an effective /ca 0.250', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->fillText(new TextFragment(new Rect(10, 10, 50, 19.2), 'Hola', 24.4, 16.0, new Color(0, 0, 255, 0.5), 'default:400:normal', false, 0.5));
    });
    expect($pdf)->toContain('/ca 0.250');
});

it('inserts /GSn gs right after the opening q for a drawImage with opacity < 1, same q/Q scope as cm', function () {
    $imagePath = __DIR__ . '/../../../resources/images/tiny.jpg';
    $pdf = renderOnePage(function (PdfCanvas $canvas) use ($imagePath): void {
        $canvas->drawImage(new Rect(0.0, 0.0, 10.0, 10.0), $imagePath, 0.5);
    });
    expect($pdf)->toContain("q\n/GS1 gs\n");
    expect($pdf)->toContain('/ca 0.500');
    expect($pdf)->toContain('/Im1 Do Q');
});

it('paints NOTHING for a drawImage with opacity 0 — no XObject registered, no Do op', function () {
    $imagePath = __DIR__ . '/../../../resources/images/tiny.jpg';
    $pdf = renderOnePage(function (PdfCanvas $canvas) use ($imagePath): void {
        $canvas->drawImage(new Rect(0.0, 0.0, 10.0, 10.0), $imagePath, 0.0);
    });
    expect($pdf)->not->toContain('/Subtype /Image');
    expect($pdf)->not->toContain('Do');
});

// M6-T6 (controller addition, T5 review): byte-level proof of the q/Q scoping guarantee
// documented on emitWithAlpha() — an alpha'd op's `gs` is scoped to its OWN q/Q pair and must
// never leak onto whatever paints right after it.

it('leaves NO residual gs state after an alpha\'d op: the very next opaque op starts right at "Q\\n", byte for byte', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->fillRect(new Rect(0, 0, 10, 10), new Color(255, 0, 0, 0.5)); // alpha'd: q/gs/rg/Q
        $canvas->fillRect(new Rect(20, 20, 10, 10), new Color(0, 0, 255));    // opaque: rg only, unwrapped
    });
    // The opaque fillRect's own body (its "rg" line) is untouched by emitWithAlpha() and is
    // appended immediately after the alpha'd op's closing "Q\n" — a literal substring match
    // proves there is no dangling "gs"/stray "q" between the two ops, at the byte level.
    expect($pdf)->toContain("Q\n0.000 0.000 1.000 rg");
    // Sanity: exactly one q/Q scope opened total (the alpha'd op's own) — the opaque op that
    // follows adds neither a new q/Q pair nor a second "gs" (the /GS1 name also appears once
    // more in the page's /Resources /ExtGState dict, unrelated to the content stream itself).
    expect(substr_count($pdf, "q\n"))->toBe(1);
    expect(substr_count($pdf, "Q\n"))->toBe(1);
    expect(substr_count($pdf, '/GS1 gs'))->toBe(1);
});
