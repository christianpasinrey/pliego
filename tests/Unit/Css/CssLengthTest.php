<?php

declare(strict_types=1);

use Pliego\Css\Value\CssLength;
use Pliego\Css\Value\LengthUnit;

it('parses px as an already-resolved Px unit', function () {
    $css = CssLength::fromCss('16px');
    expect($css?->unit)->toBe(LengthUnit::Px);
    expect($css?->value)->toBe(16.0);
});

it('parses unitless zero as Px 0', function () {
    $css = CssLength::fromCss('0');
    expect($css?->unit)->toBe(LengthUnit::Px);
    expect($css?->value)->toBe(0.0);
});

it('keeps em symbolic', function () {
    $css = CssLength::fromCss('2em');
    expect($css?->unit)->toBe(LengthUnit::Em);
    expect($css?->value)->toBe(2.0);
});

it('keeps rem symbolic', function () {
    $css = CssLength::fromCss('1.5rem');
    expect($css?->unit)->toBe(LengthUnit::Rem);
    expect($css?->value)->toBe(1.5);
});

it('keeps percent symbolic', function () {
    $css = CssLength::fromCss('150%');
    expect($css?->unit)->toBe(LengthUnit::Percent);
    expect($css?->value)->toBe(150.0);
});

it('folds 1in to exactly 96px at parse time', function () {
    $css = CssLength::fromCss('1in');
    expect($css?->unit)->toBe(LengthUnit::Px);
    expect($css?->value)->toBe(96.0);
});

it('folds 1pt to exactly 96/72 px at parse time', function () {
    $css = CssLength::fromCss('1pt');
    expect($css?->unit)->toBe(LengthUnit::Px);
    expect($css?->value)->toBe(96.0 / 72.0);
});

it('folds 1cm to exactly 96/2.54 px at parse time', function () {
    $css = CssLength::fromCss('1cm');
    expect($css?->unit)->toBe(LengthUnit::Px);
    expect($css?->value)->toBe(96.0 / 2.54);
});

it('folds 1mm to exactly 9.6/2.54 px at parse time', function () {
    $css = CssLength::fromCss('1mm');
    expect($css?->unit)->toBe(LengthUnit::Px);
    expect($css?->value)->toBe(9.6 / 2.54);
});

it('handles negative values across units', function () {
    expect(CssLength::fromCss('-2em')?->value)->toBe(-2.0);
    expect(CssLength::fromCss('-1in')?->value)->toBe(-96.0);
});

it('returns null for garbage or unsupported units', function () {
    expect(CssLength::fromCss('auto'))->toBeNull();
    expect(CssLength::fromCss('2vh'))->toBeNull();
    expect(CssLength::fromCss(''))->toBeNull();
});
