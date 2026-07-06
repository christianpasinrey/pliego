<?php

declare(strict_types=1);

use Pliego\Css\VarResolver;

it('substitutes a known custom property', function () {
    $resolver = new VarResolver(['--primary' => '#0d6efd']);
    expect($resolver->substitute('var(--primary)'))->toBe('#0d6efd');
});

it('substitutes inline inside a longer value', function () {
    $resolver = new VarResolver(['--sp' => '10px']);
    expect($resolver->substitute('var(--sp) 10px'))->toBe('10px 10px');
});

it('falls back when the custom property is unknown', function () {
    $resolver = new VarResolver([]);
    expect($resolver->substitute('var(--missing, blue)'))->toBe('blue');
});

it('supports a nested var() inside the fallback', function () {
    $resolver = new VarResolver(['--b' => 'green']);
    expect($resolver->substitute('var(--a, var(--b, blue))'))->toBe('green');
});

it('falls all the way through a fallback chain when nothing resolves', function () {
    $resolver = new VarResolver([]);
    expect($resolver->substitute('var(--a, var(--b, blue))'))->toBe('blue');
});

it('returns null (invalid at computed-value time) for an unknown property without a fallback', function () {
    $resolver = new VarResolver([]);
    expect($resolver->substitute('var(--missing)'))->toBeNull();
    expect($resolver->drainWarnings())->not->toBeEmpty();
});

it('resolves a custom property that itself references another custom property', function () {
    $resolver = new VarResolver(['--a' => 'var(--b)', '--b' => 'red']);
    expect($resolver->substitute('var(--a)'))->toBe('red');
});

it('detects a direct cycle and invalidates both custom properties', function () {
    $resolver = new VarResolver(['--a' => 'var(--b)', '--b' => 'var(--a)']);
    expect($resolver->substitute('var(--a)'))->toBeNull();
    expect($resolver->drainWarnings())->not->toBeEmpty();

    $resolver2 = new VarResolver(['--a' => 'var(--b)', '--b' => 'var(--a)']);
    expect($resolver2->substitute('var(--b)'))->toBeNull();
    expect($resolver2->drainWarnings())->not->toBeEmpty();
});

it('uses the fallback when a cyclic reference is used with one', function () {
    $resolver = new VarResolver(['--a' => 'var(--b)', '--b' => 'var(--a)']);
    expect($resolver->substitute('var(--a, black)'))->toBe('black');
});

// --- M7-T1 housekeeping: var( inside a quoted string is never a real function call --------

it('does not substitute a var() that is written literally inside a quoted string', function () {
    $resolver = new VarResolver(['--a' => '"var(--b)"', '--b' => 'green']);
    expect($resolver->substitute('var(--a)'))->toBe('"var(--b)"');
});

it('still substitutes a real var() call that follows a quoted string containing the literal text', function () {
    $resolver = new VarResolver(['--b' => 'green']);
    expect($resolver->substitute('"var(--x)" var(--b)'))->toBe('"var(--x)" green');
});

it('honors an escaped quote inside the string so it does not end the string early', function () {
    $resolver = new VarResolver(['--b' => 'green']);
    // The string is `"a\"var(` (escaped quote), so the "var(" a few chars later is still inside
    // the (still-open) string, followed by the real var(--b) outside of any quotes.
    expect($resolver->substitute('"a\\"var(" var(--b)'))->toBe('"a\\"var(" green');
});
