<?php

// tests/EndToEnd/BootstrapLikeTest.php
declare(strict_types=1);

use Pliego\Box\BoxTreeBuilder;
use Pliego\Css\StylesheetParser;
use Pliego\Css\Value\Color;
use Pliego\Css\WarningCollector;
use Pliego\Dom\HtmlParser;
use Pliego\Engine;
use Pliego\Image\ImageLoader;
use Pliego\Layout\BlockFlowContext;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\FragmentDumper;
use Pliego\Layout\Geometry\Rect;
use Pliego\Layout\TextMeasurer;
use Pliego\Style\CssStyleSource;
use Pliego\Style\StyleResolver;
use Pliego\Text\FontCatalog;

/**
 * M6-T6: closing E2E for the whole M6 CSS core — a REAL Bootstrap-flavored snippet (:root
 * custom properties, a full combinator+:nth-child selector, rem-based calc(), a var()-fed
 * border and background) rendered over a table + a card, through the actual Engine pipeline.
 * Every M6 feature lands in ONE document: T1/T2 selectors (.table > tbody > tr:nth-child(odd)),
 * T3 rem units, T4 var()/calc() (including the Bootstrap spacer idiom
 * calc(var(--bs-spacing) * .5)), T5 rgba() alpha via ExtGState.
 *
 * Helper functions below are named with a `bootstrap` prefix (not reused from
 * ColorOpacityTest's `renderToPdfString()` or FragmentDumperGoldenTest's `goldenLayoutHtml()`/
 * `assertMatchesGolden()`) so this file stays runnable in isolation, e.g.
 * `pest tests/EndToEnd/BootstrapLikeTest.php` — PHP would fatal on "cannot redeclare" if two
 * test files under the same suite run declared same-named top-level functions.
 */

/** @return array{0: string, 1: \Pliego\RenderReport} */
function bootstrapRenderToPdfString(string $css, string $html): array
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::make()->stylesheet($css)->render($html)->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

/** Same recipe as FragmentDumperGoldenTest's goldenLayoutHtml(), but threading a caller-owned
 * WarningCollector through StyleResolver/BoxTreeBuilder/BlockFlowContext (all three surfaces
 * that can emit a var()/calc()/selector warning) so a test can assert "0 warnings" at the
 * layout level, not just end to end through Engine. */
function bootstrapLayoutFragment(string $html, string $css, float $width, WarningCollector $warnings): BoxFragment
{
    $doc = HtmlParser::parse($html);
    $parseResult = new StylesheetParser()->parse($css);
    foreach ($parseResult->warnings as $warning) {
        $warnings->addWarning($warning);
    }
    $map = new StyleResolver([new CssStyleSource($parseResult)], $warnings)->resolve($doc);
    $root = new BoxTreeBuilder(new ImageLoader(), $warnings, __DIR__)->build($doc, $map);
    return new BlockFlowContext(new TextMeasurer(), FontCatalog::withDefaults(), $warnings)
        ->layout($root, new Rect(0.0, 0.0, $width, INF));
}

/** @param array<string, mixed> $dump */
function assertMatchesBootstrapGolden(string $name, array $dump): void
{
    $path = __DIR__ . '/goldens/' . $name . '.json';
    $encoded = json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($encoded === false) {
        throw new RuntimeException("Failed to encode golden dump '$name' as JSON");
    }
    $json = $encoded . "\n";

    if (getenv('UPDATE_GOLDENS') === '1') {
        file_put_contents($path, $json);
        test()->markTestSkipped('golden regenerated');
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Missing golden file: $path (run with UPDATE_GOLDENS=1 to generate it)");
    }
    /** @var array<string, mixed> $golden */
    $golden = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    expect($dump)->toBe($golden);
}

// --- The bootstrap-flavored stylesheet, shared by the full E2E render below ---------------------

const BOOTSTRAP_LIKE_CSS = <<<'CSS'
:root {
  --bs-primary: #0d6efd;
  --bs-border-color: #dee2e6;
  --bs-spacing: 1rem;
}
.table > tbody > tr:nth-child(odd) { background-color: rgba(0, 0, 0, .05); }
.card {
  border: 1px solid var(--bs-border-color);
  padding: calc(var(--bs-spacing) * .5);
  background-color: var(--bs-primary);
}
CSS;

const BOOTSTRAP_LIKE_HTML = <<<'HTML'
<body>
  <div class="card">Bootstrap-like card</div>
  <table class="table"><tbody>
    <tr><td>Fila 1</td></tr>
    <tr><td>Fila 2</td></tr>
    <tr><td>Fila 3</td></tr>
    <tr><td>Fila 4</td></tr>
  </tbody></table>
</body>
HTML;

it('renders a real Bootstrap-flavored stylesheet (root vars, nth-child stripes, var()+calc()) over a table + card, end to end, with 0 warnings and a valid PDF', function () {
    [$pdf, $report] = bootstrapRenderToPdfString(BOOTSTRAP_LIKE_CSS, BOOTSTRAP_LIKE_HTML);

    expect($pdf)->toStartWith('%PDF-1.7');
    expect($report->warnings)->toBe([]);

    // Striped rows: rgba(0, 0, 0, .05) -> alpha 0.05, wrapped in its own q/gs(/ca 0.050)/Q scope
    // (M6-T5 ExtGState), same contract exercised end to end by ColorOpacityTest.
    expect($pdf)->toContain('/GS')->toContain('/ca 0.050')->toContain('/Type /ExtGState');
    $stripeFill = sprintf('%.3F %.3F %.3F rg', 0, 0, 0);
    $stripePattern = '/^' . preg_quote($stripeFill, '/') . ' [\d.]+ [\d.]+ [\d.]+ [\d.]+ re f$/m';
    // Exactly 2 rects filled with the stripe color: rows 1 and 3 (1-based, :nth-child(odd)) —
    // rows 2 and 4 stay unpainted, i.e. the alternation is real, not "every row painted".
    expect(preg_match_all($stripePattern, $pdf))->toBe(2);

    // .card's background-color: var(--bs-primary) -> #0d6efd = rgb(13, 110, 253).
    $cardFill = sprintf('%.3F %.3F %.3F rg', 13 / 255, 110 / 255, 253 / 255);
    expect($pdf)->toContain($cardFill);

    // .card's border: 1px solid var(--bs-border-color) -> #dee2e6 = rgb(222, 226, 230), painted
    // as 4 opaque filled rects (M2-T5 border painting), same pattern as RenderTest's border test.
    $borderFill = sprintf('%.3F %.3F %.3F rg', 222 / 255, 226 / 255, 230 / 255);
    $borderPattern = '/^' . preg_quote($borderFill, '/') . ' [\d.]+ [\d.]+ [\d.]+ [\d.]+ re f$/m';
    expect(preg_match_all($borderPattern, $pdf))->toBe(4);
});

// --- Golden 1: nth-child striped table (selectors-3 combinators + :nth-child + var()) ------------

it('golden: .table > tbody > tr:nth-child(odd) striping, with the stripe color itself coming from a :root var()', function () {
    $warnings = new WarningCollector();
    $css = ':root { --bs-stripe: rgba(0, 0, 0, .05); } '
        . '.table > tbody > tr:nth-child(odd) { background-color: var(--bs-stripe); }';
    $html = '<body><table class="table"><tbody>'
        . '<tr><td>Fila 1</td></tr>'
        . '<tr><td>Fila 2</td></tr>'
        . '<tr><td>Fila 3</td></tr>'
        . '<tr><td>Fila 4</td></tr>'
        . '</tbody></table></body>';
    $fragment = bootstrapLayoutFragment($html, $css, 300.0, $warnings);

    expect($warnings->drain())->toBe([]);

    // Hand-computed: table is the (only) child of body; its 4 <tr> children are DIRECT children
    // of the table fragment (thead/tbody are transparent in the fragment tree, M5-T3) — rows 1
    // and 3 (1-based) get the FULL rgba(0,0,0,.05) Color (alpha included, not just its rgb hex),
    // rows 2 and 4 stay unpainted (null).
    $table = $fragment->children[0];
    assert($table instanceof BoxFragment);
    expect($table->children)->toHaveCount(4);
    [$row1, $row2, $row3, $row4] = $table->children;
    assert($row1 instanceof BoxFragment && $row2 instanceof BoxFragment);
    assert($row3 instanceof BoxFragment && $row4 instanceof BoxFragment);
    expect($row1->background)->toEqual(new Color(0, 0, 0, 0.05));
    expect($row2->background)->toBeNull();
    expect($row3->background)->toEqual(new Color(0, 0, 0, 0.05));
    expect($row4->background)->toBeNull();

    assertMatchesBootstrapGolden('bootstrap-nth-child-table', new FragmentDumper()->dump($fragment));
});

// --- Golden 2: var()+calc() card (rem-based Bootstrap spacer idiom + var()-fed border/background) -

it('golden: a .card with border/background from var() and padding from calc(var(--bs-spacing) * .5) — rem scaling verified by hand', function () {
    $warnings = new WarningCollector();
    $css = ':root { --bs-primary: #0d6efd; --bs-border-color: #dee2e6; --bs-spacing: 1rem; } '
        . '.card { border: 1px solid var(--bs-border-color); '
        . 'padding: calc(var(--bs-spacing) * .5); background-color: var(--bs-primary); }';
    $html = '<body><div class="card">Card body</div></body>';
    $fragment = bootstrapLayoutFragment($html, $css, 300.0, $warnings);

    expect($warnings->drain())->toBe([]);

    $card = $fragment->children[0];
    assert($card instanceof BoxFragment);

    // --bs-spacing: 1rem resolves against the document's root font-size (16px, the CSS 2.2
    // initial value — no html{font-size} declared here) -> 1rem = 16px; calc(...*.5) = 8px,
    // applied to all 4 sides by the single-value `padding` shorthand (css-values-3 §8's
    // acceptance probe for this EXACT expression, "the Bootstrap spacer idiom").
    expect($card->borders->top->widthPx)->toBe(1.0);
    expect($card->borders->top->color)->toEqual(new Color(0xde, 0xe2, 0xe6)); // var(--bs-border-color)
    expect($card->background)->toEqual(new Color(0x0d, 0x6e, 0xfd)); // var(--bs-primary)

    // Content starts at border(1px) + padding(8px) = 9px from the card's own border-box origin
    // (card's own rect origin is (0,0): it is body's only, unmargined, undeclared-width child,
    // so its border-box fills body's full 300px content width) — an EXACT hand-computed number,
    // not a golden-only assertion, proving the rem->calc chain resolved to precisely 8px.
    $text = $card->children[0];
    assert($text instanceof \Pliego\Layout\Fragment\TextFragment);
    expect($text->rect->x)->toBe(9.0);
    expect($text->rect->y)->toBe(9.0);

    assertMatchesBootstrapGolden('bootstrap-var-calc-card', new FragmentDumper()->dump($fragment));
});
