<?php

// tests/EndToEnd/PageRuleTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * @page overrides Engine::margins() per side (M2-T6): asserted here through the geometry of the
 * first painted text fragment, read straight off the (uncompressed) PDF content stream — same
 * technique already used by RenderTest.php for background/border rects. body/p carry no CSS
 * margin of their own in these fixtures, so the first line's content-box x is exactly the
 * effective left margin, and its baseline shifts by exactly the effective top margin delta.
 *
 * @return array{0: float, 1: float} [x, baselineY] in pt, of the PDF's first Tj operator.
 */
function firstTextPosition(string $pdf): array
{
    preg_match('/rg ([\d.]+) ([\d.]+) Td/', $pdf, $m);
    expect($m)->not->toBeEmpty();
    return [(float) $m[1], (float) $m[2]];
}

it('uses Engine::margins() uniformly when there is no @page rule', function () {
    $path = sys_get_temp_dir() . '/pliego-page-rule-no-at-page.pdf';
    Engine::make()->render('<body><p>Texto</p></body>')->save($path);
    [$x] = firstTextPosition((string) file_get_contents($path));

    expect($x)->toBe(round(48.0 * 0.75, 2));
});

it('overrides Engine margins on every side when @page declares a uniform margin', function () {
    $pathDefault = sys_get_temp_dir() . '/pliego-page-rule-default.pdf';
    $pathOverride = sys_get_temp_dir() . '/pliego-page-rule-override.pdf';
    Engine::make()->render('<body><p>Texto</p></body>')->save($pathDefault);
    Engine::make()->stylesheet('@page { margin: 30px }')->render('<body><p>Texto</p></body>')->save($pathOverride);

    [$xDefault] = firstTextPosition((string) file_get_contents($pathDefault));
    [$xOverride] = firstTextPosition((string) file_get_contents($pathOverride));

    expect($xDefault)->toBe(round(48.0 * 0.75, 2));
    expect($xOverride)->toBe(round(30.0 * 0.75, 2));
});

it('falls back to Engine margins on sides not declared by a partial @page rule', function () {
    $pathDefault = sys_get_temp_dir() . '/pliego-page-rule-partial-default.pdf';
    $pathPartial = sys_get_temp_dir() . '/pliego-page-rule-partial.pdf';
    Engine::make()->render('<body><p>Texto</p></body>')->save($pathDefault);
    Engine::make()->stylesheet('@page { margin-top: 80px }')->render('<body><p>Texto</p></body>')->save($pathPartial);

    $pdfDefault = (string) file_get_contents($pathDefault);
    $pdfPartial = (string) file_get_contents($pathPartial);
    [$xDefault, $yDefault] = firstTextPosition($pdfDefault);
    [$xPartial, $yPartial] = firstTextPosition($pdfPartial);

    // margin-left wasn't declared in the partial @page -> still the Engine's uniform margin
    // (48px), same x as the no-@page render.
    expect($xPartial)->toBe($xDefault);
    // margin-top WAS declared (80px vs. the 48px default) -> the first baseline shifts down by
    // exactly (80-48)*0.75 pt (a smaller PDF-space y, since PDF y grows upward from the bottom).
    expect(round($yDefault - $yPartial, 2))->toBe(round((80.0 - 48.0) * 0.75, 2));
});

it('reduces the usable content width when @page declares a larger margin, forcing extra wraps', function () {
    // Wide margins shrink the content area (css-page-3): the same paragraph that fits on one
    // line at the Engine default (48px) must wrap into more lines once @page pushes the margin
    // up to 300px on every side (content width 793.7 - 600 = 193.7px vs. 697.7px by default).
    $css = 'p { font-size: 16px }';
    $html = '<body><p>Una linea de texto suficientemente larga para envolver bajo un margen grande.</p></body>';

    $pathDefault = sys_get_temp_dir() . '/pliego-page-rule-wrap-default.pdf';
    $pathNarrow = sys_get_temp_dir() . '/pliego-page-rule-wrap-narrow.pdf';
    Engine::make()->stylesheet($css)->render($html)->save($pathDefault);
    Engine::make()->stylesheet($css . ' @page { margin: 300px }')->render($html)->save($pathNarrow);

    $linesDefault = substr_count((string) file_get_contents($pathDefault), ' Tj ET');
    $linesNarrow = substr_count((string) file_get_contents($pathNarrow), ' Tj ET');

    expect($linesNarrow)->toBeGreaterThan($linesDefault);
});
