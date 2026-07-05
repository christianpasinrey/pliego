<?php

declare(strict_types=1);

use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\Color;
use Pliego\Layout\Fragment\BorderSet;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;
use Pliego\Page\Paginator;

function textAt(float $y, float $height = 20.0): TextFragment
{
    return new TextFragment(new Rect(0.0, $y, 100.0, $height), 'x', $y + 15.0, 16.0, new Color(0, 0, 0), 'default:400:normal', false);
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
