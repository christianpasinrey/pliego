<?php

// tests/EndToEnd/BorderRadiusTest.php
declare(strict_types=1);

use Pliego\Engine;
use Pliego\Layout\TextMeasurer;
use Pliego\Text\FontCatalog;

/**
 * M8-T2 acceptance (css-backgrounds-3 §5 reducido): border-radius end to end through the real
 * Engine pipeline -- rounded background (Bézier fillRoundedRect), annular border ring (f*),
 * mixed-widths approximation + warning, and rounded overflow:hidden clip. Byte-level PDF proof,
 * same style as MinMaxOverflowTest.php.
 *
 * Helper con nombre único (prefijo `borderRadius`) por el mismo motivo documentado en otros
 * ficheros EndToEnd (dos ficheros de test no pueden declarar una función de nivel superior con
 * el mismo nombre).
 *
 * @return array{0: string, 1: \Pliego\RenderReport}
 */
function borderRadiusRenderToPdfString(string $css, string $html): array
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::make()->stylesheet($css)->render($html)->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

it('paints a rounded background as a Bézier path (4 curve ops) instead of a plain re/f rect, end to end', function () {
    $css = '.box { width: 100px; height: 40px; background-color: #ff0000; border-radius: 10px }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = borderRadiusRenderToPdfString($css, $html);

    expect($pdf)->toStartWith('%PDF-1.7');
    expect($report->warnings)->toBe([]);
    expect(substr_count($pdf, " c\n"))->toBe(4);
    expect($pdf)->toContain('1.000 0.000 0.000 rg');
    expect($pdf)->not->toContain(' re f');
});

it('paints a UNIFORM border with radius as a single annular f* fill (outer minus inner), end to end', function () {
    $css = '.box { width: 100px; height: 40px; border: 5px solid #000000; border-radius: 20px }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = borderRadiusRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect($pdf)->toContain("h\nf*\n");
    expect(substr_count($pdf, " c\n"))->toBe(8); // 4 outer + 4 inner
    // The flat 4-rect border approach (border-only re/f, no background here) must NOT be used.
    expect($pdf)->not->toContain(' re f');
});

it('falls back to the flat 4-rect border approximation + a warning when border widths differ, with radius, end to end', function () {
    $css = '.box { width: 100px; height: 40px; '
        . 'border-top: 2px solid #000000; border-right: 6px solid #000000; '
        . 'border-bottom: 2px solid #000000; border-left: 2px solid #000000; '
        . 'border-radius: 10px }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = borderRadiusRenderToPdfString($css, $html);

    expect($report->warnings)->toBe(['mixed border widths with border-radius approximated']);
    // 4 flat border rects (`re f`), no annular f* fill.
    expect(substr_count($pdf, ' re f'))->toBe(4);
    expect($pdf)->not->toContain('f*');
});

it('clips to a rounded path (Bézier + W n) for overflow:hidden with a non-zero border-radius, end to end', function () {
    $css = '.box { width: 60px; max-height: 20px; overflow: hidden; border-radius: 10px; background-color: #eeeeee; }';
    $html = '<body><div class="box">AAAA<br>BBBB<br>CCCC</div></body>';
    [$pdf, $report] = borderRadiusRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect($pdf)->toContain('W n');
    expect($pdf)->not->toContain(' re W n'); // the OLD plain-rect clip must not appear
    // Rounded background (4 curves) + rounded clip path (4 more curves) = 8 total.
    expect(substr_count($pdf, " c\n"))->toBe(8);
});

it('renders a zero-radius box completely unaffected (byte-stable regression guard)', function () {
    $css = '.box { width: 100px; height: 40px; background-color: #ff0000; border: 2px solid #000000; }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = borderRadiusRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect($pdf)->not->toContain(" c\n");
    expect($pdf)->not->toContain('f*');
});

// --- M8-T2 review Finding 1 (Critical), end to end: a `.btn` inline span with a UNIFORM declared
// border+radius, wrapped across two lines by box-decoration-break:slice, must paint rounded
// corners on BOTH slices via the annular ring path (f*), not the flat 4-rect approximation, and
// must NOT trigger the "mixed border widths" warning -- the lateral side suppressed to
// BorderStyle::None on each non-terminal slice (InlineFlowContext::buildInlineBoxFragment()) is
// slice bookkeeping, not user-declared heterogeneity (see Painter::bordersUniform()).

it('golden E2E: a .btn span with uniform border+radius wrapped across two lines paints BOTH slices via the ring path, zero warnings', function () {
    // Same forced-wrap recipe as BootstrapComponentsTest's "golden: a bordered/padded inline span
    // slices across two wrapped lines" (tests/EndToEnd/BootstrapComponentsTest.php): "aaa" fits
    // on the line but "aaa bbb" doesn't, forcing the wrap between the two words -- here run
    // through the FULL Engine pipeline (real PDF bytes), not just the layout fragment tree, and
    // with border-radius added on top of the M7 border/padding slicing.
    $measurer = new TextMeasurer();
    $catalog = FontCatalog::withDefaults();
    $face = $catalog->select('default', 400, false);
    $aaaSpaceWidth = $measurer->widthOf('aaa ', $face, 16.0);
    $bbbWidth = $measurer->widthOf('bbb', $face, 16.0);
    $wrapWidthPx = $aaaSpaceWidth + $bbbWidth * 0.5;

    $css = sprintf('.wrap { width: %.2Fpx; } ', $wrapWidthPx)
        . '.btn { border: 2px solid #333333; border-radius: 8px; padding: 0 4px; background-color: #ffe08a; }';
    $html = '<body><div class="wrap"><p><span class="btn">aaa bbb</span></p></div></body>';
    [$pdf, $report] = borderRadiusRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]); // NOT ['mixed border widths with border-radius approximated']
    expect($pdf)->toContain(" c\n"); // rounded (Bézier) paths present
    expect($pdf)->not->toContain(' re f'); // no flat-rect border/background fallback anywhere
    // One annular ring fill (f*) PER slice -- both slices went through the rounded-border path,
    // neither fell back to the flat 4-rect approximation.
    expect(substr_count($pdf, 'f*'))->toBe(2);
});
