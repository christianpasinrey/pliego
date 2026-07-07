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

// --- M8-T2 review Finding 2 (Important): reclampFor() -- re-applying §5.5's proportional clamp
// to an ALREADY-RESOLVED radius against a NEW border-box height (FlexFormattingContext::
// withHeight()/TableFormattingContext::withHeight() call this after a geometry-only height
// change, see both docblocks) instead of preserving it unchanged.

it('reclampFor(): reviewer\'s exact repro -- 50/50 radius (fit at height 200) re-clamps to 30/30 when shrunk to height 60', function () {
    // 100px-wide box, radius 50 on all 4 corners: at height 200, tl+bl=100<=200 (no clamp, this
    // IS the "clamped to 50/50 at height 200" starting point from the finding). Shrink the SAME
    // box to height 60 (flex-column flex-shrink, the reviewer's repro): tl+bl=100 > 60 -> ratio
    // 60/100 = 0.6 -> 50*0.6 = 30.0 exact, on all 4 (symmetric input).
    $radius = new BorderRadius(50.0, 50.0, 50.0, 50.0);
    $reclamped = $radius->reclampFor(100.0, 60.0);
    expect($reclamped->tl)->toBe(30.0);
    expect($reclamped->tr)->toBe(30.0);
    expect($reclamped->br)->toBe(30.0);
    expect($reclamped->bl)->toBe(30.0);
    // The clamp invariant §5.5 is meant to guarantee: no adjacent pair may exceed the side they
    // share -- this is exactly what stops roundedRectPathOps() from emitting a self-intersecting
    // (bowtie) curve on the shrunk box.
    expect($reclamped->tl + $reclamped->bl)->toBeLessThanOrEqual(60.0);
    expect($reclamped->tr + $reclamped->br)->toBeLessThanOrEqual(60.0);
});

it('reclampFor(): does NOT enlarge radii when the border box GROWS (stretch case, room to spare)', function () {
    // Same 50/50/50/50 radius, now the height GROWS from 60 to 200 (e.g. align-items:stretch
    // making an item taller than its natural content) -- §5.5's clamp only ever shrinks, the
    // ratio-1.0 floor in the min() means a bigger height never inflates a radius past what it
    // already was.
    $radius = new BorderRadius(50.0, 50.0, 50.0, 50.0);
    $reclamped = $radius->reclampFor(100.0, 200.0);
    expect($reclamped->tl)->toBe(50.0);
    expect($reclamped->tr)->toBe(50.0);
    expect($reclamped->br)->toBe(50.0);
    expect($reclamped->bl)->toBe(50.0);
});

it('reclampFor(): still scales an ALREADY-clamped-by-width radius consistently (round-trips with fromCss on the same box)', function () {
    // 40x100 box, 30px radii -- same fixture as the fromCss() §5.5 test above (clamps to 20 via
    // the WIDTH pair, 40/60). Calling reclampFor() with the SAME dimensions on an ALREADY-clamped
    // 20/20/20/20 radius must be a no-op (nothing new overlaps).
    $already = new BorderRadius(20.0, 20.0, 20.0, 20.0);
    $reclamped = $already->reclampFor(40.0, 100.0);
    expect($reclamped->tl)->toBe(20.0);
    expect($reclamped->tr)->toBe(20.0);
    expect($reclamped->br)->toBe(20.0);
    expect($reclamped->bl)->toBe(20.0);
});
