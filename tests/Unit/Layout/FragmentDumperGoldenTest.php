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
function goldenLayoutHtml(string $html, string $css, float $width, string $basePath = __DIR__): BoxFragment
{
    $doc = HtmlParser::parse($html);
    $map = new StyleResolver([new CssStyleSource(new StylesheetParser()->parse($css))])->resolve($doc);
    $root = new BoxTreeBuilder(new ImageLoader(), new WarningCollector(), $basePath)->build($doc, $map);
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

it('golden: replaced box sizing and ImageFragment (M3-T3)', function () {
    // tiny.jpg fixture: 4x3px (ratio 0.75). One image sized by an explicit CSS width (derives
    // height via ratio), one by HTML attrs alone, and a paragraph after both to exercise the
    // margin-bottom cursor advance/box model interplay in the same golden document.
    $html = '<body>'
        . '<img src="tiny.jpg" class="css-sized">'
        . '<img src="tiny.jpg" width="20" height="15">'
        . '<p>Texto tras las imagenes.</p>'
        . '</body>';
    $css = '.css-sized { width: 40px; margin-bottom: 10px; border: 1px solid #000000; padding: 2px }';
    $fragment = goldenLayoutHtml($html, $css, 300.0, __DIR__ . '/../../../resources/images');

    assertMatchesGolden('replaced-box-sizing', new FragmentDumper()->dump($fragment));
});

it('golden: THE CARD -- a flex row of a fixed-width photo plus a flex:1 text column, through the real HTML/CSS pipeline (M4-T6)', function () {
    // Same recipe as FlexFormattingContextTest's "THE CARD" unit test (M4-T4/T5), but exercised end
    // to end through HtmlParser -> StyleResolver -> BoxTreeBuilder -> BlockFlowContext, which lazily
    // delegates the `.card` child to FlexFormattingContext (BlockFlowContext::flexContext(), M4-T4)
    // instead of constructing the BlockBox/ImageBox tree by hand. tiny.jpg (committed fixture, 4x3px,
    // ratio 0.75) sized by the HTML width attribute alone -- same fixture/convention as
    // 'replaced-box-sizing' above -- so the photo's own natural height (120 * 0.75 = 90) comes from
    // real intrinsic dims, not a hand-picked number.
    $html = '<body><div class="card">'
        . '<img src="tiny.jpg" width="120">'
        . '<div class="info"><p class="day">Sarria</p>'
        . '<p>Un breve paragrafo di testo per riempire lo spazio flessibile della scheda.</p></div>'
        . '</div></body>';
    $css = '.card { display: flex; gap: 12px; width: 300px } .info { flex: 1 } .day { font-weight: bold }';
    $fragment = goldenLayoutHtml($html, $css, 300.0, __DIR__ . '/../../../resources/images');

    assertMatchesGolden('flex-card', new FragmentDumper()->dump($fragment));
});

it('golden: table auto layout -- 2 columns, asymmetric content, borders and border-spacing (M5-T6)', function () {
    // 2-col table, table-layout:auto (the default): col0 is short ("Km"-sized) content, col1 is
    // longer text -- exercises the SAME auto column algorithm as TableFormattingContextTest's "2
    // columns sized from their own max-content" unit test, now through the real HTML/CSS pipeline
    // (selectors, UA table display defaults, StyleResolver cascade), with a visible border on the
    // table AND each cell (M2's border painting) plus a non-zero border-spacing (M5-T2, separated
    // borders model §17.6.1) -- both of which shift geometry away from the bare 0-spacing/
    // 0-border unit test, so this golden is a genuinely distinct fixture, not a duplicate.
    $html = '<body><table class="tbl">'
        . '<tr><td class="c">Km</td><td class="c">Un texto bastante más largo en esta celda</td></tr>'
        . '<tr><td class="c">12,5</td><td class="c">Otro contenido de la segunda fila</td></tr>'
        . '</table></body>';
    $css = '.tbl { border: 1px solid #999999; border-spacing: 4px } .c { border: 1px solid #cccccc; padding: 3px 6px }';
    $fragment = goldenLayoutHtml($html, $css, 400.0);

    assertMatchesGolden('table-auto-borders-spacing', new FragmentDumper()->dump($fragment));
});

it('golden: table colspan -- a header cell spans both columns (M5-T6)', function () {
    // 2-col table where the header row is a SINGLE <th colspan="2"> spanning both body columns --
    // the same excess-distribution branch TableFormattingContextTest's "colspan=2 cell distributes
    // its excess width" unit test exercises, now through the real pipeline: <thead>/<tbody> (M5-T3
    // transparency), a real colspan HTML attribute (BoxTreeBuilder::parseColspan()), and the th's
    // own UA-default bold+center (M5-T2) visible in the dump alongside the column-width geometry.
    $html = '<body><table class="tbl">'
        . '<thead><tr><th class="c" colspan="2">Resumen del día</th></tr></thead>'
        . '<tbody><tr><td class="c">Sarria</td><td class="c">Portomarín</td></tr></tbody>'
        . '</table></body>';
    $css = '.tbl { border: 1px solid #999999; border-spacing: 4px } .c { border: 1px solid #cccccc; padding: 3px 6px }';
    $fragment = goldenLayoutHtml($html, $css, 400.0);

    assertMatchesGolden('table-colspan-header', new FragmentDumper()->dump($fragment));
});

it('golden: flex-wrap -- 3 fixed-width/height images wrap into 2 lines inside a 200px container (M4-T6)', function () {
    // Same numbers as FlexFormattingContextTest's wrap unit test (item widths 80/80/80 via the HTML
    // width attribute, heights 30/50/20 via the height attribute, container 200px, row-gap:10,
    // default column-gap:0) -- reproduced here through the full pipeline as a cross-check of the
    // hand-verified unit test's arithmetic (item1+item2 = 160 <= 200 fits line 1; item3 would push
    // to 240 > 200, opens line 2 alone), and to capture the wrap case's own golden geometry.
    $html = '<body><div class="wrap">'
        . '<img src="tiny.jpg" width="80" height="30">'
        . '<img src="tiny.jpg" width="80" height="50">'
        . '<img src="tiny.jpg" width="80" height="20">'
        . '</div></body>';
    $css = '.wrap { display: flex; flex-wrap: wrap; width: 200px; row-gap: 10px }';
    $fragment = goldenLayoutHtml($html, $css, 200.0, __DIR__ . '/../../../resources/images');

    assertMatchesGolden('flex-wrap', new FragmentDumper()->dump($fragment));
});
