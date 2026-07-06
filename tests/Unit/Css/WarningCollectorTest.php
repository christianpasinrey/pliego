<?php

declare(strict_types=1);

use Pliego\Css\WarningCollector;

it('collects warnings in order and drains them exactly once', function () {
    $warnings = new WarningCollector();
    $warnings->addWarning('a');
    $warnings->addWarning('b');
    expect($warnings->drain())->toBe(['a', 'b']);
    expect($warnings->drain())->toBe([]);
});

// --- M7-T2: addWarningOnce() dedup ---------------------------------------------------------

it('addWarningOnce emits the message only the first time for a given key', function () {
    $warnings = new WarningCollector();
    $warnings->addWarningOnce('missing-font', 'Generic font family missing (1st)');
    $warnings->addWarningOnce('missing-font', 'Generic font family missing (2nd, should be dropped)');
    expect($warnings->drain())->toBe(['Generic font family missing (1st)']);
});

it('addWarningOnce with a DIFFERENT key still emits, even after the first key was already used', function () {
    $warnings = new WarningCollector();
    $warnings->addWarningOnce('key-a', 'A');
    $warnings->addWarningOnce('key-b', 'B');
    expect($warnings->drain())->toBe(['A', 'B']);
});

it('addWarningOnce and addWarning share the same underlying list/order', function () {
    $warnings = new WarningCollector();
    $warnings->addWarning('plain');
    $warnings->addWarningOnce('key', 'once');
    $warnings->addWarning('plain again');
    expect($warnings->drain())->toBe(['plain', 'once', 'plain again']);
});
