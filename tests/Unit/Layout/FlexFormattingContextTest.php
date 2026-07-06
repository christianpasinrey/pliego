<?php

// tests/Unit/Layout/FlexFormattingContextTest.php
declare(strict_types=1);

use Pliego\Box\BlockBox;
use Pliego\Box\ImageBox;
use Pliego\Box\TextRun;
use Pliego\Css\Value\Length;
use Pliego\Css\Value\LengthPercentage;
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

// --- empty flex container: no items, still resolves a sane box ------------------------------

it('an empty flex container with a declared height resolves to that height, no items to lay out', function () {
    $container = new BlockBox(flexStyle(['width' => LengthPercentage::px(300.0), 'height' => Length::px(40.0)]), [], 'div');
    $frag = $this->ctx->layout($container, new Rect(0.0, 0.0, 500.0, INF));

    expect($frag->children)->toBe([]);
    expect($frag->rect->height)->toBe(40.0);
});
