<?php

// tests/EndToEnd/ColorOpacityTest.php
declare(strict_types=1);

use Pliego\Engine;

/** @return array{0: string, 1: \Pliego\RenderReport} */
function renderToPdfString(string $css, string $html): array
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::make()->stylesheet($css)->render($html)->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

// M6-T5: full color syntax + alpha rendering via ExtGState, end to end through Engine — no
// Ghostscript in this environment (checked: `gs --version` unavailable), so these are
// STRUCTURAL assertions (valid PDF header/xref via existing Engine invariants, plus the
// specific ExtGState/gs op contract) rather than a rasterized visual diff.

it('renders an rgba() overlay as a valid PDF with a /GSn gs and matching /ca ExtGState', function () {
    [$pdf, $report] = renderToPdfString(
        'div { background-color: rgba(255, 0, 0, 0.5); width: 100px; height: 50px }',
        '<body><div>x</div></body>',
    );
    expect($pdf)->toStartWith('%PDF-1.7');
    expect($report->warnings)->toBeEmpty();
    expect($pdf)->toContain('/GS1 gs')->toContain('/ca 0.500')->toContain('/Type /ExtGState');
});

it('renders hsl()/hsla() colors end to end with zero warnings', function () {
    [$pdf, $report] = renderToPdfString(
        'p { color: hsl(0, 100%, 50%); background-color: hsla(240, 100%, 50%, 0.5) }',
        '<body><p>x</p></body>',
    );
    expect($pdf)->toStartWith('%PDF-1.7');
    expect($report->warnings)->toBeEmpty();
    expect($pdf)->toContain('1.000 0.000 0.000 rg'); // color: red text
});

it('renders a rebeccapurple background end to end (named color spot check)', function () {
    [$pdf, $report] = renderToPdfString(
        'div { background-color: rebeccapurple; width: 10px; height: 10px }',
        '<body><div>x</div></body>',
    );
    expect($report->warnings)->toBeEmpty();
    // #663399 = rgb(102, 51, 153) -> 102/255=0.400, 51/255=0.200, 153/255=0.600
    expect($pdf)->toContain('0.400 0.200 0.600 rg');
});

it('paints nothing for a transparent background (no fillRect op), end to end', function () {
    [$pdf, $report] = renderToPdfString(
        'div { background-color: transparent; border: 2px solid #000; width: 50px; height: 50px }',
        '<body><div>x</div></body>',
    );
    expect($report->warnings)->toBeEmpty();
    // The border (opaque black) still paints; the transparent background must not add any
    // extra `re f` beyond the 4 border-side rects.
    expect(substr_count($pdf, 're f'))->toBe(4);
});

it('resolves background-color:currentColor to the element text color, end to end', function () {
    [$pdf, $report] = renderToPdfString(
        'p { color: #00ff00; background-color: currentColor; width: 20px; height: 20px }',
        '<body><p>x</p></body>',
    );
    expect($report->warnings)->toBeEmpty();
    expect(substr_count($pdf, '0.000 1.000 0.000 rg'))->toBe(2); // background fill + text fill, same green
});

it('combines opacity:0.5 over an rgba(...,0.5) background into effective /ca 0.250, end to end', function () {
    [$pdf, $report] = renderToPdfString(
        'div { background-color: rgba(0, 0, 255, 0.5); opacity: 0.5; width: 40px; height: 40px }',
        '<body><div>x</div></body>',
    );
    expect($report->warnings)->toBeEmpty();
    expect($pdf)->toContain('/ca 0.250');
});
