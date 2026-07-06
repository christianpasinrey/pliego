<?php

// tests/Unit/Layout/TableFormattingContextTest.php
declare(strict_types=1);

use Pliego\Box\BlockBox;
use Pliego\Box\LineBreakRun;
use Pliego\Box\TableBox;
use Pliego\Box\TableCellBox;
use Pliego\Box\TableRowBox;
use Pliego\Box\TextRun;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\Length;
use Pliego\Css\Value\LengthPercentage;
use Pliego\Css\WarningCollector;
use Pliego\Layout\BlockFlowContext;
use Pliego\Layout\FlexFormattingContext;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\FragmentDumper;
use Pliego\Layout\Geometry\Rect;
use Pliego\Layout\IntrinsicSizer;
use Pliego\Layout\TableFormattingContext;
use Pliego\Layout\TextMeasurer;
use Pliego\Page\Paginator;
use Pliego\Style\ComputedStyle;
use Pliego\Text\FontCatalog;

/** @param array<string, mixed> $declarations */
function tableStyle(array $declarations = [], ?ComputedStyle $parent = null): ComputedStyle
{
    return ComputedStyle::compute($declarations, $parent ?? ComputedStyle::root(), 'div', 16.0);
}

beforeEach(function (): void {
    $this->measurer = new TextMeasurer();
    $this->catalog = FontCatalog::withDefaults();
    $this->sizer = new IntrinsicSizer($this->measurer, $this->catalog);
    $this->ctx = new TableFormattingContext($this->measurer, $this->catalog, $this->sizer);
    $this->face = $this->catalog->select('default', 400, false);
});

// --- AUTO: 2 columns sized from their own max-content, asymmetric content --------------------

it('auto layout: sizes 2 columns from their own max-content with no declared width', function () {
    $style = tableStyle();
    $short = new TextRun('hi', $style);
    $long = new TextRun('a much longer piece of text here', $style);
    $cellA = new TableCellBox($style, [$short], 1, 'td');
    $cellB = new TableCellBox($style, [$long], 1, 'td');
    $row = new TableRowBox($style, [$cellA, $cellB], false);
    $table = new TableBox($style, [$row], 'table');

    $frag = $this->ctx->layout($table, new Rect(0.0, 0.0, 1000.0, INF));
    [$rowFrag] = $frag->children;
    assert($rowFrag instanceof BoxFragment);
    [$aFrag, $bFrag] = $rowFrag->children;
    assert($aFrag instanceof BoxFragment && $bFrag instanceof BoxFragment);

    $maxA = $this->measurer->widthOf('hi', $this->face, 16.0);
    $maxB = $this->measurer->widthOf('a much longer piece of text here', $this->face, 16.0);

    expect($aFrag->rect->x)->toBe(0.0);
    expect($aFrag->rect->width)->toBe($maxA);
    expect($bFrag->rect->x)->toBe($maxA);
    expect($bFrag->rect->width)->toBe($maxB);
    expect($frag->rect->width)->toBe($maxA + $maxB); // no borderSpacing (default 0), no table padding/border
    expect($rowFrag->rect->width)->toBe($maxA + $maxB);
});

// --- AUTO: declared table width larger than content distributes the surplus proportionally ----

it('auto layout: a declared table width larger than natural content distributes the surplus proportional to each column max', function () {
    $short = new TextRun('aa', tableStyle());
    $long = new TextRun('bbbbbbbbbb', tableStyle());
    $cellA = new TableCellBox(tableStyle(), [$short], 1, 'td');
    $cellB = new TableCellBox(tableStyle(), [$long], 1, 'td');
    $row = new TableRowBox(tableStyle(), [$cellA, $cellB], false);

    $maxA = $this->measurer->widthOf('aa', $this->face, 16.0);
    $maxB = $this->measurer->widthOf('bbbbbbbbbb', $this->face, 16.0);
    $sumMax = $maxA + $maxB;
    $surplus = 40.0;
    $declaredWidth = $sumMax + $surplus;

    $table = new TableBox(tableStyle(['width' => LengthPercentage::px($declaredWidth)]), [$row], 'table');
    $frag = $this->ctx->layout($table, new Rect(0.0, 0.0, 1000.0, INF));
    [$rowFrag] = $frag->children;
    assert($rowFrag instanceof BoxFragment);
    [$aFrag, $bFrag] = $rowFrag->children;
    assert($aFrag instanceof BoxFragment && $bFrag instanceof BoxFragment);

    $expectedA = $maxA + $surplus * ($maxA / $sumMax);
    $expectedB = $maxB + $surplus * ($maxB / $sumMax);

    expect($aFrag->rect->width)->toEqualWithDelta($expectedA, 0.001);
    expect($bFrag->rect->width)->toEqualWithDelta($expectedB, 0.001);
    expect($frag->rect->width)->toBe($declaredWidth);
});

// --- AUTO: declared table width far too narrow clamps to min-content, overflow + warning -------

it('auto layout: a table declared far too narrow clamps every column to its min-content and warns once', function () {
    $a = new TextRun('alpha beta gamma', tableStyle());
    $b = new TextRun('delta epsilon zeta', tableStyle());
    $cellA = new TableCellBox(tableStyle(), [$a], 1, 'td');
    $cellB = new TableCellBox(tableStyle(), [$b], 1, 'td');
    $row = new TableRowBox(tableStyle(), [$cellA, $cellB], false);
    $table = new TableBox(tableStyle(['width' => LengthPercentage::px(1.0)]), [$row], 'table');

    $warnings = new WarningCollector();
    $ctx = new TableFormattingContext($this->measurer, $this->catalog, $this->sizer, $warnings);
    $frag = $ctx->layout($table, new Rect(0.0, 0.0, 500.0, INF));

    $minA = max(
        $this->measurer->widthOf('alpha', $this->face, 16.0),
        $this->measurer->widthOf('beta', $this->face, 16.0),
        $this->measurer->widthOf('gamma', $this->face, 16.0),
    );
    $minB = max(
        $this->measurer->widthOf('delta', $this->face, 16.0),
        $this->measurer->widthOf('epsilon', $this->face, 16.0),
        $this->measurer->widthOf('zeta', $this->face, 16.0),
    );

    [$rowFrag] = $frag->children;
    assert($rowFrag instanceof BoxFragment);
    [$aFrag, $bFrag] = $rowFrag->children;
    assert($aFrag instanceof BoxFragment && $bFrag instanceof BoxFragment);

    expect($aFrag->rect->width)->toBe($minA);
    expect($bFrag->rect->width)->toBe($minB);
    expect($warnings->drain())->toBe(['table minimum content width exceeds available width']);
});

// --- FIXED: equal shares when the first row declares no cell widths ---------------------------

it('fixed layout: equal column shares when the first row declares no cell widths', function () {
    $style = tableStyle();
    $row = new TableRowBox($style, [
        new TableCellBox($style, [], 1, 'td'),
        new TableCellBox($style, [], 1, 'td'),
        new TableCellBox($style, [], 1, 'td'),
    ], false);
    $table = new TableBox(tableStyle(['table-layout' => 'fixed', 'width' => LengthPercentage::px(300.0)]), [$row], 'table');

    $frag = $this->ctx->layout($table, new Rect(0.0, 0.0, 500.0, INF));
    [$rowFrag] = $frag->children;
    assert($rowFrag instanceof BoxFragment);

    foreach ($rowFrag->children as $cellFrag) {
        assert($cellFrag instanceof BoxFragment);
        expect($cellFrag->rect->width)->toBe(100.0);
    }
    expect($frag->rect->width)->toBe(300.0);
});

// --- FIXED: first-row cell widths win, the rest share the remainder equally -------------------

it('fixed layout: first-row declared cell widths win, remaining columns share the leftover equally', function () {
    $style = tableStyle();
    $row = new TableRowBox($style, [
        new TableCellBox(tableStyle(['width' => LengthPercentage::px(50.0)]), [], 1, 'td'),
        new TableCellBox($style, [], 1, 'td'),
        new TableCellBox($style, [], 1, 'td'),
    ], false);
    $table = new TableBox(tableStyle(['table-layout' => 'fixed', 'width' => LengthPercentage::px(250.0)]), [$row], 'table');

    $frag = $this->ctx->layout($table, new Rect(0.0, 0.0, 500.0, INF));
    [$rowFrag] = $frag->children;
    assert($rowFrag instanceof BoxFragment);
    [$aFrag, $bFrag, $cFrag] = $rowFrag->children;
    assert($aFrag instanceof BoxFragment && $bFrag instanceof BoxFragment && $cFrag instanceof BoxFragment);

    expect($aFrag->rect->width)->toBe(50.0);
    // leftover = 250 - 50 = 200, split equally between B and C -> 100 each
    expect($bFrag->rect->width)->toBe(100.0);
    expect($cFrag->rect->width)->toBe(100.0);
});

// --- FIXED without a declared width falls back to auto, with a warning -----------------------

it('fixed layout without a declared width warns and falls back to auto (natural content sizing, not equal shares)', function () {
    $short = new TextRun('a', tableStyle());
    $long = new TextRun('a much longer piece of content', tableStyle());
    $row = new TableRowBox(tableStyle(), [
        new TableCellBox(tableStyle(), [$short], 1, 'td'),
        new TableCellBox(tableStyle(), [$long], 1, 'td'),
    ], false);
    $table = new TableBox(tableStyle(['table-layout' => 'fixed']), [$row], 'table');

    $warnings = new WarningCollector();
    $ctx = new TableFormattingContext($this->measurer, $this->catalog, $this->sizer, $warnings);
    $frag = $ctx->layout($table, new Rect(0.0, 0.0, 1000.0, INF));
    [$rowFrag] = $frag->children;
    assert($rowFrag instanceof BoxFragment);
    [$aFrag, $bFrag] = $rowFrag->children;
    assert($aFrag instanceof BoxFragment && $bFrag instanceof BoxFragment);

    // Falls back to auto: columns follow their own max-content (very DIFFERENT widths), never the
    // equal-share behaviour fixed layout would otherwise produce.
    expect($aFrag->rect->width)->toBe($this->measurer->widthOf('a', $this->face, 16.0));
    expect($bFrag->rect->width)->toBe($this->measurer->widthOf('a much longer piece of content', $this->face, 16.0));
    expect($warnings->drain())->toBe(['table-layout: fixed without a declared width falls back to auto']);
});

// --- colspan=2: excess distributed proportional to the single-span columns' max ----------------

it('auto layout: a colspan=2 cell distributes its excess width proportional to the single-span columns below it', function () {
    // Row 0: one cell spanning both columns, a single long "word" (max == min, no wrapping).
    // Row 1: two single-span cells with different (short) natural widths -- these anchor the
    // per-column single-span max that the colspan cell's excess gets distributed against.
    $spanText = new TextRun('wwwwwwwwwwwwwwwwwwwwwwwwwwwwww', tableStyle());
    $shortText = new TextRun('x', tableStyle());
    $longerText = new TextRun('yy', tableStyle());

    $rowSpan = new TableRowBox(tableStyle(), [new TableCellBox(tableStyle(), [$spanText], 2, 'td')], false);
    $rowSingles = new TableRowBox(tableStyle(), [
        new TableCellBox(tableStyle(), [$shortText], 1, 'td'),
        new TableCellBox(tableStyle(), [$longerText], 1, 'td'),
    ], false);
    $table = new TableBox(tableStyle(), [$rowSpan, $rowSingles], 'table');

    $spanMax = $this->measurer->widthOf('wwwwwwwwwwwwwwwwwwwwwwwwwwwwww', $this->face, 16.0);
    $x = $this->measurer->widthOf('x', $this->face, 16.0);
    $yy = $this->measurer->widthOf('yy', $this->face, 16.0);
    // Sanity: the spanning cell must actually be wider than both single-span columns combined,
    // otherwise this test would not exercise the excess-distribution branch at all.
    expect($spanMax)->toBeGreaterThan($x + $yy);

    $frag = $this->ctx->layout($table, new Rect(0.0, 0.0, 1000.0, INF));
    [$rowSpanFrag, $rowSinglesFrag] = $frag->children;
    assert($rowSpanFrag instanceof BoxFragment && $rowSinglesFrag instanceof BoxFragment);
    [$spanFrag] = $rowSpanFrag->children;
    [$xFrag, $yyFrag] = $rowSinglesFrag->children;
    assert($spanFrag instanceof BoxFragment && $xFrag instanceof BoxFragment && $yyFrag instanceof BoxFragment);

    $excess = $spanMax - ($x + $yy);
    $expectedColX = $x + $excess * ($x / ($x + $yy));
    $expectedColYy = $yy + $excess * ($yy / ($x + $yy));

    expect($xFrag->rect->width)->toEqualWithDelta($expectedColX, 0.001);
    expect($yyFrag->rect->width)->toEqualWithDelta($expectedColYy, 0.001);
    // The two columns, once the excess is fully distributed, sum back up to exactly the spanning
    // cell's own natural width -- the whole point of an auto-layout colspan.
    expect($xFrag->rect->width + $yyFrag->rect->width)->toEqualWithDelta($spanMax, 0.001);
    expect($spanFrag->rect->width)->toEqualWithDelta($spanMax, 0.001);
});

it('auto layout: a colspan cell splits its width equally across columns when both single-span maxes are zero', function () {
    $spanText = new TextRun('wwwwwwwwww', tableStyle());
    $rowSpan = new TableRowBox(tableStyle(), [new TableCellBox(tableStyle(), [$spanText], 2, 'td')], false);
    // Both single-span cells are empty -- their column max starts at 0, forcing the "equal parts"
    // fallback (documented in TableFormattingContext::autoColumnExtents()).
    $rowSingles = new TableRowBox(tableStyle(), [
        new TableCellBox(tableStyle(), [], 1, 'td'),
        new TableCellBox(tableStyle(), [], 1, 'td'),
    ], false);
    $table = new TableBox(tableStyle(), [$rowSpan, $rowSingles], 'table');

    $spanMax = $this->measurer->widthOf('wwwwwwwwww', $this->face, 16.0);

    $frag = $this->ctx->layout($table, new Rect(0.0, 0.0, 1000.0, INF));
    [$rowSpanFrag, $rowSinglesFrag] = $frag->children;
    assert($rowSpanFrag instanceof BoxFragment && $rowSinglesFrag instanceof BoxFragment);
    [$aFrag, $bFrag] = $rowSinglesFrag->children;
    assert($aFrag instanceof BoxFragment && $bFrag instanceof BoxFragment);

    expect($aFrag->rect->width)->toEqualWithDelta($spanMax / 2.0, 0.001);
    expect($bFrag->rect->width)->toEqualWithDelta($spanMax / 2.0, 0.001);
});

// --- border-spacing: 6px, positions include edge + between spacing ----------------------------

it('applies a 6px border-spacing before/between/after columns and rows', function () {
    $style = tableStyle(['border-spacing' => Length::px(6.0)]);
    $a = new TextRun('aa', $style);
    $b = new TextRun('bbbb', $style);
    $cellA = new TableCellBox($style, [$a], 1, 'td');
    $cellB = new TableCellBox($style, [$b], 1, 'td');
    $row = new TableRowBox($style, [$cellA, $cellB], false);
    $table = new TableBox($style, [$row], 'table');

    $frag = $this->ctx->layout($table, new Rect(0.0, 0.0, 1000.0, INF));
    [$rowFrag] = $frag->children;
    assert($rowFrag instanceof BoxFragment);
    [$aFrag, $bFrag] = $rowFrag->children;
    assert($aFrag instanceof BoxFragment && $bFrag instanceof BoxFragment);

    $maxA = $this->measurer->widthOf('aa', $this->face, 16.0);
    $maxB = $this->measurer->widthOf('bbbb', $this->face, 16.0);

    // 2 columns -> spacing x (2+1) = 18px total horizontal; before col0, between col0/col1, after col1.
    expect($aFrag->rect->x)->toBe(6.0);
    expect($aFrag->rect->width)->toBe($maxA);
    expect($bFrag->rect->x)->toBe(6.0 + $maxA + 6.0);
    expect($bFrag->rect->width)->toBe($maxB);
    expect($frag->rect->width)->toBe($maxA + $maxB + 18.0);

    // 1 row -> spacing before + after = 2x6 = 12px total vertical.
    expect($rowFrag->rect->y)->toBe(6.0);
    expect($frag->rect->height)->toBe(12.0 + $rowFrag->rect->height);
});

// --- cell with its own padding + border --------------------------------------------------------

it('sizes a cell to its content plus its own padding and border, and offsets the content accordingly', function () {
    $cellDeclarations = [
        'padding-top' => LengthPercentage::px(10.0),
        'padding-right' => LengthPercentage::px(10.0),
        'padding-bottom' => LengthPercentage::px(10.0),
        'padding-left' => LengthPercentage::px(10.0),
        'border-top-width' => Length::px(2.0), 'border-top-style' => BorderStyle::Solid,
        'border-right-width' => Length::px(2.0), 'border-right-style' => BorderStyle::Solid,
        'border-bottom-width' => Length::px(2.0), 'border-bottom-style' => BorderStyle::Solid,
        'border-left-width' => Length::px(2.0), 'border-left-style' => BorderStyle::Solid,
    ];
    $cellStyle = tableStyle($cellDeclarations);
    $text = new TextRun('hi', $cellStyle);
    $cell = new TableCellBox($cellStyle, [$text], 1, 'td');
    $row = new TableRowBox(tableStyle(), [$cell], false);
    $table = new TableBox(tableStyle(), [$row], 'table');

    $frag = $this->ctx->layout($table, new Rect(0.0, 0.0, 1000.0, INF));
    [$rowFrag] = $frag->children;
    assert($rowFrag instanceof BoxFragment);
    [$cellFrag] = $rowFrag->children;
    assert($cellFrag instanceof BoxFragment);

    $textWidth = $this->measurer->widthOf('hi', $this->face, 16.0);
    $expectedCellWidth = $textWidth + 2 * 10.0 + 2 * 2.0; // padding + border, both sides

    expect($cellFrag->rect->width)->toBe($expectedCellWidth);
    $textFrag = $cellFrag->children[0];
    assert($textFrag instanceof TextFragment);
    expect($textFrag->rect->x)->toBe(0.0 + 2.0 + 10.0); // table x=0 + cell's own border + padding
});

// --- row height = tallest cell, shorter cells stretched (geometry-only, content anchored top) --

it('row height equals the tallest cell fragment; shorter cells are stretched without moving their content', function () {
    $style = tableStyle();
    $short = new TextRun('one line', $style);
    $tall = [new TextRun('line one', $style), new LineBreakRun(), new TextRun('line two', $style)];
    $cellShort = new TableCellBox($style, [$short], 1, 'td');
    $cellTall = new TableCellBox($style, $tall, 1, 'td');
    $row = new TableRowBox($style, [$cellShort, $cellTall], false);
    $table = new TableBox($style, [$row], 'table');

    // Independent oracle (NOT the table layout under test): the natural, un-stretched height of
    // each cell's content, measured via a standalone BlockFlowContext layout at the same content
    // width both cells will actually get (the whole table's content width here, since it's the
    // only column) -- confirms the two-line cell really IS taller before asserting the row
    // equalizes them, instead of assuming it from the fixture shape alone.
    $blockFlow = new BlockFlowContext($this->measurer, $this->catalog);
    $naturalShort = $blockFlow->layout(new BlockBox($style, [$short], 'td'), new Rect(0.0, 0.0, 1000.0, INF))->rect->height;
    $naturalTall = $blockFlow->layout(new BlockBox($style, $tall, 'td'), new Rect(0.0, 0.0, 1000.0, INF))->rect->height;
    expect($naturalTall)->toBeGreaterThan($naturalShort);

    $frag = $this->ctx->layout($table, new Rect(0.0, 0.0, 1000.0, INF));
    [$rowFrag] = $frag->children;
    assert($rowFrag instanceof BoxFragment);
    [$shortFrag, $tallFrag] = $rowFrag->children;
    assert($shortFrag instanceof BoxFragment && $tallFrag instanceof BoxFragment);

    expect($tallFrag->rect->height)->toBe($naturalTall);
    expect($shortFrag->rect->height)->toBe($naturalTall); // stretched up from its natural height
    expect($rowFrag->rect->height)->toBe($naturalTall);

    // Content of the stretched (short) cell stays anchored at the top -- not re-centered.
    $shortText = $shortFrag->children[0];
    assert($shortText instanceof TextFragment);
    expect($shortText->rect->y)->toBe($shortFrag->rect->y);
});

// --- nested table inside a cell: recursion via BlockFlowContext delegation --------------------

it('lays out a table nested inside a cell via BlockFlowContext delegation, no crash', function () {
    $innerText = new TextRun('inner', tableStyle());
    $innerCell = new TableCellBox(tableStyle(), [$innerText], 1, 'td');
    $innerRow = new TableRowBox(tableStyle(), [$innerCell], false);
    $innerTable = new TableBox(tableStyle(), [$innerRow], 'table');

    // Outer table declares its own width -- kept from before the bugfix that gave a nested
    // TableBox its own real intrinsic size (see IntrinsicSizer::sizeTable()); this single-column
    // table would get a real width from that fix alone too, but the declared width keeps this
    // test focused on the delegation/no-crash behavior it was written for, not column sizing.
    $outerCell = new TableCellBox(tableStyle(), [$innerTable], 1, 'td');
    $outerRow = new TableRowBox(tableStyle(), [$outerCell], false);
    $outerTable = new TableBox(tableStyle(['width' => LengthPercentage::px(200.0)]), [$outerRow], 'table');

    $frag = $this->ctx->layout($outerTable, new Rect(0.0, 0.0, 500.0, INF));
    [$outerRowFrag] = $frag->children;
    assert($outerRowFrag instanceof BoxFragment);
    [$outerCellFrag] = $outerRowFrag->children;
    assert($outerCellFrag instanceof BoxFragment);

    $innerTableFrag = $outerCellFrag->children[0];
    assert($innerTableFrag instanceof BoxFragment);
    [$innerRowFrag] = $innerTableFrag->children;
    assert($innerRowFrag instanceof BoxFragment);
    [$innerCellFrag] = $innerRowFrag->children;
    assert($innerCellFrag instanceof BoxFragment);

    expect($innerCellFrag->rect->width)->toBeGreaterThan(0.0);
    $innerTextFrag = $innerCellFrag->children[0];
    assert($innerTextFrag instanceof TextFragment);
    expect($innerTextFrag->text)->toBe('inner');
});

// --- bugfix (post-review): a nested-table-only cell no longer collapses its column to 0 ---------

// Reviewer's exact overlap probe: a 2-column table where col0's ONLY content is a nested table
// (no other text/block sibling in that cell) and col1 is plain text. Before the fix, IntrinsicSizer
// skipped TableBox entirely when measuring a cell's own intrinsic content, so col0's max-content
// was 0 -- in TableFormattingContext::distributeAutoWidths()'s proportional-surplus branch, a 0 max
// against a non-zero sibling max means a 0 SHARE of the surplus too, so col0 collapsed to exactly
// 0 width while col1 took the entire table. The nested table then painted at col0's x (0), which
// was ALSO col1's x (since col0 had 0 width) -- a direct visual overlap.
it('a nested-table-only cell gets the nested table\'s real intrinsic width instead of collapsing its column to zero (reviewer overlap probe)', function () {
    $innerText = new TextRun('nested wide text here', tableStyle());
    $innerCell = new TableCellBox(tableStyle(), [$innerText], 1, 'td');
    $innerRow = new TableRowBox(tableStyle(), [$innerCell], false);
    $innerTable = new TableBox(tableStyle(), [$innerRow], 'table');

    $col0Cell = new TableCellBox(tableStyle(), [$innerTable], 1, 'td');
    $col1Text = new TextRun('col1 text', tableStyle());
    $col1Cell = new TableCellBox(tableStyle(), [$col1Text], 1, 'td');
    $row = new TableRowBox(tableStyle(), [$col0Cell, $col1Cell], false);

    // Declare the OUTER table's width as exactly the sum of the two columns' natural max-content
    // (computed independently below via the same IntrinsicSizer, not the code under test) so
    // distributeAutoWidths() takes its "Σmax <= available, no surplus" branch and returns colMax
    // UNCHANGED -- letting this test assert EXACT widths instead of only "greater than zero".
    $expectedCol0Max = $this->sizer->maxContentWidth($innerTable);
    $expectedCol1Max = $this->measurer->widthOf('col1 text', $this->face, 16.0);
    expect($expectedCol0Max)->toBeGreaterThan(0.0); // sanity: the fix must produce a REAL width here
    $declaredWidth = $expectedCol0Max + $expectedCol1Max;
    $table = new TableBox(tableStyle(['width' => LengthPercentage::px($declaredWidth)]), [$row], 'table');

    $frag = $this->ctx->layout($table, new Rect(0.0, 0.0, 1000.0, INF));
    $dump = new FragmentDumper()->dump($frag);

    [$rowFrag] = $frag->children;
    assert($rowFrag instanceof BoxFragment);
    [$col0Frag, $col1Frag] = $rowFrag->children;
    assert($col0Frag instanceof BoxFragment && $col1Frag instanceof BoxFragment);

    // col0 gets the nested table's real intrinsic width, not 0.
    expect($col0Frag->rect->width)->toEqualWithDelta($expectedCol0Max, 0.001);
    expect($col1Frag->rect->width)->toEqualWithDelta($expectedCol1Max, 0.001);

    // No overlap between the two columns: col0's border-box ends exactly where col1 begins.
    expect($col0Frag->rect->right())->toBeLessThanOrEqual($col1Frag->rect->x);

    [$innerTableFrag] = $col0Frag->children;
    assert($innerTableFrag instanceof BoxFragment);
    [$innerRowFrag] = $innerTableFrag->children;
    assert($innerRowFrag instanceof BoxFragment);
    [$innerCellFrag] = $innerRowFrag->children;
    assert($innerCellFrag instanceof BoxFragment);
    $innerTextFrag = $innerCellFrag->children[0];
    assert($innerTextFrag instanceof TextFragment);
    $col1TextFrag = $col1Frag->children[0];
    assert($col1TextFrag instanceof TextFragment);

    // The nested table's own text sits strictly LEFT of col1's text -- not at the same x, the
    // exact symptom the reviewer reported ("nested text at same x as col1").
    expect($innerTextFrag->rect->x)->toBeLessThan($col1TextFrag->rect->x);

    // Same assertions again, but via FragmentDumper's plain-array rects (task requirement): the
    // dumped rect tuple is [x, y, width, height].
    $rowDump = $dump['children'][0];
    $col0Dump = $rowDump['children'][0];
    $col1Dump = $rowDump['children'][1];
    expect($col0Dump['rect'][2])->toBeGreaterThan(0.0);

    $nestedTextDump = $col0Dump['children'][0]['children'][0]['children'][0];
    $col1TextDump = $col1Dump['children'][0];
    expect($nestedTextDump['rect'][0])->toBeLessThan($col1TextDump['rect'][0]);
});

// --- row fragments are atomic (M5-T5), the table itself never is / rows never paint their own border

it('marks the row fragment as atomic:true (M5-T5: Paginator splits between rows), the table itself stays atomic:false, and rows never paint their own border (separated model)', function () {
    $rowStyle = tableStyle([
        'border-top-width' => Length::px(5.0), 'border-top-style' => BorderStyle::Solid,
    ]);
    $row = new TableRowBox($rowStyle, [new TableCellBox(tableStyle(), [], 1, 'td')], false);
    $table = new TableBox(tableStyle(), [$row], 'table');

    $frag = $this->ctx->layout($table, new Rect(0.0, 0.0, 500.0, INF));
    [$rowFrag] = $frag->children;
    assert($rowFrag instanceof BoxFragment);

    expect($rowFrag->atomic)->toBeTrue();
    expect($frag->atomic)->toBeFalse();
    expect($rowFrag->borders->isVisible())->toBeFalse();
});

// --- BlockFlowContext integration: a table as a normal block child, cursor advances past it -----

it('BlockFlowContext delegates a TableBox child to TableFormattingContext and advances the cursor past it', function () {
    $style = tableStyle();
    $cell = new TableCellBox($style, [new TextRun('cell text', $style)], 1, 'td');
    $row = new TableRowBox($style, [$cell], false);
    $table = new TableBox($style, [$row], 'table');
    $sibling = new BlockBox($style, [new TextRun('after the table', $style)], 'p');
    $root = new BlockBox($style, [$table, $sibling], 'div');

    $frag = new BlockFlowContext($this->measurer, $this->catalog)->layout($root, new Rect(0.0, 0.0, 500.0, INF));
    [$tableFrag, $siblingFrag] = $frag->children;
    assert($tableFrag instanceof BoxFragment && $siblingFrag instanceof BoxFragment);

    expect($tableFrag->rect->height)->toBeGreaterThan(0.0);
    // No T3-style overlap: the sibling starts exactly where the table's border-box ends (default
    // margins are 0, so no extra gap either).
    expect($siblingFrag->rect->y)->toBe($tableFrag->rect->bottom());
});

// --- M5-T5: vertical-align middle/bottom, hand-computed shifts ---------------------------------

it('vertical-align: middle shifts a shorter cell\'s content down by half the delta to the row height', function () {
    $style = tableStyle();
    $short = new TextRun('one line', $style);
    $tall = [new TextRun('line one', $style), new LineBreakRun(), new TextRun('line two', $style)];
    $cellShort = new TableCellBox(tableStyle(['vertical-align' => 'middle']), [$short], 1, 'td');
    $cellTall = new TableCellBox($style, $tall, 1, 'td');
    $row = new TableRowBox($style, [$cellShort, $cellTall], false);
    $table = new TableBox($style, [$row], 'table');

    // Independent oracle (NOT the code under test): the natural, un-stretched height of each
    // cell's content, same pattern as the T4 "row height equals the tallest cell" test.
    $blockFlow = new BlockFlowContext($this->measurer, $this->catalog);
    $naturalShort = $blockFlow->layout(new BlockBox($style, [$short], 'td'), new Rect(0.0, 0.0, 1000.0, INF))->rect->height;
    $naturalTall = $blockFlow->layout(new BlockBox($style, $tall, 'td'), new Rect(0.0, 0.0, 1000.0, INF))->rect->height;
    expect($naturalTall)->toBeGreaterThan($naturalShort);

    $frag = $this->ctx->layout($table, new Rect(0.0, 0.0, 1000.0, INF));
    [$rowFrag] = $frag->children;
    assert($rowFrag instanceof BoxFragment);
    [$shortFrag, $tallFrag] = $rowFrag->children;
    assert($shortFrag instanceof BoxFragment && $tallFrag instanceof BoxFragment);

    // The cell's own box is still stretched to the full row height (background/border still
    // paint over the whole row slot) -- only its CONTENT moves, per the geometry-shift pattern.
    expect($shortFrag->rect->height)->toBe($naturalTall);
    expect($shortFrag->rect->y)->toBe($rowFrag->rect->y);

    $expectedDelta = ($naturalTall - $naturalShort) / 2.0;
    $shortText = $shortFrag->children[0];
    assert($shortText instanceof TextFragment);
    expect($shortText->rect->y)->toBeGreaterThan($shortFrag->rect->y); // top-align would give exactly rect->y
    expect($shortText->rect->y)->toEqualWithDelta($shortFrag->rect->y + $expectedDelta, 0.001);

    // The tallest cell's content is never shifted (its own contentHeight already equals the row
    // height -- delta=0, alignCell() is a no-op regardless of its vertical-align).
    $tallFirstLine = $tallFrag->children[0];
    assert($tallFirstLine instanceof TextFragment);
    expect($tallFirstLine->rect->y)->toBe($tallFrag->rect->y);
});

it('vertical-align: bottom shifts a shorter cell\'s content down by the full delta to the row height', function () {
    $style = tableStyle();
    $short = new TextRun('one line', $style);
    $tall = [new TextRun('line one', $style), new LineBreakRun(), new TextRun('line two', $style)];
    $cellShort = new TableCellBox(tableStyle(['vertical-align' => 'bottom']), [$short], 1, 'td');
    $cellTall = new TableCellBox($style, $tall, 1, 'td');
    $row = new TableRowBox($style, [$cellShort, $cellTall], false);
    $table = new TableBox($style, [$row], 'table');

    $blockFlow = new BlockFlowContext($this->measurer, $this->catalog);
    $naturalShort = $blockFlow->layout(new BlockBox($style, [$short], 'td'), new Rect(0.0, 0.0, 1000.0, INF))->rect->height;
    $naturalTall = $blockFlow->layout(new BlockBox($style, $tall, 'td'), new Rect(0.0, 0.0, 1000.0, INF))->rect->height;

    $frag = $this->ctx->layout($table, new Rect(0.0, 0.0, 1000.0, INF));
    [$rowFrag] = $frag->children;
    assert($rowFrag instanceof BoxFragment);
    [$shortFrag, $tallFrag] = $rowFrag->children;
    assert($shortFrag instanceof BoxFragment && $tallFrag instanceof BoxFragment);

    expect($shortFrag->rect->height)->toBe($naturalTall);

    $expectedDelta = $naturalTall - $naturalShort;
    $shortText = $shortFrag->children[0];
    assert($shortText instanceof TextFragment);
    // Content's own bottom edge lands exactly at the cell's bottom edge (full delta, not half).
    expect($shortText->rect->y)->toEqualWithDelta($shortFrag->rect->y + $expectedDelta, 0.001);
    expect($shortText->rect->y + $shortText->rect->height)->toEqualWithDelta($shortFrag->rect->bottom(), 0.001);
});

// --- M5-T5: row-atomic pagination integration (real TableFormattingContext output through Paginator)

it('warns and keeps a row taller than the page unsplit (M5-T1 warning channel, reused verbatim for rows)', function () {
    // A single cell stacking many lines makes its (and therefore the row's) height comfortably
    // exceed a small page content height -- the same documented "atomic taller than page" limit
    // Paginator already has for flex containers, now exercised through a real table row.
    $style = tableStyle();
    $lines = [];
    for ($i = 0; $i < 40; $i++) {
        if ($i > 0) {
            $lines[] = new LineBreakRun();
        }
        $lines[] = new TextRun("line {$i}", $style);
    }
    $cell = new TableCellBox($style, $lines, 1, 'td');
    $row = new TableRowBox($style, [$cell], false);
    $table = new TableBox($style, [$row], 'table');

    $frag = $this->ctx->layout($table, new Rect(0.0, 0.0, 500.0, INF));
    [$rowFrag] = $frag->children;
    assert($rowFrag instanceof BoxFragment);

    $pageHeight = 100.0;
    expect($rowFrag->rect->height)->toBeGreaterThan($pageHeight); // sanity: really exercises the giant-row branch

    $warnings = new WarningCollector();
    $pages = iterator_to_array(new Paginator($pageHeight, $warnings)->paginate($frag));
    expect($pages)->toHaveCount(1);
    $keptRow = $pages[0]->fragments[0];
    assert($keptRow instanceof BoxFragment);
    expect($keptRow->atomic)->toBeTrue();
    expect($keptRow->rect->height)->toBe($rowFrag->rect->height); // kept whole, not split
    expect($warnings->drain())->toBe(['atomic fragment taller than page, kept unsplit']);
});

it('splits a 30-row table between rows exactly across page boundaries, no row straddling and spacing intact within a page', function () {
    $borderSpacing = 4.0;
    $style = tableStyle(['border-spacing' => Length::px($borderSpacing)]);
    $rows = [];
    for ($i = 0; $i < 30; $i++) {
        $cell = new TableCellBox($style, [new TextRun("r{$i}", $style)], 1, 'td');
        $rows[] = new TableRowBox($style, [$cell], false);
    }
    $table = new TableBox($style, $rows, 'table');

    $frag = $this->ctx->layout($table, new Rect(0.0, 0.0, 500.0, INF));
    expect($frag->children)->toHaveCount(30);
    $firstRow = $frag->children[0];
    assert($firstRow instanceof BoxFragment);
    $rowHeight = $firstRow->rect->height;
    // All 30 rows share the same natural height (single line of similarly-sized text, no wrapping).
    foreach ($frag->children as $rowFrag) {
        assert($rowFrag instanceof BoxFragment);
        expect($rowFrag->rect->height)->toEqualWithDelta($rowHeight, 0.001);
    }

    // Force a page boundary to fall strictly INSIDE row index 15 (its middle) -- guarantees a
    // push-down is required at that row, deterministically, without hand-tuning font metrics.
    $row15Top = $borderSpacing + 15 * ($rowHeight + $borderSpacing);
    $pageHeight = $row15Top + $rowHeight / 2.0;

    $pages = iterator_to_array(new Paginator($pageHeight)->paginate($frag));
    expect(count($pages))->toBeGreaterThanOrEqual(2); // the split actually happened

    $rowFragmentsByPage = [];
    foreach ($pages as $page) {
        $rowsOnPage = [];
        foreach ($page->fragments as $leaf) {
            if ($leaf instanceof BoxFragment && $leaf->atomic) {
                $rowsOnPage[] = $leaf;
            }
        }
        $rowFragmentsByPage[] = $rowsOnPage;
    }

    // No row lost, none duplicated, none split (each row-atomic leaf keeps its original height).
    $totalRows = 0;
    $orderedTexts = [];
    foreach ($rowFragmentsByPage as $rowsOnPage) {
        $totalRows += count($rowsOnPage);
        $prevBottom = null;
        foreach ($rowsOnPage as $rowLeaf) {
            expect($rowLeaf->rect->height)->toEqualWithDelta($rowHeight, 0.001);
            // Lands page-locally: never straddles the page's own content height.
            expect($rowLeaf->rect->y + $rowLeaf->rect->height)->toBeLessThanOrEqual($pageHeight + 0.001);
            // Spacing arithmetic intact between two consecutive rows ON THE SAME PAGE.
            if ($prevBottom !== null) {
                expect($rowLeaf->rect->y - $prevBottom)->toEqualWithDelta($borderSpacing, 0.001);
            }
            $prevBottom = $rowLeaf->rect->y + $rowLeaf->rect->height;

            $cellFrag = $rowLeaf->children[0];
            assert($cellFrag instanceof BoxFragment);
            $textFrag = $cellFrag->children[0];
            assert($textFrag instanceof TextFragment);
            $orderedTexts[] = $textFrag->text;
        }
    }
    expect($totalRows)->toBe(30);
    $expectedTexts = [];
    for ($i = 0; $i < 30; $i++) {
        $expectedTexts[] = "r{$i}";
    }
    expect($orderedTexts)->toBe($expectedTexts); // document order preserved across the page split
});

// --- M5-T5: a table inside a flex card -- the outer atomic flex container governs pagination ----

it('a table nested inside an atomic flex card is never split at the row level: the outer atom governs pagination', function () {
    $style = tableStyle();
    $rows = [];
    for ($i = 0; $i < 5; $i++) {
        $cell = new TableCellBox($style, [new TextRun("row {$i}", $style)], 1, 'td');
        $rows[] = new TableRowBox($style, [$cell], false);
    }
    $table = new TableBox($style, $rows, 'table');
    // The "card": a plain block item (NOT itself display:flex) whose only child is the table --
    // FlexFormattingContext delegates a non-flex item to ITS OWN internal BlockFlowContext
    // (layoutItem(), see class docblock), which in turn delegates the nested TableBox to its
    // own lazily-autocreated TableFormattingContext (BlockFlowContext::tableContext(), see that
    // class's docblock) -- a DIFFERENT instance from $this->ctx, but functionally identical: the
    // table still lays out for real, this test only cares about the resulting atomicity/nesting.
    $card = new BlockBox($style, [$table], 'div');
    $container = new BlockBox(tableStyle(['display' => 'flex', 'width' => LengthPercentage::px(400.0)]), [$card], 'div');

    $flexCtx = new FlexFormattingContext($this->measurer, $this->catalog, $this->sizer);
    $cardFrag = $flexCtx->layout($container, new Rect(0.0, 0.0, 500.0, INF));

    expect($cardFrag->atomic)->toBeTrue(); // the flex container itself

    $pageHeight = 10.0; // deliberately tiny: the card is certainly taller than this
    expect($cardFrag->rect->height)->toBeGreaterThan($pageHeight);

    $warnings = new WarningCollector();
    $pages = iterator_to_array(new Paginator($pageHeight, $warnings)->paginate($cardFrag));

    // flatten() returns at its very first check when a fragment is atomic (see Paginator
    // docblock) -- the ENTIRE card+table+rows subtree comes out as exactly ONE leaf, never
    // decomposed into its 5 individually-atomic row fragments.
    expect($pages)->toHaveCount(1);
    expect($pages[0]->fragments)->toHaveCount(1);
    $keptWhole = $pages[0]->fragments[0];
    assert($keptWhole instanceof BoxFragment);
    expect($keptWhole->atomic)->toBeTrue();
    // Only ONE warning -- for the OUTER atom, never one per inner row (Paginator's flatten()
    // never even looks at the rows individually once the outer atomic gate returns first).
    expect($warnings->drain())->toBe(['atomic fragment taller than page, kept unsplit']);

    // The nested structure survives completely intact inside that single leaf.
    [$cardBoxFrag] = $keptWhole->children;
    assert($cardBoxFrag instanceof BoxFragment);
    [$tableFrag] = $cardBoxFrag->children;
    assert($tableFrag instanceof BoxFragment);
    expect($tableFrag->children)->toHaveCount(5);
    foreach ($tableFrag->children as $rowFrag) {
        assert($rowFrag instanceof BoxFragment);
        expect($rowFrag->atomic)->toBeTrue();
    }
});
