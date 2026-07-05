<?php

declare(strict_types=1);

use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\Color;
use Pliego\Layout\Fragment\BorderSet;

it('is not visible when all sides default to none()', function () {
    expect(BorderSet::none()->isVisible())->toBeFalse();
});

it('is visible when at least one side is solid with a positive width', function () {
    $none = new BorderSide(0.0, BorderStyle::None, null);
    $solid = new BorderSide(1.0, BorderStyle::Solid, new Color(0, 0, 0));
    $borders = new BorderSet($none, $none, $solid, $none);
    expect($borders->isVisible())->toBeTrue();
});

it('is not visible when style is solid but width is zero', function () {
    $zeroWidthSolid = new BorderSide(0.0, BorderStyle::Solid, new Color(0, 0, 0));
    $borders = new BorderSet($zeroWidthSolid, $zeroWidthSolid, $zeroWidthSolid, $zeroWidthSolid);
    expect($borders->isVisible())->toBeFalse();
});
