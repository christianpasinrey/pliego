<?php

// tests/Unit/Layout/FragmentDumperGoldenTest.php
declare(strict_types=1);

use Pliego\Box\BoxTreeBuilder;
use Pliego\Css\StylesheetParser;
use Pliego\Css\WarningCollector;
use Pliego\Dom\HtmlParser;
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
 * Golden fragment-tree dumps (M1-T10 brief): three representative documents laid out through
 * the HtmlParser -> StyleResolver -> BoxTreeBuilder -> BlockFlowContext pipeline (same pattern
 * as BlockFlowContextTest's `layoutHtml()`, duplicated locally under a distinct name so this
 * file stays self-contained when run in isolation, e.g. `pest tests/Unit/Layout/FragmentDumperGoldenTest.php`,
 * where sibling test files' global helper functions are never `require`d), dumped to a
 * JSON-friendly array via FragmentDumper, and compared against a golden file committed under
 * tests/Unit/Layout/goldens/.
 *
 * Regeneration flow: run with env UPDATE_GOLDENS=1 to rewrite the golden file and mark the test
 * skipped instead of asserting against it, e.g.
 *   UPDATE_GOLDENS=1 ./vendor/bin/pest tests/Unit/Layout/FragmentDumperGoldenTest.php
 * then re-run without the env var to confirm the freshly-written golden passes, and diff/review
 * the golden file change before committing it.
 */
function goldenLayoutHtml(string $html, string $css, float $width): BoxFragment
{
    $doc = HtmlParser::parse($html);
    $map = new StyleResolver([new CssStyleSource(new StylesheetParser()->parse($css))])->resolve($doc);
    $root = new BoxTreeBuilder(new ImageLoader(), new WarningCollector(), __DIR__)->build($doc, $map);
    return new BlockFlowContext(new TextMeasurer(), FontCatalog::withDefaults())
        ->layout($root, new Rect(0.0, 0.0, $width, INF));
}

/** @param array<string, mixed> $dump */
function assertMatchesGolden(string $name, array $dump): void
{
    $path = __DIR__ . '/goldens/' . $name . '.json';
    // JSON_PRESERVE_ZERO_FRACTION: without it, a whole-number float like 0.0 or 300.0 encodes
    // as the bare integer literal "0"/"300", and json_decode() on the other side would read it
    // back as PHP int, not float — a silent type mismatch against the strict `toBe()` (===)
    // comparison below even though every rect/baselineY value here is conceptually a float.
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

    // assertSame-equivalent decoded-array comparison (brief): clearer diffs than a raw string
    // compare, since Pest/PHPUnit renders an array diff instead of one giant string blob.
    expect($dump)->toBe($golden);
}

it('golden: paragraph mixing bold and italic runs across a wrap', function () {
    $html = '<body><p>Texto normal con <b>negrita</b> y <i>cursiva</i> mezclado de verdad para forzar el ajuste de línea.</p></body>';
    $fragment = goldenLayoutHtml($html, '', 200.0);

    assertMatchesGolden('mixed-bold-italic', new FragmentDumper()->dump($fragment));
});

it('golden: center- and right-aligned paragraphs', function () {
    $html = '<body><p class="center">Centrado</p><p class="right">A la derecha</p></body>';
    $css = '.center { text-align: center } .right { text-align: right }';
    $fragment = goldenLayoutHtml($html, $css, 300.0);

    assertMatchesGolden('text-align-center-right', new FragmentDumper()->dump($fragment));
});

it('golden: custom line-height paragraph broken by <br>', function () {
    $html = '<body><p class="tight">Primera línea<br>Segunda línea</p></body>';
    $css = '.tight { line-height: 2 }';
    $fragment = goldenLayoutHtml($html, $css, 300.0);

    assertMatchesGolden('line-height-br', new FragmentDumper()->dump($fragment));
});

it('golden: bordered box with a percentage border-box width (M2-T8)', function () {
    // Exercises M2's box model together in one fixture: a solid border on every side (T4/T5,
    // now visible in FragmentDumper's dump too), width:50% resolved against the 300px containing
    // block (T2/T4), and box-sizing:border-box (T3/T4) — the declared width IS the border-box
    // width, so content width = 150 - 2*2 (border) - 2*10 (padding) = 126px.
    $html = '<body><div class="box">Contenido con borde y ancho porcentual</div></body>';
    $css = '.box { width: 50%; box-sizing: border-box; border: 2px solid #000000; padding: 10px }';
    $fragment = goldenLayoutHtml($html, $css, 300.0);

    assertMatchesGolden('border-percent-box-sizing', new FragmentDumper()->dump($fragment));
});
