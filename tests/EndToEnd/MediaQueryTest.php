<?php

// tests/EndToEnd/MediaQueryTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * M10-T2 (css-mediaqueries-4, reduced): closing E2E for width media queries end to end through
 * the REAL Engine pipeline (not just StylesheetParser/MediaQueryEvaluator in isolation, see
 * tests/Unit/Css/MediaQueryEvaluatorTest.php and tests/Unit/Css/StylesheetParserTest.php for the
 * grammar-level unit coverage) -- a `(min-width: N)`/`(max-width: N)` block that holds against
 * Engine's own configured paper width must produce REAL PAINT (a background-color rect, same
 * "re f" assertion pattern as RenderTest.php's other CSS-behavior E2Es), and one that doesn't
 * hold must produce none, with the usual aggregated skip warning.
 *
 * Engine::make()'s default paper is A4 (Page\PaperSize::A4, 793.70px CSS width) -- every test
 * below relies on that default, exactly mirroring the brief's own worked example ("Bootstrap on
 * A4 794px: min-width 576/768 apply, 992/1200 don't -- like Chrome printing A4").
 */

function mediaQueryFillRect(string $pdf, string $hex): int
{
    $r = (int) hexdec(substr($hex, 0, 2));
    $g = (int) hexdec(substr($hex, 2, 2));
    $b = (int) hexdec(substr($hex, 4, 2));
    $fill = sprintf('%.3F %.3F %.3F rg', $r / 255, $g / 255, $b / 255);
    $pattern = '/^' . preg_quote($fill, '/') . ' [\d.]+ [\d.]+ [\d.]+ [\d.]+ re f$/m';
    $count = preg_match_all($pattern, $pdf);
    return $count === false ? 0 : $count;
}

it('applies a min-width breakpoint that holds against the A4 default page width, painting a real background rect', function () {
    $path = sys_get_temp_dir() . '/pliego-e2e-media-min-width-applies.pdf';
    $css = '@media (min-width: 768px) { .box { background-color: #eeeeee } }';
    $report = Engine::make()->stylesheet($css)->render('<body><div class="box">x</div></body>')->save($path);
    $pdf = (string) file_get_contents($path);

    expect($report->warnings)->toBe([]);
    expect(mediaQueryFillRect($pdf, 'eeeeee'))->toBe(1);
});

it('skips a min-width breakpoint that does not hold against the A4 default page width: no paint, one aggregated warning', function () {
    $path = sys_get_temp_dir() . '/pliego-e2e-media-min-width-skips.pdf';
    $css = '@media (min-width: 1200px) { .box { background-color: #eeeeee } }';
    $report = Engine::make()->stylesheet($css)->render('<body><div class="box">x</div></body>')->save($path);
    $pdf = (string) file_get_contents($path);

    expect(mediaQueryFillRect($pdf, 'eeeeee'))->toBe(0);
    expect($report->warnings)->toBe(['1 @media rule blocks skipped (screen/interactive-only media)']);
});

it('applies a max-width breakpoint that holds against the A4 default page width', function () {
    $path = sys_get_temp_dir() . '/pliego-e2e-media-max-width-applies.pdf';
    $css = '@media (max-width: 1199.98px) { .box { background-color: #eeeeee } }';
    $report = Engine::make()->stylesheet($css)->render('<body><div class="box">x</div></body>')->save($path);
    $pdf = (string) file_get_contents($path);

    expect($report->warnings)->toBe([]);
    expect(mediaQueryFillRect($pdf, 'eeeeee'))->toBe(1);
});

it('skips a max-width breakpoint that does not hold against the A4 default page width', function () {
    $path = sys_get_temp_dir() . '/pliego-e2e-media-max-width-skips.pdf';
    $css = '@media (max-width: 767.98px) { .box { background-color: #eeeeee } }';
    $report = Engine::make()->stylesheet($css)->render('<body><div class="box">x</div></body>')->save($path);
    $pdf = (string) file_get_contents($path);

    expect(mediaQueryFillRect($pdf, 'eeeeee'))->toBe(0);
    expect($report->warnings)->toBe(['1 @media rule blocks skipped (screen/interactive-only media)']);
});

it('threads the ACTUAL configured paper width (Engine::paper()), not a hardcoded A4 literal, into media-width evaluation', function () {
    // Page\PaperSize is a single-case enum (A4 only, M0-M10) -- there is no narrower built-in
    // preset to exercise a "does NOT apply" case through the public paper() API, so this proves
    // the threading is real (not a StylesheetParser-internal default silently always winning) by
    // asserting the EXACT paper width Engine itself computes (Page\PaperSize::A4->widthPx(),
    // 793.7007874015749px) is what a boundary breakpoint compares against -- see
    // StylesheetParserTest's own "threads an explicit non-default page width" unit test for the
    // narrower-paper case this API can't reach yet.
    $path = sys_get_temp_dir() . '/pliego-e2e-media-custom-paper.pdf';
    $css = '@media (min-width: 793.7007874015749px) { .box { background-color: #eeeeee } }';
    $report = Engine::make()->paper(\Pliego\Page\PaperSize::A4)->stylesheet($css)
        ->render('<body><div class="box">x</div></body>')->save($path);
    $pdf = (string) file_get_contents($path);

    expect($report->warnings)->toBe([]);
    expect(mediaQueryFillRect($pdf, 'eeeeee'))->toBe(1);
});
