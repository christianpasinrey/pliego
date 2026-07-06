<?php

declare(strict_types=1);

use Pliego\Css\Value\CalcExpr;
use Pliego\Css\Value\CalcParser;

it('parses simple px addition (2px + 3px)', function () {
    $expr = new CalcParser()->parse('2px + 3px');
    expect($expr)->toEqual(CalcExpr::of(0.0, 0.0, 0.0, 5.0));
});

it('respects precedence: (2 + 3) * 4px = 20px', function () {
    $expr = new CalcParser()->parse('(2 + 3) * 4px');
    expect($expr)->toEqual(CalcExpr::of(0.0, 0.0, 0.0, 20.0));
});

it('respects precedence without parens: 2px + 3px * 2 = 8px', function () {
    $expr = new CalcParser()->parse('2px + 3px * 2');
    expect($expr)->toEqual(CalcExpr::of(0.0, 0.0, 0.0, 8.0));
});

it('keeps % and em/rem symbolic in the folded 4-vector', function () {
    $expr = new CalcParser()->parse('100% - 20px');
    expect($expr)->toEqual(CalcExpr::of(100.0, 0.0, 0.0, -20.0));

    $expr2 = new CalcParser()->parse('1em + 4px');
    expect($expr2)->toEqual(CalcExpr::of(0.0, 1.0, 0.0, 4.0));

    $expr3 = new CalcParser()->parse('2rem');
    expect($expr3)->toEqual(CalcExpr::of(0.0, 0.0, 2.0, 0.0));
});

it('folds physical units to px at parse time, same factors as CssLength', function () {
    $expr = new CalcParser()->parse('1in - 1pt');
    expect($expr?->pxOffset)->toBe(96.0 - 96.0 / 72.0);
});

it('divides a length by a plain number', function () {
    $expr = new CalcParser()->parse('20px / 4');
    expect($expr)->toEqual(CalcExpr::of(0.0, 0.0, 0.0, 5.0));
});

it('warns and returns null on division by zero', function () {
    $parser = new CalcParser();
    $expr = $parser->parse('10px / 0');
    expect($expr)->toBeNull();
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('warns and returns null when multiplying two lengths together', function () {
    $parser = new CalcParser();
    $expr = $parser->parse('10px * 20px');
    expect($expr)->toBeNull();
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('warns and returns null when adding a bare number to a length', function () {
    $parser = new CalcParser();
    $expr = $parser->parse('10px + 5');
    expect($expr)->toBeNull();
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('warns and returns null when the whole expression resolves to a bare number', function () {
    $parser = new CalcParser();
    $expr = $parser->parse('2 + 3');
    expect($expr)->toBeNull();
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('warns and returns null on malformed syntax (unbalanced parens)', function () {
    $parser = new CalcParser();
    $expr = $parser->parse('(2px + 3px');
    expect($expr)->toBeNull();
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('supports unary minus on a dimension', function () {
    $expr = new CalcParser()->parse('-10px + 3px');
    expect($expr)->toEqual(CalcExpr::of(0.0, 0.0, 0.0, -7.0));
});

// --- M6-T4 fix: bare leading-decimal numbers (css-values-3 <number-token> allows ".5", no
// digit required before the dot) — the Bootstrap literal spacer pattern
// "calc(var(--bs-spacing) * .5)" was dropping the whole declaration before this fix. ----------

it('accepts a bare leading-decimal dimension: .5rem + 1px', function () {
    $expr = new CalcParser()->parse('.5rem + 1px');
    expect($expr)->toEqual(CalcExpr::of(0.0, 0.0, 0.5, 1.0));
});

it('accepts a negative bare leading-decimal dimension: -.5rem', function () {
    $expr = new CalcParser()->parse('-.5rem');
    expect($expr)->toEqual(CalcExpr::of(0.0, 0.0, -0.5, 0.0));
});

it('multiplies by a bare leading-decimal number: 1rem * .5', function () {
    $expr = new CalcParser()->parse('1rem * .5');
    expect($expr)->toEqual(CalcExpr::of(0.0, 0.0, 0.5, 0.0));
});

it('warns and returns null when a bare leading-decimal number is the whole expression: .25', function () {
    $parser = new CalcParser();
    $expr = $parser->parse('.25');
    expect($expr)->toBeNull();
    expect($parser->drainWarnings())->not->toBeEmpty();
});
