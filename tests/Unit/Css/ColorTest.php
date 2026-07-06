<?php

declare(strict_types=1);

use Pliego\Css\Value\Color;

it('parses 6-digit hex', fn() => expect(Color::fromCss('#8b5e34'))
    ->r->toBe(139)->g->toBe(94)->b->toBe(52));
it('parses 3-digit hex', fn() => expect(Color::fromCss('#f00'))
    ->r->toBe(255)->g->toBe(0)->b->toBe(0));
it('parses keywords case-insensitively', fn() => expect(Color::fromCss('White'))
    ->r->toBe(255)->g->toBe(255)->b->toBe(255));
it('returns null for unsupported values', fn() => expect(Color::fromCss('unsupportedfunc(1,2,3)'))->toBeNull());

// M6-T5: 148 named colors (css-color-4 §6.1), generated table — spot checks only (the full
// table is generated verbatim from the color-name npm package, see Color::KEYWORDS docblock).
it('resolves all 148 named colors from the generated table, spot-checked', function () {
    expect(Color::fromCss('rebeccapurple'))->toEqual(new Color(102, 51, 153));
    expect(Color::fromCss('cornflowerblue'))->toEqual(new Color(100, 149, 237));
    expect(Color::fromCss('darkslategray'))->toEqual(new Color(47, 79, 79));
    expect(Color::fromCss('darkslategrey'))->toEqual(new Color(47, 79, 79)); // both spellings
    expect(Color::fromCss('MediumVioletRed'))->toEqual(new Color(199, 21, 133)); // case-insensitive
});

// M6-T5: transparent — rgb(0,0,0) with alpha 0 by spec convention (css-color-3 §4.1).
it('parses "transparent" as black with alpha 0', function () {
    $color = Color::fromCss('transparent');
    expect($color)->toEqual(new Color(0, 0, 0, 0.0));
    expect($color->alpha)->toBe(0.0);
});

// M6-T5: currentColor sentinel — the actual color is resolved later, in ComputedStyle::compute
// (see StyleResolverTest); Color::fromCss() only recognizes the keyword and marks the sentinel.
it('parses "currentColor" (case-insensitively) as a sentinel, not a real color', function () {
    $color = Color::fromCss('currentColor');
    expect($color)->not->toBeNull();
    expect($color?->isCurrentColor)->toBeTrue();
    $lower = Color::fromCss('currentcolor');
    expect($lower?->isCurrentColor)->toBeTrue();
});

it('does not mark an ordinary color as currentColor', function () {
    expect(Color::fromCss('red')?->isCurrentColor)->toBeFalse();
});

// M6-T5: rgb()/rgba() — classic comma syntax, ints 0-255.
it('parses rgb() with integer components', function () {
    expect(Color::fromCss('rgb(139, 94, 52)'))->toEqual(new Color(139, 94, 52));
});
it('clamps out-of-range rgb() integer components to 0-255', function () {
    expect(Color::fromCss('rgb(300, -10, 500)'))->toEqual(new Color(255, 0, 255));
});
it('parses rgb() with percentage components', function () {
    expect(Color::fromCss('rgb(100%, 0%, 50%)'))->toEqual(new Color(255, 0, 128));
});
it('parses rgba() with an integer alpha component', function () {
    $color = Color::fromCss('rgba(0, 0, 255, 0.5)');
    expect($color)->toEqual(new Color(0, 0, 255, 0.5));
    expect($color?->alpha)->toBe(0.5);
});
it('parses rgba() with a percentage alpha component', function () {
    $color = Color::fromCss('rgba(0, 0, 255, 50%)');
    expect($color?->alpha)->toBe(0.5);
});
it('accepts rgb() (not just rgba()) with a 4th alpha argument, per css-color-4', function () {
    $color = Color::fromCss('rgb(0, 0, 255, 0.5)');
    expect($color)->toEqual(new Color(0, 0, 255, 0.5));
});
it('rejects rgb() with the modern space+slash syntax (out of scope for M6)', function () {
    expect(Color::fromCss('rgb(255 0 0 / 50%)'))->toBeNull();
});
it('rejects malformed rgb() argument counts and non-numeric components', function () {
    expect(Color::fromCss('rgb(1, 2)'))->toBeNull();
    expect(Color::fromCss('rgb(1, 2, 3, 4, 5)'))->toBeNull();
    expect(Color::fromCss('rgb(a, b, c)'))->toBeNull();
});

// M6-T5: hsl()/hsla() — exact css-color-3 §4.2.4 HUE→RGB algorithm, hand-verified conversions.
it('converts hsl(0, 100%, 50%) to red, exactly, per css-color-3 §4.2.4', function () {
    expect(Color::fromCss('hsl(0, 100%, 50%)'))->toEqual(new Color(255, 0, 0));
});
it('converts hsl(120, 100%, 50%) to green, exactly', function () {
    expect(Color::fromCss('hsl(120, 100%, 50%)'))->toEqual(new Color(0, 255, 0));
});
it('converts hsl(240, 100%, 50%) to blue, exactly', function () {
    expect(Color::fromCss('hsl(240, 100%, 50%)'))->toEqual(new Color(0, 0, 255));
});
it('converts hsl(60, 100%, 50%) to yellow, exactly', function () {
    expect(Color::fromCss('hsl(60, 100%, 50%)'))->toEqual(new Color(255, 255, 0));
});
it('converts hsl(0, 0%, 0%) to black and hsl(0, 0%, 100%) to white', function () {
    expect(Color::fromCss('hsl(0, 0%, 0%)'))->toEqual(new Color(0, 0, 0));
    expect(Color::fromCss('hsl(0, 0%, 100%)'))->toEqual(new Color(255, 255, 255));
});
it('converts hsl(120, 100%, 25%) close to the spec\'s illustrative "dark green" example', function () {
    // css-color-3 illustrates hsl(120,100%,25%) as approximately #006400 (darkgreen); the EXACT
    // algorithm at l=25% flat (not green's real 25.098%) yields (0,128,0) — hand-verified against
    // the hue-to-rgb algorithm cited verbatim in Color::hueToRgb()'s docblock:
    //   m2 = 0.25*(1+1) = 0.5; m1 = 0.5-0.5 = 0
    //   R: hue-to-rgb(0,0.5, 2/3) -> h*3=2, not<2 -> m1 = 0
    //   G: hue-to-rgb(0,0.5, 1/3) -> h*2=2/3<1 -> m2 = 0.5 -> round(127.5) = 128
    //   B: hue-to-rgb(0,0.5, 0)   -> h*6=0<1  -> m1+(m2-m1)*0 = 0
    expect(Color::fromCss('hsl(120, 100%, 25%)'))->toEqual(new Color(0, 128, 0));
});
it('normalizes a hue outside [0,360) modulo 360, per css-color-3 §4.2.4', function () {
    expect(Color::fromCss('hsl(480, 100%, 50%)'))->toEqual(Color::fromCss('hsl(120, 100%, 50%)'));
    expect(Color::fromCss('hsl(-120, 100%, 50%)'))->toEqual(Color::fromCss('hsl(240, 100%, 50%)'));
});
it('accepts an optional "deg" suffix on the hue component', function () {
    expect(Color::fromCss('hsl(120deg, 100%, 50%)'))->toEqual(new Color(0, 255, 0));
});
it('parses hsla() with an alpha component', function () {
    $color = Color::fromCss('hsla(0, 100%, 50%, 0.25)');
    expect($color)->toEqual(new Color(255, 0, 0, 0.25));
});
it('rejects hsl() with non-percentage saturation/lightness', function () {
    expect(Color::fromCss('hsl(0, 100, 50)'))->toBeNull();
});

// M6-T5: opacity combination (rgba(...,0.5) with element opacity:0.5 -> effective alpha 0.25).
it('withOpacity multiplies alpha (rgba 0.5 over element opacity 0.5 -> effective 0.25)', function () {
    $color = new Color(0, 0, 255, 0.5);
    $effective = $color->withOpacity(0.5);
    expect($effective->alpha)->toBe(0.25);
    expect($effective->r)->toBe(0)->and($effective->g)->toBe(0)->and($effective->b)->toBe(255);
});
it('withOpacity treats a null (opaque) alpha as 1.0 before multiplying', function () {
    $color = new Color(255, 0, 0);
    expect($color->withOpacity(0.5)->alpha)->toBe(0.5);
});
it('withOpacity(1.0) is a no-op, returning the exact same instance', function () {
    $color = new Color(255, 0, 0, 0.5);
    expect($color->withOpacity(1.0))->toBe($color);
});
