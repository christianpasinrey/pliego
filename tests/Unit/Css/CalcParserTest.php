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

// --- M10-T1 (css-values-4 §5.1.1): vw/vh join the folded vector as two more symbolic
// components, same deferred treatment as % (resolved in ComputedStyle::compute() against the
// page's own CSS-px size, never here). --------------------------------------------------------

it('keeps vw/vh symbolic in the folded vector', function () {
    $expr = new CalcParser()->parse('1.5vw');
    expect($expr)->toEqual(CalcExpr::of(0.0, 0.0, 0.0, 0.0, 1.5, 0.0));

    $expr2 = new CalcParser()->parse('100vh - 20px');
    expect($expr2)->toEqual(CalcExpr::of(0.0, 0.0, 0.0, -20.0, 0.0, 100.0));
});

it('hand-computes Bootstrap\'s calc(1.375rem + 1.5vw) on an A4 page (793.7007874015748px wide)', function () {
    $expr = new CalcParser()->parse('1.375rem + 1.5vw');
    expect($expr)->toEqual(CalcExpr::of(0.0, 0.0, 1.375, 0.0, 1.5, 0.0));

    // remBase=16px -> 1.375rem = 22px; A4 width 793.7007874015748px -> 1.5vw = 11.905511811023623px
    // -> 22 + 11.905511811023623 = 33.905511811023623px (rounds to the brief's hand-verified
    // 33.91px).
    $pageWidthPx = 210.0 / 25.4 * 96.0;
    $folded = $expr->fold(16.0, 16.0, null, $pageWidthPx, 0.0);
    expect($folded)->toBeFloat();
    expect(round($folded, 2))->toBe(33.91);
    expect($folded)->toBe(22.0 + 11.905511811023623);
});

it('combines vw and vh in the same expression, each folded against its own base', function () {
    $expr = new CalcParser()->parse('50vw + 25vh');
    expect($expr)->toEqual(CalcExpr::of(0.0, 0.0, 0.0, 0.0, 50.0, 25.0));
    $folded = $expr->fold(16.0, 16.0, null, 800.0, 600.0);
    expect($folded)->toBe(0.5 * 800.0 + 0.25 * 600.0);
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

// --- M10-T5: parseNumberOrLength() -- the line-height-only entry point that accepts a
// dimensionless calc() result (Tailwind v4's `--text-*--line-height` ratios), unlike parse()
// above which always rejects a bare number. ------------------------------------------------------

it('parseNumberOrLength() resolves Tailwind\'s calc(1.25 / .875) ratio to a bare-number multiplier', function () {
    $parser = new CalcParser();
    $result = $parser->parseNumberOrLength('1.25 / .875');
    // 1.25 / .875 = 1.4285714285714286 (hand-verified) -- exact float comparison, same convention
    // as this file's own "hand-computes Bootstrap's calc()" test above.
    expect($result)->toEqual(['number' => 1.25 / 0.875]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('parseNumberOrLength() resolves calc(2 / 1.5) to 1.3333...', function () {
    $parser = new CalcParser();
    $result = $parser->parseNumberOrLength('2 / 1.5');
    expect($result)->toEqual(['number' => 2.0 / 1.5]);
});

it('parseNumberOrLength() still resolves a length/percentage calc() to the vector shape, unchanged', function () {
    $parser = new CalcParser();
    $result = $parser->parseNumberOrLength('1em + 4px');
    expect($result)->toEqual(['length' => CalcExpr::of(0.0, 1.0, 0.0, 4.0)]);
});

it('parseNumberOrLength() warns and returns null on the same failures parse() rejects (division by zero)', function () {
    $parser = new CalcParser();
    $result = $parser->parseNumberOrLength('10px / 0');
    expect($result)->toBeNull();
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('parseNumberOrLength() warns and returns null on malformed syntax (unbalanced parens)', function () {
    $parser = new CalcParser();
    $result = $parser->parseNumberOrLength('(2 + 3');
    expect($result)->toBeNull();
    expect($parser->drainWarnings())->not->toBeEmpty();
});
