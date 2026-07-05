<?php

declare(strict_types=1);

use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\Color;

it('holds width, style and an optional color', function () {
    $side = new BorderSide(1.0, BorderStyle::Solid, new Color(204, 204, 204));
    expect($side->widthPx)->toBe(1.0);
    expect($side->style)->toBe(BorderStyle::Solid);
    expect($side->color)->toEqual(new Color(204, 204, 204));
});
it('allows a null color (resolved to currentColor downstream)', function () {
    $side = new BorderSide(0.0, BorderStyle::None, null);
    expect($side->color)->toBeNull();
});
