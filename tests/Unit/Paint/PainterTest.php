<?php

declare(strict_types=1);

use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\Color;
use Pliego\Css\WarningCollector;
use Pliego\Layout\Fragment\BorderRadius;
use Pliego\Layout\Fragment\BorderSet;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\ImageFragment;
use Pliego\Layout\Fragment\InlineBoxFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;
use Pliego\Page\Page;
use Pliego\Paint\Canvas;
use Pliego\Paint\Painter;
use Pliego\Text\FontCatalog;

final class RecordingCanvas implements Canvas
{
    /** @var list<string> */
    public array $calls = [];

    public function fillRect(Rect $rect, Color $color): void
    {
        // M6-T5: sufijo ",a=N.NN" SOLO cuando alpha no es null (opaco) — así el formato de
        // llamada registrado no cambia para ningún test anterior a esta tarea (todos usan colores
        // opacos, alpha===null).
        $alphaSuffix = $color->alpha !== null ? sprintf(',a=%.2F', $color->alpha) : '';
        $this->calls[] = sprintf(
            'rect(%.2F,%.2F,%.2F,%.2F,#%02x%02x%02x%s)',
            $rect->x,
            $rect->y,
            $rect->width,
            $rect->height,
            $color->r,
            $color->g,
            $color->b,
            $alphaSuffix,
        );
    }

    public function fillText(TextFragment $text): void
    {
        $this->calls[] = "text({$text->text})";
    }

    public function strokeLine(float $x1, float $y1, float $x2, float $y2, float $widthPx, Color $color): void
    {
        $alphaSuffix = $color->alpha !== null ? sprintf(',a=%.2F', $color->alpha) : '';
        $this->calls[] = sprintf('line(%.2F,%.2F,%.2F,%.2F,%.2F%s)', $x1, $y1, $x2, $y2, $widthPx, $alphaSuffix);
    }

    public function drawImage(Rect $rect, string $imageKey, float $opacity = 1.0): void
    {
        $this->calls[] = sprintf('image(%.2F,%.2F,%.2F,%.2F,%s,%.2F)', $rect->x, $rect->y, $rect->width, $rect->height, $imageKey, $opacity);
    }

    public function clipRect(Rect $rect): void
    {
        $this->calls[] = sprintf('clip(%.2F,%.2F,%.2F,%.2F)', $rect->x, $rect->y, $rect->width, $rect->height);
    }

    public function restoreClip(): void
    {
        $this->calls[] = 'restoreClip()';
    }

    public function fillRoundedRect(Rect $rect, BorderRadius $radius, Color $color): void
    {
        $this->calls[] = sprintf(
            'roundedRect(%.2F,%.2F,%.2F,%.2F,r=%.2F/%.2F/%.2F/%.2F,#%02x%02x%02x)',
            $rect->x,
            $rect->y,
            $rect->width,
            $rect->height,
            $radius->tl,
            $radius->tr,
            $radius->br,
            $radius->bl,
            $color->r,
            $color->g,
            $color->b,
        );
    }

    public function fillRoundedRectRing(Rect $outerRect, BorderRadius $outerRadius, Rect $innerRect, BorderRadius $innerRadius, Color $color): void
    {
        $this->calls[] = sprintf(
            'roundedRing(outer=%.2F,%.2F,%.2F,%.2F,r=%.2F/%.2F/%.2F/%.2F;inner=%.2F,%.2F,%.2F,%.2F,r=%.2F/%.2F/%.2F/%.2F,#%02x%02x%02x)',
            $outerRect->x,
            $outerRect->y,
            $outerRect->width,
            $outerRect->height,
            $outerRadius->tl,
            $outerRadius->tr,
            $outerRadius->br,
            $outerRadius->bl,
            $innerRect->x,
            $innerRect->y,
            $innerRect->width,
            $innerRect->height,
            $innerRadius->tl,
            $innerRadius->tr,
            $innerRadius->br,
            $innerRadius->bl,
            $color->r,
            $color->g,
            $color->b,
        );
    }

    public function clipRoundedRect(Rect $rect, BorderRadius $radius): void
    {
        $this->calls[] = sprintf(
            'clipRounded(%.2F,%.2F,%.2F,%.2F,r=%.2F/%.2F/%.2F/%.2F)',
            $rect->x,
            $rect->y,
            $rect->width,
            $rect->height,
            $radius->tl,
            $radius->tr,
            $radius->br,
            $radius->bl,
        );
    }
}

it('paints backgrounds and text in page order', function () {
    $canvas = new RecordingCanvas();
    $page = new Page(1, [
        new BoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), [], BorderSet::none()),
        new TextFragment(new Rect(10, 10, 50, 19.2), 'Hola', 24.0, 16.0, new Color(0, 0, 0), 'default:400:normal', false),
    ]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);
    expect($canvas->calls)->toBe(['rect(0.00,0.00,100.00,50.00,#ff0000)', 'text(Hola)']);
});
it('skips boxes without background', function () {
    $canvas = new RecordingCanvas();
    $page = new Page(1, [new BoxFragment(new Rect(0, 0, 100, 50), null, [], BorderSet::none())]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);
    expect($canvas->calls)->toBe([]);
});
it('does not stroke an underline for non-underlined text', function () {
    $canvas = new RecordingCanvas();
    $page = new Page(1, [
        new TextFragment(new Rect(10, 10, 50, 19.2), 'Hola', 24.0, 16.0, new Color(0, 0, 0), 'default:400:normal', false),
    ]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);
    expect($canvas->calls)->toBe(['text(Hola)']);
});
it('strokes an underline below the baseline for underlined text, using real post-table metrics', function () {
    $canvas = new RecordingCanvas();
    $rect = new Rect(10, 10, 50, 19.2);
    $baselineY = 24.0;
    $fontSizePx = 16.0;
    $page = new Page(1, [
        new TextFragment($rect, 'Hola', $baselineY, $fontSizePx, new Color(0, 0, 0), 'default:400:normal', true),
    ]);
    $catalog = FontCatalog::withDefaults();
    new Painter($catalog)->paint($page, $canvas);

    expect($canvas->calls)->toHaveCount(2);
    expect($canvas->calls[0])->toBe('text(Hola)');

    // DejaVuSans.ttf: post underlinePosition=-130, underlineThickness=90, unitsPerEm=2048
    // (verificado a mano, ver TtfFontTest). y = baselineY - (posición escalada, NEGATIVA)
    // => la línea cae DEBAJO de la baseline (Y crece hacia abajo en px CSS).
    $expectedY = $baselineY - (-130 / 2048 * $fontSizePx);
    $expectedThickness = 90 / 2048 * $fontSizePx;
    $expectedLine = sprintf(
        'line(%.2F,%.2F,%.2F,%.2F,%.2F)',
        $rect->x,
        $expectedY,
        $rect->x + $rect->width,
        $expectedY,
        $expectedThickness,
    );
    expect($canvas->calls[1])->toBe($expectedLine);
    expect($expectedY)->toBeGreaterThan($baselineY); // por debajo, no por encima
});
it('skips painting an empty forced-line fragment (from <br>) without touching the canvas', function () {
    // TextMeasurer produce un TextFragment con text === '' y rect->width === 0.0 para la línea
    // vacía que deja un <br> — no debe generar fillText (ni por tanto registrar la cara/glifos
    // en el FontRegistry) ni strokeLine, aunque underline sea true.
    $canvas = new RecordingCanvas();
    $page = new Page(1, [
        new TextFragment(new Rect(10, 20, 0.0, 19.2), '', 24.0, 16.0, new Color(0, 0, 0), 'default:400:normal', true),
    ]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);
    expect($canvas->calls)->toBe([]);
});

it('falls back to -0.1em/0.05em underline metrics when the font has no post table', function () {
    $path = buildMinimalTtfWithoutPostTable();
    try {
        $catalog = new FontCatalog();
        $catalog->register('nopost', 400, false, $path);
        $canvas = new RecordingCanvas();
        $rect = new Rect(10, 10, 50, 19.2);
        $baselineY = 24.0;
        $fontSizePx = 16.0;
        $page = new Page(1, [
            new TextFragment($rect, 'Hola', $baselineY, $fontSizePx, new Color(0, 0, 0), 'nopost:400:normal', true),
        ]);
        new Painter($catalog)->paint($page, $canvas);

        $expectedY = $baselineY - (-0.1 * $fontSizePx);
        $expectedThickness = 0.05 * $fontSizePx;
        $expectedLine = sprintf(
            'line(%.2F,%.2F,%.2F,%.2F,%.2F)',
            $rect->x,
            $expectedY,
            $rect->x + $rect->width,
            $expectedY,
            $expectedThickness,
        );
        expect($canvas->calls[1])->toBe($expectedLine);
    } finally {
        unlink($path);
    }
});

it('paints background then all 4 visible borders, in top/right/bottom/left order (css-backgrounds-3 painting order)', function () {
    // Caja de 100x50 con borde uniforme de 2px negro y fondo rojo: 1 rect de fondo + 4 rects
    // de borde, en ese orden (background -> borders -> children, T5). Geometría: los rects
    // horizontales (top/bottom) cubren toda la anchura; los verticales (left/right) encajan
    // ENTRE ellos (h - topW - bottomW) — solape simple, sin miter real (milestone posterior).
    $canvas = new RecordingCanvas();
    $black = new Color(0, 0, 0);
    $side = new BorderSide(2.0, BorderStyle::Solid, $black);
    $borders = new BorderSet($side, $side, $side, $side);
    $page = new Page(1, [
        new BoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), [], $borders),
    ]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'rect(0.00,0.00,100.00,50.00,#ff0000)', // background
        'rect(0.00,0.00,100.00,2.00,#000000)',  // top
        'rect(98.00,2.00,2.00,46.00,#000000)',  // right
        'rect(0.00,48.00,100.00,2.00,#000000)', // bottom
        'rect(0.00,2.00,2.00,46.00,#000000)',   // left
    ]);
});

it('paints only the visible border sides when some sides have width 0 or style none', function () {
    // top: solid 3px; right: solid width 0 (invisible, css-backgrounds-3: width>0 required);
    // bottom: style None a pesar de width>0 (invisible); left: solid 1px.
    $canvas = new RecordingCanvas();
    $black = new Color(0, 0, 0);
    $top = new BorderSide(3.0, BorderStyle::Solid, $black);
    $right = new BorderSide(0.0, BorderStyle::Solid, $black);
    $bottom = new BorderSide(4.0, BorderStyle::None, $black);
    $left = new BorderSide(1.0, BorderStyle::Solid, $black);
    $borders = new BorderSet($top, $right, $bottom, $left);
    $page = new Page(1, [
        new BoxFragment(new Rect(0, 0, 100, 50), null, [], $borders),
    ]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'rect(0.00,0.00,100.00,3.00,#000000)', // top
        'rect(0.00,3.00,1.00,47.00,#000000)',  // left (h - topW - bottomW=0 => 50-3-0=47)
    ]);
});

it('paints a border-only box with no background (T5 gating: no background, still paintable)', function () {
    $canvas = new RecordingCanvas();
    $side = new BorderSide(2.0, BorderStyle::Solid, new Color(0, 128, 0));
    $borders = new BorderSet($side, $side, $side, $side);
    $page = new Page(1, [
        new BoxFragment(new Rect(0, 0, 100, 50), null, [], $borders),
    ]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toHaveCount(4);
    foreach ($canvas->calls as $call) {
        expect($call)->toContain('#008000');
    }
});

it('defensively skips a border side whose color is null even though width>0 and style is Solid', function () {
    // BorderSide::$color es ?Color por tipo; ComputedStyle nunca produce null (currentColor
    // eager en T3), pero el Painter debe ser defensivo y no romper si alguna vez ocurre.
    $canvas = new RecordingCanvas();
    $nullColorSide = new BorderSide(2.0, BorderStyle::Solid, null);
    $normalSide = new BorderSide(2.0, BorderStyle::Solid, new Color(0, 0, 0));
    $borders = new BorderSet($nullColorSide, $normalSide, $normalSide, $normalSide);
    $page = new Page(1, [
        new BoxFragment(new Rect(0, 0, 100, 50), null, [], $borders),
    ]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toHaveCount(3); // top skipped, right/bottom/left painted
});

it('skips border painting entirely when the box has no visible border side', function () {
    $canvas = new RecordingCanvas();
    $page = new Page(1, [new BoxFragment(new Rect(0, 0, 100, 50), null, [], BorderSet::none())]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);
    expect($canvas->calls)->toBe([]);
});

it('recurses into a composite BoxFragment (M4-T6): background, then borders, then children, walking each nested level in the same order', function () {
    // The Painter recursion path this exercises (paintFragment(), extracted in M4-T5 for exactly
    // this purpose) was, until now, only reached indirectly through FlexFormattingContext's atomic
    // fragments in E2E tests -- never with a dedicated Painter-level unit test asserting the
    // painting ORDER of a composite fragment tree. Built here directly (no flex involved at all):
    // an outer BoxFragment (background + border) containing an inner BoxFragment (its own,
    // different background + border) containing a TextFragment -- three nesting levels, each with
    // paintable content, so the RecordingCanvas call order proves bg->borders->children recurses
    // correctly at every depth, not just one level.
    $canvas = new RecordingCanvas();
    $red = new Color(255, 0, 0);
    $blue = new Color(0, 0, 255);
    $black = new Color(0, 0, 0);
    $outerBorder = new BorderSet(
        new BorderSide(1.0, BorderStyle::Solid, $black),
        BorderSet::none()->right,
        BorderSet::none()->bottom,
        BorderSet::none()->left,
    );
    $innerBorder = new BorderSet(
        BorderSet::none()->top,
        new BorderSide(2.0, BorderStyle::Solid, $black),
        BorderSet::none()->bottom,
        BorderSet::none()->left,
    );
    $innerText = new TextFragment(new Rect(5, 5, 30, 19.2), 'Hi', 20.0, 16.0, $black, 'default:400:normal', false);
    $inner = new BoxFragment(new Rect(0, 0, 40, 30), $blue, [$innerText], $innerBorder);
    $outer = new BoxFragment(new Rect(0, 0, 100, 50), $red, [$inner], $outerBorder, atomic: true);
    $page = new Page(1, [$outer]);

    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'rect(0.00,0.00,100.00,50.00,#ff0000)', // outer background
        'rect(0.00,0.00,100.00,1.00,#000000)',  // outer top border (only visible side)
        'rect(0.00,0.00,40.00,30.00,#0000ff)',  // inner background
        'rect(38.00,0.00,2.00,30.00,#000000)',  // inner right border (only visible side)
        'text(Hi)',                             // innermost leaf, last
    ]);
});

it('paints an ImageFragment via Canvas::drawImage(), in document order between backgrounds and text (M3-T4)', function () {
    $canvas = new RecordingCanvas();
    $page = new Page(1, [
        new BoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), [], BorderSet::none()),
        new ImageFragment(new Rect(10, 10, 40, 30), '/tmp/tiny.jpg'),
        new TextFragment(new Rect(10, 50, 50, 19.2), 'Hola', 60.0, 16.0, new Color(0, 0, 0), 'default:400:normal', false),
    ]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);
    expect($canvas->calls)->toBe([
        'rect(0.00,0.00,100.00,50.00,#ff0000)',
        'image(10.00,10.00,40.00,30.00,/tmp/tiny.jpg,1.00)',
        'text(Hola)',
    ]);
});

// M6-T5: opacity multiplies the element's OWN painted colors (background/border/underline/
// image) — combined via Color::withOpacity() in Painter, NEVER affecting children (each fragment
// carries its own opacity, default 1.0/opaque, see BoxFragment/TextFragment/ImageFragment).

it('multiplies the background alpha by the BoxFragment opacity', function () {
    $canvas = new RecordingCanvas();
    $page = new Page(1, [
        new BoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), [], BorderSet::none(), opacity: 0.5),
    ]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);
    expect($canvas->calls)->toBe(['rect(0.00,0.00,100.00,50.00,#ff0000,a=0.50)']);
});

it('combines an rgba background alpha WITH the element opacity multiplicatively (0.5 alpha x 0.5 opacity = 0.25)', function () {
    $canvas = new RecordingCanvas();
    $page = new Page(1, [
        new BoxFragment(new Rect(0, 0, 100, 50), new Color(0, 0, 255, 0.5), [], BorderSet::none(), opacity: 0.5),
    ]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);
    expect($canvas->calls)->toBe(['rect(0.00,0.00,100.00,50.00,#0000ff,a=0.25)']);
});

it('does NOT apply a BoxFragment opacity to its children (M6 divergence: no real transparency group)', function () {
    $canvas = new RecordingCanvas();
    $innerText = new TextFragment(new Rect(5, 5, 30, 19.2), 'Hi', 20.0, 16.0, new Color(0, 0, 0), 'default:400:normal', false);
    $inner = new BoxFragment(new Rect(0, 0, 40, 30), new Color(0, 0, 255), [$innerText], BorderSet::none());
    $outer = new BoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), [$inner], BorderSet::none(), atomic: true, opacity: 0.5);
    $page = new Page(1, [$outer]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);
    expect($canvas->calls)->toBe([
        'rect(0.00,0.00,100.00,50.00,#ff0000,a=0.50)', // outer: dimmed by its own opacity
        'rect(0.00,0.00,40.00,30.00,#0000ff)',          // inner: its OWN opacity is 1.0, untouched
        'text(Hi)',
    ]);
});

it('multiplies a visible border side alpha by the BoxFragment opacity', function () {
    $canvas = new RecordingCanvas();
    $side = new BorderSide(2.0, BorderStyle::Solid, new Color(0, 0, 0));
    $borders = new BorderSet($side, $side, $side, $side);
    $page = new Page(1, [new BoxFragment(new Rect(0, 0, 100, 50), null, [], $borders, opacity: 0.4)]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);
    foreach ($canvas->calls as $call) {
        expect($call)->toContain('a=0.40');
    }
    expect($canvas->calls)->toHaveCount(4);
});

it('multiplies the underline stroke alpha by the TextFragment opacity', function () {
    $canvas = new RecordingCanvas();
    $rect = new Rect(10, 10, 50, 19.2);
    $page = new Page(1, [
        new TextFragment($rect, 'Hola', 24.0, 16.0, new Color(0, 0, 0), 'default:400:normal', true, 0.5),
    ]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);
    expect($canvas->calls)->toHaveCount(2);
    expect($canvas->calls[1])->toContain('a=0.50');
});

it('passes the ImageFragment opacity through to Canvas::drawImage()', function () {
    $canvas = new RecordingCanvas();
    $page = new Page(1, [new ImageFragment(new Rect(0, 0, 40, 30), '/tmp/tiny.jpg', 0.3)]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);
    expect($canvas->calls)->toBe(['image(0.00,0.00,40.00,30.00,/tmp/tiny.jpg,0.30)']);
});

// --- M7-T4: InlineBoxFragment (caja inline real) --------------------------------------------

it('paints an InlineBoxFragment background then its 4 border sides, exactly like a BoxFragment (no children)', function () {
    $canvas = new RecordingCanvas();
    $side = new BorderSide(2.0, BorderStyle::Solid, new Color(0, 0, 0));
    $borders = new BorderSet($side, $side, $side, $side);
    $page = new Page(1, [
        new InlineBoxFragment(new Rect(0, 0, 100, 50), new Color(200, 200, 200), $borders, 1.0, true, true),
        new TextFragment(new Rect(10, 10, 50, 19.2), 'mid', 24.0, 16.0, new Color(0, 0, 0), 'default:400:normal', false),
    ]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'rect(0.00,0.00,100.00,50.00,#c8c8c8)', // background
        'rect(0.00,0.00,100.00,2.00,#000000)',  // top
        'rect(98.00,2.00,2.00,46.00,#000000)',  // right
        'rect(0.00,48.00,100.00,2.00,#000000)', // bottom
        'rect(0.00,2.00,2.00,46.00,#000000)',   // left
        'text(mid)',                            // painted AFTER the box (contract: boxes before text)
    ]);
});

it('paints only top/bottom borders for a non-extreme slice (lateral sides already suppressed by InlineFlowContext)', function () {
    $canvas = new RecordingCanvas();
    $side = new BorderSide(2.0, BorderStyle::Solid, new Color(0, 0, 0));
    $noSide = new BorderSide(0.0, BorderStyle::None, null);
    // Slice intermedio (isFirstSlice=false, isLastSlice=false): InlineFlowContext ya habría
    // sustituido left/right por $noSide antes de construir este BorderSet -- el Painter no
    // necesita saber nada de slices, solo pinta lo que trae.
    $borders = new BorderSet($side, $noSide, $side, $noSide);
    $page = new Page(1, [
        new InlineBoxFragment(new Rect(0, 0, 100, 50), null, $borders, 1.0, false, false),
    ]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'rect(0.00,0.00,100.00,2.00,#000000)',  // top
        'rect(0.00,48.00,100.00,2.00,#000000)', // bottom
    ]);
});

// --- M7-T5 (css-overflow-3): overflow:hidden clipping -------------------------------------------

it('wraps a clipsChildren BoxFragment\'s descendants in clipRect()/restoreClip(), around the OWN background/border unclipped', function () {
    $canvas = new RecordingCanvas();
    $innerText = new TextFragment(new Rect(5, 5, 30, 19.2), 'Hi', 20.0, 16.0, new Color(0, 0, 0), 'default:400:normal', false);
    $box = new BoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), [$innerText], BorderSet::none(), clipsChildren: true);
    $page = new Page(1, [$box]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'rect(0.00,0.00,100.00,50.00,#ff0000)', // own background: NOT inside the clip scope
        'clip(0.00,0.00,100.00,50.00)',         // clip scope opens at the box's OWN border-box rect
        'text(Hi)',                             // child painted INSIDE the clip scope
        'restoreClip()',                        // clip scope closes right after the last child
    ]);
});

it('does not clip a BoxFragment whose clipsChildren is false (default), same as before this task', function () {
    $canvas = new RecordingCanvas();
    $innerText = new TextFragment(new Rect(5, 5, 30, 19.2), 'Hi', 20.0, 16.0, new Color(0, 0, 0), 'default:400:normal', false);
    $box = new BoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), [$innerText], BorderSet::none());
    $page = new Page(1, [$box]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'rect(0.00,0.00,100.00,50.00,#ff0000)',
        'text(Hi)',
    ]);
});

it('clips a nested composite subtree (borders included) inside a single clip scope, closing it after ALL descendants', function () {
    $innerText = new TextFragment(new Rect(5, 5, 30, 19.2), 'Hi', 20.0, 16.0, new Color(0, 0, 0), 'default:400:normal', false);
    $side = new BorderSide(1.0, BorderStyle::Solid, new Color(0, 0, 0));
    $borders = new BorderSet($side, $side, $side, $side);
    $inner = new BoxFragment(new Rect(0, 0, 40, 30), new Color(0, 0, 255), [$innerText], $borders);
    $canvas = new RecordingCanvas();
    $outer = new BoxFragment(new Rect(0, 0, 100, 50), null, [$inner], BorderSet::none(), clipsChildren: true);
    $page = new Page(1, [$outer]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls[0])->toBe('clip(0.00,0.00,100.00,50.00)');
    expect(end($canvas->calls))->toBe('restoreClip()');
    // clip() + inner background + 4 inner border sides + inner text + restoreClip() = 8
    expect($canvas->calls)->toHaveCount(8);
});

it('multiplies an InlineBoxFragment background/border alpha by its own opacity', function () {
    $canvas = new RecordingCanvas();
    $side = new BorderSide(2.0, BorderStyle::Solid, new Color(0, 0, 0));
    $borders = new BorderSet($side, $side, $side, $side);
    $page = new Page(1, [
        new InlineBoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), $borders, 0.5, true, true),
    ]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls[0])->toBe('rect(0.00,0.00,100.00,50.00,#ff0000,a=0.50)');
    foreach (array_slice($canvas->calls, 1) as $call) {
        expect($call)->toContain('a=0.50');
    }
});

// --- M8-T2 (css-backgrounds-3 §5): border-radius painting ---------------------------------------

it('paints a rounded background via fillRoundedRect() instead of fillRect() when borderRadius is non-zero', function () {
    $canvas = new RecordingCanvas();
    $box = new BoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), [], BorderSet::none(), borderRadius: new BorderRadius(10.0, 10.0, 10.0, 10.0));
    $page = new Page(1, [$box]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe(['roundedRect(0.00,0.00,100.00,50.00,r=10.00/10.00/10.00/10.00,#ff0000)']);
});

it('keeps plain fillRect() when borderRadius is zero (default), byte-identical to before this task', function () {
    $canvas = new RecordingCanvas();
    $box = new BoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), [], BorderSet::none());
    $page = new Page(1, [$box]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe(['rect(0.00,0.00,100.00,50.00,#ff0000)']);
});

it('paints UNIFORM borders with non-zero radius as a single fillRoundedRectRing() call (outer minus inner)', function () {
    $canvas = new RecordingCanvas();
    $side = new BorderSide(5.0, BorderStyle::Solid, new Color(0, 0, 0));
    $borders = new BorderSet($side, $side, $side, $side);
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [], $borders, borderRadius: new BorderRadius(20.0, 20.0, 20.0, 20.0));
    $page = new Page(1, [$box]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);

    // Inner rect inset by the uniform border width (5px each side); inner radius reduced by the
    // SAME width (§5.3-style clamp, ver Painter::paintBorders()).
    expect($canvas->calls)->toBe([
        'roundedRing(outer=0.00,0.00,100.00,50.00,r=20.00/20.00/20.00/20.00;inner=5.00,5.00,90.00,40.00,r=15.00/15.00/15.00/15.00,#000000)',
    ]);
});

it('falls back to the flat 4-rect border painting + a one-time warning when border widths/colors/styles are MIXED with a non-zero radius', function () {
    $canvas = new RecordingCanvas();
    $top = new BorderSide(2.0, BorderStyle::Solid, new Color(0, 0, 0));
    $right = new BorderSide(4.0, BorderStyle::Solid, new Color(0, 0, 0));
    $borders = new BorderSet($top, $right, $top, $top);
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [], $borders, borderRadius: new BorderRadius(10.0, 10.0, 10.0, 10.0));
    $page = new Page(1, [$box]);
    $warnings = new WarningCollector();
    new Painter(FontCatalog::withDefaults(), $warnings)->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'rect(0.00,0.00,100.00,2.00,#000000)',  // top
        'rect(96.00,2.00,4.00,46.00,#000000)',  // right
        'rect(0.00,48.00,100.00,2.00,#000000)', // bottom
        'rect(0.00,2.00,2.00,46.00,#000000)',   // left
    ]);
    expect($warnings->drain())->toBe(['mixed border widths with border-radius approximated']);
});

it('does not paint a ring when a uniform border has radius but the border is not actually visible (style None/width 0)', function () {
    $canvas = new RecordingCanvas();
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [], BorderSet::none(), borderRadius: new BorderRadius(10.0, 10.0, 10.0, 10.0));
    $page = new Page(1, [$box]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);
    expect($canvas->calls)->toBe([]);
});

it('clips with clipRoundedRect() instead of clipRect() when a clipsChildren box has a non-zero radius', function () {
    $canvas = new RecordingCanvas();
    $innerText = new TextFragment(new Rect(5, 5, 30, 19.2), 'Hi', 20.0, 16.0, new Color(0, 0, 0), 'default:400:normal', false);
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [$innerText], BorderSet::none(), clipsChildren: true, borderRadius: new BorderRadius(8.0, 8.0, 8.0, 8.0));
    $page = new Page(1, [$box]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'clipRounded(0.00,0.00,100.00,50.00,r=8.00/8.00/8.00/8.00)',
        'text(Hi)',
        'restoreClip()',
    ]);
});

it('paints a rounded InlineBoxFragment background+border the same way as a BoxFragment', function () {
    $canvas = new RecordingCanvas();
    $side = new BorderSide(2.0, BorderStyle::Solid, new Color(0, 0, 0));
    $borders = new BorderSet($side, $side, $side, $side);
    $page = new Page(1, [
        new InlineBoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), $borders, 1.0, true, true, new BorderRadius(6.0, 6.0, 6.0, 6.0)),
    ]);
    new Painter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'roundedRect(0.00,0.00,100.00,50.00,r=6.00/6.00/6.00/6.00,#ff0000)',
        'roundedRing(outer=0.00,0.00,100.00,50.00,r=6.00/6.00/6.00/6.00;inner=2.00,2.00,96.00,46.00,r=4.00/4.00/4.00/4.00,#000000)',
    ]);
});
