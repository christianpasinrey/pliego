<?php

// tests/EndToEnd/TailwindPageTest.php
declare(strict_types=1);

use Pliego\Engine;
use PliegoOracle\FixtureHtml;

/**
 * M10-T5: the closing Tailwind E2E for M10 -- a REAL, FULL Tailwind v4.3.2 PAGE (as opposed to
 * TailwindRealComponentsTest.php's isolated card+table showcase, M10-T3, or
 * TailwindPlaygroundSampleTest.php's hand-curated slim CSS slice, M10-T4): a header bar, a row of
 * three rounded/shadowed stat cards, a row of three colored "buttons", and a striped table --
 * everything driven through `Engine::make()->stylesheet(<the FULL, unmodified vendored v4.3.2
 * build>)`, exactly the tools/oracle/fixtures/08-tailwind-page.html document Chrome renders too
 * (oracle's 8th fixture, see that file's own comment for the exact classes used and the landmines
 * deliberately avoided: no `hover:`/`odd:`/`even:` variants, no `w-1/2` fractions, no grid, no
 * all-sides `border`/`border-{color}` shorthand).
 *
 * Warning count: NOT the sheet-level parse-only audit (TailwindIngestionTest.php owns that number,
 * 104, "parse the WHOLE 1030-line build, no elements to resolve against") -- linking the SAME full
 * vendored sheet here via a real page produces those same 104 sheet-level warnings (they come from
 * the stylesheet's own @layer/@property/@media/pseudo-element/color-mix()/border-shorthand content,
 * independent of which classes any given document actually uses) PLUS this page's own
 * resolved-against-real-elements warnings: 145 total, observed from a real run against this exact
 * fixture (not guessed) -- a regression here means either the vendored sheet or this fixture's
 * markup changed. The extra 41 break down as +2 `unsupported-property-other`, +9
 * `unsupported-font-weight` (computed font-weight values -- `font-bold`/`font-semibold` resolve
 * fine, but preflight's own `b, strong { font-weight: bolder }`/inherited-`inherit` rules only ever
 * fire once real elements exist to resolve them against) and two genuinely NEW category shapes the
 * sheet-only audit never exercises at all (no elements => no declarations ever resolved against a
 * real box): `box-shadow-limitation` (this page's three `shadow-md`/`shadow-sm` cards/buttons, each
 * a real multi-layer `--tw-shadow` chain -- see DeclarationParser's own "Multiple box-shadows not
 * supported" message, same pre-existing M8 limitation TailwindPlaygroundSampleTest.php's single
 * `shadow-md` div already pins) and `calc-bare-number` (Css\Value\CalcParser's "calc() must
 * resolve to a length or percentage, got a bare number" -- Tailwind's real `--text-*--line-height`
 * theme vars are unitless ratios like `calc(1.25 / 1)`, only resolved once `text-xs`/`text-sm`/
 * `text-2xl` etc. apply to a real element with a real `line-height: var(--tw-leading, ...)` chain;
 * the same gap TailwindPlaygroundSampleTest.php's curated CSS sidesteps by omitting the
 * `--text-*--line-height` companion vars entirely -- this page's fixture links the REAL,
 * unmodified build, so it hits the gap honestly instead of curating around it).
 *
 * Helper functions prefixed `tailwindPage` (unique-per-file convention, see every Bootstrap- and
 * Tailwind-prefixed E2E file's own docblock on "one process, unique-per-file helpers").
 */

const TAILWIND_PAGE_HTML_PATH = __DIR__ . '/../../tools/oracle/fixtures/08-tailwind-page.html';

/** @return array{0: string, 1: \Pliego\RenderReport} */
function tailwindPageRender(): array
{
    $html = file_get_contents(TAILWIND_PAGE_HTML_PATH);
    if ($html === false) {
        throw new RuntimeException('Missing oracle fixture 08 for the Tailwind full-page E2E');
    }
    $fixturesDir = dirname(TAILWIND_PAGE_HTML_PATH);
    // Same extraction convention as OracleFixturesSmokeTest.php/render-pliego.php: the fixture's
    // own <link rel="stylesheet"> (the FULL, unmodified vendored v4.3.2 build) plus its inline
    // <style> block (DejaVu @font-face + @page/body reset), in author order.
    $css = FixtureHtml::extractCss($html, $fixturesDir);
    $htmlStripped = FixtureHtml::stripStyleTags($html);

    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::make()->basePath($fixturesDir)->stylesheet($css)->render($htmlStripped)->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

/** Same category SHAPES as TailwindIngestionTest.php's tailwindIngestionCategorizeWarning(), plus
 * two genuinely new ones (box-shadow-limitation, calc-bare-number) that only fire once real
 * elements exist to resolve declarations against -- see this file's class docblock. Copied, not
 * shared cross-file, per this codebase's own "one process, unique-per-file helpers" convention. */
function tailwindPageCategorizeWarning(string $warning): string
{
    return match (true) {
        (bool) preg_match('/@media rule blocks skipped/', $warning) => 'media-skipped',
        (bool) preg_match('/@property rule blocks skipped/', $warning) => 'property-skipped',
        (bool) preg_match('/^layered !important uses simplified precedence/', $warning) => 'layer-important-simplified',
        (bool) preg_match('/^color-mix\(\) is not supported/', $warning) => 'color-mix-fallback',
        (bool) preg_match('/^Invalid selector syntax:/', $warning) => 'invalid-selector',
        (bool) preg_match('/^Unknown pseudo-class:/', $warning) => 'pseudo-unknown',
        (bool) preg_match('/^Multiple box-shadows not supported/', $warning) => 'box-shadow-limitation',
        (bool) preg_match('/^calc\(\) must resolve to a length or percentage/', $warning) => 'calc-bare-number',
        (bool) preg_match('/^Unsupported color for/', $warning) => 'unsupported-color',
        (bool) preg_match('/^Unsupported length for/', $warning) => 'unsupported-length',
        (bool) preg_match('/^Unsupported font-weight:/', $warning) => 'unsupported-font-weight',
        (bool) preg_match('/^Unsupported vertical-align:/', $warning) => 'unsupported-vertical-align',
        (bool) preg_match('/^Unsupported text-align:/', $warning) => 'unsupported-text-align',
        (bool) preg_match('/^Unsupported text-decoration:/', $warning) => 'unsupported-text-decoration',
        (bool) preg_match('/^Unsupported keyword for/', $warning) => 'unsupported-keyword',
        (bool) preg_match('/^Unsupported shorthand for/', $warning) => 'unsupported-shorthand',
        (bool) preg_match('/^Invalid calc\(\) expression:/', $warning) => 'invalid-calc',
        (bool) preg_match('/^Unsupported property:/', $warning) => 'unsupported-property-other',
        default => 'other',
    };
}

/**
 * @param list<string> $warnings
 * @return array<string, int>
 */
function tailwindPageAuditWarnings(array $warnings): array
{
    $counts = [];
    foreach ($warnings as $warning) {
        $category = tailwindPageCategorizeWarning($warning);
        $counts[$category] = ($counts[$category] ?? 0) + 1;
    }
    ksort($counts);
    return $counts;
}

function tailwindPageFindGhostscriptBinary(): ?string
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

// --- Structural: valid, single-page PDF (the oracle compares against a single Chrome screenshot) --

it('renders the full Tailwind page as a valid, single-page PDF', function () {
    [$pdf, $report] = tailwindPageRender();

    expect($pdf)->toStartWith('%PDF-1.7');
    // Same "exactly 1" requirement as OracleFixturesSmokeTest.php -- compare.php aligns pliego's
    // raster against a SINGLE Chrome full-page screenshot (see PixelDiff's own docblock).
    expect($report->pageCount)->toBe(1);
});

// --- Warning audit: pinned exact total + full categorized breakdown ------------------------------

it('produces a pinned, categorized warning count for this exact page (honest capability audit, subset of the full sheet audit)', function () {
    [, $report] = tailwindPageRender();

    $byCategory = tailwindPageAuditWarnings($report->warnings);

    // Same safety net as TailwindIngestionTest.php's full audit: every warning this page produces
    // must land in a KNOWN category -- an 'other' bucket would mean some new warning shape isn't
    // accounted for yet.
    expect($byCategory)->not->toHaveKey('other');
    expect(array_sum($byCategory))->toBe(count($report->warnings));

    // Pinned exact total, observed from a real run against this exact fixture (not guessed) --
    // see this file's class docblock for the sheet-level (104) vs resolved-against-elements (+41)
    // breakdown.
    expect($report->warnings)->toHaveCount(145);

    // Sheet-level categories, IDENTICAL to TailwindIngestionTest.php's own golden numbers (none of
    // this page's classes touch @media/@property/@layer !important/color-mix()) -- unaffected by
    // which classes this specific document happens to use.
    expect($byCategory['media-skipped'] ?? 0)->toBe(1);
    expect($byCategory['property-skipped'] ?? 0)->toBe(1);
    expect($byCategory['layer-important-simplified'] ?? 0)->toBe(1);
    expect($byCategory['color-mix-fallback'] ?? 0)->toBe(1);
    expect($byCategory['invalid-selector'] ?? 0)->toBe(37);
    expect($byCategory['pseudo-unknown'] ?? 0)->toBe(6);
    expect($byCategory['unsupported-color'] ?? 0)->toBe(3);
    expect($byCategory['unsupported-length'] ?? 0)->toBe(6);
    expect($byCategory['unsupported-keyword'] ?? 0)->toBe(5);
    expect($byCategory['unsupported-shorthand'] ?? 0)->toBe(1);
    expect($byCategory['invalid-calc'] ?? 0)->toBe(1);
    expect($byCategory['unsupported-text-align'] ?? 0)->toBe(1);
    expect($byCategory['unsupported-text-decoration'] ?? 0)->toBe(2);
    expect($byCategory['unsupported-vertical-align'] ?? 0)->toBe(2);

    // Resolved-against-real-elements categories: DIFFERENT from the sheet-only audit's numbers
    // (this page's own mix of real elements resolves declarations the ingestion test never does).
    expect($byCategory['unsupported-property-other'] ?? 0)->toBe(35);
    expect($byCategory['unsupported-font-weight'] ?? 0)->toBe(12);
    // Two categories the sheet-only audit CANNOT produce at all (no elements to resolve against) --
    // see this file's class docblock.
    expect($byCategory['box-shadow-limitation'] ?? 0)->toBe(6);
    expect($byCategory['calc-bare-number'] ?? 0)->toBe(24);
});

// --- Key bytes: the visual signature this page's Tailwind utilities must actually paint ----------

/**
 * bg-blue-500 resolves through var(--color-blue-500) to oklch(62.3% .214 259.815), which
 * Color::oklchToSrgb() converts to #2b7fff = rgb(43, 127, 255) -- the REAL Tailwind v4 value
 * (hand-verified against css-color-4 and the independent `culori` npm library in M10-T3), NOT the
 * old Tailwind v3 hex (#3b82f6) for this same utility class name -- same spot check as
 * TailwindRealComponentsTest.php/TailwindPlaygroundSampleTest.php, now against the "Primary" button
 * in this full page.
 */
it('paints bg-blue-500 (the "Primary" button) with its REAL oklch()-converted color (#2b7fff), not the old Tailwind v3 hex', function () {
    [$pdf, ] = tailwindPageRender();
    $blue500Fill = sprintf('%.3F %.3F %.3F rg', 0x2b / 255, 0x7f / 255, 0xff / 255);
    expect($pdf)->toContain($blue500Fill);
});

it('paints the three stat cards\' rounded-lg corners as real bezier curve operators', function () {
    [$pdf, ] = tailwindPageRender();
    expect(preg_match_all('/^[\d.\s-]+ c$/m', $pdf))->toBeGreaterThan(10);
});

/**
 * This page's three shadow-md/shadow-sm elements each trigger the pre-existing "Multiple
 * box-shadows not supported (using the first only)" limitation (M8) -- confirms the shadow LAYERS
 * this fixture's cards/buttons declare actually reach DeclarationParser (not silently dropped
 * earlier in the pipeline), same shape TailwindPlaygroundSampleTest.php's own single shadow-md
 * pins as its one documented warning.
 */
it('surfaces the multiple-box-shadow-layers limitation for every shadow-md/shadow-sm element on the page', function () {
    [, $report] = tailwindPageRender();
    $shadowWarnings = array_values(array_filter(
        $report->warnings,
        static fn(string $w): bool => str_starts_with($w, 'Multiple box-shadows not supported'),
    ));
    expect($shadowWarnings)->toHaveCount(6);
});

/**
 * tracking-wide (letter-spacing: 0.025em, a non-zero value) forces PdfCanvas::spacedShowOp()'s
 * per-glyph-adjusted `[...] TJ` array instead of the plain `Tj` operator (ISO 32000-1 §9.4.3, see
 * PdfCanvas's own docblock) -- this page's header title and every card/table label carry
 * tracking-wide, so at least one real `] TJ` run must reach the content stream.
 */
it('paints tracking-wide text using the per-glyph-adjusted TJ array operator', function () {
    [$pdf, ] = tailwindPageRender();
    expect(substr_count($pdf, '] TJ'))->toBeGreaterThan(0);
});

$gsBinary = tailwindPageFindGhostscriptBinary();

it('renders a PDF Ghostscript can rasterize without error (E2E render check)', function () use ($gsBinary) {
    if ($gsBinary === null) {
        return;
    }
    $gs = $gsBinary;
    [$pdf, ] = tailwindPageRender();

    $pdfPath = sys_get_temp_dir() . '/pliego-tailwind-page-e2e.pdf';
    file_put_contents($pdfPath, $pdf);

    $renderedPage = sys_get_temp_dir() . '/pliego-tailwind-page-e2e-page.png';
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
