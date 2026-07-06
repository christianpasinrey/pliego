<?php

declare(strict_types=1);

use Pliego\Css\Value\Length;

it('parses px values', fn() => expect(Length::fromCss('16px'))->px->toBe(16.0));
it('parses unitless zero', fn() => expect(Length::fromCss('0'))->px->toBe(0.0));
it('returns null for unsupported units', fn() => expect(Length::fromCss('2em'))->toBeNull());

// --- M6 final-review fix, finding 3: @page (this class's only caller, via StylesheetParser)
// accepts the same physical units element margins already do since M6-T3 — folded here with the
// exact same factors as CssLength (shared consts, no duplication). em/rem/% stay null: @page has
// no font-size/containing-block context in M6, so the caller warns and keeps the default margin.

it('folds physical units (pt/cm/mm/in) to the exact same px factors as CssLength', function () {
    expect(Length::fromCss('1in')?->px)->toBe(96.0);
    expect(Length::fromCss('1pt')?->px)->toBe(96.0 / 72.0);
    expect(Length::fromCss('1cm')?->px)->toBe(96.0 / 2.54);
    expect(Length::fromCss('1mm')?->px)->toBe(9.6 / 2.54);
});

it('still returns null for em/rem/% (no font/containing-block context at this level)', function () {
    expect(Length::fromCss('2em'))->toBeNull();
    expect(Length::fromCss('2rem'))->toBeNull();
    expect(Length::fromCss('50%'))->toBeNull();
});
