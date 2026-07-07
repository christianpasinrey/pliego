<?php

declare(strict_types=1);

use Pliego\Css\MediaQueryEvaluator;

// M10-T2 (css-mediaqueries-4, reduced): unit coverage for MediaQueryEvaluator, extracted from
// StylesheetParser::mediaQueryApplies() (see that class's docblock for the 'only'/print/all/screen
// history this class inherits) plus REAL min-width/max-width/width evaluation against a page CSS-px
// width. A4 in CSS px (793.70079...) is used throughout as "the page width" -- same number
// Page\PaperSize::widthPx() returns, duplicated here as a literal to keep Css decoupled from Page
// (deptrac boundary, same reasoning as StyleResolver's own DEFAULT_PAGE_WIDTH_PX).
const MQ_A4_WIDTH_PX = 210.0 / 25.4 * 96.0; // 793.7007874015749

// --- print/all/screen (M9-T2 baseline, unchanged behavior) --------------------------------------

it('applies print', function () {
    expect(new MediaQueryEvaluator()->applies('print', MQ_A4_WIDTH_PX))->toBeTrue();
});

it('applies all', function () {
    expect(new MediaQueryEvaluator()->applies('all', MQ_A4_WIDTH_PX))->toBeTrue();
});

it('is case-insensitive and whitespace-tolerant on the type keyword', function () {
    expect(new MediaQueryEvaluator()->applies('  PRINT  ', MQ_A4_WIDTH_PX))->toBeTrue();
});

it('does not apply screen', function () {
    expect(new MediaQueryEvaluator()->applies('screen', MQ_A4_WIDTH_PX))->toBeFalse();
});

it('normalizes a leading only prefix before the type comparison', function () {
    expect(new MediaQueryEvaluator()->applies('only print', MQ_A4_WIDTH_PX))->toBeTrue();
    expect(new MediaQueryEvaluator()->applies('only all', MQ_A4_WIDTH_PX))->toBeTrue();
    expect(new MediaQueryEvaluator()->applies('only screen', MQ_A4_WIDTH_PX))->toBeFalse();
});

// --- min-width/max-width/width: real comparison against the page width --------------------------

it('applies min-width when the page is at least as wide (px)', function () {
    expect(new MediaQueryEvaluator()->applies('(min-width: 768px)', MQ_A4_WIDTH_PX))->toBeTrue();
    expect(new MediaQueryEvaluator()->applies('(min-width: 576px)', MQ_A4_WIDTH_PX))->toBeTrue();
});

it('does not apply min-width when the page is narrower', function () {
    expect(new MediaQueryEvaluator()->applies('(min-width: 992px)', MQ_A4_WIDTH_PX))->toBeFalse();
    expect(new MediaQueryEvaluator()->applies('(min-width: 1200px)', MQ_A4_WIDTH_PX))->toBeFalse();
});

it('applies min-width at the exact boundary (page width == breakpoint)', function () {
    expect(new MediaQueryEvaluator()->applies('(min-width: 793.7007874015749px)', MQ_A4_WIDTH_PX))->toBeTrue();
});

it('applies max-width when the page is at most as wide (px)', function () {
    expect(new MediaQueryEvaluator()->applies('(max-width: 991.98px)', MQ_A4_WIDTH_PX))->toBeTrue();
    expect(new MediaQueryEvaluator()->applies('(max-width: 1199.98px)', MQ_A4_WIDTH_PX))->toBeTrue();
});

it('does not apply max-width when the page is wider', function () {
    expect(new MediaQueryEvaluator()->applies('(max-width: 767.98px)', MQ_A4_WIDTH_PX))->toBeFalse();
    expect(new MediaQueryEvaluator()->applies('(max-width: 575.98px)', MQ_A4_WIDTH_PX))->toBeFalse();
});

it('applies max-width at the exact boundary (page width == breakpoint)', function () {
    expect(new MediaQueryEvaluator()->applies('(max-width: 793.7007874015749px)', MQ_A4_WIDTH_PX))->toBeTrue();
});

it('applies width only for an exact match', function () {
    expect(new MediaQueryEvaluator()->applies('(width: 793.7007874015749px)', MQ_A4_WIDTH_PX))->toBeTrue();
    expect(new MediaQueryEvaluator()->applies('(width: 800px)', MQ_A4_WIDTH_PX))->toBeFalse();
});

it('resolves rem against a fixed 16px root, independent of any author root font-size', function () {
    // 48rem = 768px, 62rem = 992px -- Bootstrap's own breakpoints expressed in rem instead of px.
    expect(new MediaQueryEvaluator()->applies('(min-width: 48rem)', MQ_A4_WIDTH_PX))->toBeTrue();
    expect(new MediaQueryEvaluator()->applies('(min-width: 62rem)', MQ_A4_WIDTH_PX))->toBeFalse();
});

it('resolves em the same as rem (fixed 16px root at evaluation time)', function () {
    expect(new MediaQueryEvaluator()->applies('(min-width: 48em)', MQ_A4_WIDTH_PX))->toBeTrue();
    expect(new MediaQueryEvaluator()->applies('(min-width: 62em)', MQ_A4_WIDTH_PX))->toBeFalse();
});

it('does not apply a min-width in a unit with no meaning for a media feature length (%, vw)', function () {
    expect(new MediaQueryEvaluator()->applies('(min-width: 50%)', MQ_A4_WIDTH_PX))->toBeFalse();
    expect(new MediaQueryEvaluator()->applies('(min-width: 50vw)', MQ_A4_WIDTH_PX))->toBeFalse();
});

it('does not apply an unparseable min-width value', function () {
    expect(new MediaQueryEvaluator()->applies('(min-width: banana)', MQ_A4_WIDTH_PX))->toBeFalse();
});

// --- 'and' combinators ----------------------------------------------------------------------------

it('applies a type-and-feature combo when both hold', function () {
    expect(new MediaQueryEvaluator()->applies('print and (min-width: 768px)', MQ_A4_WIDTH_PX))->toBeTrue();
});

it('does not apply a type-and-feature combo when the type does not hold (screen)', function () {
    expect(new MediaQueryEvaluator()->applies('screen and (min-width: 768px)', MQ_A4_WIDTH_PX))->toBeFalse();
});

it('does not apply a feature-and-feature combo when only one side holds', function () {
    // min-width holds (576 <= 793.7), max-width does not (793.7 > 767.98) -- AND requires both.
    expect(new MediaQueryEvaluator()->applies('(min-width: 576px) and (max-width: 767.98px)', MQ_A4_WIDTH_PX))->toBeFalse();
});

it('applies a feature-and-feature combo when both hold', function () {
    expect(new MediaQueryEvaluator()->applies('(min-width: 576px) and (max-width: 991.98px)', MQ_A4_WIDTH_PX))->toBeTrue();
});

it('does not apply a combo with an unknown feature, even if the width feature alone would apply (conservative AND)', function () {
    expect(new MediaQueryEvaluator()->applies('(max-width: 991.98px) and (prefers-reduced-motion: reduce)', MQ_A4_WIDTH_PX))->toBeFalse();
});

// --- comma lists (OR semantics) -------------------------------------------------------------------

it('applies a comma list when any entry applies', function () {
    expect(new MediaQueryEvaluator()->applies('screen, (min-width: 768px)', MQ_A4_WIDTH_PX))->toBeTrue();
});

it('does not apply a comma list when no entry applies', function () {
    expect(new MediaQueryEvaluator()->applies('screen, (min-width: 1200px)', MQ_A4_WIDTH_PX))->toBeFalse();
});

// --- unknown features: conservative skip -----------------------------------------------------------

it('does not apply an unknown feature query', function () {
    expect(new MediaQueryEvaluator()->applies('(prefers-reduced-motion: reduce)', MQ_A4_WIDTH_PX))->toBeFalse();
    expect(new MediaQueryEvaluator()->applies('(prefers-color-scheme: dark)', MQ_A4_WIDTH_PX))->toBeFalse();
    expect(new MediaQueryEvaluator()->applies('(hover: hover)', MQ_A4_WIDTH_PX))->toBeFalse();
});

it('does not apply a boolean-context feature (no colon, e.g. (hover))', function () {
    expect(new MediaQueryEvaluator()->applies('(hover)', MQ_A4_WIDTH_PX))->toBeFalse();
});

it('does not apply an unrecognized media type keyword', function () {
    expect(new MediaQueryEvaluator()->applies('speech', MQ_A4_WIDTH_PX))->toBeFalse();
});
