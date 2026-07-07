<?php

// tests/EndToEnd/GradientTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * M8-T3 (css-images-3 §3.1 reducido; ISO 32000-1 §8.7.4.5 shadings): gradients through the full
 * Engine pipeline (CSS parse -> Style -> Layout -> Paint -> Pdf), byte-level proof, same style as
 * BorderRadiusTest.php/ColorOpacityTest.php. Helper named uniquely (prefix `gradient`) per the
 * convention documented in other EndToEnd files (two test files can't declare a top-level function
 * with the same name).
 *
 * @return array{0: string, 1: \Pliego\RenderReport}
 */
function gradientRenderToPdfString(string $css, string $html): array
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::make()->stylesheet($css)->render($html)->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

it('paints a linear-gradient() background as an axial (/ShadingType 2) shading, end to end, zero warnings', function () {
    $css = '.box { width: 100px; height: 50px; background: linear-gradient(to right, red, blue); }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = gradientRenderToPdfString($css, $html);

    expect($pdf)->toStartWith('%PDF-1.7');
    expect($report->warnings)->toBe([]);
    expect($pdf)->toContain('/ShadingType 2');
    expect($pdf)->toContain('/Function');
    expect($pdf)->toContain('/Sh1 sh');
});

it('paints a radial-gradient() background as a radial (/ShadingType 3) shading, end to end, zero warnings', function () {
    $css = '.box { width: 100px; height: 50px; background: radial-gradient(circle at center, red, blue); }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = gradientRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect($pdf)->toContain('/ShadingType 3');
});

it('paints background-color THEN the gradient on top, when both are declared, end to end', function () {
    $css = '.box { width: 100px; height: 50px; background-color: #008000; background-image: linear-gradient(red, blue); }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = gradientRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    // #008000 = rgb(0,128,0) -> 128/255=0.502.
    $rectPos = strpos($pdf, '0.000 0.502 0.000 rg');
    $shPos = strpos($pdf, '/Sh1 sh');
    if ($rectPos === false || $shPos === false) {
        throw new \RuntimeException('expected both the background rect and the gradient sh op in the PDF bytes');
    }
    expect($rectPos)->toBeLessThan($shPos);
});

it('clips the gradient to a rounded path (Bézier + W n) when border-radius is declared, end to end', function () {
    $css = '.box { width: 100px; height: 50px; background: linear-gradient(red, blue); border-radius: 10px; }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = gradientRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect(substr_count($pdf, " c\n"))->toBe(4);
    expect($pdf)->toContain("h\nW n\n");
    expect($pdf)->toContain('/Sh1 sh');
    expect($pdf)->not->toContain(' re W n');
});

it('dedups two elements sharing the exact same absolute rect + gradient into a SINGLE /Shading object, end to end', function () {
    // The /Shading /Coords are ABSOLUTE page coordinates (not relative to the element's own box —
    // see PdfCanvas::paintGradient()), so dedup by content signature only fires when two elements
    // occupy the literal SAME rect on the page. position:absolute with identical top/left/width/
    // height forces exactly that (both children of the same root containing block, CSS 2.2 §10.3.7).
    $css = '.a, .b { position: absolute; top: 20px; left: 10px; width: 80px; height: 40px;
        background: linear-gradient(to right, red, blue); }';
    $html = '<body><div class="a">x</div><div class="b">y</div></body>';
    [$pdf, $report] = gradientRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect(substr_count($pdf, '/ShadingType'))->toBe(1);
    expect(substr_count($pdf, '/Sh1 sh'))->toBe(2);
});

it('warns and falls back to circle-at-center for an unsupported radial-gradient() shape, end to end', function () {
    $css = '.box { width: 100px; height: 50px; background: radial-gradient(ellipse at top left, red, blue); }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = gradientRenderToPdfString($css, $html);

    expect($report->warnings)->toHaveCount(1);
    expect($pdf)->toContain('/ShadingType 3');
});

// --- M8 final-review Finding C (Box\BoxTreeBuilder::hasVisibleInlineBox()): a gradient-only inline
// span was missing from the "does this inline element need a real InlineBoxFragment" check --
// falling through the fast path that flattens a <span> with no visible box CSS to plain text,
// silently dropping the gradient with zero ink and zero warning (InlineFlowContext::
// buildInlineBoxFragment() already paints a per-slice gradient once an InlineBoxFragment exists,
// see its own $backgroundGradient plumbing -- the feature worked, it just never got a chance to run).

it('paints a linear-gradient() declared on a <span> with NO other box CSS, end to end, zero warnings (Finding C)', function () {
    $css = '.tag { background: linear-gradient(to right, red, blue); }';
    $html = '<body><p><span class="tag">hi</span></p></body>';
    [$pdf, $report] = gradientRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect($pdf)->toContain('/ShadingType 2');
    expect($pdf)->toContain('/Sh1 sh');
});

// --- code review Finding 1 (css-backgrounds-3 §5, shorthand reset semantics): `background` is a
// SHORTHAND -- a more-specific `background:<color>` (or `background:<gradient>`) declaration must
// reset the OTHER sub-property, not just add its own on top of whatever a less-specific rule
// already cascaded in. Before the fix, BOTH painted (the gradient's own /sh op on top of the
// override color's rect) -- the exact repro from the code review finding.

it('paints ONLY the override color, no gradient /sh op, when a more-specific rule overrides "background" with a plain color (Finding 1 exact repro)', function () {
    $css = '.box { width: 100px; height: 50px; background: linear-gradient(red, blue); }
        .box.override { background: yellow; }';
    $html = '<body><div class="box override">x</div></body>';
    [$pdf, $report] = gradientRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect($pdf)->not->toContain('/ShadingType');
    expect($pdf)->not->toContain('/Sh1 sh');
    // yellow = rgb(255,255,0) -> 1.000 1.000 0.000 rg.
    expect($pdf)->toContain('1.000 1.000 0.000 rg');
});

it('paints ONLY the override gradient, no leftover color rect, when a more-specific rule overrides "background" with a gradient (Finding 1, reverse order)', function () {
    $css = '.box { width: 100px; height: 50px; background: yellow; }
        .box.override { background: linear-gradient(red, blue); }';
    $html = '<body><div class="box override">x</div></body>';
    [$pdf, $report] = gradientRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect($pdf)->toContain('/ShadingType 2');
    expect($pdf)->not->toContain('1.000 1.000 0.000 rg');
});

// --- code review Finding 2 (css-images-3 §3.1): radial-gradient() size/extent prefixes (extent
// keywords, a bare length, a percentage pair) must degrade to circle-at-center + a warning, not
// silently drop the whole declaration (the pre-fix behavior: the prefix was misread as an invalid
// color-stop, Color::fromCss() rejected it, and the gradient vanished with a misleading warning).

it('degrades radial-gradient(closest-side, ...) to circle-at-center with a warning, instead of dropping it, end to end', function () {
    $css = '.box { width: 100px; height: 50px; background: radial-gradient(closest-side, red, blue); }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = gradientRenderToPdfString($css, $html);

    expect($report->warnings)->toHaveCount(1);
    expect($pdf)->toContain('/ShadingType 3');
});

it('degrades radial-gradient(50px, ...) (bare length size) to circle-at-center with a warning, instead of dropping it, end to end', function () {
    $css = '.box { width: 100px; height: 50px; background: radial-gradient(50px, red, blue); }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = gradientRenderToPdfString($css, $html);

    expect($report->warnings)->toHaveCount(1);
    expect($pdf)->toContain('/ShadingType 3');
});

it('degrades radial-gradient(50% 50%, ...) (percentage-pair size) to circle-at-center with a warning, instead of dropping it, end to end', function () {
    $css = '.box { width: 100px; height: 50px; background: radial-gradient(50% 50%, red, blue); }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = gradientRenderToPdfString($css, $html);

    expect($report->warnings)->toHaveCount(1);
    expect($pdf)->toContain('/ShadingType 3');
});

// --- M9-T3 (ISO 32000-1 §11.6.5.2, luminosity soft masks): rgba() gradient stops, end to end ------

it('paints a linear-gradient() with an rgba() stop as a /SMask /Luminosity soft mask, end to end, zero warnings (M9-T3)', function () {
    $css = '.box { width: 100px; height: 50px; background-color: #0000ff;
        background-image: linear-gradient(rgba(255, 0, 0, 0.5), rgba(255, 0, 0, 0.5)); }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = gradientRenderToPdfString($css, $html);

    // M8-T3 used to emit "Gradient color-stop alpha not supported (...)" here -- M9-T3 supports it,
    // zero warnings.
    expect($report->warnings)->toBe([]);
    expect($pdf)->toContain('/ColorSpace /DeviceRGB')->toContain('/ColorSpace /DeviceGray');
    expect($pdf)->toContain('/Group << /S /Transparency /CS /DeviceGray >>');
    expect($pdf)->toMatch('#/SMask << /Type /Mask /S /Luminosity /G \d+ 0 R >>#');
});

// --- Ghostscript smoke test: proves the shading dict is well-formed enough for a real PDF
// consumer to rasterize without error (not just "our own byte assertions agree with themselves").

function gradientFindGhostscriptBinary(): ?string
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

$gsBinary = gradientFindGhostscriptBinary();

it('renders a gradient PDF that Ghostscript can rasterize without error (E2E render check)', function () use ($gsBinary) {
    // PHPStan-clean narrowing of the ?string found at collection time (see ->skip() below, which
    // already prevents this closure from RUNNING at all when $gsBinary is null — this guard is a
    // defensive no-op belt-and-braces, not the actual skip mechanism).
    if ($gsBinary === null) {
        return;
    }
    $gs = $gsBinary;

    $css = '.box { width: 150px; height: 80px; background: linear-gradient(45deg, red, yellow, blue);
        border-radius: 12px; }
        .circle { width: 60px; height: 60px; background: radial-gradient(circle at center, #fff, #000); }';
    $html = '<body><div class="box">x</div><div class="circle">y</div></body>';
    $pdfPath = sys_get_temp_dir() . '/pliego-gradient-e2e.pdf';
    $report = Engine::make()->stylesheet($css)->render($html)->save($pdfPath);
    expect($report->warnings)->toBe([]);

    // Fixed filename, no "%d" page-number placeholder: our test document is single-page, and a
    // literal "%d" tripped up cmd.exe's percent-expansion on Windows when passed through exec()
    // (silently ate the "%", leaving a mangled "pliego...page- d.png" — reproduced manually).
    // Ghostscript writes straight to this exact path when there is exactly one page.
    $renderedPage = sys_get_temp_dir() . '/pliego-gradient-e2e-page.png';
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

// --- M9-T3 combined Ghostscript smoke test: a PatternType 1 tiling background AND a translucent
// (rgba stop) gradient over a colored background, in the SAME document -- proves both new PDF
// constructs (tiling pattern, luminosity soft mask) are well-formed enough for a real consumer to
// rasterize, and (when the GD extension is available) that the translucency is actually VISIBLE in
// the raster, not just present in the PDF bytes.

it('renders a tiling-pattern background AND a translucent gradient over a colored background that Ghostscript can rasterize, translucency visible when GD is available (M9-T3)', function () use ($gsBinary) {
    if ($gsBinary === null) {
        return;
    }
    $gs = $gsBinary;

    $css = 'body { margin: 0; }
        .translucent { position: absolute; top: 0; left: 0; width: 100px; height: 60px; background-color: #0000ff;
            background-image: linear-gradient(rgba(255, 0, 0, 0.5), rgba(255, 0, 0, 0.5)); }
        .tiled { position: absolute; top: 100px; left: 0; width: 60px; height: 40px;
            background-image: url(tiny.jpg); background-repeat: repeat; }';
    $html = '<body><div class="translucent">x</div><div class="tiled">y</div></body>';
    $pdfPath = sys_get_temp_dir() . '/pliego-gradient-alpha-tiling-e2e.pdf';
    $report = Engine::make()
        ->basePath(__DIR__ . '/../../resources/images')
        ->stylesheet($css)
        ->render($html)
        ->save($pdfPath);
    expect($report->warnings)->toBe([]);

    // Fixed filename, no "%d" page-number placeholder -- same reasoning as the other Ghostscript
    // smoke tests in this file (single-page document, cmd.exe percent-expansion gotcha on Windows).
    $renderedPage = sys_get_temp_dir() . '/pliego-gradient-alpha-tiling-e2e-page.png';
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

    // Translucency check: .translucent is pinned at the page's own top-left (0,0), 100x60 CSS px --
    // at Ghostscript's -r72 raster (72dpi, exactly this engine's own px->pt scale of ×0.75, so 1
    // output pixel = 1 pt = 1.333 CSS px), sampling well inside the box (CSS px 20,20 -> raster
    // ~15,15) should land on the translucent-red-over-opaque-blue blend, NOT pure blue (0,0,255)
    // nor pure red (255,0,0) -- proof the /SMask /Luminosity actually modulated opacity in a REAL
    // PDF consumer's raster, not just our own byte assertions. Sampled only if GD is available in
    // this environment (visual note otherwise -- the Ghostscript exit-code check above already
    // proved the PDF itself is well-formed).
    if (extension_loaded('gd')) {
        $im = @imagecreatefrompng($renderedPage);
        if ($im !== false) {
            $sampleX = (int) round(20 * 0.75);
            $sampleY = (int) round(20 * 0.75);
            $colorIndex = imagecolorat($im, $sampleX, $sampleY);
            if ($colorIndex !== false) {
                $colors = imagecolorsforindex($im, $colorIndex);
                expect($colors['red'])->toBeGreaterThan(30);
                expect($colors['blue'])->toBeGreaterThan(30);
            }
            imagedestroy($im);
        }
    }

    @unlink($renderedPage);
    @unlink($pdfPath);
})->skip($gsBinary === null, 'Ghostscript not found on PATH in this environment.');
