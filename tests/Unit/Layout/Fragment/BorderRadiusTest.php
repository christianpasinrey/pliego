<?php

// tests/Unit/Layout/Fragment/BorderRadiusTest.php
declare(strict_types=1);

use Pliego\Css\Value\BorderRadius as CssBorderRadius;
use Pliego\Css\Value\LengthPercentage;
use Pliego\Layout\Fragment\BorderRadius;

it('is zero by default (no construction site needs to pass it)', function () {
    expect(new BorderRadius()->isZero())->toBeTrue();
});

it('isZero() is false as soon as one corner is non-zero', function () {
    expect(new BorderRadius(tl: 5.0)->isZero())->toBeFalse();
});

it('resolves px corners unchanged when nothing overlaps', function () {
    $css = new CssBorderRadius(
        LengthPercentage::px(10.0),
        LengthPercentage::px(10.0),
        LengthPercentage::px(10.0),
        LengthPercentage::px(10.0),
    );
    $resolved = BorderRadius::fromCss($css, 200.0, 100.0);
    expect($resolved->tl)->toBe(10.0);
    expect($resolved->tr)->toBe(10.0);
    expect($resolved->br)->toBe(10.0);
    expect($resolved->bl)->toBe(10.0);
});

// css-backgrounds-3 §5: % SIEMPRE contra el ancho del border box (adjudicación M8), incluso el
// componente "vertical" -- aquí un 50% del ancho (200px) da 100px en las 4 esquinas.
it('resolves % against the border box WIDTH for all 4 corners (M8 adjudication)', function () {
    $css = new CssBorderRadius(
        LengthPercentage::percent(50.0),
        LengthPercentage::percent(50.0),
        LengthPercentage::percent(50.0),
        LengthPercentage::percent(50.0),
    );
    $resolved = BorderRadius::fromCss($css, 200.0, 999.0);
    expect($resolved->tl)->toBe(100.0);
    expect($resolved->tr)->toBe(100.0);
    expect($resolved->br)->toBe(100.0);
    expect($resolved->bl)->toBe(100.0);
});

// css-backgrounds-3 §5.5, hand-computed (brief): 4 radios de 30px en una caja de 40x100 --
// tl+tr = 60 > width 40 (ratio 40/60 = 2/3), bl+br = 60 > 40 (mismo ratio) -- el mínimo de las 4
// razones (las 2 verticales, tl+bl=60/height=100 y tr+br=60/height=100, NO limitan: razón > 1) es
// 2/3, así que los 4 radios (simétricos aquí) se escalan por igual: 30 * 2/3 = 20.0 exacto.
it('scales ALL 4 radii proportionally when adjacent corners overlap a side (§5.5, hand-computed)', function () {
    $css = new CssBorderRadius(
        LengthPercentage::px(30.0),
        LengthPercentage::px(30.0),
        LengthPercentage::px(30.0),
        LengthPercentage::px(30.0),
    );
    $resolved = BorderRadius::fromCss($css, 40.0, 100.0);
    expect($resolved->tl)->toBe(20.0);
    expect($resolved->tr)->toBe(20.0);
    expect($resolved->br)->toBe(20.0);
    expect($resolved->bl)->toBe(20.0);
});

// Clamp asimétrico: solo tl es grande, el resto 0 -- ningún par SUMA más que el lado (tl+tr=30+0=
// 30 <= width 50; tl+bl=30+0=30 <= height 200), así que nada se escala.
it('does not scale down when no adjacent PAIR overlaps, even with one large corner', function () {
    $css = new CssBorderRadius(
        LengthPercentage::px(30.0),
        LengthPercentage::zero(),
        LengthPercentage::zero(),
        LengthPercentage::zero(),
    );
    $resolved = BorderRadius::fromCss($css, 50.0, 200.0);
    expect($resolved->tl)->toBe(30.0);
    expect($resolved->tr)->toBe(0.0);
    expect($resolved->br)->toBe(0.0);
    expect($resolved->bl)->toBe(0.0);
});

// Solape solo en el eje vertical (tl+bl > height), el horizontal (tl+tr, bl+br) no limita.
it('clamps on the vertical pair alone when only left-side corners overlap the height', function () {
    $css = new CssBorderRadius(
        LengthPercentage::px(40.0),
        LengthPercentage::zero(),
        LengthPercentage::zero(),
        LengthPercentage::px(40.0),
    );
    // tl+bl = 80 > height 60 -> ratio 60/80 = 0.75; tl+tr=40<=width 500, bl+br=40<=500: no limitan.
    $resolved = BorderRadius::fromCss($css, 500.0, 60.0);
    expect($resolved->tl)->toBe(30.0);
    expect($resolved->bl)->toBe(30.0);
    expect($resolved->tr)->toBe(0.0);
    expect($resolved->br)->toBe(0.0);
});
