<?php

declare(strict_types=1);

use Pliego\Layout\TextMeasurer;
use Pliego\Text\FontCatalog;

beforeEach(function (): void {
    $this->measurer = new TextMeasurer();
    $catalog = FontCatalog::withDefaults();
    $this->regular = $catalog->select('default', 400, false);
    $this->bold = $catalog->select('default', 700, false);
});

it('measures longer text as wider', function () {
    expect($this->measurer->widthOf('Hola mundo', $this->regular, 16.0))
        ->toBeGreaterThan($this->measurer->widthOf('Hola', $this->regular, 16.0));
});
it('scales width linearly with font size', function () {
    $w16 = $this->measurer->widthOf('Hola', $this->regular, 16.0);
    expect($this->measurer->widthOf('Hola', $this->regular, 32.0))->toEqualWithDelta($w16 * 2, 0.001);
});
it('measures different faces of the same text with different widths', function () {
    expect($this->measurer->widthOf('Hola', $this->bold, 16.0))
        ->toBeGreaterThan($this->measurer->widthOf('Hola', $this->regular, 16.0));
});
it('computes line height as 1.2 times the font size', fn() =>
    expect($this->measurer->lineHeight(20.0))->toBe(24.0));
it('computes ascent proportionally to font size', function () {
    $ascent16 = $this->measurer->ascent($this->regular, 16.0);
    expect($this->measurer->ascent($this->regular, 32.0))->toEqualWithDelta($ascent16 * 2, 0.001);
});
it('computes descent as a positive magnitude', function () {
    expect($this->measurer->descent($this->regular, 16.0))->toBeGreaterThan(0.0);
});

// --- M8-T5 (css-text-3 §8 reducido): letter-spacing/word-spacing enter widthOf() -----------------

it('is unaffected by the new optional spacing parameters when both default to 0.0 (fast path, byte-stable goldens)', function () {
    // CRITICAL: the fast path must be arithmetically IDENTICAL to before this task -- no
    // letter/word-spacing declared anywhere means every existing golden PDF stays byte-for-byte
    // unchanged. Calling widthOf() with no spacing args at all (the pre-M8-T5 call shape, still
    // used by every existing caller) must equal calling it with explicit 0.0/0.0.
    $plain = $this->measurer->widthOf('Hola mundo', $this->regular, 16.0);
    expect($this->measurer->widthOf('Hola mundo', $this->regular, 16.0, 0.0, 0.0))->toBe($plain);
});

it('adds letterSpacingPx AFTER EVERY character, including the last (5 chars x 2px = +10px)', function () {
    $base = $this->measurer->widthOf('Hello', $this->regular, 16.0);
    $spaced = $this->measurer->widthOf('Hello', $this->regular, 16.0, 2.0, 0.0);
    expect($spaced)->toEqualWithDelta($base + 5 * 2.0, 0.0001);
});

it('adds wordSpacingPx only per space character, not per letter', function () {
    $base = $this->measurer->widthOf('Hola mundo', $this->regular, 16.0);
    // "Hola mundo" has exactly ONE space -- +1 x wordSpacingPx only, nothing per letter.
    $spaced = $this->measurer->widthOf('Hola mundo', $this->regular, 16.0, 0.0, 3.0);
    expect($spaced)->toEqualWithDelta($base + 1 * 3.0, 0.0001);
});

it('combines letter-spacing and word-spacing additively (both applied on the same space character)', function () {
    $base = $this->measurer->widthOf('ab cd', $this->regular, 16.0);
    // 5 chars total ('a','b',' ','c','d'), 1 of which is a space: letterSpacing after ALL 5,
    // wordSpacing on top of that same space char.
    $spaced = $this->measurer->widthOf('ab cd', $this->regular, 16.0, 2.0, 3.0);
    expect($spaced)->toEqualWithDelta($base + 5 * 2.0 + 1 * 3.0, 0.0001);
});

it('applies negative letter-spacing/word-spacing (both CSS properties allow negative values)', function () {
    $base = $this->measurer->widthOf('Hello', $this->regular, 16.0);
    $tightened = $this->measurer->widthOf('Hello', $this->regular, 16.0, -1.0, 0.0);
    expect($tightened)->toEqualWithDelta($base - 5 * 1.0, 0.0001);
});
