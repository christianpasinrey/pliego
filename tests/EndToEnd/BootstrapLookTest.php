<?php

// tests/EndToEnd/BootstrapLookTest.php
declare(strict_types=1);

use Pliego\Box\BoxTreeBuilder;
use Pliego\Css\StylesheetParser;
use Pliego\Css\WarningCollector;
use Pliego\Dom\HtmlParser;
use Pliego\Engine;
use Pliego\Image\ImageLoader;
use Pliego\Layout\BlockFlowContext;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\FragmentDumper;
use Pliego\Layout\Geometry\Rect;
use Pliego\Layout\TextMeasurer;
use Pliego\Style\CssStyleSource;
use Pliego\Style\StyleResolver;
use Pliego\Text\FontCatalog;

/**
 * M8-T8: the CLOSING E2E for the whole M8 visual milestone -- one real Bootstrap-flavored
 * document exercising every M8 feature at once, through the actual Engine pipeline:
 *
 *  - T2 border-radius: `.card`'s rounded uniform border ring (annular f*), `.btn`'s rounded
 *    gradient clip, `.badge`'s pill (a declared 999px radius auto-clamped by css-backgrounds-3
 *    §5.5 to exactly half the badge's own height -- the real Bootstrap ".badge.rounded-pill" look,
 *    achieved with NO special-case code, just the existing clamp).
 *  - T3 native gradients: `.btn`'s `linear-gradient(to right, ...)` background, an axial
 *    (/ShadingType 2) shading.
 *  - T4 box-shadow + dashed borders: `.card`'s blur>0 shadow (4 concentric layers, shared
 *    ExtGState) and the `.data` table's `border: 1px dashed`.
 *  - T5 letter/word-spacing + text-transform: `.display-4`'s `letter-spacing` (forces the TJ
 *    per-glyph path) + `text-transform: uppercase`, the exact Bootstrap `.display-*` idiom.
 *  - T6 background-image: `.hero`'s `background-image` + `background-size: cover`.
 *  - T7 @font-face: a custom 'Display' family (DejaVuSerif-Bold.ttf) used by `.display-4`.
 *
 * Every one of these lands on the SAME page, all through Engine::make()->render() -- not seven
 * separate documents. Helper functions are named with a `bootstrapLook` prefix (not reused from
 * BootstrapComponentsTest's/BootstrapLikeTest's own helpers) so this file stays runnable in
 * isolation, e.g. `pest tests/EndToEnd/BootstrapLookTest.php` -- PHP would fatal on "cannot
 * redeclare" if two test files in the same suite declared same-named top-level functions.
 */

const BOOTSTRAP_LOOK_RESOURCES_DIR = __DIR__ . '/../../resources';

const BOOTSTRAP_LOOK_CSS = <<<'CSS'
@font-face { font-family: 'Display'; src: url('fonts/DejaVuSerif-Bold.ttf'); font-weight: bold; }

.hero {
  background-image: url('images/tiny.jpg');
  background-size: cover;
  padding: 20px;
  color: #ffffff;
}
h1.display-4 {
  font-family: 'Display', serif;
  font-weight: bold;
  letter-spacing: 2px;
  text-transform: uppercase;
  font-size: 24px;
}
.card {
  border: 1px solid #dee2e6;
  border-radius: 12px;
  box-shadow: 4px 4px 10px rgba(0, 0, 0, 0.3);
  background-color: #ffffff;
  padding: 16px;
}
.btn {
  display: inline-block;
  border-radius: 6px;
  background: linear-gradient(to right, #0d6efd, #6610f2);
  color: #ffffff;
  padding: 6px 14px;
  margin-right: 6px;
}
.badge {
  border-radius: 999px;
  background-color: #6c757d;
  color: #ffffff;
  padding: 2px 10px;
}
table.data, table.data td { border: 1px dashed #999999; }
table.data { width: 200px; }
CSS;

const BOOTSTRAP_LOOK_HTML = <<<'HTML'
<body>
  <div class="hero">Hero banner background image cover check</div>
  <h1 class="display-4">Bootstrap Look</h1>
  <div class="card">
    <p>Card body with <a class="btn">Learn more</a> and a <span class="badge">NEW</span>&nbsp;badge.</p>
    <table class="data">
      <tr><td>Row 1</td><td>Val</td></tr>
      <tr><td>Row 2</td><td>Val</td></tr>
    </table>
  </div>
</body>
HTML;

/** @return array{0: string, 1: \Pliego\RenderReport} */
function bootstrapLookRenderToPdfString(string $css, string $html): array
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::make()->basePath(BOOTSTRAP_LOOK_RESOURCES_DIR)->stylesheet($css)->render($html)->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

/** Same recipe as BootstrapComponentsTest's bootstrapComponentsLayoutFragment(): threads a
 * caller-owned WarningCollector through StyleResolver/BoxTreeBuilder/BlockFlowContext so a test
 * can assert "0 warnings" at the layout level too, not just through Engine's RenderReport. Note:
 * @font-face wiring (Engine::render()-only, see M8-T7) never runs on this path, so a test using
 * this helper must not rely on the custom 'Display' family actually resolving to DejaVuSerif-Bold
 * -- it silently falls back to the next name in the font-family list instead (no warning, same
 * pre-existing FontCatalog fallback behavior any unregistered family already gets). */
function bootstrapLookLayoutFragment(string $html, string $css, float $width, WarningCollector $warnings): BoxFragment
{
    $doc = HtmlParser::parse($html);
    $parseResult = new StylesheetParser()->parse($css);
    foreach ($parseResult->warnings as $warning) {
        $warnings->addWarning($warning);
    }
    $map = new StyleResolver([new CssStyleSource($parseResult)], $warnings)->resolve($doc);
    $root = new BoxTreeBuilder(new ImageLoader(), $warnings, BOOTSTRAP_LOOK_RESOURCES_DIR)->build($doc, $map);
    return new BlockFlowContext(new TextMeasurer(), FontCatalog::withDefaults(), $warnings)
        ->layout($root, new Rect(0.0, 0.0, $width, INF));
}

/** @param array<string, mixed> $dump */
function assertMatchesBootstrapLookGolden(string $name, array $dump): void
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

// --- The whole document, end to end: 0 warnings, structurally valid PDF -------------------------

it('renders the full bootstrap-look document (.card/.btn/.badge/table/@font-face/.hero) end to end, zero warnings, a valid PDF', function () {
    [$pdf, $report] = bootstrapLookRenderToPdfString(BOOTSTRAP_LOOK_CSS, BOOTSTRAP_LOOK_HTML);

    expect($pdf)->toStartWith('%PDF-1.7');
    expect(preg_match('/startxref\n(\d+)\n%%EOF\s*$/', $pdf, $m))->toBe(1);
    expect(substr($pdf, (int) $m[1], 4))->toBe('xref');
    expect($report->warnings)->toBe([]);
    expect($report->pageCount)->toBe(1);
});

// --- T2 border-radius: card ring + btn's rounded gradient clip + badge pill ----------------------

it('paints rounded (Bézier) corners for the card ring, the shadow, the btn clip and the pill badge -- 36 total c ops, hand-verified', function () {
    [$pdf, $report] = bootstrapLookRenderToPdfString(BOOTSTRAP_LOOK_CSS, BOOTSTRAP_LOOK_HTML);
    expect($report->warnings)->toBe([]);

    // Hand-computed breakdown (all rounded, all Bézier -- 4 `c` ops per rounded rect):
    //   .card: 4 shadow layers (rounded, T4) = 16, + rounded background fill = 4,
    //          + rounded UNIFORM border ring (outer+inner, T2) = 8  --> 28
    //   .btn:  rounded gradient clip (no border/background-color declared) = 4
    //   .badge: rounded pill background (no border) = 4
    //   28 + 4 + 4 = 36.
    expect(substr_count($pdf, " c\n"))->toBe(36);
    // The card's border ring is annular (f*), never the flat 4-rect fallback.
    expect($pdf)->toContain("h\nf*\n");
});

// --- T3 native gradient: .btn's linear-gradient -> axial /ShadingType 2 --------------------------

it('paints the .btn gradient as a native axial (/ShadingType 2) shading with a /ShN sh op', function () {
    [$pdf, $report] = bootstrapLookRenderToPdfString(BOOTSTRAP_LOOK_CSS, BOOTSTRAP_LOOK_HTML);
    expect($report->warnings)->toBe([]);

    expect($pdf)->toContain('/ShadingType 2');
    expect($pdf)->toContain('/Function');
    expect($pdf)->toMatch('/\/Sh\d+ sh/');
});

// --- T4 box-shadow + dashed border ---------------------------------------------------------------

it('approximates the .card box-shadow as 4 concentric layers sharing ONE /ca 0.075 ExtGState (0.3 alpha / 4)', function () {
    [$pdf, $report] = bootstrapLookRenderToPdfString(BOOTSTRAP_LOOK_CSS, BOOTSTRAP_LOOK_HTML);
    expect($report->warnings)->toBe([]);

    expect($pdf)->toContain('/ca 0.075');
    expect(substr_count($pdf, 'gs'))->toBeGreaterThanOrEqual(4);
});

it('paints the .data table\'s dashed border with a hand-computed [2.25 0.75] PDF dash array (1px -> 0.75pt, pattern [3w w])', function () {
    [$pdf, $report] = bootstrapLookRenderToPdfString(BOOTSTRAP_LOOK_CSS, BOOTSTRAP_LOOK_HTML);
    expect($report->warnings)->toBe([]);

    expect($pdf)->toContain('[2.25 0.75] 0 d');
});

// --- T5 letter-spacing + text-transform + T7 @font-face (the .display-4 heading) ------------------

it('renders .display-4 (letter-spacing -> TJ) in the custom @font-face family, subsetted and embedded', function () {
    [$pdf, $report] = bootstrapLookRenderToPdfString(BOOTSTRAP_LOOK_CSS, BOOTSTRAP_LOOK_HTML);
    expect($report->warnings)->toBe([]);

    expect($pdf)->toContain('] TJ');
    // Genuinely subsetted (6-letter tag prefix), the real DejaVuSerif-Bold program, not the
    // default DejaVuSans -- proves the @font-face 'Display' family actually won the cascade.
    expect($pdf)->toMatch('/\/BaseFont \/[A-Z]{6}\+DejaVuSerif-Bold\b/');
});

// --- T6 background-image: .hero's cover-sized image XObject --------------------------------------

it('paints the .hero background-image as a clipped image XObject (cover-sized)', function () {
    [$pdf, $report] = bootstrapLookRenderToPdfString(BOOTSTRAP_LOOK_CSS, BOOTSTRAP_LOOK_HTML);
    expect($report->warnings)->toBe([]);

    expect($pdf)->toContain('/Subtype /Image');
    expect($pdf)->toContain(' re W n'); // clip to the hero's border-box before drawing
    expect($pdf)->toMatch('/\/Im\d+ Do/');
});

// --- Golden: FragmentDumper geometry for the .display-4 heading (letter-spacing widens the run) --

it('golden: the .display-4 heading is uppercased and its TextFragment carries a non-zero letterSpacingPx, widening its rect by exactly 2px per character', function () {
    $warnings = new WarningCollector();
    $html = '<body><h1 class="display-4">Bootstrap Look</h1></body>';
    $css = '.display-4 { font-size: 24px; letter-spacing: 2px; text-transform: uppercase; }';
    $fragment = bootstrapLookLayoutFragment($html, $css, 400.0, $warnings);

    expect($warnings->drain())->toBe([]);

    $h1 = $fragment->children[0];
    assert($h1 instanceof BoxFragment);
    $text = $h1->children[0];
    assert($text instanceof TextFragment);
    expect($text->text)->toBe('BOOTSTRAP LOOK'); // text-transform: uppercase applied before measuring
    expect($text->letterSpacingPx)->toBe(2.0);

    // Hand-computed: TextMeasurer applies letter-spacing after EVERY char, including the last
    // (M8-T5 convention) -- "BOOTSTRAP LOOK" is 14 characters (13 letters + 1 space). h1 is bold
    // by the UA stylesheet default (Style\UserAgentStylesheet: "h1 { ...; font-weight: bold; }"),
    // so the plain-width baseline must use the weight-700 face, not the regular one.
    $measurer = new TextMeasurer();
    $catalog = FontCatalog::withDefaults();
    $face = $catalog->select('default', 700, false);
    $plainWidth = $measurer->widthOf('BOOTSTRAP LOOK', $face, 24.0);
    expect(mb_strlen('BOOTSTRAP LOOK'))->toBe(14);
    expect($text->rect->width)->toEqualWithDelta($plainWidth + 2.0 * 14, 0.01);

    assertMatchesBootstrapLookGolden('bootstrap-look-letter-spacing-heading', new FragmentDumper()->dump($h1));
});

// --- Ghostscript smoke test: proves the whole combined document is a PDF a real consumer can
// rasterize without error -- not just "our own byte assertions agree with themselves". ------------

function bootstrapLookFindGhostscriptBinary(): ?string
{
    foreach (['gswin64c', 'gswin32c', 'gs'] as $candidate) {
        $which = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'where' : 'which';
        $output = [];
        $exitCode = 0;
        @exec("$which $candidate 2>NUL", $output, $exitCode);
        if ($exitCode === 0 && $output !== []) {
            return trim($output[0]);
        }
    }
    return null;
}

$bootstrapLookGsBinary = bootstrapLookFindGhostscriptBinary();

it('renders the full bootstrap-look document as a PDF Ghostscript can rasterize without error (E2E render check)', function () use ($bootstrapLookGsBinary) {
    if ($bootstrapLookGsBinary === null) {
        return;
    }
    $gs = $bootstrapLookGsBinary;

    $pdfPath = sys_get_temp_dir() . '/pliego-bootstrap-look-e2e.pdf';
    $report = Engine::make()->basePath(BOOTSTRAP_LOOK_RESOURCES_DIR)->stylesheet(BOOTSTRAP_LOOK_CSS)->render(BOOTSTRAP_LOOK_HTML)->save($pdfPath);
    expect($report->warnings)->toBe([]);

    $renderedPage = sys_get_temp_dir() . '/pliego-bootstrap-look-e2e-page.png';
    $cmd = sprintf(
        '%s -dNOPAUSE -dBATCH -dSAFER -sDEVICE=png16m -r72 -sOutputFile=%s %s 2>&1',
        escapeshellarg($gs),
        escapeshellarg($renderedPage),
        escapeshellarg($pdfPath),
    );
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    expect($exitCode)->toBe(0);
    expect(file_exists($renderedPage))->toBeTrue();
    expect(filesize($renderedPage))->toBeGreaterThan(0);

    @unlink($renderedPage);
    @unlink($pdfPath);
})->skip($bootstrapLookGsBinary === null, 'Ghostscript not found on PATH in this environment.');
