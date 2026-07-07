<?php

// tests/EndToEnd/OracleFixturesSmokeTest.php
declare(strict_types=1);

use Pliego\Engine;
use PliegoOracle\FixtureHtml;

/**
 * M9-T5 (+M9-T6's fixture 07): the tools/oracle/fixtures/*.html documents (shared verbatim with Chrome via
 * render-chrome.mjs) must at minimum render through Engine without throwing and produce exactly
 * one page -- the oracle's compare.php aligns pliego's raster against a single Chrome full-page
 * screenshot, so a fixture that silently paginates to 2 pages would desync the whole comparison
 * (see PixelDiff's docblock on why only the *height* is normalized, not "which page").
 *
 * Deliberately NOT threshold-checking pixel fidelity here -- that lives only in the oracle's own
 * run (composer oracle / .github/workflows/oracle.yml, both requiring node+Chromium+Ghostscript),
 * never in `pest`: this file's whole point is keeping the ordinary CI PHP job hermetic while still
 * catching "a fixture literally crashes the engine" or "a fixture now needs 2 pages" during normal
 * development, before anyone even runs the oracle.
 *
 * Uses FixtureHtml::extractCss() + ->stylesheet(), exactly like render-pliego.php -- see that
 * class's docblock for why passing the fixture's raw HTML straight to ->render() with no
 * ->stylesheet() call silently renders everything with UA defaults instead (a real bug this very
 * test file caught during M9-T5 calibration, before that class existed). M9-T6's fixture 07 is the
 * first to need extractCss() rather than extractInlineCss() alone -- it pulls the real vendored
 * Bootstrap sheet in via `<link rel="stylesheet">` rather than inlining 232KB into a <style> block
 * (see FixtureHtml::extractCss()'s own docblock).
 */

/** @return array{0: string, 1: \Pliego\RenderReport} */
function oracleSmokeRender(string $fixturePath): array
{
    $html = (string) file_get_contents($fixturePath);
    $css = FixtureHtml::extractCss($html, dirname($fixturePath));
    $htmlStripped = FixtureHtml::stripStyleTags($html);
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::make()->basePath(dirname($fixturePath))->stylesheet($css)->render($htmlStripped)->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

const ORACLE_FIXTURE_NAMES = [
    '01-typography.html',
    '02-table-striped.html',
    '03-card-btn-badge.html',
    '04-flex-layout.html',
    '05-blockquote-pre.html',
    '06-gradients-shadows.html',
    '07-bootstrap-page.html',
];

it('finds exactly the oracle fixtures this task calibrated (no stray/missing files)', function () {
    $fixtures = glob(__DIR__ . '/../../tools/oracle/fixtures/*.html');
    $names = array_map('basename', $fixtures !== false ? $fixtures : []);
    sort($names);
    $expected = ORACLE_FIXTURE_NAMES;
    sort($expected);

    expect($names)->toBe($expected);
});

it('renders oracle fixture through Engine without throwing, onto exactly one page', function (string $fixtureName) {
    $fixturePath = __DIR__ . '/../../tools/oracle/fixtures/' . $fixtureName;
    [$pdf, $report] = oracleSmokeRender($fixturePath);

    expect($pdf)->toStartWith('%PDF-1.7');
    // Oracle's compare.php aligns pliego's raster against a SINGLE Chrome full-page screenshot
    // (see this file's top docblock) -- a fixture that silently grew to 2 pages would desync it.
    expect($report->pageCount)->toBe(1);
})->with(ORACLE_FIXTURE_NAMES);
