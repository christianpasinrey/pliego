<?php

// tests/EndToEnd/TailwindPerfTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * M10-T3 perf: SAME budget/methodology as BootstrapPerfTest.php (M9-T2) -- "<=500ms first render"
 * -- measured through the REAL Engine pipeline (CSS parse -> Style -> Layout -> Paint -> Pdf), with
 * the full vendored Tailwind v4 build (tests/Fixtures/tailwind/tailwind-output.css, ~24KB
 * unminified) loaded via ->stylesheet(). "Cold" is the first Engine::render() call in this file's
 * fresh Pest process; "warm" is steady-state repeated renders in that SAME process afterwards (see
 * BootstrapPerfTest's own docblock for why "warm" here means throughput, not "instant second
 * call" -- Engine::render() builds a fresh StylesheetParser/StyleResolver every call, by design).
 */

const TAILWIND_PERF_CSS_PATH = __DIR__ . '/../Fixtures/tailwind/tailwind-output.css';

/** @return array{0: float, 1: \Pliego\RenderReport} elapsed seconds + report */
function tailwindPerfRenderOnce(string $css, string $html): array
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $start = microtime(true);
    $report = Engine::make()->stylesheet($css)->render($html)->toStream($stream);
    $elapsed = microtime(true) - $start;
    return [$elapsed, $report];
}

it('renders a Tailwind-flavored component with the FULL vendored build loaded, first render within the 500ms budget', function () {
    $css = file_get_contents(TAILWIND_PERF_CSS_PATH);
    expect($css)->not->toBeFalse();
    $html = '<body><div class="p-4 m-2 rounded-lg border border-slate-300 shadow-md bg-blue-500">'
        . '<h1 class="text-2xl font-bold text-white">Title</h1>'
        . '<p class="text-sm text-slate-100 leading-normal">Some text.</p></div></body>';

    [$coldElapsed, $coldReport] = tailwindPerfRenderOnce((string) $css, $html);

    $coldMs = $coldElapsed * 1000;
    expect($coldMs)
        ->toBeLessThanOrEqual(500.0)
        ->and($coldReport->pageCount)->toBe(1);
});

it('sustains steady-state renders of the full vendored build well within budget across repeated calls (same process)', function () {
    $css = (string) file_get_contents(TAILWIND_PERF_CSS_PATH);
    $html = '<body><div class="p-4 m-2 rounded-lg border border-slate-300 shadow-md bg-blue-500">'
        . '<h1 class="text-2xl font-bold text-white">Title</h1>'
        . '<p class="text-sm text-slate-100 leading-normal">Some text.</p></div></body>';

    $times = [];
    for ($i = 0; $i < 5; $i++) {
        [$elapsed, ] = tailwindPerfRenderOnce($css, $html);
        $times[] = $elapsed * 1000;
    }

    $avgMs = array_sum($times) / count($times);
    expect(max($times))->toBeLessThanOrEqual(500.0);
    expect($avgMs)->toBeLessThanOrEqual(500.0);
});
