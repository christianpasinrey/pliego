<?php

// tests/EndToEnd/BootstrapRealComponentsTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * M9-T2: the vendored REAL bootstrap.min.css (resources/presets/bootstrap.min.css, v5.3.6 -- see
 * LICENSE-bootstrap.txt) driving an actual component page
 * (tests/Fixtures/bootstrap/components.html: button variants, a card, a striped table, badges,
 * alerts) through the full Engine pipeline -- NOT the hand-written "Bootstrap-flavored" CSS subset
 * BootstrapComponentsTest.php/BootstrapLookTest.php use (those predate this task and stay as-is,
 * M7/M8 closing E2Es for THEIR own milestones); this is the "does the real, unmodified upstream
 * file actually work" proof.
 *
 * M9-T4: now driven through Engine::bootstrap() instead of a manual
 * `->stylesheet(file_get_contents(...))` -- the vendored sheet moved from
 * tests/Fixtures/bootstrap/ to resources/presets/ specifically so Engine::bootstrap() could read
 * it as a runtime package asset (see that method's docblock); this test exercises that exact
 * public entry point end to end, on the real 232KB sheet, instead of only on hand-written probes.
 * The preset's print addendum (resources/presets/bootstrap-print.css, `@page { margin: 15mm }`)
 * rides along too -- it adds zero warnings (a bare @page margin declaration, nothing
 * unsupported), so the pinned warning count below is unaffected; the only visible effect is a
 * slightly larger page margin than the pre-T4 default (48px), which none of the assertions below
 * depend on.
 *
 * One known, deliberate DEVIATION from "byte-for-byte real sheet only": `.table-striped`'s row
 * accent is painted, in real Bootstrap 5.3, through `.table > :not(caption) > * > *`'s
 * `box-shadow: inset 0 0 0 9999px var(--bs-table-bg-state, var(--bs-table-bg-type,
 * var(--bs-table-accent-bg)))` (an inset-box-shadow-as-fill trick) combined with
 * `.table-striped > tbody > tr:nth-of-type(odd) > *` setting `--bs-table-bg-type`.
 *
 * M10-T1 (Selectors-4 §14.4) gave `:nth-of-type`/`:nth-last-of-type` real support (An+B reused
 * from `:nth-child`, counting only same-tagName siblings, see Css\PseudoClass::matchesNthOfType())
 * -- `tr:nth-of-type(odd)` now genuinely MATCHES and sets `--bs-table-bg-type` for real, with zero
 * warning.
 *
 * M10-T1 finding fix (css-variables-1 §7.3, css-cascade-4 §7.3): `Css\VarResolver` also gained
 * real handling of `--x: initial` (the CSS-wide keyword, which sets a custom property to the
 * GUARANTEED-INVALID value per spec) -- Bootstrap's `.table` reset (`--bs-table-bg-state: initial;
 * --bs-table-bg-type: initial; ...`) used to have the LITERAL three-letter string "initial" win
 * substitution instead of engaging the fallback chain, so `color:
 * var(--bs-table-color-state, var(--bs-table-color-type, var(--bs-table-color)))` produced
 * "Unsupported color for color: initial" (15/15 cells warned, one per `.table` cell) and
 * `box-shadow: inset 0 0 0 9999px var(--bs-table-bg-state, var(--bs-table-bg-type,
 * var(--bs-table-accent-bg)))` produced `Unsupported box-shadow component "initial": inset 0 0 0
 * 9999px initial`. Both chains now resolve to their REAL fallback value (`transparent` for
 * unstriped rows, the striped `rgba(...)` for odd rows) -- the color warning is gone entirely
 * (correctness win, independent of the striping mechanism), and the box-shadow warning survives
 * only because inset box-shadow ITSELF remains unsupported (M8), now correctly fed a real color
 * instead of the bogus literal keyword (`Unsupported box-shadow (inset not supported in M8):
 * inset 0 0 0 9999px <the real resolved color>`).
 *
 * Verified by hand (see the task report): removing this file's compat CSS entirely and
 * re-rendering still paints NO visible stripe, because the striping-specific ingredient -- inset
 * box-shadow -- remains unsupported. So real `.table-striped`, used exactly as upstream ships it,
 * STILL paints no visible stripe through this engine today -- a narrower, honestly re-audited
 * capability gap (inset box-shadow ALONE, not "neither ingredient" anymore, and not blocked by the
 * `initial` keyword bug anymore either).
 * `components.html` uses `.table-striped` (so the real class and its rules -- now partially live,
 * `:nth-of-type` included -- are still exercised and warn exactly as they would in any consumer's
 * document) PLUS one extra tiny author-level compat rule (`.table-striped-compat tbody
 * tr:nth-child(odd)`, appended as a SEPARATE ->stylesheet() call, same public API any consumer
 * would use to layer their own CSS after a preset) that reproduces the visual with a mechanism
 * this engine DOES support (background-color, not inset box-shadow) -- this is what makes the
 * "striped rgba" spot check below meaningful instead of silently vacuous.
 *
 * Helper functions prefixed `bootstrapRealComponents` (unique-per-file convention, see other
 * EndToEnd Bootstrap-* files' docblocks).
 */

const BOOTSTRAP_REAL_COMPONENTS_HTML_PATH = __DIR__ . '/../Fixtures/bootstrap/components.html';

// See the class docblock: real Bootstrap's own row-striping mechanism needs BOTH :nth-of-type
// (supported since M10-T1) AND inset box-shadow (still NOT supported) -- this is the documented
// compat shim, still required.
const BOOTSTRAP_REAL_COMPONENTS_STRIPE_COMPAT_CSS = '.table-striped-compat tbody tr:nth-child(odd) > * { background-color: rgba(0, 0, 0, 0.05); }';

/** @return array{0: string, 1: \Pliego\RenderReport} */
function bootstrapRealComponentsRender(): array
{
    $html = file_get_contents(BOOTSTRAP_REAL_COMPONENTS_HTML_PATH);
    if ($html === false) {
        throw new RuntimeException('Missing vendored fixture for the real components E2E');
    }
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    // M9-T4: Engine::bootstrap() replaces the old manual ->stylesheet(file_get_contents(...))
    // -- same vendored sheet content (now read from resources/presets/, see class docblock),
    // same warning budget, plus the preset's own print addendum queued right after it.
    $report = Engine::bootstrap()
        ->stylesheet(BOOTSTRAP_REAL_COMPONENTS_STRIPE_COMPAT_CSS)
        ->render($html)
        ->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

function bootstrapRealComponentsFindGhostscriptBinary(): ?string
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

it('renders the real Bootstrap components page as a single valid page, with the vendored sheet\'s own warning budget', function () {
    [$pdf, $report] = bootstrapRealComponentsRender();

    expect($pdf)->toStartWith('%PDF-1.7');
    expect($report->pageCount)->toBe(1);

    // Pinned exact total (deterministic, same vendored sheet + same fixture html + same compat
    // shim every run): 870 from parsing the real sheet alone (see BootstrapIngestionTest's golden --
    // 902 pre-M9-T3, minus the 7 "Gradient color-stop alpha not supported" warnings, now GONE:
    // rgba() gradient stops are supported via /SMask /Luminosity, see Pdf\PdfCanvas::paintGradient();
    // 895 pre-M10-T1, minus 25 more now GONE: M10-T1 (css-values-4 §5.1.1) gives vw/vh real support
    // -- 9 "Unsupported length for ...: NNvw/vh" and 15 "Invalid calc() expression" (a vw/vh unit
    // inside calc() used to make the WHOLE expression unparseable) vanish, plus 1
    // "Pseudo-class not supported yet: :nth-of-type" (Selectors-4 §14.4, now matches for real) --
    // see the M10-T1 report for the full delta) plus 49 more that only surface once declarations
    // are actually RESOLVED against real elements (unresolved var() chains on empty custom
    // properties like `--bs-btn-font-family: ;`, a known IACVT gap already on record since M7).
    //
    // M10-T1 finding fix (css-variables-1 §7.3): 919 pre-fix, minus 15 more now GONE --
    // Css\VarResolver used to treat a custom property set to the CSS-wide keyword `initial` (e.g.
    // Bootstrap's own `.table { --bs-table-bg-state: initial; ... }`) as a literal string, so
    // `var(--bs-table-bg-state, var(--bs-table-bg-type, var(--bs-table-accent-bg)))` substituted
    // the literal text "initial" instead of engaging the fallback chain -- 15 "Unsupported color
    // for color: initial" warnings (one per `.table` cell, the class docblock's "15/15 cells warn"
    // repro) vanish now that the chain resolves to a real color (`transparent` or the striped
    // rgba()). NOTE: this does NOT change the `box-shadow-limitation` category's total count --
    // the SAME 15 cells' box-shadow warning just changes its own message from `Unsupported
    // box-shadow component "initial": inset 0 0 0 9999px initial` to `Unsupported box-shadow
    // (inset not supported in M8): inset 0 0 0 9999px <the now-correctly-resolved color>`, because
    // inset box-shadow itself is still unsupported (M8) regardless of what color feeds it -- see
    // this file's class docblock for the honestly re-audited, narrower gap. A regression here
    // (count changing) means either the vendored sheet changed or something in parse/style/layout
    // started handling one of these constructs differently. (904 post-M10-T1's `initial` fix.)
    //
    // M10-T2 (css-mediaqueries-4, reduced): 904 -> 1100 (+196), the EXACT same +196 delta as
    // BootstrapIngestionTest's golden (unlike BootstrapPageTest's +195, this fixture's grid uses
    // plain `.col` with no `row-cols-md-*` breakpoint class, so it has no atomic-fragment warning
    // to lose -- see BootstrapPageTest's own docblock for that one-warning difference). Every one
    // of the +196 warnings is a real Bootstrap responsive-utility declaration inside one of the 30
    // @media blocks that now correctly apply at this page's A4 width (793.70px, exactly like
    // Chrome printing an A4 page) -- min-width:576/768 and max-width:991.98/1199.98/1399.98 all
    // hold, min-width:992/1200/1400 and max-width:575.98/767.98 do not (see
    // MediaQueryEvaluatorTest for the grammar and StylesheetParserTest/BootstrapIngestionTest for
    // the sheet-wide breakdown: +29 unsupported-keyword `position: sticky`, +42 unsupported-length
    // `width/right: auto`, +106 unsupported-property-other `z-index`/`visibility`/`overflow-y`,
    // +17 unsupported-property-transform, +2 unsupported-shorthand `margin: auto`).
    expect($report->warnings)->toHaveCount(1100);
});

it('paints .btn-primary with real Bootstrap\'s own blue (#0d6efd), resolved through its full --bs-btn-bg/--bs-btn-color CSS-variable chain', function () {
    [$pdf, ] = bootstrapRealComponentsRender();
    $btnPrimaryFill = sprintf('%.3F %.3F %.3F rg', 13 / 255, 110 / 255, 253 / 255);
    expect($pdf)->toContain($btnPrimaryFill);
});

it('paints rounded corners (border-radius) as real bezier curve operators, not just straight rects', function () {
    [$pdf, ] = bootstrapRealComponentsRender();
    expect(preg_match_all('/^[\d.\s-]+ c$/m', $pdf))->toBeGreaterThan(10);
});

it('paints the striped-table compat shim as a real 5%-alpha black fill (ExtGState /ca 0.050 + a black rg fill)', function () {
    [$pdf, ] = bootstrapRealComponentsRender();
    expect($pdf)->toContain('/ca 0.050');
    expect($pdf)->toContain('0.000 0.000 0.000 rg');
});

$gsBinary = bootstrapRealComponentsFindGhostscriptBinary();

it('renders a PDF Ghostscript can rasterize without error (E2E render check)', function () use ($gsBinary) {
    if ($gsBinary === null) {
        return;
    }
    $gs = $gsBinary;
    [$pdf, ] = bootstrapRealComponentsRender();

    $pdfPath = sys_get_temp_dir() . '/pliego-bootstrap-real-components-e2e.pdf';
    file_put_contents($pdfPath, $pdf);

    $renderedPage = sys_get_temp_dir() . '/pliego-bootstrap-real-components-e2e-page.png';
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
})->skip($gsBinary === null, 'Ghostscript not found on PATH in this environment.');
