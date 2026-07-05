<?php

declare(strict_types=1);

use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\Color;
use Pliego\Layout\Fragment\BorderSet;
use Pliego\Layout\Fragment\BoxFragment;
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
        $this->calls[] = sprintf(
            'rect(%.2F,%.2F,%.2F,%.2F,#%02x%02x%02x)',
            $rect->x,
            $rect->y,
            $rect->width,
            $rect->height,
            $color->r,
            $color->g,
            $color->b,
        );
    }

    public function fillText(TextFragment $text): void
    {
        $this->calls[] = "text({$text->text})";
    }

    public function strokeLine(float $x1, float $y1, float $x2, float $y2, float $widthPx, Color $color): void
    {
        $this->calls[] = sprintf('line(%.2F,%.2F,%.2F,%.2F,%.2F)', $x1, $y1, $x2, $y2, $widthPx);
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
