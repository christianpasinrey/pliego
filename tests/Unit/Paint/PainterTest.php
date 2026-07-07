<?php

declare(strict_types=1);

use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\BoxShadow;
use Pliego\Css\Value\Color;
use Pliego\Css\Value\Gradient;
use Pliego\Css\Value\GradientKind;
use Pliego\Css\Value\GradientStop;
use Pliego\Css\WarningCollector;
use Pliego\Image\ImageLoader;
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
use Pliego\Style\BackgroundPosition;
use Pliego\Style\BackgroundSize;
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

    /** @param list<float> $dashPattern */
    public function strokeLine(float $x1, float $y1, float $x2, float $y2, float $widthPx, Color $color, array $dashPattern = [], bool $roundCap = false): void
    {
        $alphaSuffix = $color->alpha !== null ? sprintf(',a=%.2F', $color->alpha) : '';
        // M8-T4: sufijo ",dash=[..],cap=round" SOLO cuando hay patrón/cap -- así el formato de
        // llamada NO cambia para ningún test anterior a esta tarea (todos pintan líneas sólidas,
        // dashPattern===[] && !roundCap).
        $dashSuffix = $dashPattern !== [] ? ',dash=[' . implode(',', array_map(fn(float $v): string => sprintf('%.2F', $v), $dashPattern)) . ']' : '';
        $capSuffix = $roundCap ? ',cap=round' : '';
        $this->calls[] = sprintf('line(%.2F,%.2F,%.2F,%.2F,%.2F%s%s%s)', $x1, $y1, $x2, $y2, $widthPx, $alphaSuffix, $dashSuffix, $capSuffix);
    }

    /** @param list<float> $dashPattern */
    public function strokeRect(Rect $rect, float $widthPx, Color $color, array $dashPattern, bool $roundCap): void
    {
        $alphaSuffix = $color->alpha !== null ? sprintf(',a=%.2F', $color->alpha) : '';
        $this->calls[] = sprintf(
            'strokeRect(%.2F,%.2F,%.2F,%.2F,w=%.2F,#%02x%02x%02x,dash=[%s],cap=%s%s)',
            $rect->x,
            $rect->y,
            $rect->width,
            $rect->height,
            $widthPx,
            $color->r,
            $color->g,
            $color->b,
            implode(',', array_map(fn(float $v): string => sprintf('%.2F', $v), $dashPattern)),
            $roundCap ? 'round' : 'butt',
            $alphaSuffix,
        );
    }

    /** @param list<float> $dashPattern */
    public function strokeRoundedRect(Rect $rect, BorderRadius $radius, float $widthPx, Color $color, array $dashPattern, bool $roundCap): void
    {
        $alphaSuffix = $color->alpha !== null ? sprintf(',a=%.2F', $color->alpha) : '';
        $this->calls[] = sprintf(
            'strokeRoundedRect(%.2F,%.2F,%.2F,%.2F,r=%.2F/%.2F/%.2F/%.2F,w=%.2F,#%02x%02x%02x,dash=[%s],cap=%s%s)',
            $rect->x,
            $rect->y,
            $rect->width,
            $rect->height,
            $radius->tl,
            $radius->tr,
            $radius->br,
            $radius->bl,
            $widthPx,
            $color->r,
            $color->g,
            $color->b,
            implode(',', array_map(fn(float $v): string => sprintf('%.2F', $v), $dashPattern)),
            $roundCap ? 'round' : 'butt',
            $alphaSuffix,
        );
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
        // M8-T4: sufijo ",a=N.NN" SOLO cuando alpha no es null -- mismo criterio que fillRect(),
        // añadido aditivamente para poder verificar el alpha/4 de cada capa de box-shadow
        // redondeada (ningún test preexistente a esta tarea pasaba un color con alpha aquí, así
        // que ninguna aserción existente cambia).
        $alphaSuffix = $color->alpha !== null ? sprintf(',a=%.2F', $color->alpha) : '';
        $this->calls[] = sprintf(
            'roundedRect(%.2F,%.2F,%.2F,%.2F,r=%.2F/%.2F/%.2F/%.2F,#%02x%02x%02x%s)',
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
            $alphaSuffix,
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

    public function paintGradient(Rect $rect, Gradient $gradient, ?BorderRadius $radius = null): void
    {
        $r = $radius ?? new BorderRadius();
        $this->calls[] = sprintf(
            'gradient(%.2F,%.2F,%.2F,%.2F,%s,stops=%d,r=%.2F/%.2F/%.2F/%.2F)',
            $rect->x,
            $rect->y,
            $rect->width,
            $rect->height,
            $gradient->kind->name,
            count($gradient->stops),
            $r->tl,
            $r->tr,
            $r->br,
            $r->bl,
        );
    }
}

// M8-T6: Painter's constructor now REQUIRES an Image\ImageLoader + a basePath (background-image
// is loaded at PAINT time, see Paint\Painter::paintBackgroundImage()) -- every pre-T6 test in this
// file only exercised paths that never touch either (no background-image involved), so a fresh
// ImageLoader + an arbitrary basePath (this directory) is a safe, inert placeholder wherever the
// test doesn't care. PAINTER_IMAGE_FIXTURES_DIR points at the REAL fixture directory, for the new
// background-image tests below that DO need a real file to load.
const PAINTER_IMAGE_FIXTURES_DIR = __DIR__ . '/../../../resources/images';

function testPainter(FontCatalog $catalog, ?WarningCollector $warnings = null): Painter
{
    return new Painter($catalog, new ImageLoader(), PAINTER_IMAGE_FIXTURES_DIR, $warnings);
}

it('paints backgrounds and text in page order', function () {
    $canvas = new RecordingCanvas();
    $page = new Page(1, [
        new BoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), [], BorderSet::none()),
        new TextFragment(new Rect(10, 10, 50, 19.2), 'Hola', 24.0, 16.0, new Color(0, 0, 0), 'default:400:normal', false),
    ]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);
    expect($canvas->calls)->toBe(['rect(0.00,0.00,100.00,50.00,#ff0000)', 'text(Hola)']);
});
it('skips boxes without background', function () {
    $canvas = new RecordingCanvas();
    $page = new Page(1, [new BoxFragment(new Rect(0, 0, 100, 50), null, [], BorderSet::none())]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);
    expect($canvas->calls)->toBe([]);
});
it('does not stroke an underline for non-underlined text', function () {
    $canvas = new RecordingCanvas();
    $page = new Page(1, [
        new TextFragment(new Rect(10, 10, 50, 19.2), 'Hola', 24.0, 16.0, new Color(0, 0, 0), 'default:400:normal', false),
    ]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);
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
    testPainter($catalog)->paint($page, $canvas);

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
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);
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
        testPainter($catalog)->paint($page, $canvas);

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
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

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
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

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
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

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
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toHaveCount(3); // top skipped, right/bottom/left painted
});

it('skips border painting entirely when the box has no visible border side', function () {
    $canvas = new RecordingCanvas();
    $page = new Page(1, [new BoxFragment(new Rect(0, 0, 100, 50), null, [], BorderSet::none())]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);
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

    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

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
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);
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
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);
    expect($canvas->calls)->toBe(['rect(0.00,0.00,100.00,50.00,#ff0000,a=0.50)']);
});

it('combines an rgba background alpha WITH the element opacity multiplicatively (0.5 alpha x 0.5 opacity = 0.25)', function () {
    $canvas = new RecordingCanvas();
    $page = new Page(1, [
        new BoxFragment(new Rect(0, 0, 100, 50), new Color(0, 0, 255, 0.5), [], BorderSet::none(), opacity: 0.5),
    ]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);
    expect($canvas->calls)->toBe(['rect(0.00,0.00,100.00,50.00,#0000ff,a=0.25)']);
});

it('does NOT apply a BoxFragment opacity to its children (M6 divergence: no real transparency group)', function () {
    $canvas = new RecordingCanvas();
    $innerText = new TextFragment(new Rect(5, 5, 30, 19.2), 'Hi', 20.0, 16.0, new Color(0, 0, 0), 'default:400:normal', false);
    $inner = new BoxFragment(new Rect(0, 0, 40, 30), new Color(0, 0, 255), [$innerText], BorderSet::none());
    $outer = new BoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), [$inner], BorderSet::none(), atomic: true, opacity: 0.5);
    $page = new Page(1, [$outer]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);
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
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);
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
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);
    expect($canvas->calls)->toHaveCount(2);
    expect($canvas->calls[1])->toContain('a=0.50');
});

it('passes the ImageFragment opacity through to Canvas::drawImage()', function () {
    $canvas = new RecordingCanvas();
    $page = new Page(1, [new ImageFragment(new Rect(0, 0, 40, 30), '/tmp/tiny.jpg', 0.3)]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);
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
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

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
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

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
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

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
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

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
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

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
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

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
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe(['roundedRect(0.00,0.00,100.00,50.00,r=10.00/10.00/10.00/10.00,#ff0000)']);
});

it('keeps plain fillRect() when borderRadius is zero (default), byte-identical to before this task', function () {
    $canvas = new RecordingCanvas();
    $box = new BoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), [], BorderSet::none());
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe(['rect(0.00,0.00,100.00,50.00,#ff0000)']);
});

it('paints UNIFORM borders with non-zero radius as a single fillRoundedRectRing() call (outer minus inner)', function () {
    $canvas = new RecordingCanvas();
    $side = new BorderSide(5.0, BorderStyle::Solid, new Color(0, 0, 0));
    $borders = new BorderSet($side, $side, $side, $side);
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [], $borders, borderRadius: new BorderRadius(20.0, 20.0, 20.0, 20.0));
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

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
    testPainter(FontCatalog::withDefaults(), $warnings)->paint($page, $canvas);

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
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);
    expect($canvas->calls)->toBe([]);
});

it('clips with clipRoundedRect() instead of clipRect() when a clipsChildren box has a non-zero radius', function () {
    $canvas = new RecordingCanvas();
    $innerText = new TextFragment(new Rect(5, 5, 30, 19.2), 'Hi', 20.0, 16.0, new Color(0, 0, 0), 'default:400:normal', false);
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [$innerText], BorderSet::none(), clipsChildren: true, borderRadius: new BorderRadius(8.0, 8.0, 8.0, 8.0));
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

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
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'roundedRect(0.00,0.00,100.00,50.00,r=6.00/6.00/6.00/6.00,#ff0000)',
        'roundedRing(outer=0.00,0.00,100.00,50.00,r=6.00/6.00/6.00/6.00;inner=2.00,2.00,96.00,46.00,r=4.00/4.00/4.00/4.00,#000000)',
    ]);
});

// --- M8-T2 review Finding 1 (Critical): multi-slice inline boxes with a UNIFORM declared border
// must keep the annular ring path -- a lateral side suppressed to BorderStyle::None by
// box-decoration-break:slice (InlineFlowContext::buildInlineBoxFragment(), see its docblock) is
// NOT genuine heterogeneity, and must not disqualify bordersUniform() nor fire the "mixed border
// widths" warning. Before this fix, bordersUniform() required all 4 BorderSide byte-equal, so
// every non-terminal slice (with one lateral forced to None) fell into the flat/warning branch
// even though the AUTHOR declared a perfectly uniform border -- flat corners everywhere + a false
// "mixed border widths" warning.

it('Finding 1: a FIRST slice (right side suppressed to None) still paints via the ring path -- left corners curved, right corners already zeroed by slicing, no warning', function () {
    $canvas = new RecordingCanvas();
    $warnings = new WarningCollector();
    $black = new Color(0, 0, 0);
    $side = new BorderSide(2.0, BorderStyle::Solid, $black);
    $noSide = new BorderSide(0.0, BorderStyle::None, null);
    // isFirstSlice=true, isLastSlice=false: InlineFlowContext suppresses ONLY the right side
    // (left survives, see buildInlineBoxFragment()) and zeroes the tr/br corners of the radius
    // (also its responsibility, verified by the M8-T2 docblock at InlineFlowContext.php:~793).
    $borders = new BorderSet($side, $noSide, $side, $side);
    $radius = new BorderRadius(8.0, 0.0, 0.0, 8.0);
    $box = new InlineBoxFragment(new Rect(0, 0, 100, 50), null, $borders, 1.0, true, false, $radius);
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults(), $warnings)->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        // inner inset: left/top/bottom by the common 2px width, right by 0 (None side, flush to
        // the outer edge -- zero-width fill there, exactly the slice semantics); inner radius
        // reduced by the SAME 2px on the corners that survive (tl/bl), already-zero corners
        // (tr/br) stay at max(0, 0-2)=0.
        'roundedRing(outer=0.00,0.00,100.00,50.00,r=8.00/0.00/0.00/8.00;inner=2.00,2.00,98.00,46.00,r=6.00/0.00/0.00/6.00,#000000)',
    ]);
    expect($warnings->drain())->toBe([]);
});

it('Finding 1: a LAST slice (left side suppressed to None) still paints via the ring path -- right corners curved, left corners already zeroed by slicing, no warning', function () {
    $canvas = new RecordingCanvas();
    $warnings = new WarningCollector();
    $black = new Color(0, 0, 0);
    $side = new BorderSide(2.0, BorderStyle::Solid, $black);
    $noSide = new BorderSide(0.0, BorderStyle::None, null);
    // isFirstSlice=false, isLastSlice=true: the mirror case -- left suppressed, tl/bl zeroed.
    $borders = new BorderSet($side, $side, $side, $noSide);
    $radius = new BorderRadius(0.0, 8.0, 8.0, 0.0);
    $box = new InlineBoxFragment(new Rect(0, 0, 100, 50), null, $borders, 1.0, false, true, $radius);
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults(), $warnings)->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'roundedRing(outer=0.00,0.00,100.00,50.00,r=0.00/8.00/8.00/0.00;inner=0.00,2.00,98.00,46.00,r=0.00/6.00/6.00/0.00,#000000)',
    ]);
    expect($warnings->drain())->toBe([]);
});

// --- M8-T2 fix (Reviewer, Important): an AUTHOR-DECLARED partial border (e.g.
// `border-bottom: 8px solid; border-radius: 15px`) must NOT reuse the slice-relaxed
// bordersUniform() path -- unlike an InlineFlowContext slice (which always pre-zeroes the
// radius on any corner touching a suppressed side, see buildInlineBoxFragment() at
// InlineFlowContext.php:793-799: `$isFirstSlice ? $rawRadius->tl : 0.0` etc.), a BoxFragment's
// BorderSet/BorderRadius pair carries NO such guarantee -- ComputedStyle can produce a single
// styled side with a full-corner radius with nothing enforcing "every corner with radius>0 has
// both adjacent sides styled". Before this fix, bordersUniform() only compared the STYLED sides
// to each other (trivially equal when there's only one), so a lone bottom border sailed through
// as "uniform" and painted a full annular ring using ALL FOUR outer corners -- phantom curved
// ink at the top-left/top-right corners, where NO border was declared, and the "mixed border
// widths" warning that should have fired for this heterogeneity never did.

it('a border-bottom-only side with radius on ALL corners falls back to flat painting + warning instead of a ring with phantom top-corner ink', function () {
    $canvas = new RecordingCanvas();
    $warnings = new WarningCollector();
    $black = new Color(0, 0, 0);
    $none = new BorderSide(0.0, BorderStyle::None, null);
    $bottom = new BorderSide(8.0, BorderStyle::Solid, $black);
    $borders = new BorderSet($none, $none, $bottom, $none);
    // border-radius: 15px shorthand -- ALL 4 corners, including tl/tr whose adjacent sides
    // (top+left, top+right) are BOTH None. Those are exactly the corners where the old ring path
    // painted a curved crescent with no author-declared border underneath.
    $radius = new BorderRadius(15.0, 15.0, 15.0, 15.0);
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [], $borders, borderRadius: $radius);
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults(), $warnings)->paint($page, $canvas);

    // Flat painting: only the bottom side is Solid && width>0 -- the same paintBordersFlat() as
    // always, radius ignored entirely (no roundedRing(...) call anywhere in $canvas->calls, no
    // curved corners of any kind).
    expect($canvas->calls)->toBe([
        'rect(0.00,42.00,100.00,8.00,#000000)', // bottom only (height 50 - bottomW 8 = y 42)
    ]);
    expect($warnings->drain())->toBe(['mixed border widths with border-radius approximated']);
});

it('edge case: radius restricted to ONLY the bottom corners (border-bottom-left/right-radius) still falls back -- each bottom corner adjoins one None side (left/right)', function () {
    // Narrower declaration than the test above: border-radius only on bl/br (top radii stay 0,
    // as if the author wrote border-bottom-left-radius/border-bottom-right-radius alone). One
    // might expect this to be "safe" since the radius no longer touches the fully-unstyled top
    // corners -- but bottom-left adjoins BOTTOM (styled) AND LEFT (None), and bottom-right
    // adjoins BOTTOM (styled) AND RIGHT (None): each still has one unstyled adjacent side, so a
    // ring there would paint a crescent on the LEFT/RIGHT edge of that corner where no border
    // was declared either. The guard correctly fires here too -- flat + warning, not a ring.
    $canvas = new RecordingCanvas();
    $warnings = new WarningCollector();
    $black = new Color(0, 0, 0);
    $none = new BorderSide(0.0, BorderStyle::None, null);
    $bottom = new BorderSide(8.0, BorderStyle::Solid, $black);
    $borders = new BorderSet($none, $none, $bottom, $none);
    $radius = new BorderRadius(0.0, 0.0, 15.0, 15.0); // tl=0, tr=0, br=15, bl=15
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [], $borders, borderRadius: $radius);
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults(), $warnings)->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'rect(0.00,42.00,100.00,8.00,#000000)',
    ]);
    expect($warnings->drain())->toBe(['mixed border widths with border-radius approximated']);
});

it('Finding 1 regression guard: GENUINELY mixed declared border widths (no None side involved) still warn and fall back to flat painting', function () {
    // Same shape as the pre-existing "falls back... MIXED" test above, restated here next to the
    // slice fix as an explicit regression guard: a real user-declared width mismatch (2px vs 4px,
    // both Solid, no slicing involved at all) must NOT be swallowed by the relaxed slice-aware
    // uniformity check -- it is still genuine heterogeneity, still warns, still paints flat.
    $canvas = new RecordingCanvas();
    $warnings = new WarningCollector();
    $top = new BorderSide(2.0, BorderStyle::Solid, new Color(0, 0, 0));
    $right = new BorderSide(4.0, BorderStyle::Solid, new Color(0, 0, 0));
    $borders = new BorderSet($top, $right, $top, $top);
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [], $borders, borderRadius: new BorderRadius(10.0, 10.0, 10.0, 10.0));
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults(), $warnings)->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'rect(0.00,0.00,100.00,2.00,#000000)',
        'rect(96.00,2.00,4.00,46.00,#000000)',
        'rect(0.00,48.00,100.00,2.00,#000000)',
        'rect(0.00,2.00,2.00,46.00,#000000)',
    ]);
    expect($warnings->drain())->toBe(['mixed border widths with border-radius approximated']);
});

// --- M8-T3 (css-images-3 §3.1 reducido): gradient painting order + coexistence with background-color

function twoStopGradient(): Gradient
{
    return new Gradient(GradientKind::Linear, 90.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]);
}

it('paints a gradient via Canvas::paintGradient() when the box has no background-color', function () {
    $canvas = new RecordingCanvas();
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [], BorderSet::none(), backgroundGradient: twoStopGradient());
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe(['gradient(0.00,0.00,100.00,50.00,Linear,stops=2,r=0.00/0.00/0.00/0.00)']);
});

it('paints the gradient AFTER the background-color, when both are declared (gradient sits on top)', function () {
    $canvas = new RecordingCanvas();
    $box = new BoxFragment(new Rect(0, 0, 100, 50), new Color(0, 128, 0), [], BorderSet::none(), backgroundGradient: twoStopGradient());
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'rect(0.00,0.00,100.00,50.00,#008000)',
        'gradient(0.00,0.00,100.00,50.00,Linear,stops=2,r=0.00/0.00/0.00/0.00)',
    ]);
});

it('passes the box borderRadius through to Canvas::paintGradient() (rounded clip)', function () {
    $canvas = new RecordingCanvas();
    $box = new BoxFragment(
        new Rect(0, 0, 100, 50),
        null,
        [],
        BorderSet::none(),
        backgroundGradient: twoStopGradient(),
        borderRadius: new BorderRadius(10.0, 10.0, 10.0, 10.0),
    );
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe(['gradient(0.00,0.00,100.00,50.00,Linear,stops=2,r=10.00/10.00/10.00/10.00)']);
});

it('does not paint any gradient op when backgroundGradient is null (default), byte-identical to before this task', function () {
    $canvas = new RecordingCanvas();
    $box = new BoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), [], BorderSet::none());
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe(['rect(0.00,0.00,100.00,50.00,#ff0000)']);
});

it('paints an InlineBoxFragment gradient using its OWN (per-slice) rect as the gradient box', function () {
    $canvas = new RecordingCanvas();
    $inline = new InlineBoxFragment(new Rect(10, 10, 40, 20), null, BorderSet::none(), 1.0, true, true, backgroundGradient: twoStopGradient());
    $page = new Page(1, [$inline]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe(['gradient(10.00,10.00,40.00,20.00,Linear,stops=2,r=0.00/0.00/0.00/0.00)']);
});

// --- M8-T4 (css-backgrounds-3 §6 reducido): box-shadow painting --------------------------------

it('paints a blur=0 box-shadow as ONE offset rect, BEFORE the background', function () {
    $canvas = new RecordingCanvas();
    $shadow = new BoxShadow(5.0, 5.0, 0.0, new Color(0, 0, 0));
    $box = new BoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), [], BorderSet::none(), boxShadow: $shadow);
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'rect(5.00,5.00,100.00,50.00,#000000)', // shadow: rect offset by (5,5), same size, opaque
        'rect(0.00,0.00,100.00,50.00,#ff0000)', // element's own background, painted on top
    ]);
});

it('paints a blur=0 box-shadow with no background at all (shadow-only card)', function () {
    $canvas = new RecordingCanvas();
    $shadow = new BoxShadow(4.0, 4.0, 0.0, new Color(0, 0, 0, 0.3));
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [], BorderSet::none(), boxShadow: $shadow);
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe(['rect(4.00,4.00,100.00,50.00,#000000,a=0.30)']);
});

it('multiplies the shadow color alpha by the BoxFragment opacity', function () {
    $canvas = new RecordingCanvas();
    $shadow = new BoxShadow(2.0, 2.0, 0.0, new Color(0, 0, 0));
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [], BorderSet::none(), opacity: 0.5, boxShadow: $shadow);
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe(['rect(2.00,2.00,100.00,50.00,#000000,a=0.50)']);
});

it('paints a blur=0 box-shadow rounded when the fragment has border-radius (follows the SAME radius)', function () {
    $canvas = new RecordingCanvas();
    $shadow = new BoxShadow(3.0, 3.0, 0.0, new Color(0, 0, 0));
    $box = new BoxFragment(
        new Rect(0, 0, 100, 50),
        new Color(255, 255, 255),
        [],
        BorderSet::none(),
        borderRadius: new BorderRadius(10.0, 10.0, 10.0, 10.0),
        boxShadow: $shadow,
    );
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'roundedRect(3.00,3.00,100.00,50.00,r=10.00/10.00/10.00/10.00,#000000)',
        'roundedRect(0.00,0.00,100.00,50.00,r=10.00/10.00/10.00/10.00,#ffffff)',
    ]);
});

it('approximates a blur>0 box-shadow as 4 concentric layers, each 1/4 alpha, hand-computed (blur=6, no radius, no offset)', function () {
    // step = blur/3 = 2.0 -- layer deltas -3,-1,+1,+3 (layer 0 inset by blur/2=3, layer 3
    // expanded by blur/2=3, "total spread = blur" per the brief's adjudicated geometry, see
    // Painter::paintBoxShadow()). Base radius is ZERO here: a NEGATIVE delta clamps to 0 (still a
    // sharp rect, fillRect()), a POSITIVE delta produces radius=delta itself (0+delta, clamped at
    // 0 as floor) -- the outward-expanding layers naturally pick up soft rounded corners even on a
    // perfectly square box, an emergent (and visually sensible: blur softens edges) side effect of
    // "radius + delta" applied to a zero base radius.
    $canvas = new RecordingCanvas();
    $shadow = new BoxShadow(0.0, 0.0, 6.0, new Color(0, 0, 0));
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [], BorderSet::none(), boxShadow: $shadow);
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'rect(3.00,3.00,94.00,44.00,#000000,a=0.25)',           // layer 0: delta=-3 (inset), sharp
        'rect(1.00,1.00,98.00,48.00,#000000,a=0.25)',           // layer 1: delta=-1 (inset), sharp
        'roundedRect(-1.00,-1.00,102.00,52.00,r=1.00/1.00/1.00/1.00,#000000,a=0.25)', // layer 2: delta=+1
        'roundedRect(-3.00,-3.00,106.00,56.00,r=3.00/3.00/3.00/3.00,#000000,a=0.25)', // layer 3: delta=+3 (=blur/2)
    ]);
});

it('offsets every blur>0 layer by (offsetX, offsetY) before expanding/insetting', function () {
    $canvas = new RecordingCanvas();
    $shadow = new BoxShadow(10.0, 20.0, 6.0, new Color(255, 0, 0));
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [], BorderSet::none(), boxShadow: $shadow);
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    // Base shadow rect (before blur layers): (10,20,100,50) -- same insets/expansions as the
    // no-offset test above, just translated.
    expect($canvas->calls)->toBe([
        'rect(13.00,23.00,94.00,44.00,#ff0000,a=0.25)',
        'rect(11.00,21.00,98.00,48.00,#ff0000,a=0.25)',
        'roundedRect(9.00,19.00,102.00,52.00,r=1.00/1.00/1.00/1.00,#ff0000,a=0.25)',
        'roundedRect(7.00,17.00,106.00,56.00,r=3.00/3.00/3.00/3.00,#ff0000,a=0.25)',
    ]);
});

it('paints nothing for box-shadow when null (default), byte-identical to before this task', function () {
    $canvas = new RecordingCanvas();
    $box = new BoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), [], BorderSet::none());
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe(['rect(0.00,0.00,100.00,50.00,#ff0000)']);
});

// --- M8-T4: dashed/dotted border painting -------------------------------------------------------

it('paints a UNIFORM dashed border (no radius) as ONE strokeRect() call along the centerline, inset by width/2', function () {
    $canvas = new RecordingCanvas();
    $side = new BorderSide(2.0, BorderStyle::Dashed, new Color(0, 0, 0));
    $borders = new BorderSet($side, $side, $side, $side);
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [], $borders);
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    // centerline: inset by widthPx/2=1 on all sides -- (1,1,98,48). dash pattern [3w w] = [6,2].
    expect($canvas->calls)->toBe([
        'strokeRect(1.00,1.00,98.00,48.00,w=2.00,#000000,dash=[6.00,2.00],cap=butt)',
    ]);
});

it('paints a UNIFORM dotted border as ONE strokeRect() with the [0 2w] pattern and a round cap', function () {
    $canvas = new RecordingCanvas();
    $side = new BorderSide(2.0, BorderStyle::Dotted, new Color(0, 0, 0));
    $borders = new BorderSet($side, $side, $side, $side);
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [], $borders);
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'strokeRect(1.00,1.00,98.00,48.00,w=2.00,#000000,dash=[0.00,4.00],cap=round)',
    ]);
});

it('paints a UNIFORM dashed border WITH border-radius as strokeRoundedRect(), centerline rect + radius reduced by width/2', function () {
    $canvas = new RecordingCanvas();
    $side = new BorderSide(4.0, BorderStyle::Dashed, new Color(0, 0, 0));
    $borders = new BorderSet($side, $side, $side, $side);
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [], $borders, borderRadius: new BorderRadius(10.0, 10.0, 10.0, 10.0));
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    // centerline: inset by 4/2=2; radius reduced by the same 2 -> 10-2=8.
    expect($canvas->calls)->toBe([
        'strokeRoundedRect(2.00,2.00,96.00,46.00,r=8.00/8.00/8.00/8.00,w=4.00,#000000,dash=[12.00,4.00],cap=butt)',
    ]);
});

it('folds the BoxFragment opacity into the dashed border Color BEFORE calling strokeRect (0.5 opacity halves alpha to 0.50)', function () {
    $canvas = new RecordingCanvas();
    $side = new BorderSide(2.0, BorderStyle::Dashed, new Color(0, 0, 0));
    $borders = new BorderSet($side, $side, $side, $side);
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [], $borders, opacity: 0.5);
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toHaveCount(1);
    expect($canvas->calls[0])->toBe('strokeRect(1.00,1.00,98.00,48.00,w=2.00,#000000,dash=[6.00,2.00],cap=butt,a=0.50)');
});

it('per-side heterogeneous dashed border: each Dashed/Dotted side strokes an independent centerline segment; Solid sides still fillRect the band', function () {
    $canvas = new RecordingCanvas();
    $top = new BorderSide(2.0, BorderStyle::Solid, new Color(0, 0, 0));
    $right = new BorderSide(2.0, BorderStyle::Dashed, new Color(0, 128, 0));
    $bottom = new BorderSide(2.0, BorderStyle::Dotted, new Color(0, 0, 255));
    $left = new BorderSide(0.0, BorderStyle::None, null);
    $borders = new BorderSet($top, $right, $bottom, $left);
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [], $borders);
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    // top: Solid -> full band fillRect (0,0,100,2). right: Dashed, width 2, band x=98..100,
    // y=2..48 (middleHeight=50-topW(2)-bottomW(2)=46) -> vertical centerline at x=99, from y=2 to
    // y=48, dash [6,2]. bottom: Dotted, width 2, band y=48..50, full width -> horizontal
    // centerline at y=49, dash [0,4], round cap. left: None -> nothing.
    expect($canvas->calls)->toBe([
        'rect(0.00,0.00,100.00,2.00,#000000)',
        'line(99.00,2.00,99.00,48.00,2.00,dash=[6.00,2.00])',
        'line(0.00,49.00,100.00,49.00,2.00,dash=[0.00,4.00],cap=round)',
    ]);
});

it('per-side heterogeneous with radius falls back to flat painting + the mixed-border warning, same as Solid heterogeneous', function () {
    $canvas = new RecordingCanvas();
    $top = new BorderSide(2.0, BorderStyle::Dashed, new Color(0, 0, 0));
    $right = new BorderSide(4.0, BorderStyle::Dashed, new Color(0, 0, 0));
    $borders = new BorderSet($top, $right, $top, $top);
    $box = new BoxFragment(new Rect(0, 0, 100, 50), null, [], $borders, borderRadius: new BorderRadius(10.0, 10.0, 10.0, 10.0));
    $page = new Page(1, [$box]);
    $warnings = new WarningCollector();
    testPainter(FontCatalog::withDefaults(), $warnings)->paint($page, $canvas);

    expect($canvas->calls)->toBe([
        'line(0.00,1.00,100.00,1.00,2.00,dash=[6.00,2.00])',
        'line(98.00,2.00,98.00,48.00,4.00,dash=[12.00,4.00])',
        'line(0.00,49.00,100.00,49.00,2.00,dash=[6.00,2.00])',
        'line(1.00,2.00,1.00,48.00,2.00,dash=[6.00,2.00])',
    ]);
    expect($warnings->drain())->toBe(['mixed border widths with border-radius approximated']);
});

// --- M8-T6 (css-backgrounds-3 §4 reducido): background-image (cover/contain/tiling) ------------

/**
 * A REAL GD-generated JPEG of the requested dimensions -- same generation pattern as
 * tests/EndToEnd/ItineraryWithPhotosTest.php's itineraryPhotoFixture(). Tests that need the EXACT
 * 300x150/100x40 hand-computed numbers from the brief skip gracefully when GD is missing (see
 * ->skip() below).
 *
 * @param positive-int $width
 * @param positive-int $height
 */
function painterBgImageFixture(int $width, int $height): string
{
    $path = sys_get_temp_dir() . '/pliego-painter-bg-' . getmypid() . '-' . $width . 'x' . $height . '.jpg';
    $image = imagecreatetruecolor($width, $height);
    $color = imagecolorallocate($image, 200, 120, 40);
    // imagecolorallocate() returns int|false (color-table exhaustion, effectively never for a
    // fresh truecolor image with a single color) -- same defensive ternary already used by
    // tests/Unit/Image/PngImageTest.php for the same GD quirk.
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $color === false ? 0 : $color);
    imagejpeg($image, $path, 85);
    imagedestroy($image);
    return $path;
}

it('paints an auto-sized background-image top-left (default position), no scaling', function () {
    $path = painterBgImageFixture(40, 30);
    try {
        $canvas = new RecordingCanvas();
        $box = new BoxFragment(new Rect(10, 10, 100, 100), null, [], BorderSet::none(), backgroundImagePath: $path);
        $page = new Page(1, [$box]);
        testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

        expect($canvas->calls)->toBe([
            'clip(10.00,10.00,100.00,100.00)',
            sprintf('image(10.00,10.00,40.00,30.00,%s,1.00)', $path),
            'restoreClip()',
        ]);
    } finally {
        unlink($path);
    }
});

it('centers an auto-sized background-image when background-position:center is declared', function () {
    $path = painterBgImageFixture(40, 30);
    try {
        $canvas = new RecordingCanvas();
        $box = new BoxFragment(
            new Rect(0, 0, 100, 100),
            null,
            [],
            BorderSet::none(),
            backgroundImagePath: $path,
            backgroundPosition: BackgroundPosition::Center,
        );
        $page = new Page(1, [$box]);
        testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

        // offset = (100-40)/2=30, (100-30)/2=35.
        expect($canvas->calls)->toBe([
            'clip(0.00,0.00,100.00,100.00)',
            sprintf('image(30.00,35.00,40.00,30.00,%s,1.00)', $path),
            'restoreClip()',
        ]);
    } finally {
        unlink($path);
    }
});

it('computes the cover destination rect hand-verified for a 300x150 image in a 200x200 box (scale=1.333, centered, offset -100,0)', function () {
    $path = painterBgImageFixture(300, 150);
    try {
        $canvas = new RecordingCanvas();
        $box = new BoxFragment(
            new Rect(0, 0, 200, 200),
            null,
            [],
            BorderSet::none(),
            backgroundImagePath: $path,
            backgroundSize: BackgroundSize::Cover,
        );
        $page = new Page(1, [$box]);
        testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

        // scale = max(200/300, 200/150) = 200/150 = 1.333... -> dest 400x200, centered -> x=-100, y=0.
        expect($canvas->calls)->toBe([
            'clip(0.00,0.00,200.00,200.00)',
            sprintf('image(-100.00,0.00,400.00,200.00,%s,1.00)', $path),
            'restoreClip()',
        ]);
    } finally {
        unlink($path);
    }
})->skip(!extension_loaded('gd'), 'GD extension not available in this environment.');

it('cover IGNORES background-position (always centered), per the M8 adjudication', function () {
    $path = painterBgImageFixture(300, 150);
    try {
        $canvas = new RecordingCanvas();
        $box = new BoxFragment(
            new Rect(0, 0, 200, 200),
            null,
            [],
            BorderSet::none(),
            backgroundImagePath: $path,
            backgroundSize: BackgroundSize::Cover,
            backgroundPosition: BackgroundPosition::Center,
        );
        $page = new Page(1, [$box]);
        testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

        expect($canvas->calls)->toBe([
            'clip(0.00,0.00,200.00,200.00)',
            sprintf('image(-100.00,0.00,400.00,200.00,%s,1.00)', $path),
            'restoreClip()',
        ]);
    } finally {
        unlink($path);
    }
})->skip(!extension_loaded('gd'), 'GD extension not available in this environment.');

it('computes the contain destination rect hand-verified for a 300x150 image in a 200x200 box (scale=0.667, letterboxed, top-left)', function () {
    $path = painterBgImageFixture(300, 150);
    try {
        $canvas = new RecordingCanvas();
        $box = new BoxFragment(
            new Rect(0, 0, 200, 200),
            new Color(0, 128, 0),
            [],
            BorderSet::none(),
            backgroundImagePath: $path,
            backgroundSize: BackgroundSize::Contain,
        );
        $page = new Page(1, [$box]);
        testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

        // scale = min(200/300, 200/150) = 200/300 = 0.667... -> dest 200x100, top-left (default
        // position): the green background-color, already painted first, shows through the
        // untouched bottom half of the clipped area (letterboxing) without any extra code here.
        expect($canvas->calls)->toBe([
            'rect(0.00,0.00,200.00,200.00,#008000)',
            'clip(0.00,0.00,200.00,200.00)',
            sprintf('image(0.00,0.00,200.00,100.00,%s,1.00)', $path),
            'restoreClip()',
        ]);
    } finally {
        unlink($path);
    }
})->skip(!extension_loaded('gd'), 'GD extension not available in this environment.');

it('contain centers when background-position:center is declared', function () {
    $path = painterBgImageFixture(300, 150);
    try {
        $canvas = new RecordingCanvas();
        $box = new BoxFragment(
            new Rect(0, 0, 200, 200),
            null,
            [],
            BorderSet::none(),
            backgroundImagePath: $path,
            backgroundSize: BackgroundSize::Contain,
            backgroundPosition: BackgroundPosition::Center,
        );
        $page = new Page(1, [$box]);
        testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

        // dest 200x100 (as above); centered -> x=(200-200)/2=0, y=(200-100)/2=50.
        expect($canvas->calls)->toBe([
            'clip(0.00,0.00,200.00,200.00)',
            sprintf('image(0.00,50.00,200.00,100.00,%s,1.00)', $path),
            'restoreClip()',
        ]);
    } finally {
        unlink($path);
    }
})->skip(!extension_loaded('gd'), 'GD extension not available in this environment.');

it('tiles a 100x40 image into a 250x100 box as a 3x3=9 drawImage() grid, hand-verified (repeat always uses the AUTO/intrinsic size)', function () {
    $path = painterBgImageFixture(100, 40);
    try {
        $canvas = new RecordingCanvas();
        $box = new BoxFragment(
            new Rect(0, 0, 250, 100),
            null,
            [],
            BorderSet::none(),
            backgroundImagePath: $path,
            // backgroundSize:Cover is set here on PURPOSE and must be IGNORED -- repeat always
            // tiles at the image's intrinsic size, taking precedence over background-size in this
            // reduced model (see Painter::paintBackgroundImage()'s docblock).
            backgroundSize: BackgroundSize::Cover,
            backgroundRepeat: true,
        );
        $page = new Page(1, [$box]);
        testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

        // n=ceil(250/100)=3, m=ceil(100/40)=3 -> 9 tiles, row-major, each 100x40, from (0,0).
        $expected = ['clip(0.00,0.00,250.00,100.00)'];
        for ($row = 0; $row < 3; $row++) {
            for ($col = 0; $col < 3; $col++) {
                $expected[] = sprintf('image(%.2F,%.2F,100.00,40.00,%s,1.00)', $col * 100.0, $row * 40.0, $path);
            }
        }
        $expected[] = 'restoreClip()';
        expect($canvas->calls)->toBe($expected);
        expect($canvas->calls)->toHaveCount(11); // clip + 9 tiles + restoreClip
    } finally {
        unlink($path);
    }
})->skip(!extension_loaded('gd'), 'GD extension not available in this environment.');

it('warns once and keeps tiling from top-left when background-repeat:repeat is combined with background-position:center', function () {
    $path = painterBgImageFixture(50, 50);
    try {
        $canvas = new RecordingCanvas();
        $warnings = new WarningCollector();
        $box = new BoxFragment(
            new Rect(0, 0, 100, 100),
            null,
            [],
            BorderSet::none(),
            backgroundImagePath: $path,
            backgroundRepeat: true,
            backgroundPosition: BackgroundPosition::Center,
        );
        $page = new Page(1, [$box]);
        testPainter(FontCatalog::withDefaults(), $warnings)->paint($page, $canvas);

        // Still tiles (2x2=4 tiles) from top-left, repeat NOT downgraded to non-repeat.
        expect($canvas->calls)->toHaveCount(6); // clip + 4 tiles + restoreClip
        expect($canvas->calls[0])->toBe('clip(0.00,0.00,100.00,100.00)');
        expect($canvas->calls[1])->toBe(sprintf('image(0.00,0.00,50.00,50.00,%s,1.00)', $path));
        expect($warnings->drain())->toBe([
            'background-repeat with background-position:center is not supported (M8): tiling from top-left',
        ]);
    } finally {
        unlink($path);
    }
});

it('clips to a ROUNDED border-box when the fragment has border-radius', function () {
    $path = painterBgImageFixture(40, 30);
    try {
        $canvas = new RecordingCanvas();
        $box = new BoxFragment(
            new Rect(0, 0, 100, 100),
            null,
            [],
            BorderSet::none(),
            borderRadius: new BorderRadius(8.0, 8.0, 8.0, 8.0),
            backgroundImagePath: $path,
        );
        $page = new Page(1, [$box]);
        testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

        expect($canvas->calls[0])->toBe('clipRounded(0.00,0.00,100.00,100.00,r=8.00/8.00/8.00/8.00)');
        expect(end($canvas->calls))->toBe('restoreClip()');
    } finally {
        unlink($path);
    }
});

it('paints background-color THEN background-image THEN background-gradient, in that order', function () {
    $path = painterBgImageFixture(40, 30);
    try {
        $canvas = new RecordingCanvas();
        // NOTE: image and gradient never coexist in practice (DeclarationParser resets one when
        // the other is set), but Painter documents/tests the order defensively anyway.
        $gradient = new Gradient(GradientKind::Linear, 90.0, [
            new GradientStop(new Color(255, 0, 0), 0.0),
            new GradientStop(new Color(0, 0, 255), 100.0),
        ]);
        $box = new BoxFragment(
            new Rect(0, 0, 100, 100),
            new Color(0, 128, 0),
            [],
            BorderSet::none(),
            backgroundGradient: $gradient,
            backgroundImagePath: $path,
        );
        $page = new Page(1, [$box]);
        testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

        expect($canvas->calls)->toBe([
            'rect(0.00,0.00,100.00,100.00,#008000)',
            'clip(0.00,0.00,100.00,100.00)',
            sprintf('image(0.00,0.00,40.00,30.00,%s,1.00)', $path),
            'restoreClip()',
            'gradient(0.00,0.00,100.00,100.00,Linear,stops=2,r=0.00/0.00/0.00/0.00)',
        ]);
    } finally {
        unlink($path);
    }
});

it('resolves a RELATIVE background-image path against the Painter basePath', function () {
    $canvas = new RecordingCanvas();
    $box = new BoxFragment(new Rect(0, 0, 100, 100), null, [], BorderSet::none(), backgroundImagePath: 'tiny.jpg');
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    $resolvedPath = PAINTER_IMAGE_FIXTURES_DIR . '/tiny.jpg';
    expect($canvas->calls)->toContain(sprintf('image(0.00,0.00,4.00,3.00,%s,1.00)', $resolvedPath));
});

it('falls back to background-color only + a warning when the background-image file cannot be loaded (soft failure)', function () {
    $canvas = new RecordingCanvas();
    $warnings = new WarningCollector();
    $box = new BoxFragment(
        new Rect(0, 0, 100, 100),
        new Color(255, 0, 0),
        [],
        BorderSet::none(),
        backgroundImagePath: 'does-not-exist.jpg',
    );
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults(), $warnings)->paint($page, $canvas);

    // background-color still painted; NOTHING else (no clip, no drawImage call at all).
    expect($canvas->calls)->toBe(['rect(0.00,0.00,100.00,100.00,#ff0000)']);
    $drained = $warnings->drain();
    expect($drained)->toHaveCount(1);
    expect($drained[0])->toContain('does-not-exist.jpg');
});

it('does not paint any background-image op when backgroundImagePath is null (default), byte-identical to before this task', function () {
    $canvas = new RecordingCanvas();
    $box = new BoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), [], BorderSet::none());
    $page = new Page(1, [$box]);
    testPainter(FontCatalog::withDefaults())->paint($page, $canvas);

    expect($canvas->calls)->toBe(['rect(0.00,0.00,100.00,50.00,#ff0000)']);
});
