<?php

// tests/EndToEnd/TailwindRealComponentsTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * M10-T3: the vendored REAL Tailwind v4 CLI build output
 * (tests/Fixtures/tailwind/tailwind-output.css) driving a small, deliberately UNAMBIGUOUS
 * component page (tests/Fixtures/tailwind/components.html: a single-color card with rounded
 * corners/shadow/typography, plus a bordered table) through the full Engine pipeline -- same
 * "does the real, unmodified upstream build actually work" proof as
 * BootstrapRealComponentsTest.php (M9-T2), for Tailwind instead of Bootstrap.
 *
 * components.html is a SEPARATE, narrower fixture from utilities.html (which
 * TailwindIngestionTest.php/TailwindPerfTest.php use) on purpose: utilities.html piles many
 * same-property utility classes onto shared elements to maximize warning-audit COVERAGE (e.g. one
 * div carries every bg-* scale at once, so only whichever rule happens to win the cascade tie
 * actually paints) -- great for an audit, useless for a deterministic color spot-check.
 * components.html instead gives each element exactly the classes it needs, so "this div is
 * bg-blue-500" is unambiguous.
 *
 * Helper functions prefixed `tailwindRealComponents` (unique-per-file convention, see the
 * Bootstrap-* E2E files' own docblocks).
 */

const TAILWIND_REAL_COMPONENTS_CSS_PATH = __DIR__ . '/../Fixtures/tailwind/tailwind-output.css';
const TAILWIND_REAL_COMPONENTS_HTML_PATH = __DIR__ . '/../Fixtures/tailwind/components.html';

/** @return array{0: string, 1: \Pliego\RenderReport} */
function tailwindRealComponentsRender(): array
{
    $css = file_get_contents(TAILWIND_REAL_COMPONENTS_CSS_PATH);
    $html = file_get_contents(TAILWIND_REAL_COMPONENTS_HTML_PATH);
    if ($css === false || $html === false) {
        throw new RuntimeException('Missing vendored fixture for the Tailwind real components E2E');
    }
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::make()->stylesheet($css)->render($html)->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

function tailwindRealComponentsFindGhostscriptBinary(): ?string
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

it('renders the real Tailwind components page as a valid PDF', function () {
    [$pdf, $report] = tailwindRealComponentsRender();

    expect($pdf)->toStartWith('%PDF-1.7');
    expect($report->pageCount)->toBeGreaterThanOrEqual(1);
});

/**
 * bg-blue-500 resolves through var(--color-blue-500) to oklch(62.3% .214 259.815) (the real
 * vendored theme value, leading-dot chroma notation and all -- see TailwindIngestionTest's own
 * theme-color assertion), which Color::oklchToSrgb() converts to #2b7fff = rgb(43, 127, 255) --
 * hand-verified against the css-color-4 matrices AND the independent `culori` npm library, see
 * ColorTest.php and the M10-T3 report for the full derivation. NOT the old Tailwind v3 hex
 * (#3b82f6) for this same utility class name -- v4's palette was recomputed directly in OKLCH.
 */
it('paints bg-blue-500 with its REAL oklch()-converted color (#2b7fff), not the old Tailwind v3 hex', function () {
    [$pdf, ] = tailwindRealComponentsRender();
    $blue500Fill = sprintf('%.3F %.3F %.3F rg', 0x2b / 255, 0x7f / 255, 0xff / 255);
    expect($pdf)->toContain($blue500Fill);
});

it('paints rounded corners (border-radius) as real bezier curve operators, not just straight rects', function () {
    [$pdf, ] = tailwindRealComponentsRender();
    expect(preg_match_all('/^[\d.\s-]+ c$/m', $pdf))->toBeGreaterThan(0);
});

/**
 * bg-slate-100 (thead row) via var(--color-slate-100) -> oklch(96.8% .007 247.896) -> #f1f5f9 =
 * rgb(241, 245, 249) -- a SECOND, visually distinct oklch()-resolved color spot check, deliberately
 * NOT border-related: this fixture's `border`/`border-slate-300` utilities rely on the all-sides
 * `border-width`/`border-style`/`border-color` shorthand form (2-part: side omitted, all 4 sides
 * at once), which is a PRE-EXISTING gap unrelated to M10-T3 (DeclarationParser::isBorderLonghand()
 * only recognizes the 4-part `border-{side}-{width,style,color}` longhands, see that method) --
 * already honestly captured by the audit's 'unsupported-property-other' category
 * (border-width/border-style/border-color all appear there), not something this task's scope
 * (@layer/oklch/audit) touches or fixes.
 */
it('paints bg-slate-100 (thead row) with its REAL oklch()-converted color (#f1f5f9)', function () {
    [$pdf, ] = tailwindRealComponentsRender();
    $slate100Fill = sprintf('%.3F %.3F %.3F rg', 0xf1 / 255, 0xf5 / 255, 0xf9 / 255);
    expect($pdf)->toContain($slate100Fill);
});

$gsBinary = tailwindRealComponentsFindGhostscriptBinary();

it('renders a PDF Ghostscript can rasterize without error (E2E render check)', function () use ($gsBinary) {
    if ($gsBinary === null) {
        return;
    }
    $gs = $gsBinary;
    [$pdf, ] = tailwindRealComponentsRender();

    $pdfPath = sys_get_temp_dir() . '/pliego-tailwind-real-components-e2e.pdf';
    file_put_contents($pdfPath, $pdf);

    $renderedPage = sys_get_temp_dir() . '/pliego-tailwind-real-components-e2e-page.png';
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
