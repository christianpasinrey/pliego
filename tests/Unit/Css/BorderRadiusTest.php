<?php

// tests/Unit/Css/BorderRadiusTest.php
declare(strict_types=1);

use Pliego\Css\Value\BorderRadius;
use Pliego\Css\Value\LengthPercentage;

it('holds one LengthPercentage per corner', function () {
    $radius = new BorderRadius(
        LengthPercentage::px(10.0),
        LengthPercentage::px(20.0),
        LengthPercentage::percent(5.0),
        LengthPercentage::zero(),
    );
    expect($radius->tl)->toEqual(LengthPercentage::px(10.0));
    expect($radius->tr)->toEqual(LengthPercentage::px(20.0));
    expect($radius->br)->toEqual(LengthPercentage::percent(5.0));
    expect($radius->bl)->toEqual(LengthPercentage::zero());
});

it('zero() is all-zero on every corner (initial value)', function () {
    $radius = BorderRadius::zero();
    foreach ([$radius->tl, $radius->tr, $radius->br, $radius->bl] as $corner) {
        expect($corner->resolve(1000.0))->toBe(0.0);
    }
});
