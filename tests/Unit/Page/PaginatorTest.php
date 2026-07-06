<?php

declare(strict_types=1);

use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\Color;
use Pliego\Css\WarningCollector;
use Pliego\Layout\Fragment\BorderSet;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\ImageFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;
use Pliego\Page\Paginator;

function textAt(float $y, float $height = 20.0): TextFragment
{
    return new TextFragment(new Rect(0.0, $y, 100.0, $height), 'x', $y + 15.0, 16.0, new Color(0, 0, 0), 'default:400:normal', false);
}

function imageAt(float $y, float $height = 20.0): ImageFragment
{
    return new ImageFragment(new Rect(0.0, $y, 100.0, $height), '/tmp/tiny.jpg');
}

it('yields a single page when content fits', function () {
    $root = new BoxFragment(new Rect(0, 0, 100, 500), null, [textAt(10.0), textAt(400.0)], BorderSet::none());
    $pages = iterator_to_array(new Paginator(1000.0)->paginate($root));
    expect($pages)->toHaveCount(1);
    expect($pages[0]->number)->toBe(1);
    expect($pages[0]->fragments)->toHaveCount(2);
});
it('pushes a line crossing the boundary to the next page top', function () {
    $root = new BoxFragment(new Rect(0, 0, 100, 1100), null, [textAt(10.0), textAt(990.0), textAt(1010.0)], BorderSet::none());
    $pages = iterator_to_array(new Paginator(1000.0)->paginate($root));
    expect($pages)->toHaveCount(2);
    // La hoja en y=990 (990..1010) cruza el límite 1000 => empieza la página 2 en y local 0
    expect($pages[1]->fragments[0]->rect()->y)->toBe(0.0);
    // La siguiente conserva la distancia relativa (1010-990=20) tras el push-down
    expect($pages[1]->fragments[1]->rect()->y)->toBe(20.0);
});
it('keeps page-local coordinates', function () {
    $root = new BoxFragment(new Rect(0, 0, 100, 2500), null, [textAt(2100.0)], BorderSet::none());
    $pages = iterator_to_array(new Paginator(1000.0)->paginate($root));
    $last = end($pages);
    assert($last !== false);
    expect($last->number)->toBe(3);
    expect($last->fragments[0]->rect()->y)->toBe(100.0);
});
it('emits container backgrounds before their text in paint order', function () {
    $bg = new BoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), [textAt(10.0)], BorderSet::none());
    $pages = iterator_to_array(new Paginator(1000.0)->paginate($bg));
    expect($pages[0]->fragments[0])->toBeInstanceOf(BoxFragment::class);
    expect($pages[0]->fragments[1])->toBeInstanceOf(TextFragment::class);
});
it('relocates a box with a visible border unchanged, just like its background', function () {
    // M2-T4: los bordes viajan con la caja a través de Paginator::relocate exactamente igual
    // que el background — necesarios para que T5 los pinte con las coordenadas de página.
    $solid = new BorderSide(2.0, BorderStyle::Solid, new Color(0, 0, 0));
    $borders = new BorderSet($solid, $solid, $solid, $solid);
    $bg = new BoxFragment(new Rect(0, 0, 100, 50), new Color(255, 0, 0), [textAt(10.0)], $borders);
    $pages = iterator_to_array(new Paginator(1000.0)->paginate($bg));
    $box = $pages[0]->fragments[0];
    assert($box instanceof BoxFragment);
    expect($box->borders)->toBe($borders);
});
it('emits a leaf for a border-only box with no background (T5: widened leaf gating)', function () {
    // Antes de T5, flatten() solo emitía una hoja BoxFragment si background !== null; una caja
    // con borde visible pero sin fondo se perdía (Painter no tenía nada que pintar). T5 amplía
    // la condición a background !== null || borders->isVisible().
    $solid = new BorderSide(2.0, BorderStyle::Solid, new Color(0, 0, 0));
    $borders = new BorderSet($solid, $solid, $solid, $solid);
    $root = new BoxFragment(new Rect(0, 0, 100, 50), null, [textAt(10.0)], $borders);
    $pages = iterator_to_array(new Paginator(1000.0)->paginate($root));
    expect($pages[0]->fragments)->toHaveCount(2);
    $box = $pages[0]->fragments[0];
    assert($box instanceof BoxFragment);
    expect($box->background)->toBeNull();
    expect($box->borders)->toBe($borders);
    expect($pages[0]->fragments[1])->toBeInstanceOf(TextFragment::class);
});
it('does not emit a leaf for a box with neither background nor visible border', function () {
    $root = new BoxFragment(new Rect(0, 0, 100, 50), null, [textAt(10.0)], BorderSet::none());
    $pages = iterator_to_array(new Paginator(1000.0)->paginate($root));
    expect($pages[0]->fragments)->toHaveCount(1);
    expect($pages[0]->fragments[0])->toBeInstanceOf(TextFragment::class);
});

// --- M3-T3: ImageFragment is a leaf, just like TextFragment ------------------------------------

it('treats an ImageFragment as a leaf that flows through paint order like text', function () {
    $root = new BoxFragment(new Rect(0, 0, 100, 50), null, [imageAt(10.0)], BorderSet::none());
    $pages = iterator_to_array(new Paginator(1000.0)->paginate($root));
    expect($pages)->toHaveCount(1);
    expect($pages[0]->fragments)->toHaveCount(1);
    expect($pages[0]->fragments[0])->toBeInstanceOf(ImageFragment::class);
});

it('pushes an image crossing the page boundary to the next page top, same as a TextFragment', function () {
    $root = new BoxFragment(new Rect(0, 0, 100, 1100), null, [imageAt(990.0)], BorderSet::none());
    $pages = iterator_to_array(new Paginator(1000.0)->paginate($root));
    expect($pages)->toHaveCount(2);
    expect($pages[1]->fragments[0])->toBeInstanceOf(ImageFragment::class);
    expect($pages[1]->fragments[0]->rect()->y)->toBe(0.0);
});

it('relocates an image preserving its imageKey and shrinking coordinates to the page-local origin', function () {
    $root = new BoxFragment(new Rect(0, 0, 100, 2500), null, [imageAt(2100.0)], BorderSet::none());
    $pages = iterator_to_array(new Paginator(1000.0)->paginate($root));
    $last = end($pages);
    assert($last !== false);
    expect($last->number)->toBe(3);
    $image = $last->fragments[0];
    assert($image instanceof ImageFragment);
    expect($image->rect->y)->toBe(100.0);
    expect($image->imageKey)->toBe('/tmp/tiny.jpg');
});

// --- M4-T5: atomic fragments (flex containers) push down or stay as a whole subtree -----------

it('pushes an atomic fragment crossing the page boundary down as a whole subtree, children coordinates intact relative to the container', function () {
    // Atomic container: y=990, height=60 (990..1050), page content height 1000 => crosses the
    // 1000 boundary and fits within one page (60 <= 1000) => pushed down whole to start page 2.
    // Two children at absolute y=1000 and y=1030 (offsets +10/+40 from the container's own top).
    $child1 = textAt(1000.0, 10.0);
    $child2 = textAt(1030.0, 10.0);
    $atomicBox = new BoxFragment(new Rect(0, 990, 100, 60), new Color(0, 200, 0), [$child1, $child2], BorderSet::none(), atomic: true);
    $root = new BoxFragment(new Rect(0, 0, 100, 1100), null, [$atomicBox], BorderSet::none());

    $pages = iterator_to_array(new Paginator(1000.0)->paginate($root));
    expect($pages)->toHaveCount(2);
    expect($pages[0]->fragments)->toHaveCount(0);

    $relocated = $pages[1]->fragments[0];
    assert($relocated instanceof BoxFragment);
    expect($relocated->atomic)->toBeTrue();
    expect($relocated->rect->y)->toBe(0.0); // pushed to the top of page 2
    expect($relocated->children)->toHaveCount(2);

    $relocatedChild1 = $relocated->children[0];
    $relocatedChild2 = $relocated->children[1];
    assert($relocatedChild1 instanceof TextFragment && $relocatedChild2 instanceof TextFragment);
    // Offsets relative to the container preserved: 1000-990=10, 1030-990=40.
    expect($relocatedChild1->rect->y)->toBe(10.0);
    expect($relocatedChild2->rect->y)->toBe(40.0);
});

it('leaves an atomic fragment taller than the page crossing the boundary unsplit and warns (M5-T1: warning channel)', function () {
    // Height (1200) > page content height (1000): the generic push-down guard never fires,
    // same documented limitation as an over-tall image/text fragment already has -- but M5-T1
    // adds an explicit warning for this specific (atomic) case via the injected WarningCollector.
    $child = textAt(500.0, 10.0);
    $atomicBox = new BoxFragment(new Rect(0, 500.0, 100, 1200), new Color(0, 200, 0), [$child], BorderSet::none(), atomic: true);
    $root = new BoxFragment(new Rect(0, 0, 100, 1800), null, [$atomicBox], BorderSet::none());

    $warnings = new WarningCollector();
    $pages = iterator_to_array(new Paginator(1000.0, $warnings)->paginate($root));
    expect($pages)->toHaveCount(1);
    $box = $pages[0]->fragments[0];
    assert($box instanceof BoxFragment);
    expect($box->rect->y)->toBe(500.0);
    expect($box->rect->height)->toBe(1200.0);
    expect($box->children[0]->rect()->y)->toBe(500.0);
    expect($warnings->drain())->toBe(['atomic fragment taller than page, kept unsplit']);
});

it('stays silent (no WarningCollector injected) when an atomic fragment taller than the page is left unsplit', function () {
    // Regression: the new constructor parameter is OPTIONAL (null = silent) so every existing
    // caller/test that builds a Paginator with just the content height keeps working unchanged.
    $child = textAt(500.0, 10.0);
    $atomicBox = new BoxFragment(new Rect(0, 500.0, 100, 1200), new Color(0, 200, 0), [$child], BorderSet::none(), atomic: true);
    $root = new BoxFragment(new Rect(0, 0, 100, 1800), null, [$atomicBox], BorderSet::none());

    $pages = iterator_to_array(new Paginator(1000.0)->paginate($root));
    expect($pages)->toHaveCount(1);
});

it('does not decompose an atomic fragment into its individual children even when it fits entirely within one page', function () {
    $child1 = textAt(10.0, 10.0);
    $child2 = textAt(30.0, 10.0);
    $atomicBox = new BoxFragment(new Rect(0, 0, 100, 50), null, [$child1, $child2], BorderSet::none(), atomic: true);
    $root = new BoxFragment(new Rect(0, 0, 100, 50), null, [$atomicBox], BorderSet::none());

    $pages = iterator_to_array(new Paginator(1000.0)->paginate($root));
    expect($pages)->toHaveCount(1);
    expect($pages[0]->fragments)->toHaveCount(1); // NOT flattened into 2 separate text leaves
    $box = $pages[0]->fragments[0];
    assert($box instanceof BoxFragment);
    expect($box->atomic)->toBeTrue();
    expect($box->children)->toHaveCount(2);
});

it('leaves an image taller than the page crossing the boundary unsplit (documented push-down limitation)', function () {
    // Height > page content height ($h): the generic push-down guard (`height <= $h`) never
    // fires, so a too-tall image is never pushed to the next page — it just stays where it
    // lands and visually overflows the page boundary, same limitation as an over-tall
    // TextFragment already has (brief: "documented, not split").
    $root = new BoxFragment(new Rect(0, 0, 100, 1500), null, [imageAt(500.0, 1200.0)], BorderSet::none());
    $pages = iterator_to_array(new Paginator(1000.0)->paginate($root));
    expect($pages)->toHaveCount(1);
    $image = $pages[0]->fragments[0];
    assert($image instanceof ImageFragment);
    expect($image->rect->y)->toBe(500.0);
    expect($image->rect->height)->toBe(1200.0);
});
