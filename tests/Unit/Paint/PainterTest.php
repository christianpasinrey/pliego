<?php

declare(strict_types=1);

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
        $this->calls[] = "rect({$rect->x},{$rect->y})";
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
    expect($canvas->calls)->toBe(['rect(0,0)', 'text(Hola)']);
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
