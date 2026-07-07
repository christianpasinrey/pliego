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

// --- M10-T3: oklch() (css-color-4 §10), Tailwind v4's default color function ---------------------

/**
 * Asserts every channel is within +-1/255 of expected (brief M10-T3's stated tolerance) -- the
 * table below is pinned EXACT (computed to match, see the report for the by-hand matrix
 * derivation + the independent `culori` npm library cross-check), so this helper's tolerance is a
 * safety margin for float rounding-mode edge cases, not an acknowledgment of imprecision.
 */
function expectOklchWithinOneChannel(string $css, int $r, int $g, int $b): void
{
    $color = Color::fromCss($css);
    if ($color === null) {
        throw new RuntimeException("expected a valid oklch() color for: $css");
    }
    expect(abs($color->r - $r))->toBeLessThanOrEqual(1, "red channel for $css");
    expect(abs($color->g - $g))->toBeLessThanOrEqual(1, "green channel for $css");
    expect(abs($color->b - $b))->toBeLessThanOrEqual(1, "blue channel for $css");
}

it('converts oklch() spec achromatic table values (white/black/mid-gray) exactly', function () {
    expectOklchWithinOneChannel('oklch(100% 0 0)', 255, 255, 255);
    expectOklchWithinOneChannel('oklch(0% 0 0)', 0, 0, 0);
    // L=0.5, C=0/H=anything (achromatic) -- hand-derivable in closed form (see class docblock's
    // oklchToSrgb() matrix-sums-to-identity note for achromatic input): 0.5^3 linear on all three
    // channels, gamma-encoded -> #636363.
    expectOklchWithinOneChannel('oklch(50% 0 0)', 0x63, 0x63, 0x63);
});

it('accepts a bare-number lightness (0-1), equivalent to the same value as a percentage', function () {
    expect(Color::fromCss('oklch(0.623 0.214 259.815)'))
        ->toEqual(Color::fromCss('oklch(62.3% 0.214 259.815)'));
});

it('accepts an optional "deg" suffix on the hue component, identical to the bare number', function () {
    expect(Color::fromCss('oklch(62.3% 0.214 259.815deg)'))
        ->toEqual(Color::fromCss('oklch(62.3% 0.214 259.815)'));
});

it('parses an optional alpha component after a slash, as a 0-1 number or a percentage', function () {
    expect(Color::fromCss('oklch(62.3% 0.214 259.815 / 0.5)')?->alpha)->toBe(0.5);
    expect(Color::fromCss('oklch(62.3% 0.214 259.815 / 50%)')?->alpha)->toBe(0.5);
});

/**
 * css-color-4 §10, Tailwind v4's OWN generated theme colors (tests/Fixtures/tailwind/
 * tailwind-output.css's `--color-*` custom properties, verbatim) -- hand-verified against the
 * full OKLCH->OKLab->LMS->linear-sRGB->gamma pipeline (see Color::oklchToSrgb()'s docblock) AND
 * cross-checked with the independent `culori` npm package (MIT). NOTE: these do NOT match
 * Tailwind v3's old hex palette (e.g. v3's blue-500 was #3b82f6) -- v4's palette was recomputed
 * directly in OKLCH space for wider-gamut displays, so it is only visually close to v3's sRGB
 * values, never byte-identical. See the M10-T3 report for the full derivation.
 */
it('converts every Tailwind v4 color scale referenced by the fixture, within +-1/255 per channel', function () {
    expectOklchWithinOneChannel('oklch(62.3% 0.214 259.815)', 0x2b, 0x7f, 0xff); // blue-500
    expectOklchWithinOneChannel('oklch(54.6% 0.245 262.881)', 0x15, 0x5d, 0xfc); // blue-600
    expectOklchWithinOneChannel('oklch(57.7% 0.245 27.325)', 0xe7, 0x00, 0x0b); // red-600
    expectOklchWithinOneChannel('oklch(20.8% 0.042 265.755)', 0x0f, 0x17, 0x2b); // slate-900
    expectOklchWithinOneChannel('oklch(92.9% 0.013 255.508)', 0xe2, 0xe8, 0xf0); // slate-200
    expectOklchWithinOneChannel('oklch(82.8% 0.189 84.429)', 0xff, 0xb9, 0x00); // amber-400
    expectOklchWithinOneChannel('oklch(72.3% 0.219 149.579)', 0x00, 0xc9, 0x50); // green-500
    expectOklchWithinOneChannel('oklch(58.5% 0.233 277.117)', 0x61, 0x5f, 0xff); // indigo-500
    expectOklchWithinOneChannel('oklch(59.6% 0.145 163.225)', 0x00, 0x99, 0x66); // emerald-600
});

it('clamps an out-of-sRGB-gamut oklch() color via a simple per-channel clamp (documented, not proper gamut mapping)', function () {
    // High chroma at this hue pushes linear-sRGB red past 1.0 -- clamped to the pure-red corner.
    expectOklchWithinOneChannel('oklch(70% 0.4 30)', 255, 0, 0);
});

it('rejects a malformed oklch() (wrong argument shape, unsupported %-chroma)', function () {
    expect(Color::fromCss('oklch(50%)'))->toBeNull();
    expect(Color::fromCss('oklch(50% 20% 0)'))->toBeNull(); // C as a % is out of the reduced contract
});

// --- M10-T3: color-mix() (css-color-4 §16) -- reduced to "warning + first color" ------------------

it('color-mix() resolves to its FIRST color argument verbatim (no warning at the Color layer -- see DeclarationParserTest)', function () {
    expect(Color::fromCss('color-mix(in oklab, currentcolor 50%, transparent)'))
        ->toEqual(Color::currentColor());
    expect(Color::fromCss('color-mix(in srgb, red 50%, blue)'))
        ->toEqual(new Color(255, 0, 0));
});

it('color-mix() first-color extraction is comma-depth-aware (a nested rgb() argument does not confuse the split)', function () {
    expect(Color::fromCss('color-mix(in srgb, rgb(0, 0, 0) 50%, white)'))
        ->toEqual(new Color(0, 0, 0));
});

it('rejects a malformed color-mix() (missing "in <space>," prefix)', function () {
    expect(Color::fromCss('color-mix(red, blue)'))->toBeNull();
});
