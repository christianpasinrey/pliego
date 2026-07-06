<?php

// tests/Unit/Layout/FlexFormattingContextTest.php
declare(strict_types=1);

use Pliego\Box\BlockBox;
use Pliego\Box\ImageBox;
use Pliego\Box\TextRun;
use Pliego\Css\Value\Length;
use Pliego\Css\Value\LengthPercentage;
use Pliego\Css\WarningCollector;
use Pliego\Layout\BlockFlowContext;
use Pliego\Layout\FlexFormattingContext;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;
use Pliego\Layout\IntrinsicSizer;
use Pliego\Layout\TextMeasurer;
use Pliego\Style\ComputedStyle;
use Pliego\Text\FontCatalog;

/** @param array<string, mixed> $declarations */
function flexStyle(array $declarations = [], ?ComputedStyle $parent = null): ComputedStyle
{
    return ComputedStyle::compute($declarations, $parent ?? ComputedStyle::root(), 'div');
}

beforeEach(function (): void {
    $this->measurer = new TextMeasurer();
    $this->catalog = FontCatalog::withDefaults();
    $this->sizer = new IntrinsicSizer($this->measurer, $this->catalog);
    $this->ctx = new FlexFormattingContext($this->measurer, $this->catalog, $this->sizer);
    $this->face = $this->catalog->select('default', 400, false);
});

// --- THE CARD: img 120px + div flex:1 + gap 12 in a 400px container ------------------------

it('THE CARD: an auto-basis flex:1 item takes exactly the remaining space next to a fixed image, same line', function () {
    // css-flexbox-1 §9.2: el div sin flex-basis propio pero con `flex-grow:1` y `flex-basis:0`
    // (equivalente a `flex: 1`) parte de base 0; §9.7: libre = 400 - 120(img) - 12(gap) = 268,
    // todo absorbido por el único item con grow>0 -> 0 + 268 = 268 exacto.
    $img = new ImageBox(flexStyle(['width' => LengthPercentage::px(120.0)]), 'card.jpg', 120, 120, null, null);
    $div = new BlockBox(flexStyle(['flex-grow' => 1.0, 'flex-shrink' => 1.0, 'flex-basis' => LengthPercentage::zero()]), [], 'div');
    $container = new BlockBox(
        flexStyle(['width' => LengthPercentage::px(400.0), 'column-gap' => Length::px(12.0)]),
        [$img, $div],
        'div',
    );

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    [$imgFrag, $divFrag] = $frag->children;
    assert($imgFrag instanceof BoxFragment && $divFrag instanceof BoxFragment);

    expect($imgFrag->rect->x)->toBe(0.0);
    expect($imgFrag->rect->width)->toBe(120.0);
    expect($divFrag->rect->x)->toBe(132.0); // 120 + 12 gap
    expect($divFrag->rect->width)->toBe(268.0); // 400 - 120 - 12, exactly
    expect($imgFrag->rect->y)->toBe($divFrag->rect->y); // same line
});

// --- CARRY-OVER FIX (T4 review): an item with its own declared width must still grow ----------

it('CARRY-OVER: an item with its own declared width still grows via flex-grow, no gap left behind', function () {
    // Container 400px; A declares width:100px but flex-grow:1 (browsers grow it to fill the
    // leftover); B declares width:100px, flex-grow:0 (stays at 100). Before the fix, A's own
    // BlockFlowContext branch ignored the resolved flex main size entirely and rendered at
    // 100px, leaving a 200px hole between A and B instead of A growing to 300px.
    $a = new BlockBox(flexStyle(['width' => LengthPercentage::px(100.0), 'flex-grow' => 1.0]), [], 'div');
    $b = new BlockBox(flexStyle(['width' => LengthPercentage::px(100.0)]), [], 'div');
    $container = new BlockBox(flexStyle(['width' => LengthPercentage::px(400.0)]), [$a, $b], 'div');

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    [$aFrag, $bFrag] = $frag->children;
    assert($aFrag instanceof BoxFragment && $bFrag instanceof BoxFragment);

    expect($aFrag->rect->x)->toBe(0.0);
    expect($aFrag->rect->width)->toBe(300.0); // grown from its own 100px to fill the leftover
    expect($bFrag->rect->x)->toBe(300.0); // flush against A, no 200px hole
    expect($bFrag->rect->width)->toBe(100.0);
});

// --- flex-grow 2:1 splits the leftover proportionally ---------------------------------------

it('splits leftover space 2:1 between two flex-grow items with a zero basis', function () {
    $a = new BlockBox(flexStyle(['flex-grow' => 2.0, 'flex-shrink' => 1.0, 'flex-basis' => LengthPercentage::zero()]), [], 'div');
    $b = new BlockBox(flexStyle(['flex-grow' => 1.0, 'flex-shrink' => 1.0, 'flex-basis' => LengthPercentage::zero()]), [], 'div');
    $container = new BlockBox(flexStyle(['width' => LengthPercentage::px(300.0)]), [$a, $b], 'div');

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    [$aFrag, $bFrag] = $frag->children;
    assert($aFrag instanceof BoxFragment && $bFrag instanceof BoxFragment);

    expect($aFrag->rect->width)->toBe(200.0);
    expect($bFrag->rect->width)->toBe(100.0);
    expect($aFrag->rect->x)->toBe(0.0);
    expect($bFrag->rect->x)->toBe(200.0);
});

// --- shrink with a min-content clamp, verifying the one-pass re-distribution ----------------

it('clamps a shrinking item at its min-content and redistributes the remaining deficit to the other item', function () {
    // A's flex-basis is comfortably above its own min-content (the width of its one word, "w"):
    // Ba = w + 10. B's basis is half of that. The container demands an overall 50% shrink of
    // (Ba+Bb), which would push A below "w" if applied proportionally — forcing the clamp.
    $word = 'palabralarguisimaparaelclamp';
    $style = flexStyle();
    $w = $this->measurer->widthOf($word, $this->face, 16.0);
    $ba = $w + 10.0;
    $bb = $ba / 2.0;
    $containerWidth = $ba + $bb - 20.0; // demands a fixed 20px deficit (see report for the algebra)

    $a = new BlockBox(flexStyle(['flex-basis' => LengthPercentage::px($ba)], $style), [new TextRun($word, $style)], 'div');
    $b = new BlockBox(flexStyle(['flex-basis' => LengthPercentage::px($bb)], $style), [], 'div');
    $container = new BlockBox(flexStyle(['width' => LengthPercentage::px($containerWidth)], $style), [$a, $b], 'div');

    // Sanity: the naive (unclamped) proportional share for A would undershoot its min-content —
    // confirms this scenario actually exercises the clamp+redistribution path, not a no-op.
    $naiveA = $ba - 20.0 * ($ba / ($ba + $bb));
    expect($naiveA)->toBeLessThan($w);

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    [$aFrag, $bFrag] = $frag->children;
    assert($aFrag instanceof BoxFragment && $bFrag instanceof BoxFragment);

    expect($aFrag->rect->width)->toEqualWithDelta($w, 0.001); // clamped to its min-content
    expect($bFrag->rect->width)->toEqualWithDelta(($w - 10.0) / 2.0, 0.001); // absorbs the rest
    // both fit exactly in the container, no overflow left over
    expect($aFrag->rect->width + $bFrag->rect->width)->toEqualWithDelta($containerWidth, 0.001);
});

// --- justify-content: center / space-between, hand-computed -------------------------------

it('justify-content: center centers three fixed-width items around the leftover space', function () {
    $items = [];
    for ($i = 0; $i < 3; $i++) {
        $items[] = new BlockBox(flexStyle(['flex-basis' => LengthPercentage::px(50.0)]), [], 'div');
    }
    $container = new BlockBox(flexStyle(['width' => LengthPercentage::px(200.0), 'justify-content' => 'center']), $items, 'div');

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    // leftover = 200 - 150 = 50; centered -> 25px on each side.
    expect($frag->children[0]->rect()->x)->toBe(25.0);
    expect($frag->children[1]->rect()->x)->toBe(75.0);
    expect($frag->children[2]->rect()->x)->toBe(125.0);
});

it('justify-content: space-between anchors the first/last items and splits the leftover into the gaps', function () {
    $items = [];
    for ($i = 0; $i < 3; $i++) {
        $items[] = new BlockBox(flexStyle(['flex-basis' => LengthPercentage::px(50.0)]), [], 'div');
    }
    $container = new BlockBox(flexStyle(['width' => LengthPercentage::px(200.0), 'justify-content' => 'space-between']), $items, 'div');

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    // leftover = 50 split into 2 gaps of 25 each.
    expect($frag->children[0]->rect()->x)->toBe(0.0);
    expect($frag->children[1]->rect()->x)->toBe(75.0);
    expect($frag->children[2]->rect()->x)->toBe(150.0);
    expect($frag->children[2]->rect()->right())->toBe(200.0); // last item flush against the end
});

it('justify-content: space-between with a single item falls back to the start (no gaps to split)', function () {
    $item = new BlockBox(flexStyle(['flex-basis' => LengthPercentage::px(50.0)]), [], 'div');
    $container = new BlockBox(flexStyle(['width' => LengthPercentage::px(200.0), 'justify-content' => 'space-between']), [$item], 'div');

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    expect($frag->children[0]->rect()->x)->toBe(0.0);
});

// --- align-items: center / stretch -----------------------------------------------------------

it('align-items: center positions a shorter image halfway into the taller item\'s cross size', function () {
    $short = new ImageBox(flexStyle(), 'short.jpg', 30, 20, 30.0, 20.0);
    $tall = new ImageBox(flexStyle(), 'tall.jpg', 30, 60, 30.0, 60.0);
    $container = new BlockBox(
        flexStyle(['width' => LengthPercentage::px(300.0), 'align-items' => 'center']),
        [$short, $tall],
        'div',
    );

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    [$shortFrag, $tallFrag] = $frag->children;
    assert($shortFrag instanceof BoxFragment && $tallFrag instanceof BoxFragment);

    expect($tallFrag->rect->height)->toBe(60.0);
    expect($tallFrag->rect->y)->toBe(0.0); // as tall as the line, no offset
    expect($shortFrag->rect->height)->toBe(20.0);
    expect($shortFrag->rect->y)->toBe(20.0); // (60 - 20) / 2
});

it('align-items: stretch grows an auto-height item\'s box to the line cross size, content anchored top', function () {
    $childStyle = flexStyle();
    $item = new BlockBox($childStyle, [new TextRun('x', $childStyle)], 'div');
    // align-items defaults to Stretch (css-flexbox-1 §8.3 initial value) — no need to declare it.
    $container = new BlockBox(flexStyle(['width' => LengthPercentage::px(300.0), 'height' => Length::px(100.0)]), [$item], 'div');

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    $itemFrag = $frag->children[0];
    assert($itemFrag instanceof BoxFragment);
    $text = $itemFrag->children[0];
    assert($text instanceof TextFragment);

    // The declared container height (100) becomes the line cross size; stretch grows the item's
    // BoxFragment (background/border box) to match it — a geometry-only approximation (documented
    // in FlexFormattingContext::withHeight()): the text content itself stays anchored at the top,
    // it is NOT re-laid-out or re-centered within the stretched box.
    expect($itemFrag->rect->height)->toBe(100.0);
    expect($text->rect->y)->toBe(0.0);
    expect($frag->rect->height)->toBe(100.0);
});

it('align-items: stretch does not stretch an image with an explicit height (definite cross size)', function () {
    $img = new ImageBox(flexStyle(), 'fixed.jpg', 10, 10, 10.0, 10.0);
    $container = new BlockBox(flexStyle(['width' => LengthPercentage::px(300.0), 'height' => Length::px(50.0)]), [$img], 'div');

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    $imgFrag = $frag->children[0];
    assert($imgFrag instanceof BoxFragment);

    // Falls back to flex-start (per css-flexbox-1 §8.3: stretch only applies to items whose
    // cross size is auto) instead of being forced to the 50px line cross size.
    expect($imgFrag->rect->height)->toBe(10.0);
    expect($imgFrag->rect->y)->toBe(0.0);
});

// --- baseline sanity: a single non-growing flex item behaves like a plain block -------------

it('a single flex item with no grow renders identical geometry to the same box laid out as a plain block', function () {
    $itemStyle = flexStyle(['width' => LengthPercentage::px(200.0)]);
    $item = new BlockBox($itemStyle, [], 'div');

    $flexContainer = new BlockBox(flexStyle(['width' => LengthPercentage::px(300.0)]), [$item], 'div');
    $flexFrag = $this->ctx->layout($flexContainer, new Rect(0.0, 0.0, 500.0, INF));
    $flexItemFrag = $flexFrag->children[0];
    assert($flexItemFrag instanceof BoxFragment);

    $blockFlow = new BlockFlowContext($this->measurer, $this->catalog);
    $plainContainer = new BlockBox(flexStyle(['width' => LengthPercentage::px(300.0)]), [$item], 'div');
    $plainFrag = $blockFlow->layout($plainContainer, new Rect(0.0, 0.0, 500.0, INF));
    $plainItemFrag = $plainFrag->children[0];
    assert($plainItemFrag instanceof BoxFragment);

    expect($flexItemFrag->rect)->toEqual($plainItemFrag->rect);
});

// --- M4-T5: the flex container fragment is ATOMIC for pagination purposes --------------------

it('marks the flex container fragment as atomic (BoxFragment::$atomic === true)', function () {
    $item = new BlockBox(flexStyle(), [], 'div');
    $container = new BlockBox(flexStyle(['width' => LengthPercentage::px(100.0)]), [$item], 'div');
    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    expect($frag->atomic)->toBeTrue();
});

it('marks an empty flex container fragment as atomic too', function () {
    $container = new BlockBox(flexStyle(['width' => LengthPercentage::px(100.0)]), [], 'div');
    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    expect($frag->atomic)->toBeTrue();
});

// --- M4-T5 §9.3 wrap: items split into flex LINES when their sum overflows the main size ------

it('wraps 3 items into 2 flex lines of different cross sizes, stacked with row-gap between them', function () {
    // Container 200px wide, no gaps between items on the same line (column-gap: 0), row-gap: 10
    // between LINES. Item widths 80/80/80 (flex-basis, no grow/shrink): item1+item2 = 160 <= 200
    // fits on line 1; item3 would push it to 240 > 200 => opens line 2 on its own.
    $item1 = new ImageBox(flexStyle(['flex-basis' => LengthPercentage::px(80.0)]), 'a.jpg', 80, 30, null, 30.0);
    $item2 = new ImageBox(flexStyle(['flex-basis' => LengthPercentage::px(80.0)]), 'b.jpg', 80, 50, null, 50.0);
    $item3 = new ImageBox(flexStyle(['flex-basis' => LengthPercentage::px(80.0)]), 'c.jpg', 80, 20, null, 20.0);
    $container = new BlockBox(
        flexStyle(['width' => LengthPercentage::px(200.0), 'flex-wrap' => 'wrap', 'row-gap' => Length::px(10.0)]),
        [$item1, $item2, $item3],
        'div',
    );

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    [$f1, $f2, $f3] = $frag->children;
    assert($f1 instanceof BoxFragment && $f2 instanceof BoxFragment && $f3 instanceof BoxFragment);

    // Line 1: item1 (h30) + item2 (h50), cross size = max(30,50) = 50, both at y=0.
    expect($f1->rect->x)->toBe(0.0);
    expect($f1->rect->y)->toBe(0.0);
    expect($f1->rect->height)->toBe(30.0); // definite cross size (attrHeight), no stretch
    expect($f2->rect->x)->toBe(80.0);
    expect($f2->rect->y)->toBe(0.0);
    expect($f2->rect->height)->toBe(50.0);

    // Line 2 starts at y = 50 (line 1 cross) + 10 (row-gap): item3 back at x=0.
    expect($f3->rect->x)->toBe(0.0);
    expect($f3->rect->y)->toBe(60.0);
    expect($f3->rect->height)->toBe(20.0);

    // Container height hugs both lines: 50 + 10 (row-gap) + 20 = 80.
    expect($frag->rect->height)->toBe(80.0);
});

it('keeps a single item wider than the container on its own line, unsplit, when wrap is enabled', function () {
    $wide = new ImageBox(flexStyle(['flex-basis' => LengthPercentage::px(150.0)]), 'wide.jpg', 150, 40, null, 40.0);
    $narrow = new ImageBox(flexStyle(['flex-basis' => LengthPercentage::px(50.0)]), 'narrow.jpg', 50, 20, null, 20.0);
    $container = new BlockBox(
        flexStyle(['width' => LengthPercentage::px(100.0), 'flex-wrap' => 'wrap', 'row-gap' => Length::px(5.0)]),
        [$wide, $narrow],
        'div',
    );

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    [$wideFrag, $narrowFrag] = $frag->children;
    assert($wideFrag instanceof BoxFragment && $narrowFrag instanceof BoxFragment);

    // The wide item alone overflows the 100px container but is NOT split/shrunk: it stays at its
    // full 150px hypothetical size on its own line (first item in a line is never rejected).
    expect($wideFrag->rect->width)->toBe(150.0);
    expect($wideFrag->rect->x)->toBe(0.0);
    expect($wideFrag->rect->y)->toBe(0.0);

    // Line 2 (narrow alone) starts at y = 40 (line 1 cross) + 5 (row-gap).
    expect($narrowFrag->rect->y)->toBe(45.0);
    expect($narrowFrag->rect->x)->toBe(0.0);
});

// --- M4-T5: flex-direction: column ------------------------------------------------------------

it('column: stacks 3 items vertically with their own CSS heights and row-gap between them, auto height hugs content', function () {
    $childStyle = flexStyle();
    $a = new BlockBox(flexStyle(['height' => Length::px(30.0)], $childStyle), [], 'div');
    $b = new BlockBox(flexStyle(['height' => Length::px(40.0)], $childStyle), [], 'div');
    $c = new BlockBox(flexStyle(['height' => Length::px(50.0)], $childStyle), [], 'div');
    $container = new BlockBox(
        flexStyle(['width' => LengthPercentage::px(200.0), 'flex-direction' => 'column', 'row-gap' => Length::px(10.0)]),
        [$a, $b, $c],
        'div',
    );

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    [$aFrag, $bFrag, $cFrag] = $frag->children;
    assert($aFrag instanceof BoxFragment && $bFrag instanceof BoxFragment && $cFrag instanceof BoxFragment);

    expect($aFrag->rect->y)->toBe(0.0);
    expect($aFrag->rect->height)->toBe(30.0);
    expect($bFrag->rect->y)->toBe(40.0); // 30 + 10 (row-gap, the main-axis gap in column)
    expect($bFrag->rect->height)->toBe(40.0);
    expect($cFrag->rect->y)->toBe(90.0); // 40 + 40 + 10
    expect($cFrag->rect->height)->toBe(50.0);

    // Cross size (width): align-items defaults to Stretch, none of the items has an own width ->
    // all three stretch to the container's full content width.
    expect($aFrag->rect->width)->toBe(200.0);
    expect($bFrag->rect->width)->toBe(200.0);
    expect($cFrag->rect->width)->toBe(200.0);

    // Auto container height hugs the 3 items + 2 gaps: 30 + 40 + 50 + 2*10 = 140.
    expect($frag->rect->height)->toBe(140.0);
});

it('column: items without an explicit height fall back to their NATURAL height, measured via a throwaway layout at the container content width (M4-T6)', function () {
    // Untested branch flagged by the T5 report: layoutColumnContainer()'s base[] computation when
    // NEITHER flex-basis NOR the item's own CSS height is set (§9.2 "auto" falling through to
    // natural height, see its docblock) -- every prior column test declared an explicit height on
    // every item. A short one-line item and a longer paragraph that wraps to several lines get
    // DIFFERENT natural heights; the oracle is an INDEPENDENT plain BlockFlowContext layout at the
    // very same content width (150px, no padding/border on the container) FlexFormattingContext's
    // internal throwaway measurement pass uses -- not a tautological recomputation of the same
    // code path, since it goes through a fresh BlockFlowContext instance instead.
    $childStyle = flexStyle();
    $shortText = new TextRun('Corto', $childStyle);
    $longText = new TextRun(
        'Un paragrafo abbastanza lungo da avvolgersi su più righe dentro la larghezza del contenitore.',
        $childStyle,
    );
    $a = new BlockBox($childStyle, [$shortText], 'div');
    $b = new BlockBox($childStyle, [$longText], 'div');
    $container = new BlockBox(
        flexStyle(['width' => LengthPercentage::px(150.0), 'flex-direction' => 'column', 'row-gap' => Length::px(5.0)]),
        [$a, $b],
        'div',
    );

    $blockFlow = new BlockFlowContext($this->measurer, $this->catalog);
    $expectedAHeight = $blockFlow->layout(new BlockBox($childStyle, [$shortText], 'div'), new Rect(0.0, 0.0, 150.0, INF))->rect->height;
    $expectedBHeight = $blockFlow->layout(new BlockBox($childStyle, [$longText], 'div'), new Rect(0.0, 0.0, 150.0, INF))->rect->height;
    // Sanity: the long paragraph actually wraps to more lines than the short one, so this test
    // exercises a REAL difference in natural height, not two items that happen to match by luck.
    expect($expectedBHeight)->toBeGreaterThan($expectedAHeight);

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    [$aFrag, $bFrag] = $frag->children;
    assert($aFrag instanceof BoxFragment && $bFrag instanceof BoxFragment);

    expect($aFrag->rect->height)->toBe($expectedAHeight);
    expect($aFrag->rect->y)->toBe(0.0);
    expect($bFrag->rect->height)->toBe($expectedBHeight);
    expect($bFrag->rect->y)->toBe($expectedAHeight + 5.0); // row-gap after A's natural height

    // Auto container height hugs both natural heights plus the single row-gap between them.
    expect($frag->rect->height)->toBe($expectedAHeight + $expectedBHeight + 5.0);
});

it('column: align-items stretch widens an auto-width item but leaves one with its own declared width untouched', function () {
    $auto = new BlockBox(flexStyle(['height' => Length::px(20.0)]), [], 'div');
    $ownWidth = new BlockBox(flexStyle(['height' => Length::px(20.0), 'width' => LengthPercentage::px(100.0)]), [], 'div');
    $container = new BlockBox(
        flexStyle(['width' => LengthPercentage::px(300.0), 'flex-direction' => 'column']),
        [$auto, $ownWidth],
        'div',
    );

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    [$autoFrag, $ownFrag] = $frag->children;
    assert($autoFrag instanceof BoxFragment && $ownFrag instanceof BoxFragment);

    expect($autoFrag->rect->width)->toBe(300.0); // stretched to the full content width
    expect($ownFrag->rect->width)->toBe(100.0); // its own width wins, falls back to flex-start
    expect($ownFrag->rect->x)->toBe(0.0);
});

// --- empty flex container: no items, still resolves a sane box ------------------------------

it('an empty flex container with a declared height resolves to that height, no items to lay out', function () {
    $container = new BlockBox(flexStyle(['width' => LengthPercentage::px(300.0), 'height' => Length::px(40.0)]), [], 'div');
    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));

    expect($frag->children)->toBe([]);
    expect($frag->rect->height)->toBe(40.0);
});

// --- M4 final-review Finding 1: width override must reach a nested flex-container item -------

it('a nested display:flex item receives the resolved width override too, no gap left behind', function () {
    // Container 500px, two items: `inner` is ITSELF a flex container declaring its own
    // width:100px but flex-grow:1, `sibling` declares width:100px with no grow. §9.7: free =
    // 500 - 100 - 100 = 300, absorbed entirely by inner (the only grow>0 item) -> inner's
    // resolved main size = 100 + 300 = 400. Before the fix, layoutItem() skipped the override for
    // items whose OWN display is flex (it re-invoked FlexFormattingContext::layout() with no
    // usedWidthOverride at all), so inner's own layout() re-resolved its declared width:100px
    // from scratch -- rendering at 100px instead of 400px and leaving a 300px hole between inner's
    // painted right edge (100) and sibling's left edge (400), the exact T4 gap bug resurfacing for
    // this item kind.
    $inner = new BlockBox(flexStyle(['display' => 'flex', 'width' => LengthPercentage::px(100.0), 'flex-grow' => 1.0]), [], 'div');
    $sibling = new BlockBox(flexStyle(['width' => LengthPercentage::px(100.0)]), [], 'div');
    $container = new BlockBox(flexStyle(['width' => LengthPercentage::px(500.0)]), [$inner, $sibling], 'div');

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 600.0, INF));
    [$innerFrag, $siblingFrag] = $frag->children;
    assert($innerFrag instanceof BoxFragment && $siblingFrag instanceof BoxFragment);

    expect($innerFrag->rect->x)->toBe(0.0);
    expect($innerFrag->rect->width)->toBe(400.0); // grown via flex-grow, override reaches the nested container's own box resolution
    expect($siblingFrag->rect->x)->toBe(400.0); // flush against inner's grown right edge, no 300px hole
    expect($siblingFrag->rect->width)->toBe(100.0);
});

it('a nested display:flex item with no grow (no leftover to distribute) is unaffected by the override plumbing', function () {
    // Regression companion to the probe above: when the resolved main size already equals the
    // item's own declared width (no grow/shrink adjusts it), threading the override through must
    // be a pure no-op -- same geometry with or without the fix.
    $inner = new BlockBox(flexStyle(['display' => 'flex', 'width' => LengthPercentage::px(150.0)]), [], 'div');
    $container = new BlockBox(flexStyle(['width' => LengthPercentage::px(300.0)]), [$inner], 'div');

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    $innerFrag = $frag->children[0];
    assert($innerFrag instanceof BoxFragment);

    expect($innerFrag->rect->width)->toBe(150.0);
    expect($innerFrag->rect->x)->toBe(0.0);
});

it('a top-level flex container (no override passed in) resolves its own declared width exactly as before', function () {
    // Regression: FlexFormattingContext::layout() is also THE entry point Engine/BlockFlowContext
    // call with no third argument at all -- the new optional parameter must not disturb that path.
    $item = new BlockBox(flexStyle(), [], 'div');
    $container = new BlockBox(flexStyle(['width' => LengthPercentage::px(250.0)]), [$item], 'div');

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));

    expect($frag->rect->width)->toBe(250.0);
});

// --- M4 final-review Finding 2(a): row cross-axis margins count toward line cross size --------

it('row: an item cross-axis (vertical) margin counts toward the line cross size and the container height, §9.4 OUTER size', function () {
    // item1: 20px tall, no margin. item2: 20px tall with margin-top:30 (margin-bottom:0). Outer
    // cross size of item2 = 30 (margin-top) + 20 (height) + 0 (margin-bottom) = 50 -- the true
    // line cross size per §9.4 (OUTER, margin-inclusive). Before the fix, the line cross was
    // computed from BORDER-BOX heights only (max(20, 20) = 20): item2's box (already pushed down
    // by its own margin-top, applied by BlockFlowContext regardless) painted its bottom edge at
    // 50px while the container -- sized off the wrong (too-small) line cross -- stopped at 20px,
    // a full 30px overflow past the container's bottom edge.
    $item1 = new ImageBox(flexStyle(), 'a.jpg', 20, 20, 20.0, 20.0);
    $item2 = new ImageBox(flexStyle(['margin-top' => LengthPercentage::px(30.0)]), 'b.jpg', 20, 20, 20.0, 20.0);
    $container = new BlockBox(flexStyle(['width' => LengthPercentage::px(200.0)]), [$item1, $item2], 'div');

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    [$f1, $f2] = $frag->children;
    assert($f1 instanceof BoxFragment && $f2 instanceof BoxFragment);

    expect($f2->rect->y)->toBe(30.0); // margin-top still applied to the box itself, unchanged
    expect($f2->rect->bottom())->toBe(50.0); // 30 (margin-top) + 20 (height)
    expect($frag->rect->height)->toBe(50.0); // container grows to the OUTER cross size, no overflow past its own bottom edge
});

it('row: align-items stretch shrinks its stretch target by the item\'s own cross-axis margins', function () {
    // Container declares height:100 (forces the single line's cross size to 100). item has
    // margin-top:10, margin-bottom:20 and no definite cross size -> candidate for stretch. Its
    // OUTER box (margin-top + stretched height + margin-bottom) must fill the 100px line exactly:
    // stretched height = 100 - 10 - 20 = 70, box top at y=10 (margin-top), box bottom at 80,
    // leaving the declared margin-bottom of 20 below it (80 + 20 = 100).
    $childStyle = flexStyle();
    $item = new BlockBox(flexStyle(['margin-top' => LengthPercentage::px(10.0), 'margin-bottom' => LengthPercentage::px(20.0)], $childStyle), [], 'div');
    $container = new BlockBox(flexStyle(['width' => LengthPercentage::px(300.0), 'height' => Length::px(100.0)]), [$item], 'div');

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    $itemFrag = $frag->children[0];
    assert($itemFrag instanceof BoxFragment);

    expect($itemFrag->rect->y)->toBe(10.0);
    expect($itemFrag->rect->height)->toBe(70.0);
    expect($itemFrag->rect->bottom())->toBe(80.0);
});

// --- M4 final-review Finding 2(b): column stretch subtracts the item's own horizontal margins --

it('column: align-items stretch subtracts the item\'s own horizontal margins from the stretch width, no overflow past the content edge', function () {
    // Container content width 300px; item declares margin-right:20px and no width of its own ->
    // candidate for stretch. Before the fix, the stretch override was the FULL content width
    // (300), so the item's box (positioned at contentX + margin-left = 0) painted a 300px-wide
    // box whose right edge landed at 300 -- 20px PAST where it should stop (300 - 20 = 280, the
    // content edge minus its own declared margin-right).
    $item = new BlockBox(flexStyle(['height' => Length::px(20.0), 'margin-right' => LengthPercentage::px(20.0)]), [], 'div');
    $container = new BlockBox(
        flexStyle(['width' => LengthPercentage::px(300.0), 'flex-direction' => 'column']),
        [$item],
        'div',
    );

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    $itemFrag = $frag->children[0];
    assert($itemFrag instanceof BoxFragment);

    expect($itemFrag->rect->x)->toBe(0.0);
    expect($itemFrag->rect->width)->toBe(280.0); // 300 - 20 (margin-right)
    expect($itemFrag->rect->right())->toBe(280.0); // flush against the margin, not the raw content edge
});

// --- M5-T1: warning channel (column justify-content ignored without a declared height) ---------

it('warns exactly once when column justify-content has no effect because the container has auto height', function () {
    $warnings = new WarningCollector();
    $ctx = new FlexFormattingContext($this->measurer, $this->catalog, $this->sizer, $warnings);
    $a = new BlockBox(flexStyle(['height' => Length::px(20.0)]), [], 'div');
    $b = new BlockBox(flexStyle(['height' => Length::px(20.0)]), [], 'div');
    $container = new BlockBox(
        flexStyle(['width' => LengthPercentage::px(200.0), 'flex-direction' => 'column', 'justify-content' => 'center']),
        [$a, $b],
        'div',
    );

    $ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));

    expect($warnings->drain())->toBe([
        'flex column: justify-content has no effect without a declared container height (auto height hugs content)',
    ]);
});

it('does not warn about column justify-content when the container DOES declare a height', function () {
    $warnings = new WarningCollector();
    $ctx = new FlexFormattingContext($this->measurer, $this->catalog, $this->sizer, $warnings);
    $a = new BlockBox(flexStyle(['height' => Length::px(20.0)]), [], 'div');
    $container = new BlockBox(
        flexStyle(['width' => LengthPercentage::px(200.0), 'height' => Length::px(100.0), 'flex-direction' => 'column', 'justify-content' => 'center']),
        [$a],
        'div',
    );

    $ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));

    expect($warnings->drain())->toBeEmpty();
});

it('does not warn (stays silent) when no WarningCollector is injected, same column-without-height scenario', function () {
    // Regression: the new constructor parameter is OPTIONAL (null = silent), so $this->ctx (built
    // with no 4th argument in beforeEach) must keep behaving exactly as before this task.
    $a = new BlockBox(flexStyle(['height' => Length::px(20.0)]), [], 'div');
    $container = new BlockBox(
        flexStyle(['width' => LengthPercentage::px(200.0), 'flex-direction' => 'column', 'justify-content' => 'center']),
        [$a],
        'div',
    );
    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    expect($frag->rect->width)->toBe(200.0); // just proving layout() ran fine with no collector
});

// --- M5-T1 (housekeeping): memoized hypothetical sizes + reused (translated) natural layout on
// align-items offset must produce IDENTICAL geometry to before the refactor -----------------------

it('A/B: wrap + align-items:center produces the exact same geometry as hand-computed, exercising both the memoized bases and the translated (not relaid-out) offset fragment', function () {
    // Reuses the existing wrap fixture's shape (3 items, container 200px wide, wraps into 2
    // lines) but adds align-items:center (default is Stretch) so BOTH refactored paths fire
    // together: splitIntoLines()+resolveMainSizes() share the SAME memoized hypotheticalMainSize
    // per item (instead of recomputing), and the per-item vertical centering offset reuses the
    // natural fragment via a geometry-only Y translation instead of a second layoutItem() call.
    $item1 = new ImageBox(flexStyle(['flex-basis' => LengthPercentage::px(80.0)]), 'a.jpg', 80, 30, null, 30.0);
    $item2 = new ImageBox(flexStyle(['flex-basis' => LengthPercentage::px(80.0)]), 'b.jpg', 80, 50, null, 50.0);
    $item3 = new ImageBox(flexStyle(['flex-basis' => LengthPercentage::px(80.0)]), 'c.jpg', 80, 20, null, 20.0);
    $container = new BlockBox(
        flexStyle([
            'width' => LengthPercentage::px(200.0),
            'flex-wrap' => 'wrap',
            'row-gap' => Length::px(10.0),
            'align-items' => 'center',
        ]),
        [$item1, $item2, $item3],
        'div',
    );

    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));
    [$f1, $f2, $f3] = $frag->children;
    assert($f1 instanceof BoxFragment && $f2 instanceof BoxFragment && $f3 instanceof BoxFragment);

    // Line 1 cross size = max(30, 50) = 50. item1 (h30) is centered: offset = (50-30)/2 = 10.
    // item2 (h50) fills the line exactly: offset = 0, stays as the natural fragment.
    expect($f1->rect->x)->toBe(0.0);
    expect($f1->rect->y)->toBe(10.0); // centered within line 1's 50px cross size
    expect($f1->rect->height)->toBe(30.0);
    expect($f2->rect->x)->toBe(80.0);
    expect($f2->rect->y)->toBe(0.0);
    expect($f2->rect->height)->toBe(50.0);

    // Line 2 (just item3, h20) starts at y = 50 (line 1 cross) + 10 (row-gap) = 60; with a single
    // item, its own height IS the line cross, so centering offset is 0 -- unaffected either way.
    expect($f3->rect->x)->toBe(0.0);
    expect($f3->rect->y)->toBe(60.0);
    expect($f3->rect->height)->toBe(20.0);

    expect($frag->rect->height)->toBe(80.0); // same total as the original wrap test (unaffected by align-items)
});
