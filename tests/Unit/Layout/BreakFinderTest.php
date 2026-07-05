<?php

declare(strict_types=1);

use Pliego\Layout\Text\BreakFinder;
use Pliego\Layout\Text\BreakOpportunity;

beforeEach(function (): void {
    $this->finder = new BreakFinder();
});

it('breaks after spaces', function () {
    $offsets = array_map(fn(BreakOpportunity $o) => $o->byteOffset, $this->finder->find('ab cd ef'));

    expect($offsets)->toBe([3, 6]);
});

it('does not break at nbsp', function () {
    expect($this->finder->find("ab\u{00A0}cd"))->toBe([]);
});

it('breaks after hyphen followed by letter', function () {
    $opportunities = $this->finder->find('auto-escuela');

    expect($opportunities)->toHaveCount(1)
        ->and($opportunities[0]->byteOffset)->toBe(5)
        ->and($opportunities[0]->mandatory)->toBeFalse();
});

it('does not break after trailing hyphen', function () {
    $opportunities = $this->finder->find('auto- ');

    expect($opportunities)->toHaveCount(1)
        ->and($opportunities[0]->byteOffset)->toBe(6)
        ->and($opportunities[0]->mandatory)->toBeFalse();
});

it('marks LF as mandatory', function () {
    $opportunities = $this->finder->find("a\nb");

    expect($opportunities)->toHaveCount(1)
        ->and($opportunities[0]->byteOffset)->toBe(2)
        ->and($opportunities[0]->mandatory)->toBeTrue();
});

it('never breaks at offset zero', function () {
    $opportunities = $this->finder->find(' a');

    $offsets = array_map(fn(BreakOpportunity $o) => $o->byteOffset, $opportunities);

    expect($offsets)->not->toContain(0)
        ->and($offsets)->toBe([1]);
});

it('computes correct byte offsets for multibyte accented text', function () {
    // "año nuevo": a(1) + ñ(2) + o(1) + space(1) = byte offset 5 for the break after the space.
    $offsets = array_map(fn(BreakOpportunity $o) => $o->byteOffset, $this->finder->find('año nuevo'));

    expect($offsets)->toBe([5]);
});

it('returns an empty list for text with no break opportunities', function () {
    expect($this->finder->find('abcdef'))->toBe([]);
});

it('is a value object with readonly properties', function () {
    $opportunity = new BreakOpportunity(3, false);

    expect($opportunity->byteOffset)->toBe(3)
        ->and($opportunity->mandatory)->toBeFalse();
});
