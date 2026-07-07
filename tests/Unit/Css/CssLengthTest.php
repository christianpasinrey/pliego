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

// --- M10-T1 (css-values-4 §5.1.1): vw/vh join em/rem/% as symbolic units, resolved at
// computed-value time against the page's own CSS-px size (Style\ComputedStyle), never here. ---

it('keeps vw symbolic', function () {
    $css = CssLength::fromCss('1.5vw');
    expect($css?->unit)->toBe(LengthUnit::Vw);
    expect($css?->value)->toBe(1.5);
});

it('keeps vh symbolic', function () {
    $css = CssLength::fromCss('100vh');
    expect($css?->unit)->toBe(LengthUnit::Vh);
    expect($css?->value)->toBe(100.0);
});

it('parses a negative vw', function () {
    expect(CssLength::fromCss('-2vw')?->value)->toBe(-2.0);
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
    // M10-T1: vh is now supported (see 'keeps vh symbolic' above) -- 'ch' (character unit) takes
    // over as the still-unsupported-unit example.
    expect(CssLength::fromCss('2ch'))->toBeNull();
    expect(CssLength::fromCss(''))->toBeNull();
});

// --- M6-T4 fix: bare leading-decimal numbers (css-values-3 <number-token> allows ".5", no
// digit required before the dot). ---------------------------------------------------------

it('parses a bare leading-decimal length: .5rem', function () {
    $css = CssLength::fromCss('.5rem');
    expect($css?->unit)->toBe(LengthUnit::Rem);
    expect($css?->value)->toBe(0.5);
});

it('parses a negative bare leading-decimal length: -.5em', function () {
    $css = CssLength::fromCss('-.5em');
    expect($css?->unit)->toBe(LengthUnit::Em);
    expect($css?->value)->toBe(-0.5);
});

it('parses a bare leading-decimal physical length: .75pt', function () {
    $css = CssLength::fromCss('.75pt');
    expect($css?->unit)->toBe(LengthUnit::Px);
    expect($css?->value)->toBe(0.75 * CssLength::PX_PER_PT);
});
