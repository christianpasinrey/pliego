<?php

// tests/EndToEnd/BackgroundImageTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * M8-T6 (css-backgrounds-3 §4 reducido): background-image (url(), cover/contain/auto sizing,
 * repeat/tiling, center/top-left positioning) through the full Engine pipeline (CSS parse -> Style
 * -> Layout -> Paint -> Pdf), byte-level proof, same style as GradientTest.php/
 * BoxShadowDashedBorderTest.php. Helper named uniquely (prefix `backgroundImage`) per the
 * convention documented in other EndToEnd files (two test files can't declare a top-level function
 * with the same name).
 *
 * All tests here use ->basePath(__DIR__ . '/../../resources/images') and the committed tiny.jpg
 * (4x3px) fixture -- same fixture <img>-based image tests already use, reused here so
 * background-image and <img> can dedup against the SAME real file (see the dedup test below).
 *
 * @return array{0: string, 1: \Pliego\RenderReport}
 */
function backgroundImageRenderToPdfString(string $css, string $html): array
{
    $path = sys_get_temp_dir() . '/pliego-e2e-background-image.pdf';
    $report = Engine::make()
        ->basePath(__DIR__ . '/../../resources/images')
        ->stylesheet($css)
        ->render($html)
        ->save($path);
    $pdf = (string) file_get_contents($path);
    @unlink($path);
    return [$pdf, $report];
}

it('paints a background-image: url(...) as an image XObject inside the clipped border-box, end to end, zero warnings', function () {
    $css = '.box { width: 100px; height: 50px; background-image: url(tiny.jpg); }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = backgroundImageRenderToPdfString($css, $html);

    expect($pdf)->toStartWith('%PDF-1.7');
    expect($report->warnings)->toBe([]);
    expect($pdf)->toContain('/Subtype /Image');
    expect($pdf)->toContain(' re W n'); // clip to the border-box before drawing the image
    expect($pdf)->toContain(' cm /Im1 Do');
});

it('paints background-color THEN the background-image on top, when both are declared, end to end', function () {
    $css = '.box { width: 100px; height: 50px; background-color: #008000; background-image: url(tiny.jpg); }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = backgroundImageRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    // #008000 = rgb(0,128,0) -> 0.502 green.
    $rectPos = strpos($pdf, '0.000 0.502 0.000 rg');
    $imgPos = strpos($pdf, '/Im1 Do');
    if ($rectPos === false || $imgPos === false) {
        throw new \RuntimeException('expected both the background rect and the image Do op in the PDF bytes');
    }
    expect($rectPos)->toBeLessThan($imgPos);
});

it('scales a background-image to background-size:cover, end to end', function () {
    // M8 final-review Finding A integration audit: `height` on a plain block was a verified no-op
    // since M2 (BlockFlowContext always derived height from content) -- this test used to need
    // `display: flex` as a workaround to get the exact declared 200x200 box the cover scale
    // numbers below depend on. Now that a plain block honors `height` for real (see
    // BlockFlowContext::layout()'s declared-height override), the workaround is gone: a plain
    // `<div>` with `height: 200px` and no content grows to exactly 200x200 on its own.
    $css = '.box { width: 200px; height: 200px; background-image: url(tiny.jpg); background-size: cover; }';
    $html = '<body><div class="box"></div></body>';
    [$pdf, $report] = backgroundImageRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    // tiny.jpg is 4x3 -> cover scale = max(200/4, 200/3) = 66.667 -> dest 266.67x200 (wider than
    // the box -- cover always overflows on at least one axis, cut by the clip). 0.75pt/px factor
    // applied by PdfCanvas: 266.67*0.75=200.00pt.
    expect($pdf)->toContain('200.00 0 0 150.00'); // cm scale matrix: wPt hPt (200x150pt = 266.67x200px * 0.75)
});

it('clips a background-image to a rounded border-box when border-radius is declared, end to end', function () {
    $css = '.box { width: 100px; height: 50px; background-image: url(tiny.jpg); border-radius: 10px; }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = backgroundImageRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect(substr_count($pdf, " c\n"))->toBe(4); // 4 Bézier curves of the rounded clip path
    expect($pdf)->toContain("h\nW n\n");
    expect($pdf)->not->toContain(' re W n');
    expect($pdf)->toContain('/Im1 Do');
});

it('tiles a background-image with background-repeat:repeat as multiple Do calls sharing ONE XObject, end to end', function () {
    // M8 final-review Finding A integration audit: same `display: flex` workaround removed as the
    // cover test above, now that a plain block honors `height` for real -- a plain `<div>` gets
    // the EXACT 20x15 box the hand-verified n=5/m=5=25 tile count below depends on.
    $css = '.box { width: 20px; height: 15px; background-image: url(tiny.jpg); background-repeat: repeat; }';
    $html = '<body><div class="box"></div></body>';
    [$pdf, $report] = backgroundImageRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    // tiny.jpg is 4x3px -> n=ceil(20/4)=5, m=ceil(15/3)=5 -> 25 tiles, ONE XObject definition.
    expect(substr_count($pdf, '/Subtype /Image'))->toBe(1);
    expect(substr_count($pdf, '/Im1 Do'))->toBe(25);
});

it('dedups an <img> and a sibling background-image pointing at the SAME file into a single XObject, end to end', function () {
    $css = '.bg { width: 40px; height: 30px; background-image: url(tiny.jpg); }';
    $html = '<body><img src="tiny.jpg"><div class="bg">x</div></body>';
    [$pdf, $report] = backgroundImageRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect(substr_count($pdf, '/Subtype /Image'))->toBe(1); // one XObject definition...
    expect(substr_count($pdf, '/Im1 Do'))->toBe(2);          // ...Do-ed once for <img>, once for the div
});

it('carries a background-image through a flex ITEM (align-items:stretch geometry-only resize), end to end', function () {
    // Exercises FlexFormattingContext::withHeight()'s geometry-only resize path (the flex item's
    // rect grows via align-items:stretch, WITHOUT re-layouting its content) -- the 4 background-*
    // fields must survive that reconstruction (see withHeight()'s forwarding, M8-T6 sweep).
    $css = '.row { display: flex; width: 200px; }
        .tall { width: 50px; height: 100px; }
        .item { width: 50px; background-image: url(tiny.jpg); }';
    $html = '<body><div class="row"><div class="tall"></div><div class="item"></div></div></body>';
    [$pdf, $report] = backgroundImageRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect($pdf)->toContain('/Subtype /Image');
    expect($pdf)->toContain('/Im1 Do');
});

it('carries a background-image through a TABLE CELL (vertical-align content shift), end to end', function () {
    // Exercises TableFormattingContext::alignCell()'s reconstruction path (GeometryShift::
    // translateChildrenY() shifts the cell's CONTENT down for vertical-align:middle/bottom while
    // the cell's own BoxFragment -- carrying background-image -- is rebuilt around it).
    $css = 'table { width: 200px; } td { width: 100px; vertical-align: bottom; }
        .bgcell { background-image: url(tiny.jpg); }';
    $html = '<body><table><tr><td class="bgcell">x</td><td>a<br>much<br>taller<br>cell</td></tr></table></body>';
    [$pdf, $report] = backgroundImageRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect($pdf)->toContain('/Subtype /Image');
    expect($pdf)->toContain('/Im1 Do');
});

it('reports a missing background-image file as a soft warning, background-color still painted, PDF still valid', function () {
    $css = '.box { width: 100px; height: 50px; background-color: #ff0000; background-image: url(does-not-exist.jpg); }';
    $html = '<body><div class="box">x</div></body>';
    [$pdf, $report] = backgroundImageRenderToPdfString($css, $html);

    expect($pdf)->toStartWith('%PDF-1.7');
    expect($report->warnings)->toHaveCount(1);
    expect($report->warnings[0])->toContain('does-not-exist.jpg');
    expect($pdf)->toContain('1.000 0.000 0.000 rg'); // red background-color still painted
    expect($pdf)->not->toContain('/Subtype /Image');
});

it('surfaces a warning and drops background-image when a span declares one (M8: no InlineBoxFragment image support)', function () {
    $css = '.tag { background-image: url(tiny.jpg); }';
    $html = '<body><p><span class="tag">hi</span></p></body>';
    [, $report] = backgroundImageRenderToPdfString($css, $html);

    expect($report->warnings)->toBe(['background-image not supported on inline elements (M8): declaration ignored']);
});

// --- Ghostscript smoke test: proves the image XObject + clip are well-formed enough for a real
// PDF consumer to rasterize without error (not just "our own byte assertions agree with
// themselves").

function backgroundImageFindGhostscriptBinary(): ?string
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

$gsBinary = backgroundImageFindGhostscriptBinary();

it('renders a cover/contain/repeat background-image PDF that Ghostscript can rasterize without error (E2E render check)', function () use ($gsBinary) {
    // PHPStan-clean narrowing of the ?string found at collection time (see ->skip() below, which
    // already prevents this closure from RUNNING at all when $gsBinary is null — this guard is a
    // defensive no-op belt-and-braces, not the actual skip mechanism).
    if ($gsBinary === null) {
        return;
    }
    $gs = $gsBinary;

    $css = '.cover { width: 150px; height: 80px; background-image: url(tiny.jpg); background-size: cover; border-radius: 12px; }
        .contain { width: 150px; height: 80px; background-color: #eeeeee; background-image: url(tiny.jpg); background-size: contain; }
        .tiled { width: 60px; height: 40px; background-image: url(tiny.jpg); background-repeat: repeat; }';
    $html = '<body><div class="cover">x</div><div class="contain">y</div><div class="tiled">z</div></body>';
    $pdfPath = sys_get_temp_dir() . '/pliego-background-image-e2e.pdf';
    $report = Engine::make()
        ->basePath(__DIR__ . '/../../resources/images')
        ->stylesheet($css)
        ->render($html)
        ->save($pdfPath);
    expect($report->warnings)->toBe([]);

    // Fixed filename, no "%d" page-number placeholder -- our test document is single-page, and a
    // literal "%d" tripped up cmd.exe's percent-expansion on Windows when passed through exec()
    // (see GradientTest.php's identical comment for the reproduced failure mode).
    $renderedPage = sys_get_temp_dir() . '/pliego-background-image-e2e-page.png';
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
