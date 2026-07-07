<?php

// tests/EndToEnd/FlexInlineBlockItemsTest.php
declare(strict_types=1);

use Pliego\Box\BoxTreeBuilder;
use Pliego\Css\StylesheetParser;
use Pliego\Css\WarningCollector;
use Pliego\Dom\HtmlParser;
use Pliego\Image\ImageLoader;
use Pliego\Layout\BlockFlowContext;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Geometry\Rect;
use Pliego\Layout\TextMeasurer;
use Pliego\Style\CssStyleSource;
use Pliego\Style\StyleResolver;
use Pliego\Text\FontCatalog;

/**
 * M10 final-review Finding A (css-flexbox-1 §4): closing E2E for the InlineBlock-widening of the
 * M10-T2 flex-item fix (see Box\BoxTreeBuilder::collectChildren()'s own docblock for the full root
 * cause) at the LAYOUT level, not just the box-tree-shape level BoxTreeBuilderTest.php already
 * covers -- two adjacent `display:inline-block` children of a flex container must resolve to two
 * genuinely separate flex items with their OWN geometry (so `justify-content:space-between` has
 * two items to space apart, and a `.btn-group`-shaped `inline-flex` container lays out each button
 * at its own position instead of one shared, wrongly-sized anonymous box).
 *
 * Helper functions use a `flexInlineBlock` prefix -- same "runnable in isolation" reasoning as
 * every other E2E file in this suite (BootstrapComponentsTest.php's own docblock spells out why:
 * Pest loads every test file into one process, so top-level function names must be unique
 * document-wide).
 */
function flexInlineBlockLayoutFragment(string $html, string $css, float $width, WarningCollector $warnings): BoxFragment
{
    $doc = HtmlParser::parse($html);
    $parseResult = new StylesheetParser()->parse($css);
    foreach ($parseResult->warnings as $warning) {
        $warnings->addWarning($warning);
    }
    $map = new StyleResolver([new CssStyleSource($parseResult)], $warnings)->resolve($doc);
    $root = new BoxTreeBuilder(new ImageLoader(), $warnings, __DIR__)->build($doc, $map);
    return new BlockFlowContext(new TextMeasurer(), FontCatalog::withDefaults(), $warnings)
        ->layout($root, new Rect(0.0, 0.0, $width, INF));
}

it('Finding A: two inline-block spans in a justify-content:space-between flex container land at opposite edges, as TWO separate items', function () {
    $warnings = new WarningCollector();
    $fragment = flexInlineBlockLayoutFragment(
        '<body><div class="row"><span class="ib">a</span><span class="ib">b</span></div></body>',
        '.row { display: flex; justify-content: space-between; width: 200px }
         .ib { display: inline-block; width: 20px }',
        794.0,
        $warnings,
    );
    $row = $fragment->children[0];
    assert($row instanceof BoxFragment);
    // Before Finding A's fix, both inline-block spans merged into ONE shared anonymous flex item
    // -- space-between would have seen a single item (no gap to split, no spacing applied at all).
    expect($row->children)->toHaveCount(2);
    [$a, $b] = $row->children;
    assert($a instanceof BoxFragment && $b instanceof BoxFragment);
    // space-between anchors the first item at the start edge and the last at the end edge
    // (container width 200, each item width 20 -- 180px of leftover space between them).
    expect($a->rect->x)->toBe(0.0);
    expect($b->rect->x)->toBe(180.0);
});

it('Finding A: .btn-group-shaped markup (inline-flex container, plain inline-block children, no gap/justify) lays out each child at its own natural position, not merged', function () {
    $warnings = new WarningCollector();
    $fragment = flexInlineBlockLayoutFragment(
        '<body><div class="btn-group"><span class="btn">One</span><span class="btn">Two</span><span class="btn">Three</span></div></body>',
        '.btn-group { display: inline-flex }
         .btn { display: inline-block; width: 40px; padding: 4px }',
        794.0,
        $warnings,
    );
    $group = $fragment->children[0];
    assert($group instanceof BoxFragment);
    expect($group->children)->toHaveCount(3);
    [$one, $two, $three] = $group->children;
    assert($one instanceof BoxFragment && $two instanceof BoxFragment && $three instanceof BoxFragment);
    // Default (flex-start) main-axis packing: each 40px-wide item (+4px padding each side = 48px
    // outer) sits immediately after the previous one, left to right -- real per-child placement,
    // not one shared box sized to the sum/merge of all three labels.
    expect($one->rect->x)->toBe(0.0);
    expect($two->rect->x)->toBe(48.0);
    expect($three->rect->x)->toBe(96.0);
});

it('Finding A: loose text around an inline-block child in a flex row still shares ONE anonymous item on each side, only the inline-block itself is separate', function () {
    $warnings = new WarningCollector();
    $fragment = flexInlineBlockLayoutFragment(
        '<body><div class="row">before <span class="ib">mid</span> after</div></body>',
        '.row { display: flex } .ib { display: inline-block; width: 30px }',
        794.0,
        $warnings,
    );
    $row = $fragment->children[0];
    assert($row instanceof BoxFragment);
    expect($row->children)->toHaveCount(3);
    [$before, $mid, $afterFragment] = $row->children;
    assert($before instanceof BoxFragment && $mid instanceof BoxFragment && $afterFragment instanceof BoxFragment);
    // The inline-block item sits immediately after the "before " text item's own hypothetical
    // main size, confirming it laid out as its own flex item with real geometry (not folded into
    // either text run).
    expect($mid->rect->x)->toBe($before->rect->x + $before->rect->width);
    expect($afterFragment->rect->x)->toBe($mid->rect->x + $mid->rect->width);
});
