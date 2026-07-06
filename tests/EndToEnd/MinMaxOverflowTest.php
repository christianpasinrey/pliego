<?php

// tests/EndToEnd/MinMaxOverflowTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * M7-T5 acceptance (CSS 2.2 §10.4/§10.7, css-overflow-3 reducido): min/max-width/height clamp
 * and overflow:hidden clipping, exercised end to end so the byte-level PDF proof (clip path
 * present, content ops INSIDE the q/W n/Q scope) isn't only a Painter/PdfCanvas unit-test claim.
 *
 * Helper con nombre único (prefijo `minMaxOverflow`) por el mismo motivo documentado en
 * InlineBoxAndInlineBlockTest.php/BootstrapLikeTest.php: dos ficheros de test no pueden declarar
 * una función de nivel superior con el mismo nombre.
 *
 * @return array{0: string, 1: \Pliego\RenderReport}
 */
function minMaxOverflowRenderToPdfString(string $css, string $html): array
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::make()->stylesheet($css)->render($html)->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

it('clips overflowing content in BYTES when max-height + overflow:hidden combine: a clip path is emitted and the overflowing text op is painted INSIDE its q/Q scope', function () {
    $css = '.box { width: 120px; max-height: 30px; overflow: hidden; background-color: #eeeeee; }';
    $html = '<body><div class="box">AAAA<br>BBBB<br>CCCC<br>DDDD</div></body>';
    [$pdf, $report] = minMaxOverflowRenderToPdfString($css, $html);

    expect($pdf)->toStartWith('%PDF-1.7');
    expect($report->warnings)->toBe([]);

    // Clip path present: `re W n` (ISO 32000-1 §8.5.4) -- the box's OWN background fillRect is
    // NOT inside the clip (painted first, unclipped, same rect the clip will later use).
    expect($pdf)->toContain('re W n');
    // css-backgrounds-3: background painted BEFORE the clip scope opens.
    $bgFillPos = strpos($pdf, '0.933 0.933 0.933 rg'); // #eeeeee
    $clipOpenPos = strpos($pdf, 're W n');
    assert($bgFillPos !== false && $clipOpenPos !== false);
    expect($bgFillPos)->toBeLessThan($clipOpenPos);

    // The 4th line ("DDDD") overflows the clamped 30px-tall box (4 lines * ~19.2px line-height =
    // ~76.8px of natural content) -- its Tj op still exists in the byte stream (this engine does
    // not crop text runs themselves, only applies a PDF clip path around them) but must be
    // painted strictly BETWEEN the clip-open and the matching restoreClip() `Q`.
    $lastTjPos = strrpos($pdf, ' Tj ET');
    $closeQPos = strpos($pdf, "Q\n", $clipOpenPos);
    assert($lastTjPos !== false && $closeQPos !== false);
    expect($clipOpenPos)->toBeLessThan($lastTjPos);
    expect($lastTjPos)->toBeLessThan($closeQPos);
});

it('does not clip when overflow stays visible (default): no clip path emitted for a plain max-height box', function () {
    $css = '.box { width: 120px; max-height: 30px; background-color: #eeeeee; }';
    $html = '<body><div class="box">AAAA<br>BBBB<br>CCCC<br>DDDD</div></body>';
    [$pdf, $report] = minMaxOverflowRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect($pdf)->not->toContain('re W n');
});

it('clamps a declared width below min-width up to the min-width, end to end (background rect proves the used width)', function () {
    $css = '.box { width: 40px; min-width: 150px; background-color: #ff0000; }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf] = minMaxOverflowRenderToPdfString($css, $html);

    // width used = 150 (min-width wins over the smaller declared 40px) -- background rect width
    // in pt = 150 * 0.75 = 112.50.
    expect($pdf)->toContain('112.50 ');
});

it('coerces overflow:scroll to hidden end to end, surfaced as a warning in the RenderReport', function () {
    $css = '.box { overflow: scroll }';
    $html = '<body><div class="box">x</div></body>';
    [, $report] = minMaxOverflowRenderToPdfString($css, $html);
    expect($report->warnings)->not->toBeEmpty();
});
