<?php

// tests/EndToEnd/BootstrapPageTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * M9-T6: the closing E2E for the whole M9 milestone -- a REAL, FULL Bootstrap PAGE (as opposed to
 * BootstrapRealComponentsTest.php's isolated component showcase, M9-T2): a simplified navbar (no
 * JS -- `.navbar`/`.navbar-brand`/`.navbar-text` render fine as static markup, the collapse
 * behavior they'd need JS for is irrelevant to a PDF), a `.container`/`.row`/`.col` grid of six
 * `.card`s, a row of `.btn` variants, a run of `.badge`s, four `.alert` variants, a 20-row
 * `.table.table-striped` and a closing `.blockquote` + `.blockquote-footer` -- everything driven
 * through the public `Engine::bootstrap()` entry point (M9-T4), on real upstream Bootstrap 5.3.6
 * classes (resources/presets/bootstrap.min.css), long enough to force a real multi-page render
 * (unlike the single-page components showcase).
 *
 * tests/Fixtures/bootstrap/page.html carries the SAME documented `.table-striped-compat` shim as
 * components.html (see BootstrapRealComponentsTest's class docblock for why: real Bootstrap's own
 * striping mechanism, inset box-shadow + `:nth-of-type`, isn't supported yet).
 *
 * Warning count: NOT the full audit (BootstrapIngestionTest.php owns the "parse the WHOLE sheet"
 * number, 895; BootstrapRealComponentsTest.php owns the components-page number, 944) -- a
 * DIFFERENT document exercises a different mix of classes against the same 895 sheet-level parse
 * warnings (@media blocks, invalid selectors, unsupported properties -- all sheet-wide, independent
 * of which classes a given document actually uses) plus its OWN, DIFFERENT set of
 * resolved-against-real-elements warnings (unresolved var() chains etc. -- only fire when a
 * property is actually resolved against a real element, see BootstrapRealComponentsTest's own
 * docblock). The exact total below was observed from a real run against this exact fixture, not
 * guessed -- a regression here means either the vendored sheet or this fixture's markup changed.
 * "Spot categories" reuses the SAME regex-per-category classifier as BootstrapIngestionTest.php's
 * full audit (copied here rather than called cross-file -- see every other EndToEnd Bootstrap-*
 * file's own docblock on the "one process, unique-per-file helpers" convention) to pin a handful of
 * categories' exact counts plus the same 'other' safety net, without re-asserting the FULL
 * partition (that's what the ingestion golden is for).
 *
 * Helper functions prefixed `bootstrapPage` (unique-per-file convention).
 */

const BOOTSTRAP_PAGE_HTML_PATH = __DIR__ . '/../Fixtures/bootstrap/page.html';

/** @return array{0: string, 1: \Pliego\RenderReport} */
function bootstrapPageRender(): array
{
    $html = file_get_contents(BOOTSTRAP_PAGE_HTML_PATH);
    if ($html === false) {
        throw new RuntimeException('Missing vendored fixture for the full-page E2E');
    }
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::bootstrap()
        ->stylesheet('.table-striped-compat tbody tr:nth-child(odd) > * { background-color: rgba(0, 0, 0, 0.05); }')
        ->render($html)
        ->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

/** Same classifier as BootstrapIngestionTest.php's bootstrapIngestionCategorizeWarning() -- see
 * this file's class docblock for why it is copied, not shared, across test files. */
function bootstrapPageCategorizeWarning(string $warning): string
{
    return match (true) {
        (bool) preg_match('/@media rule blocks skipped/', $warning) => 'media-skipped',
        (bool) preg_match('/^Dynamic pseudo-class has no effect in paged media/', $warning) => 'pseudo-dynamic-permanent-exclusion',
        (bool) preg_match('/^Unknown pseudo-class:/', $warning) => 'pseudo-unknown',
        (bool) preg_match('/^Pseudo-class not supported yet:/', $warning) => 'pseudo-not-yet-supported',
        (bool) preg_match('/^:not\(\)/', $warning) => 'selector-not-argument-unsupported',
        (bool) preg_match('/^Invalid selector syntax:/', $warning) => 'invalid-selector',
        $warning === 'Unsupported property: transform' => 'unsupported-property-transform',
        (bool) preg_match('/^Unsupported property:/', $warning) => 'unsupported-property-other',
        (bool) preg_match('/^Unsupported keyword for/', $warning) => 'unsupported-keyword',
        (bool) preg_match('/^Unsupported length for/', $warning) => 'unsupported-length',
        (bool) preg_match('/^Unsupported color for/', $warning) => 'unsupported-color',
        (bool) preg_match('/^Invalid calc\(\) expression:/', $warning) => 'invalid-calc',
        (bool) preg_match('/box-shadow/i', $warning) => 'box-shadow-limitation',
        (bool) preg_match('/^Unsupported background/', $warning) => 'unsupported-background-value',
        (bool) preg_match('/^Unsupported border component/', $warning) => 'unsupported-border-component',
        (bool) preg_match('/^Unsupported shorthand for/', $warning) => 'unsupported-shorthand',
        (bool) preg_match('/^Unsupported text-decoration:/', $warning) => 'unsupported-text-decoration',
        (bool) preg_match('/^Unsupported overflow /', $warning) => 'overflow-approximated',
        (bool) preg_match('/^Unsupported font-weight:/', $warning) => 'unsupported-font-weight',
        (bool) preg_match('/^Unsupported vertical-align:/', $warning) => 'unsupported-vertical-align',
        (bool) preg_match('/^Unsupported text-align:/', $warning) => 'unsupported-text-align',
        (bool) preg_match('/^Unsupported line-height:/', $warning) => 'unsupported-line-height',
        // M9-T6: three warning SHAPES that BootstrapIngestionTest.php's classifier never needed --
        // that audit parses the sheet ALONE (no elements to resolve declarations against), so
        // var()-resolution-time and layout-time warnings never fire there. A full PAGE resolves
        // real declarations against real elements (Style\VarResolver, Style\StyleResolver,
        // Page\Paginator), surfacing these three genuinely new shapes:
        (bool) preg_match('/^Unknown custom property:/', $warning) => 'unresolved-custom-property',
        (bool) preg_match('/^Invalid value for .+ \(unresolved var\(\)\):/', $warning) => 'invalid-value-unresolved-var',
        $warning === 'atomic fragment taller than page, kept unsplit' => 'atomic-fragment-oversized',
        default => 'other',
    };
}

/**
 * @param list<string> $warnings
 * @return array<string, int>
 */
function bootstrapPageAuditWarnings(array $warnings): array
{
    $counts = [];
    foreach ($warnings as $warning) {
        $category = bootstrapPageCategorizeWarning($warning);
        $counts[$category] = ($counts[$category] ?? 0) + 1;
    }
    ksort($counts);
    return $counts;
}

function bootstrapPageFindGhostscriptBinary(): ?string
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

// --- Structural: valid, multipage PDF ------------------------------------------------------------

it('renders the full Bootstrap page as a valid, MULTI-page PDF (unlike the single-page components showcase)', function () {
    [$pdf, $report] = bootstrapPageRender();

    expect($pdf)->toStartWith('%PDF-1.7');
    // Deliberately NOT pinned to an exact page count -- any layout-affecting change (font metrics,
    // table row height, card padding) could shift it by one page without being a real regression;
    // what actually matters for this E2E is "long enough to exercise real pagination", which
    // toBeGreaterThan(1) proves without being brittle to exactly where page breaks fall.
    expect($report->pageCount)->toBeGreaterThan(1);
});

// --- Warning audit: pinned exact total + spot categories -------------------------------------------

it('produces a pinned, categorized warning count for this exact page (honest capability audit, subset of the full sheet audit)', function () {
    [, $report] = bootstrapPageRender();

    $byCategory = bootstrapPageAuditWarnings($report->warnings);

    // Same safety net as BootstrapIngestionTest.php's full audit: every warning this page produces
    // must land in a KNOWN category -- an 'other' bucket would mean some new warning shape isn't
    // accounted for yet.
    expect($byCategory)->not->toHaveKey('other');
    expect(array_sum($byCategory))->toBe(count($report->warnings));

    // Pinned exact total, observed from a real run against this exact fixture (not guessed) --
    // see this file's class docblock for why it differs from BootstrapRealComponentsTest's 944:
    // a bigger, more varied page (six card variants, five badge/button variants, four alert
    // variants, a navbar) resolves MANY more distinct declarations against real elements than the
    // components showcase does, each capable of its own var()-resolution-time warning.
    expect($report->warnings)->toHaveCount(1175);

    // Spot categories: a handful of the categories this page is EXPECTED to exercise, pinned
    // individually so a change in exactly WHICH kind of warning fires is caught, not just a total
    // that could hide two categories drifting in opposite directions.
    // -- sheet-level parse categories (same 895-warning source as BootstrapIngestionTest's golden,
    //    independent of which classes THIS page happens to use):
    expect($byCategory['pseudo-unknown'] ?? 0)->toBe(103);
    expect($byCategory['pseudo-dynamic-permanent-exclusion'] ?? 0)->toBe(123);
    expect($byCategory['unsupported-property-other'] ?? 0)->toBe(309);
    expect($byCategory['media-skipped'] ?? 0)->toBe(1);
    // -- resolved-against-real-elements categories, genuinely NEW to this page (the components
    //    showcase's own pinned 944 never broke these out): var() chains this page's own mix of
    //    card/button/navbar variants leaves unresolved, and one flex row of six cards too tall to
    //    split across a page break (Page\Paginator's documented atomic-fragment limitation, M5).
    expect($byCategory['unresolved-custom-property'] ?? 0)->toBe(30);
    expect($byCategory['invalid-value-unresolved-var'] ?? 0)->toBe(30);
    expect($byCategory['atomic-fragment-oversized'] ?? 0)->toBe(1);
});

// --- Key bytes: the visual signature this page's Bootstrap components must actually paint --------

it('paints .btn-primary with real Bootstrap\'s own blue (#0d6efd)', function () {
    [$pdf, ] = bootstrapPageRender();
    $btnPrimaryFill = sprintf('%.3F %.3F %.3F rg', 13 / 255, 110 / 255, 253 / 255);
    expect($pdf)->toContain($btnPrimaryFill);
});

it('paints .card rounded corners as real bezier curve operators', function () {
    [$pdf, ] = bootstrapPageRender();
    expect(preg_match_all('/^[\d.\s-]+ c$/m', $pdf))->toBeGreaterThan(10);
});

it('paints .alert backgrounds with their own distinct tint colors (primary/success/warning/danger are visually different)', function () {
    [$pdf, ] = bootstrapPageRender();
    // Bootstrap 5.3's own --bs-alert-bg for each variant (subtle tint, not the raw theme color).
    $alertPrimaryBg = sprintf('%.3F %.3F %.3F rg', 207 / 255, 226 / 255, 255 / 255);
    $alertSuccessBg = sprintf('%.3F %.3F %.3F rg', 209 / 255, 231 / 255, 221 / 255);
    $alertWarningBg = sprintf('%.3F %.3F %.3F rg', 255 / 255, 243 / 255, 205 / 255);
    $alertDangerBg = sprintf('%.3F %.3F %.3F rg', 248 / 255, 215 / 255, 218 / 255);
    expect($pdf)->toContain($alertPrimaryBg);
    expect($pdf)->toContain($alertSuccessBg);
    expect($pdf)->toContain($alertWarningBg);
    expect($pdf)->toContain($alertDangerBg);
});

$gsBinary = bootstrapPageFindGhostscriptBinary();

it('renders a PDF Ghostscript can rasterize without error, every page (E2E render check)', function () use ($gsBinary) {
    if ($gsBinary === null) {
        return;
    }
    $gs = $gsBinary;
    [$pdf, $report] = bootstrapPageRender();

    $pdfPath = sys_get_temp_dir() . '/pliego-bootstrap-page-e2e.pdf';
    file_put_contents($pdfPath, $pdf);

    // A literal filename per page via -dFirstPage/-dLastPage (NOT a `%d` placeholder) -- same
    // gotcha documented in tools/oracle/render-pliego.php's own docblock: PHP's exec() shells out
    // via cmd.exe on Windows, which treats a lone `%d` on the command line as an (undefined,
    // silently-blanked) batch parameter reference.
    for ($page = 1; $page <= $report->pageCount; $page++) {
        $renderedPage = sys_get_temp_dir() . "/pliego-bootstrap-page-e2e-$page.png";
        $cmd = sprintf(
            '%s -dNOPAUSE -dBATCH -dSAFER -sDEVICE=png16m -r72 -dFirstPage=%d -dLastPage=%d -sOutputFile=%s %s 2>&1',
            escapeshellarg($gs),
            $page,
            $page,
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
    }
    @unlink($pdfPath);
})->skip($gsBinary === null, 'Ghostscript not found on PATH in this environment.');
