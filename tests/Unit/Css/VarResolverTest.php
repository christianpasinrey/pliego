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
