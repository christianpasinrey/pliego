<?php

// tests/EndToEnd/BootstrapIngestionTest.php
declare(strict_types=1);

use Pliego\Css\StylesheetParser;

/**
 * M9-T2: the closing E2E for "does pliego actually swallow the REAL bootstrap.min.css". Two
 * separate concerns, deliberately split into their own `it()`s:
 *
 *  1. Ingestion: parse the ENTIRE vendored sheet (resources/presets/bootstrap.min.css, MIT,
 *     v5.3.6 -- see LICENSE-bootstrap.txt alongside it for provenance; M9-T4 relocated it here
 *     from tests/Fixtures/bootstrap/ so it also doubles as Engine::bootstrap()'s runtime asset,
 *     see that method's docblock) end to end through
 *     StylesheetParser -- no exception, bounded time (a real timeout/hang would mean some
 *     brace-matcher or regex went quadratic on ~230KB of real-world minified CSS, the whole point
 *     of testing against something bigger than our own hand-written fixtures).
 *  2. Warning audit: every one of the ~1066 warnings the real sheet produces (M10-T2: min-width/
 *     max-width media features now evaluated for real against the page's CSS-px width, see
 *     Css\MediaQueryEvaluator -- 30 of bootstrap.min.css's 108 non-print/all @media blocks now
 *     apply at A4 width instead of being uniformly skipped, surfacing genuinely new instances of
 *     already-documented gaps from their real declarations) gets bucketed by a
 *     regex-per-category classifier (bootstrapIngestionCategorizeWarning() below) and the resulting
 *     category => count table is pinned as a golden snapshot, WITH a total -- this is the "honest
 *     capability audit" the milestone asks for: a stable, at-a-glance map of exactly what real
 *     Bootstrap throws at this engine and how much of it lands in each documented limitation
 *     bucket, not just a raw "902 warnings, trust me". A non-empty 'other' bucket would mean some
 *     warning shape isn't accounted for by any category yet -- asserted to be empty so this table
 *     stays a COMPLETE partition of every warning produced, not a lossy sample.
 *
 * Helper functions are prefixed `bootstrapIngestion` (same "unique per file" convention as
 * BootstrapComponentsTest/BootstrapLookTest -- Pest loads every test file's top-level functions
 * into ONE process, a name clash would fatal).
 */

const BOOTSTRAP_INGESTION_CSS_PATH = __DIR__ . '/../../resources/presets/bootstrap.min.css';

function bootstrapIngestionCss(): string
{
    $css = file_get_contents(BOOTSTRAP_INGESTION_CSS_PATH);
    if ($css === false) {
        throw new RuntimeException('Missing vendored fixture: ' . BOOTSTRAP_INGESTION_CSS_PATH);
    }
    return $css;
}

/**
 * Regex-per-category classifier for the warning audit (see class docblock). Order matters -- the
 * FIRST matching arm wins, most specific first (e.g. the exact "transform" property gets pulled out
 * of the generic "Unsupported property: ..." bucket BEFORE the generic arm would otherwise catch
 * it, per the milestone brief's explicit "N transform" pin). `default => 'other'` is the safety net
 * that the ingestion test below asserts never fires for the real sheet.
 */
function bootstrapIngestionCategorizeWarning(string $warning): string
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
        // M9-T3: the "Gradient color-stop alpha not supported" warning (M8-T3) is GONE -- rgba()
        // gradient stops are now supported via /SMask /Luminosity (see Pdf\PdfCanvas::
        // paintGradient()), so this category no longer exists; removed rather than left as dead
        // code that would never match again.
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
        default => 'other',
    };
}

/**
 * @param list<string> $warnings
 * @return array<string, int>
 */
function bootstrapIngestionAuditWarnings(array $warnings): array
{
    $counts = [];
    foreach ($warnings as $warning) {
        $category = bootstrapIngestionCategorizeWarning($warning);
        $counts[$category] = ($counts[$category] ?? 0) + 1;
    }
    ksort($counts);
    return $counts;
}

function assertMatchesBootstrapIngestionGolden(string $name, mixed $dump): void
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
    $golden = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    expect($dump)->toBe($golden);
}

// --- Ingestion: the whole real sheet, no exception, bounded time -------------------------------

it('parses the ENTIRE real vendored bootstrap.min.css with no exception and in bounded time', function () {
    $css = bootstrapIngestionCss();
    // Sanity on the fixture itself, not just the parse -- if this ever shrinks drastically the
    // vendored file was probably swapped for something else by accident.
    expect(strlen($css))->toBeGreaterThan(200_000);
    expect($css)->toStartWith('@charset "UTF-8";/*!');
    expect($css)->toContain('Bootstrap');
    expect($css)->toContain('Licensed under MIT');

    $start = microtime(true);
    $result = new StylesheetParser()->parse($css);
    $elapsedMs = (microtime(true) - $start) * 1000;

    // Bounded, not tight -- this is a "did something go quadratic" tripwire, not a perf budget
    // (that lives in BootstrapPerfTest.php, against a full Engine::render(), a stricter number).
    expect($elapsedMs)->toBeLessThan(3000.0);

    // A real, substantial stylesheet actually got parsed -- not silently emptied by some upstream
    // extraction bug swallowing everything.
    expect($result->rules)->not->toBeEmpty();
    expect(count($result->rules))->toBeGreaterThan(500);
});

// --- Warning audit: full categorized breakdown, pinned as a golden snapshot with a total --------

it('audits every warning the real sheet produces into a categorized, total-pinned breakdown (honest capability audit)', function () {
    $result = new StylesheetParser()->parse(bootstrapIngestionCss());

    $byCategory = bootstrapIngestionAuditWarnings($result->warnings);

    // The classifier's `default => 'other'` arm must never fire for the real sheet -- if it does,
    // some new warning SHAPE showed up that isn't accounted for by any category yet, and the table
    // below would silently stop being a COMPLETE partition of the warnings.
    expect($byCategory)->not->toHaveKey('other');

    $sumOfCategories = array_sum($byCategory);
    expect($sumOfCategories)->toBe(count($result->warnings));

    assertMatchesBootstrapIngestionGolden('bootstrap-warning-audit', [
        'total' => count($result->warnings),
        'byCategory' => $byCategory,
    ]);
});

it('confirms transition/animation are silent (no warning at all), per the M9 adjudication -- not lumped into unsupported-property', function () {
    $result = new StylesheetParser()->parse(bootstrapIngestionCss());
    foreach ($result->warnings as $warning) {
        expect($warning)->not->toContain('Unsupported property: transition');
        expect($warning)->not->toContain('Unsupported property: -webkit-transition');
        expect($warning)->not->toContain('Unsupported property: -moz-transition');
        expect($warning)->not->toContain('Unsupported property: animation');
    }
});

it('aggregates every skipped @media block into exactly ONE warning, matching the real sheet\'s actual block count', function () {
    $result = new StylesheetParser()->parse(bootstrapIngestionCss());
    $mediaWarnings = array_values(array_filter(
        $result->warnings,
        static fn(string $w): bool => str_contains($w, '@media rule blocks skipped'),
    ));
    expect($mediaWarnings)->toHaveCount(1);
    expect($mediaWarnings[0])->toMatch('/^\d+ @media rule blocks skipped \(screen\/interactive-only media\)$/');
});
