<?php

// tests/Unit/Pdf/PdfCanvasTest.php
declare(strict_types=1);

use Pliego\Css\Value\Color;
use Pliego\Css\Value\Gradient;
use Pliego\Css\Value\GradientKind;
use Pliego\Css\Value\GradientStop;
use Pliego\Image\ImageLoader;
use Pliego\Layout\Fragment\BorderRadius;
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

// M7-T5 (css-overflow-3): clipRect()/restoreClip() — byte-level proof of the q/re/W n/Q clip scope.

it('emits q + re W n for clipRect(), flipping Y and converting px to pt like fillRect', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->clipRect(new Rect(0, 0, 200, 100));
    });
    $expectedY = (PaperSize::A4->heightPx() - 100.0) * 0.75;
    expect($pdf)->toContain("q\n");
    expect($pdf)->toContain(sprintf("%.2F %.2F 150.00 75.00 re W n\n", 0.00, $expectedY));
});

it('emits a bare Q for restoreClip()', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->clipRect(new Rect(0, 0, 10, 10));
        $canvas->restoreClip();
    });
    expect($pdf)->toContain("re W n\nQ\n");
});

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

// M8-T2 (css-backgrounds-3 §5): rounded rect paths -- 4 lines + 4 Bézier curves (k=0.5522847498).

it('fillRoundedRect emits EXACTLY 4 "c" curve ops for a fully-rounded rect (one per corner)', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->fillRoundedRect(new Rect(0, 0, 100, 100), new BorderRadius(20.0, 20.0, 20.0, 20.0), new Color(255, 0, 0));
    });
    expect(substr_count($pdf, " c\n"))->toBe(4);
    expect(substr_count($pdf, " l\n"))->toBe(4);
    expect($pdf)->toContain(" m\n")->toContain("h\nf\n");
});

// Hand-computed (brief: "control point arithmetic... x+r(1-k) formula") for the TOP-RIGHT corner
// of a Rect(0,0,200,100) with a 40px (=30.00pt) radius on every corner: k*r = 30 * 0.5522847498 =
// 16.568542... -> 16.57pt (2 decimals). The curve leaving the top edge starts at
// (xRight-r, yTop) = (150.00-30.00, yTop) = (120.00, yTop) and its first control point is
// (xRight - r*(1-k), yTop) = (150.00 - (30.00-16.57), yTop) = (136.57, yTop); the curve ends at
// (xRight, yTop-r) = (150.00, yTop-30.00), with a symmetric second control point
// (xRight, yTop - r*(1-k)) = (150.00, yTop-13.43).
it('hand-computes the top-right corner control points via x+r(1-k) (k=0.5522847498)', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->fillRoundedRect(new Rect(0, 0, 200, 100), new BorderRadius(40.0, 40.0, 40.0, 40.0), new Color(0, 0, 0));
    });
    $yTop = PaperSize::A4->heightPx() * 0.75;
    expect($pdf)->toContain(sprintf('%.2F %.2F l', 120.00, $yTop));
    expect($pdf)->toContain(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', 136.57, $yTop, 150.00, $yTop - 13.43, 150.00, $yTop - 30.00));
});

it('draws a sharp (non-curved) corner with a straight line when its OWN radius is zero, even if other corners are rounded', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        // Only top-left (tl) is rounded; tr/br/bl are all 0.
        $canvas->fillRoundedRect(new Rect(0, 0, 100, 100), new BorderRadius(tl: 20.0), new Color(0, 0, 0));
    });
    expect(substr_count($pdf, " c\n"))->toBe(1);
});

it('clipRoundedRect emits q + the rounded path + W n (no fill operator)', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->clipRoundedRect(new Rect(0, 0, 100, 100), new BorderRadius(10.0, 10.0, 10.0, 10.0));
    });
    expect($pdf)->toContain("q\n");
    expect(substr_count($pdf, " c\n"))->toBe(4);
    expect($pdf)->toContain("h\nW n\n");
    expect($pdf)->not->toContain("h\nf\n");
});

it('fillRoundedRectRing emits ONE f* fill with 2 subpaths (outer + inner), 8 curve ops total', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->fillRoundedRectRing(
            new Rect(0, 0, 100, 100),
            new BorderRadius(20.0, 20.0, 20.0, 20.0),
            new Rect(5, 5, 90, 90),
            new BorderRadius(15.0, 15.0, 15.0, 15.0),
            new Color(0, 0, 0),
        );
    });
    expect(substr_count($pdf, " c\n"))->toBe(8);
    expect($pdf)->toContain("h\nf*\n");
});

it('emits a /GSn gs for fillRoundedRect with an alpha color, same emitWithAlpha() contract as fillRect', function () {
    $pdf = renderOnePage(function (PdfCanvas $canvas): void {
        $canvas->fillRoundedRect(new Rect(0, 0, 100, 100), new BorderRadius(10.0, 10.0, 10.0, 10.0), new Color(255, 0, 0, 0.5));
    });
    expect($pdf)->toContain('/GS1 gs')->toContain('/ca 0.500');
});

// M8-T3 (css-images-3 §3.1 reducido; ISO 32000-1 §8.7.4.5 shadings): paintGradient() -- /Coords
// hand-computed for 0/90/180/45deg (css-images-3 §3.4.2 "abstract gradient line"), FunctionType
// 2/3, dedup by content signature, rounded clip, radial farthest-corner, alpha-stop opacity.

it('hand-computes /Coords for a 0deg linear-gradient as a straight bottom-to-top line', function () {
    $red = new Color(255, 0, 0);
    $blue = new Color(0, 0, 255);
    $gradient = new Gradient(GradientKind::Linear, 0.0, [new GradientStop($red, 0.0), new GradientStop($blue, 100.0)]);
    $pdf = renderOnePage(function (PdfCanvas $canvas) use ($gradient): void {
        $canvas->paintGradient(new Rect(0, 0, 200, 100), $gradient);
    });
    // Rect center (100,50) px; half-length = H/2 = 50 -> start (bottom, 0%) = (100,100)px, end
    // (top, 100%) = (100,0)px -- flip Y + ×0.75 like any other point conversion (strokeLine()).
    $y0 = (PaperSize::A4->heightPx() - 100.0) * 0.75;
    $y1 = PaperSize::A4->heightPx() * 0.75;
    expect($pdf)->toContain('/ShadingType 2');
    expect($pdf)->toContain(sprintf('/Coords [75.00 %.2F 75.00 %.2F]', $y0, $y1));
    expect($pdf)->toContain('/Extend [true true]');
});

it('hand-computes /Coords for a 90deg linear-gradient as a straight left-to-right line', function () {
    $gradient = new Gradient(GradientKind::Linear, 90.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]);
    $pdf = renderOnePage(function (PdfCanvas $canvas) use ($gradient): void {
        $canvas->paintGradient(new Rect(0, 0, 200, 100), $gradient);
    });
    // half-length = W/2 = 100 -> start (left,0%) = (0,50)px, end (right,100%) = (200,50)px --
    // same Y for both (dy=0), only X moves.
    $y = (PaperSize::A4->heightPx() - 50.0) * 0.75;
    expect($pdf)->toContain(sprintf('/Coords [0.00 %.2F 150.00 %.2F]', $y, $y));
});

it('hand-computes /Coords for a 180deg linear-gradient as a straight top-to-bottom line', function () {
    $gradient = new Gradient(GradientKind::Linear, 180.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]);
    $pdf = renderOnePage(function (PdfCanvas $canvas) use ($gradient): void {
        $canvas->paintGradient(new Rect(0, 0, 200, 100), $gradient);
    });
    $y0 = PaperSize::A4->heightPx() * 0.75; // start = top (0%)
    $y1 = (PaperSize::A4->heightPx() - 100.0) * 0.75; // end = bottom (100%)
    expect($pdf)->toContain(sprintf('/Coords [75.00 %.2F 75.00 %.2F]', $y0, $y1));
});

it('hand-computes /Coords for a 45deg linear-gradient on a SQUARE box as the exact corner-to-corner diagonal', function () {
    // css-images-3 §3.4.2 "abstract gradient line" length formula: half-length =
    // (W·|sin θ|+H·|cos θ|)/2 -- at 45deg on a square 100×100 box this is EXACTLY the diagonal
    // (100·0.70710678 = 70.710678, ×2 sides / 2 = 70.710678), so start/end land EXACTLY on the
    // two opposite corners: (0,100)px bottom-left (0%) -> (100,0)px top-right (100%).
    $gradient = new Gradient(GradientKind::Linear, 45.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]);
    $pdf = renderOnePage(function (PdfCanvas $canvas) use ($gradient): void {
        $canvas->paintGradient(new Rect(0, 0, 100, 100), $gradient);
    });
    // start = bottom-left px(0,100) -> PDF y uses (heightPx-100)*0.75 (SMALLER, lower on the page).
    // end = top-right px(100,0) -> PDF y uses heightPx*0.75 (LARGER, the page's own top edge).
    $y0 = (PaperSize::A4->heightPx() - 100.0) * 0.75;
    $y1 = PaperSize::A4->heightPx() * 0.75;
    expect($pdf)->toContain(sprintf('/Coords [0.00 %.2F 75.00 %.2F]', $y0, $y1));
});

it('hand-computes the radial-gradient /Coords as circle-at-center with the farthest-corner radius', function () {
    $gradient = new Gradient(GradientKind::Radial, 0.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]);
    $pdf = renderOnePage(function (PdfCanvas $canvas) use ($gradient): void {
        $canvas->paintGradient(new Rect(0, 0, 100, 100), $gradient);
    });
    // center (50,50)px; farthest-corner radius = sqrt(50^2+50^2) = 70.7106781px -> ×0.75 = 53.03pt.
    $cy = (PaperSize::A4->heightPx() - 50.0) * 0.75;
    expect($pdf)->toContain('/ShadingType 3');
    expect($pdf)->toContain(sprintf('/Coords [37.50 %.2F 0 37.50 %.2F 53.03]', $cy, $cy));
});

it('uses a FunctionType 2 (single exponential, N=1) for exactly 2 color stops', function () {
    $gradient = new Gradient(GradientKind::Linear, 90.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]);
    $pdf = renderOnePage(function (PdfCanvas $canvas) use ($gradient): void {
        $canvas->paintGradient(new Rect(0, 0, 100, 100), $gradient);
    });
    expect($pdf)->toContain('/FunctionType 2 /Domain [0 1] /C0 [1.000 0.000 0.000] /C1 [0.000 0.000 1.000] /N 1');
    expect($pdf)->not->toContain('/FunctionType 3');
});

it('uses a FunctionType 3 stitching function with exact Bounds/Encode bytes for 4 color stops', function () {
    $gradient = new Gradient(GradientKind::Linear, 90.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 255, 0), 25.0),
        new GradientStop(new Color(255, 255, 0), 50.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]);
    $pdf = renderOnePage(function (PdfCanvas $canvas) use ($gradient): void {
        $canvas->paintGradient(new Rect(0, 0, 100, 100), $gradient);
    });
    expect($pdf)->toContain('/FunctionType 3 /Domain [0 1]');
    expect($pdf)->toContain('/Bounds [0.2500 0.5000]');
    expect($pdf)->toContain('/Encode [0 1 0 1 0 1]');
    // 3 sub-tramos Type 2 (red->green, green->yellow, yellow->blue), N=1 cada uno.
    expect(substr_count($pdf, '/FunctionType 2'))->toBe(3);
});

it('dedups TWO paintGradient() calls sharing the same rect+Gradient into a SINGLE /Shading object (and its Function)', function () {
    $stops = [new GradientStop(new Color(255, 0, 0), 0.0), new GradientStop(new Color(0, 0, 255), 100.0)];
    $pdf = renderOnePage(function (PdfCanvas $canvas) use ($stops): void {
        $canvas->paintGradient(new Rect(0, 0, 100, 100), new Gradient(GradientKind::Linear, 90.0, $stops));
        $canvas->paintGradient(new Rect(0, 0, 100, 100), new Gradient(GradientKind::Linear, 90.0, $stops));
    });
    expect(substr_count($pdf, '/ShadingType'))->toBe(1);
    expect(substr_count($pdf, '/FunctionType 2'))->toBe(1);
    expect(substr_count($pdf, '/Sh1 sh'))->toBe(2);
});

it('registers TWO distinct /Shading objects for two gradients with different rects (different /Coords)', function () {
    $stops = [new GradientStop(new Color(255, 0, 0), 0.0), new GradientStop(new Color(0, 0, 255), 100.0)];
    $pdf = renderOnePage(function (PdfCanvas $canvas) use ($stops): void {
        $canvas->paintGradient(new Rect(0, 0, 100, 100), new Gradient(GradientKind::Linear, 90.0, $stops));
        $canvas->paintGradient(new Rect(0, 0, 200, 100), new Gradient(GradientKind::Linear, 90.0, $stops));
    });
    expect(substr_count($pdf, '/ShadingType'))->toBe(2);
    expect($pdf)->toContain('/Sh1 sh')->toContain('/Sh2 sh');
});

it('registers the shading as a page /Resources /Shading entry', function () {
    $gradient = new Gradient(GradientKind::Linear, 90.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]);
    $pdf = renderOnePage(function (PdfCanvas $canvas) use ($gradient): void {
        $canvas->paintGradient(new Rect(0, 0, 100, 100), $gradient);
    });
    expect($pdf)->toContain('/Shading <<')->toContain('/Sh1');
});

it('emits q, a plain rect clip (re W n), /ShN sh, Q for a gradient with no border-radius', function () {
    $gradient = new Gradient(GradientKind::Linear, 90.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]);
    $pdf = renderOnePage(function (PdfCanvas $canvas) use ($gradient): void {
        $canvas->paintGradient(new Rect(0, 0, 100, 100), $gradient);
    });
    expect($pdf)->toContain("q\n");
    expect($pdf)->toContain(" re W n\n/Sh1 sh\nQ\n");
});

it('emits a ROUNDED clip (Bézier path + W n) instead of a plain rect when a border-radius is passed', function () {
    $gradient = new Gradient(GradientKind::Linear, 90.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]);
    $pdf = renderOnePage(function (PdfCanvas $canvas) use ($gradient): void {
        $canvas->paintGradient(new Rect(0, 0, 100, 100), $gradient, new BorderRadius(10.0, 10.0, 10.0, 10.0));
    });
    expect(substr_count($pdf, " c\n"))->toBe(4);
    expect($pdf)->toContain("h\nW n\n/Sh1 sh\nQ\n");
    expect($pdf)->not->toContain(' re W n');
});

it('renders an alpha color-stop OPAQUE in the Function (r/g/b only, no alpha channel anywhere)', function () {
    // Simulates a Gradient constructed with a stop that still carries alpha (defensive: the real
    // pipeline always strips it in DeclarationParser, see its test file, but PdfCanvas itself
    // must never leak an alpha component into a PDF Function regardless of caller).
    $gradient = new Gradient(GradientKind::Linear, 90.0, [
        new GradientStop(new Color(255, 0, 0, 0.5), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]);
    $pdf = renderOnePage(function (PdfCanvas $canvas) use ($gradient): void {
        $canvas->paintGradient(new Rect(0, 0, 100, 100), $gradient);
    });
    expect($pdf)->toContain('/C0 [1.000 0.000 0.000] /C1 [0.000 0.000 1.000] /N 1');
    expect($pdf)->not->toContain('/ca');
});
