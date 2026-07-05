<?php

declare(strict_types=1);

use Pliego\Css\Value\LengthPercentage;

it('parses px values', function () {
    $lp = LengthPercentage::fromCss('16px');
    expect($lp?->isPercent)->toBeFalse();
    expect($lp?->value)->toBe(16.0);
});
it('parses percent values', function () {
    $lp = LengthPercentage::fromCss('50%');
    expect($lp?->isPercent)->toBeTrue();
    expect($lp?->value)->toBe(50.0);
});
it('parses unitless zero as a non-percent zero', function () {
    $lp = LengthPercentage::fromCss('0');
    expect($lp?->isPercent)->toBeFalse();
    expect($lp?->value)->toBe(0.0);
});
it('returns null for unsupported units', function () {
    expect(LengthPercentage::fromCss('2em'))->toBeNull();
});
it('resolves percent against the containing block', function () {
    expect(LengthPercentage::percent(50.0)->resolve(200.0))->toBe(100.0);
});
it('resolves px independently of the containing block', function () {
    expect(LengthPercentage::px(16.0)->resolve(200.0))->toBe(16.0);
});
