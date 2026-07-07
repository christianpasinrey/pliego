<?php

// tests/EndToEnd/BootstrapRealComponentsTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * M9-T2: the vendored REAL bootstrap.min.css (tests/Fixtures/bootstrap/bootstrap.min.css, v5.3.6 --
 * see LICENSE-bootstrap.txt) driving an actual component page
 * (tests/Fixtures/bootstrap/components.html: button variants, a card, a striped table, badges,
 * alerts) through the full Engine pipeline -- NOT the hand-written "Bootstrap-flavored" CSS subset
 * BootstrapComponentsTest.php/BootstrapLookTest.php use (those predate this task and stay as-is,
 * M7/M8 closing E2Es for THEIR own milestones); this is the "does the real, unmodified upstream
 * file actually work" proof.
 *
 * One known, deliberate DEVIATION from "byte-for-byte real sheet only": `.table-striped`'s row
 * accent is painted, in real Bootstrap 5.3, through `.table > :not(caption) > * > *`'s
 * `box-shadow: inset 0 0 0 9999px var(--bs-table-bg-state, var(--bs-table-bg-type,
 * var(--bs-table-accent-bg)))` (an inset-box-shadow-as-fill trick) combined with
 * `.table-striped > tbody > tr:nth-of-type(odd) > *` setting `--bs-table-bg-type`. THIS ENGINE
 * supports neither ingredient yet (inset box-shadow: dropped with a warning since M8, "no inset
 * support"; `:nth-of-type`: parses with a "Pseudo-class not supported yet" warning, matches
 * nothing) -- so real `.table-striped`, used exactly as upstream ships it, paints NO visible
 * stripe at all through this engine today (a genuine, honestly-audited capability gap, not a bug in
 * this task's code -- see the M9-T2 report). `components.html` uses `.table-striped` (so the real
 * class and its (currently inert) rules are still exercised and warn exactly as they would in any
 * consumer's document) PLUS one extra tiny author-level compat rule
 * (`.table-striped-compat tbody tr:nth-child(odd)`, appended as a SEPARATE ->stylesheet() call,
 * same public API any consumer would use to layer their own CSS after a preset) that reproduces the
 * visual with a selector this engine DOES support -- this is what makes the "striped rgba" spot
 * check below meaningful instead of silently vacuous.
 *
 * Helper functions prefixed `bootstrapRealComponents` (unique-per-file convention, see other
 * EndToEnd Bootstrap-* files' docblocks).
 */

const BOOTSTRAP_REAL_COMPONENTS_CSS_PATH = __DIR__ . '/../Fixtures/bootstrap/bootstrap.min.css';
const BOOTSTRAP_REAL_COMPONENTS_HTML_PATH = __DIR__ . '/../Fixtures/bootstrap/components.html';

// See the class docblock: real Bootstrap's own row-striping mechanism (inset box-shadow +
// :nth-of-type) is not supported by this engine yet -- this is the documented compat shim.
const BOOTSTRAP_REAL_COMPONENTS_STRIPE_COMPAT_CSS = '.table-striped-compat tbody tr:nth-child(odd) > * { background-color: rgba(0, 0, 0, 0.05); }';

/** @return array{0: string, 1: \Pliego\RenderReport} */
function bootstrapRealComponentsRender(): array
{
    $css = file_get_contents(BOOTSTRAP_REAL_COMPONENTS_CSS_PATH);
    $html = file_get_contents(BOOTSTRAP_REAL_COMPONENTS_HTML_PATH);
    if ($css === false || $html === false) {
        throw new RuntimeException('Missing vendored fixture(s) for the real components E2E');
    }
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::make()
        ->stylesheet($css)
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
    // shim every run): 902 from parsing the real sheet alone (see BootstrapIngestionTest's golden)
    // plus 49 more that only surface once declarations are actually RESOLVED against real elements
    // (unresolved var() chains on empty custom properties like `--bs-btn-font-family: ;`, a known
    // IACVT gap already on record since M7; an `initial` keyword used as a box-shadow component,
    // which is genuinely invalid CSS that real browsers also drop -- see the report for the full
    // breakdown). A regression here (count changing) means either the vendored sheet changed or
    // something in parse/style/layout started handling one of these constructs differently.
    expect($report->warnings)->toHaveCount(951);
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
