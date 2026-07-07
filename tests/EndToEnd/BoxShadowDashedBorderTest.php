<?php

// tests/EndToEnd/BoxShadowDashedBorderTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * M8-T4 acceptance (css-backgrounds-3 §6 reducido box-shadow, §4.3 border-style dashed/dotted,
 * ISO 32000-1 §8.4.3.6 dash patterns): box-shadow aproximada + bordes dashed/dotted end to end
 * through the real Engine pipeline. Byte-level PDF proof, same style as BorderRadiusTest.php/
 * GradientTest.php.
 *
 * Helper con nombre único (prefijo `boxShadowDashed`) por el mismo motivo documentado en otros
 * ficheros EndToEnd (dos ficheros de test no pueden declarar una función de nivel superior con el
 * mismo nombre).
 *
 * @return array{0: string, 1: \Pliego\RenderReport}
 */
function boxShadowDashedRenderToPdfString(string $css, string $html): array
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::make()->stylesheet($css)->render($html)->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

// --- box-shadow -----------------------------------------------------------------------------

it('paints a blur=0 box-shadow as one extra re/f BEFORE the background rect, end to end', function () {
    $css = '.card { width: 100px; height: 50px; background-color: #ffffff; box-shadow: 4px 4px #000000; }';
    $html = '<body><div class="card">x</div></body>';
    [$pdf, $report] = boxShadowDashedRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    // 2 fills total for this box: the shadow rect + the white background -- shadow painted FIRST
    // (css-backgrounds-3 painting order: box-shadow sits BEHIND background/border/content).
    $shadowPos = strpos($pdf, '0.000 0.000 0.000 rg');
    $backgroundPos = strpos($pdf, '1.000 1.000 1.000 rg');
    expect($shadowPos)->not->toBeFalse();
    expect($backgroundPos)->not->toBeFalse();
    expect((int) $shadowPos)->toBeLessThan((int) $backgroundPos);
});

it('paints a box-shadow-only card (no background/border declared) as a single extra fill, end to end', function () {
    $css = '.card { width: 100px; height: 50px; box-shadow: 3px 3px #333333; }';
    $html = '<body><div class="card">x</div></body>';
    [$pdf, $report] = boxShadowDashedRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect($pdf)->toContain('0.200 0.200 0.200 rg');
    expect(substr_count($pdf, ' re f'))->toBe(1);
});

it('approximates a blur>0 box-shadow as 4 concentric fills, each carrying 1/4 the shadow alpha via /GSn ExtGState', function () {
    $css = '.card { width: 100px; height: 50px; box-shadow: 0 0 8px rgba(0,0,0,0.8); }';
    $html = '<body><div class="card">x</div></body>';
    [$pdf, $report] = boxShadowDashedRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    // 0.8 alpha / 4 = 0.20 -- one shared ExtGState object for all 4 identical-alpha layers.
    expect($pdf)->toContain('/ca 0.2');
    expect(substr_count($pdf, 'gs'))->toBeGreaterThanOrEqual(4);
});

it('paints a rounded box-shadow (blur=0) following the SAME border-radius as the element, end to end', function () {
    $css = '.card { width: 100px; height: 50px; background-color: #ffffff; border-radius: 12px; box-shadow: 4px 4px #000000; }';
    $html = '<body><div class="card">x</div></body>';
    [$pdf, $report] = boxShadowDashedRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    // Rounded background (4 curves) + rounded shadow (4 more curves) = 8 total.
    expect(substr_count($pdf, " c\n"))->toBe(8);
});

it('surfaces a warning and drops the whole box-shadow when a span declares one (M8: no InlineBoxFragment shadow support)', function () {
    $css = '.tag { box-shadow: 2px 2px red; }';
    $html = '<body><p><span class="tag">hi</span></p></body>';
    [, $report] = boxShadowDashedRenderToPdfString($css, $html);

    expect($report->warnings)->toBe(['box-shadow not supported on inline elements (M8): declaration ignored']);
});

it('surfaces a warning and drops the whole box-shadow when inset is declared, end to end', function () {
    $css = '.card { width: 100px; height: 50px; box-shadow: inset 2px 2px #000000; }';
    $html = '<body><div class="card">x</div></body>';
    [$pdf, $report] = boxShadowDashedRenderToPdfString($css, $html);

    expect($report->warnings)->not->toBeEmpty();
    // No background/border declared either -- an inset box-shadow dropped entirely means this
    // div paints NO fill rect at all (the "x" text glyph is the only ink, no `re f` op).
    expect(substr_count($pdf, ' re f'))->toBe(0);
});

// --- dashed/dotted borders --------------------------------------------------------------------

it('paints a UNIFORM dashed table border as ONE stroked re/S path with a [3w w] dash array, end to end', function () {
    $css = 'table, td { border: 2px dashed #000000; }
        table { width: 120px; }';
    $html = '<body><table><tr><td>a</td><td>b</td></tr></table></body>';
    [$pdf, $report] = boxShadowDashedRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    // 2px border width -> pt 1.50; dash [6,2]px -> pt [4.50, 1.50].
    expect($pdf)->toContain('[4.50 1.50] 0 d');
    expect($pdf)->toContain(' re S');
    expect($pdf)->not->toContain(' re f'); // no flat-fill border approximation anywhere
});

it('paints a UNIFORM dotted border with a [0 2w] dash array and a round line cap (1 J), end to end', function () {
    $css = '.box { width: 100px; height: 40px; border: 2px dotted #000000; }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = boxShadowDashedRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect($pdf)->toContain('[0.00 3.00] 0 d');
    expect($pdf)->toContain('1 J');
});

it('paints a UNIFORM dashed border WITH border-radius as a dashed Bézier path (curves + dash + S), end to end', function () {
    $css = '.box { width: 100px; height: 40px; border: 4px dashed #000000; border-radius: 10px; }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = boxShadowDashedRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect(substr_count($pdf, " c\n"))->toBe(4);
    expect($pdf)->toContain('] 0 d');
    expect($pdf)->toContain("h\nS\n");
    expect($pdf)->not->toContain(' re f');
    expect($pdf)->not->toContain('f*'); // not the Solid annular-ring path
});

it('renders a zero-radius solid border completely unaffected by the dashed/dotted machinery (byte-stable regression guard)', function () {
    $css = '.box { width: 100px; height: 40px; background-color: #ff0000; border: 2px solid #000000; }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = boxShadowDashedRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect($pdf)->not->toContain(' d\n');
    expect($pdf)->not->toContain(' S\n');
});

// --- Ghostscript smoke test: proves both features produce a PDF a real consumer can rasterize --

function boxShadowDashedFindGhostscriptBinary(): ?string
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

$gsBinary = boxShadowDashedFindGhostscriptBinary();

it('renders a box-shadow card + a dashed table as a PDF Ghostscript can rasterize without error (E2E render check)', function () use ($gsBinary) {
    if ($gsBinary === null) {
        return;
    }
    $gs = $gsBinary;

    $css = '.card { width: 150px; height: 80px; background-color: #ffffff; border-radius: 10px;
        box-shadow: 4px 4px 10px rgba(0,0,0,0.4); }
        table, td { border: 2px dashed #333333; }
        .dotted { width: 100px; height: 40px; border: 3px dotted #0000ff; }';
    $html = '<body>'
        . '<div class="card">shadow card</div>'
        . '<table><tr><td>a</td><td>b</td></tr></table>'
        . '<div class="dotted">dotted box</div>'
        . '</body>';
    $pdfPath = sys_get_temp_dir() . '/pliego-boxshadow-dashed-e2e.pdf';
    $report = Engine::make()->stylesheet($css)->render($html)->save($pdfPath);
    expect($report->warnings)->toBe([]);

    $renderedPage = sys_get_temp_dir() . '/pliego-boxshadow-dashed-e2e-page.png';
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
