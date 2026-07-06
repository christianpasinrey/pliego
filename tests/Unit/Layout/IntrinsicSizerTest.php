<?php

// tests/Unit/Layout/IntrinsicSizerTest.php
declare(strict_types=1);

use Pliego\Box\BlockBox;
use Pliego\Box\ImageBox;
use Pliego\Box\LineBreakRun;
use Pliego\Box\TableBox;
use Pliego\Box\TextRun;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\Length;
use Pliego\Css\Value\LengthPercentage;
use Pliego\Layout\IntrinsicSizer;
use Pliego\Layout\TextMeasurer;
use Pliego\Style\ComputedStyle;
use Pliego\Text\FontCatalog;

/** @param array<string, mixed> $declarations */
function sizerStyle(array $declarations = [], ?ComputedStyle $parent = null): ComputedStyle
{
    return ComputedStyle::compute($declarations, $parent ?? ComputedStyle::root(), 'div');
}

beforeEach(function (): void {
    $this->measurer = new TextMeasurer();
    $this->catalog = FontCatalog::withDefaults();
    $this->sizer = new IntrinsicSizer($this->measurer, $this->catalog);
    $this->face = $this->catalog->select('default', 400, false);
    $this->boldFace = $this->catalog->select('default', 700, false);
});

it('max-content sums a 3-word phrase unbroken; min-content is the longest word', function () {
    $style = sizerStyle();
    $box = new BlockBox($style, [new TextRun('uno dos tres', $style)], 'p');

    $expectedMax = $this->measurer->widthOf('uno dos tres', $this->face, 16.0);
    $expectedMin = max(
        $this->measurer->widthOf('uno', $this->face, 16.0),
        $this->measurer->widthOf('dos', $this->face, 16.0),
        $this->measurer->widthOf('tres', $this->face, 16.0),
    );

    expect($this->sizer->maxContentWidth($box))->toEqualWithDelta($expectedMax, 0.001);
    expect($this->sizer->minContentWidth($box))->toEqualWithDelta($expectedMin, 0.001);
});

it('measures each run with its own face: a bold segment widens max-content and can win min-content', function () {
    $normal = sizerStyle();
    $bold = sizerStyle(['font-weight' => 700], $normal);
    $box = new BlockBox($normal, [
        new TextRun('ab ', $normal),
        new TextRun('cd', $bold),
    ], 'p');

    $expectedMax = $this->measurer->widthOf('ab ', $this->face, 16.0)
        + $this->measurer->widthOf('cd', $this->boldFace, 16.0);
    expect($this->sizer->maxContentWidth($box))->toEqualWithDelta($expectedMax, 0.001);

    // min-content is computed PER RUN (longest word within that run), then the max is taken
    // across runs — the bold "cd" is wider per-glyph than the normal "ab", so it wins.
    $expectedMin = max(
        $this->measurer->widthOf('ab', $this->face, 16.0),
        $this->measurer->widthOf('cd', $this->boldFace, 16.0),
    );
    expect($this->sizer->minContentWidth($box))->toEqualWithDelta($expectedMin, 0.001);
    expect($this->measurer->widthOf('cd', $this->boldFace, 16.0))
        ->toBeGreaterThan($this->measurer->widthOf('cd', $this->face, 16.0));
});

it('a LineBreakRun splits a run sequence into segments for max-content, taking the widest', function () {
    $style = sizerStyle();
    $box = new BlockBox($style, [
        new TextRun('un texto muy largo', $style),
        new LineBreakRun(),
        new TextRun('x', $style),
    ], 'p');

    $expectedMax = $this->measurer->widthOf('un texto muy largo', $this->face, 16.0);
    expect($this->sizer->maxContentWidth($box))->toEqualWithDelta($expectedMax, 0.001);

    // min-content ignores LineBreakRun entirely (per-run word analysis) and just looks at the
    // longest word across the two TextRuns — "largo" beats the second run's lone "x".
    $expectedMin = max(
        $this->measurer->widthOf('un', $this->face, 16.0),
        $this->measurer->widthOf('texto', $this->face, 16.0),
        $this->measurer->widthOf('muy', $this->face, 16.0),
        $this->measurer->widthOf('largo', $this->face, 16.0),
        $this->measurer->widthOf('x', $this->face, 16.0),
    );
    expect($this->sizer->minContentWidth($box))->toEqualWithDelta($expectedMin, 0.001);
});

it('recurses into a nested block child, adding its horizontal margins', function () {
    $style = sizerStyle();
    $childStyle = sizerStyle([
        'margin-left' => LengthPercentage::px(5.0),
        'margin-right' => LengthPercentage::px(7.0),
    ], $style);
    $child = new BlockBox($childStyle, [new TextRun('palabra', $childStyle)], 'div');
    $box = new BlockBox($style, [$child], 'div');

    $expectedChildMax = $this->measurer->widthOf('palabra', $this->face, 16.0);
    expect($this->sizer->maxContentWidth($box))->toEqualWithDelta($expectedChildMax + 12.0, 0.001);
    expect($this->sizer->minContentWidth($box))->toEqualWithDelta($expectedChildMax + 12.0, 0.001);
});

it('takes the max across sibling sequences/children, and adds the parent own paddings/borders', function () {
    $style = sizerStyle([
        'padding-left' => LengthPercentage::px(3.0),
        'padding-right' => LengthPercentage::px(4.0),
        'border-left-width' => Length::px(1.0),
        'border-left-style' => BorderStyle::Solid,
        'border-right-width' => Length::px(2.0),
        'border-right-style' => BorderStyle::Solid,
    ]);
    $narrowChildStyle = sizerStyle([], $style);
    $narrowChild = new BlockBox($narrowChildStyle, [new TextRun('a', $narrowChildStyle)], 'div');
    $box = new BlockBox($style, [
        new TextRun('un texto largo de verdad', $style),
        $narrowChild,
    ], 'p');

    $expectedContent = $this->measurer->widthOf('un texto largo de verdad', $this->face, 16.0);
    $expectedOwnBorderPadding = 3.0 + 4.0 + 1.0 + 2.0;
    expect($this->sizer->maxContentWidth($box))->toEqualWithDelta($expectedContent + $expectedOwnBorderPadding, 0.001);
});

it('a declared px width short-circuits recursion: content-box adds paddings/borders, border-box does not', function () {
    $contentBoxStyle = sizerStyle([
        'width' => LengthPercentage::px(100.0),
        'padding-left' => LengthPercentage::px(10.0),
        'padding-right' => LengthPercentage::px(10.0),
    ]);
    $box = new BlockBox($contentBoxStyle, [
        new TextRun('esto seria mucho mas ancho que cien pixeles', $contentBoxStyle),
    ], 'div');
    expect($this->sizer->maxContentWidth($box))->toBe(120.0);
    expect($this->sizer->minContentWidth($box))->toBe(120.0);

    $borderBoxStyle = sizerStyle([
        'width' => LengthPercentage::px(100.0),
        'padding-left' => LengthPercentage::px(10.0),
        'padding-right' => LengthPercentage::px(10.0),
        'box-sizing' => 'border-box',
    ]);
    $borderBoxBox = new BlockBox($borderBoxStyle, [new TextRun('x', $borderBoxStyle)], 'div');
    expect($this->sizer->maxContentWidth($borderBoxBox))->toBe(100.0);
    expect($this->sizer->minContentWidth($borderBoxBox))->toBe(100.0);
});

it('a declared PERCENT width is treated as auto (indefinite basis), falling back to content sizing', function () {
    $style = sizerStyle(['width' => LengthPercentage::percent(50.0)]);
    $box = new BlockBox($style, [new TextRun('palabra', $style)], 'div');

    $expected = $this->measurer->widthOf('palabra', $this->face, 16.0);
    expect($this->sizer->maxContentWidth($box))->toEqualWithDelta($expected, 0.001);
});

it('sizes an ImageBox using the CSS > attr > intrinsic priority, adding its own padding/border', function () {
    $style = sizerStyle();

    $intrinsicOnly = new ImageBox($style, 'a.png', 40, 20, null, null);
    expect($this->sizer->maxContentWidth($intrinsicOnly))->toBe(40.0);
    expect($this->sizer->minContentWidth($intrinsicOnly))->toBe(40.0);

    $withAttr = new ImageBox($style, 'a.png', 40, 20, 80.0, null);
    expect($this->sizer->maxContentWidth($withAttr))->toBe(80.0);

    $withCssPadded = sizerStyle([
        'width' => LengthPercentage::px(120.0),
        'padding-left' => LengthPercentage::px(5.0),
        'padding-right' => LengthPercentage::px(5.0),
    ]);
    $withCss = new ImageBox($withCssPadded, 'a.png', 40, 20, 80.0, null);
    expect($this->sizer->maxContentWidth($withCss))->toBe(130.0);

    // % width has no containing block here (documented divergence from M3): falls back to
    // attr/intrinsic exactly like an unset width.
    $percentStyle = sizerStyle(['width' => LengthPercentage::percent(50.0)]);
    $withPercent = new ImageBox($percentStyle, 'a.png', 40, 20, 80.0, null);
    expect($this->sizer->maxContentWidth($withPercent))->toBe(80.0);
});

// M4-T3 report nice-to-have (documented under "Concerns"): min-content is computed PER RUN (see
// class docblock), so a word split across two runs by inline markup (e.g. "au<b>to</b>") is
// measured as two INDEPENDENT half-words ("au", "to") instead of the single wider word "auto" a
// real browser's shaping/measurement would use. This regression test documents the resulting
// UNDER-ESTIMATE — it is not desired behavior, just the known, adjudicated simplification.
it('documents the cross-run word min-content under-estimate: a bold mid-word split measures shorter than the whole word', function () {
    $normal = sizerStyle();
    $bold = sizerStyle(['font-weight' => 700], $normal);
    $split = new BlockBox($normal, [
        new TextRun('au', $normal),
        new TextRun('to', $bold),
    ], 'p');
    $whole = new BlockBox($normal, [new TextRun('auto', $normal)], 'p');

    $splitMin = $this->sizer->minContentWidth($split);
    $wholeMin = $this->sizer->minContentWidth($whole);

    // Sanity: the split measurement is exactly the max of its two independent halves (never the
    // combined "auto"), confirming the per-run treatment described in the class docblock.
    $expectedSplitMin = max(
        $this->measurer->widthOf('au', $this->face, 16.0),
        $this->measurer->widthOf('to', $this->boldFace, 16.0),
    );
    expect($splitMin)->toEqualWithDelta($expectedSplitMin, 0.001);

    // The regression itself: measuring in two halves under-estimates the true one-word min-content.
    expect($splitMin)->toBeLessThan($wholeMin);
});

// M5-T1 (housekeeping): a nested display:flex row child sums its items' max-content + gaps
// instead of taking the max like a plain block would.

it('sums (not maxes) a nested display:flex ROW child\'s items max-content plus column-gap', function () {
    $flexStyle = sizerStyle(['display' => 'flex', 'column-gap' => Length::px(10.0)]);
    $itemAStyle = sizerStyle([], $flexStyle);
    $itemBStyle = sizerStyle([], $flexStyle);
    $itemA = new BlockBox($itemAStyle, [new TextRun('uno', $itemAStyle)], 'div');
    $itemB = new BlockBox($itemBStyle, [new TextRun('dostres', $itemBStyle)], 'div');
    $flexChild = new BlockBox($flexStyle, [$itemA, $itemB], 'div');
    $box = new BlockBox(sizerStyle(), [$flexChild], 'div');

    $widthA = $this->measurer->widthOf('uno', $this->face, 16.0);
    $widthB = $this->measurer->widthOf('dostres', $this->face, 16.0);
    // SUM + gap, not max(widthA, widthB) -- the row lays its items out side by side.
    $expected = $widthA + $widthB + 10.0;

    expect($this->sizer->maxContentWidth($flexChild))->toEqualWithDelta($expected, 0.001);
    expect($this->sizer->maxContentWidth($box))->toEqualWithDelta($expected, 0.001);
});

it('keeps the MAX criterion for a nested display:flex COLUMN child (items stack vertically)', function () {
    $flexStyle = sizerStyle(['display' => 'flex', 'flex-direction' => 'column']);
    $itemAStyle = sizerStyle([], $flexStyle);
    $itemBStyle = sizerStyle([], $flexStyle);
    $itemA = new BlockBox($itemAStyle, [new TextRun('uno', $itemAStyle)], 'div');
    $itemB = new BlockBox($itemBStyle, [new TextRun('dostres', $itemBStyle)], 'div');
    $flexChild = new BlockBox($flexStyle, [$itemA, $itemB], 'div');

    $widthA = $this->measurer->widthOf('uno', $this->face, 16.0);
    $widthB = $this->measurer->widthOf('dostres', $this->face, 16.0);
    // MAX, not sum -- column stacks its items vertically, same criterion as a plain block.
    expect($this->sizer->maxContentWidth($flexChild))->toEqualWithDelta(max($widthA, $widthB), 0.001);
});

it('adds an image child intrinsic width plus its margins into the parent max-content', function () {
    $style = sizerStyle();
    $imgStyle = sizerStyle(['margin-left' => LengthPercentage::px(6.0)], $style);
    $img = new ImageBox($imgStyle, 'a.png', 90, 45, null, null);
    $box = new BlockBox($style, [
        new TextRun('x', $style),
        $img,
    ], 'div');

    expect($this->sizer->maxContentWidth($box))->toBe(96.0);
});

// M5-T3: una TableBox hija (M5-T4 lo consume, no tiene min/max-content todavía) debe ser
// IGNORADA por sizeBlock() sin crashear -- mismo patrón "skip, documented" verificado también en
// BlockFlowContext. Se contrasta el resultado con y sin la tabla para probar que su presencia no
// cambia el número (contribución 0, no un error de tipo).
it('skips a TableBox child without crashing (M5-T4 not implemented yet)', function () {
    $style = sizerStyle();
    $table = new TableBox($style, [], 'table');
    $withTable = new BlockBox($style, [new TextRun('abc', $style), $table], 'div');
    $withoutTable = new BlockBox($style, [new TextRun('abc', $style)], 'div');

    expect($this->sizer->maxContentWidth($withTable))->toBe($this->sizer->maxContentWidth($withoutTable));
    expect($this->sizer->minContentWidth($withTable))->toBe($this->sizer->minContentWidth($withoutTable));
});
