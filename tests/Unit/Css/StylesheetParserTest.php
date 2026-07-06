<?php

declare(strict_types=1);

use Pliego\Css\DeferredDeclaration;
use Pliego\Css\StylesheetParser;
use Pliego\Css\Value\Color;
use Pliego\Css\Value\Length;
use Pliego\Css\Value\LengthPercentage;

it('parses rules into typed declarations', function () {
    $result = new StylesheetParser()->parse('p { color: #f00; margin: 8px; font-size: 14px }');
    expect($result->rules)->toHaveCount(1);
    $d = $result->rules[0]->declarations;
    expect($d['color'])->toEqual(new Color(255, 0, 0));
    expect($d['margin-top'])->toEqual(LengthPercentage::px(8.0));
    expect($d['margin-left'])->toEqual(LengthPercentage::px(8.0));
    expect($d['font-size'])->toEqual(Length::px(14.0));
});
it('expands two-value margin shorthand', function () {
    $d = new StylesheetParser()->parse('div { margin: 4px 12px }')->rules[0]->declarations;
    expect($d['margin-top'])->toEqual(LengthPercentage::px(4.0));
    expect($d['margin-right'])->toEqual(LengthPercentage::px(12.0));
    expect($d['margin-bottom'])->toEqual(LengthPercentage::px(4.0));
    expect($d['margin-left'])->toEqual(LengthPercentage::px(12.0));
});
it('keeps rule order for the cascade', function () {
    $rules = new StylesheetParser()->parse('p { color: red } p { color: blue }')->rules;
    expect($rules[0]->order)->toBeLessThan($rules[1]->order);
});
it('warns on unsupported properties without failing', function () {
    // p > span now parses fine (M6-T1: combinators are real selector syntax, no longer rejected
    // outright — see SelectorParserTest). `float` gained real support in M7-T6 (see
    // Style\FloatSide/DeclarationParser) — swapped to `writing-mode`, explicitly excluded-with-
    // warning by the M7 milestone brief (RESTRICCIONES GLOBALES: "Excluidos M7 con warning").
    $result = new StylesheetParser()->parse('p { writing-mode: vertical-rl; color: red }');
    expect($result->rules)->toHaveCount(1);
    expect($result->warnings)->not->toBeEmpty();
});
it('warns on an unsupported selector without failing', function () {
    $result = new StylesheetParser()->parse('.123abc { color: red }');
    expect($result->rules)->toHaveCount(0);
    expect($result->warnings)->not->toBeEmpty();
});
it('parses a combinator selector with correct specificity and no staging warning (M6-T2: matching is real)', function () {
    $result = new StylesheetParser()->parse('p > span { color: red }');
    expect($result->rules)->toHaveCount(1);
    expect($result->rules[0]->selector->specificity()->c)->toBe(2);
    expect($result->warnings)->toBe([]);
});
it('lets the last declaration of a property within a rule win', function () {
    $d = new StylesheetParser()->parse('p { color: #f00; color: #00f }')->rules[0]->declarations;
    expect($d['color'])->toEqual(new Color(0, 0, 255));
});

// --- M2-T2: @page ------------------------------------------------------------------------

it('parses @page margin shorthand into a raw PageRuleData', function () {
    $result = new StylesheetParser()->parse('@page { margin: 20px 10px 20px 10px }');
    expect($result->pageRule)->not->toBeNull();
    expect($result->pageRule?->margins)->toEqual([
        'top' => Length::px(20.0), 'right' => Length::px(10.0),
        'bottom' => Length::px(20.0), 'left' => Length::px(10.0),
    ]);
    expect($result->warnings)->toBeEmpty();
});

it('parses @page margin-{side} longhands', function () {
    $result = new StylesheetParser()->parse('@page { margin-top: 40px; margin-left: 10px }');
    expect($result->pageRule?->margins)->toEqual([
        'top' => Length::px(40.0),
        'left' => Length::px(10.0),
    ]);
});

it('parses two margin boxes with quoted strings and page counters', function () {
    $css = <<<'CSS'
    @page {
        margin: 40px;
        @top-center {
            content: "Pagina " counter(page) " de " counter(pages);
        }
        @bottom-right {
            content: "Footer";
        }
    }
    CSS;
    $result = new StylesheetParser()->parse($css);
    expect($result->pageRule)->not->toBeNull();
    expect($result->pageRule?->margins)->toEqual([
        'top' => Length::px(40.0), 'right' => Length::px(40.0),
        'bottom' => Length::px(40.0), 'left' => Length::px(40.0),
    ]);
    expect($result->pageRule?->marginBoxes)->toEqual([
        'top-center' => ['Pagina ', 'counter(page)', ' de ', 'counter(pages)'],
        'bottom-right' => ['Footer'],
    ]);
    expect($result->warnings)->toBeEmpty();
});

it('still parses regular rules alongside a @page block', function () {
    $result = new StylesheetParser()->parse('@page { margin: 1cm } p { color: #f00 }');
    expect($result->pageRule)->not->toBeNull();
    expect($result->rules)->toHaveCount(1);
    expect($result->rules[0]->declarations['color'])->toEqual(new Color(255, 0, 0));
});

it('warns on an unsupported margin box name', function () {
    $result = new StylesheetParser()->parse('@page { @left-middle { content: "x" } }');
    expect($result->pageRule?->marginBoxes)->toBe([]);
    expect($result->warnings)->toContain('Unsupported margin box: @left-middle');
});

it('warns on an unparseable content value in a margin box', function () {
    $result = new StylesheetParser()->parse('@page { @top-center { content: attr(data-x) } }');
    expect($result->pageRule?->marginBoxes)->toBe([]);
    expect($result->warnings)->not->toBeEmpty();
});

it('has a null pageRule when there is no @page block', function () {
    $result = new StylesheetParser()->parse('p { color: red }');
    expect($result->pageRule)->toBeNull();
});

// --- M6-T4: custom properties + deferred var()/calc() declarations (css-variables-1 §2-3) --

it('captures a custom property as a raw string, never typed, case preserved', function () {
    $result = new StylesheetParser()->parse(':root { --Primary: #0d6efd; --sp: 10px; }');
    $d = $result->rules[0]->declarations;
    expect($d['--Primary'])->toBe('#0d6efd');
    expect($d['--sp'])->toBe('10px');
    expect(array_key_exists('--primary', $d))->toBeFalse();
});

it('defers a declaration whose value contains var() as a DeferredDeclaration, raw and unexpanded', function () {
    $result = new StylesheetParser()->parse('p { color: var(--primary, red); margin: var(--sp) 10px; }');
    $d = $result->rules[0]->declarations;
    expect($d['color'])->toBeInstanceOf(DeferredDeclaration::class);
    // sabberworm/php-css-parser re-stringifica sin el espacio tras la coma del fallback — la
    // sustitución (VarResolver::splitVarArgs) hace trim() de ambos lados, así que es inocuo.
    expect($d['color']->rawValue)->toBe('var(--primary,red)');
    // "margin" (shorthand) queda intacto, sin expandir a margin-top/right/bottom/left todavía —
    // eso solo ocurre tras la sustitución, en StyleResolver.
    expect($d['margin'])->toBeInstanceOf(DeferredDeclaration::class);
    expect(array_key_exists('margin-top', $d))->toBeFalse();
});

it('keeps typing declarations without var() at parse time (fast path unaffected)', function () {
    $result = new StylesheetParser()->parse('p { color: red; margin: 8px; }');
    $d = $result->rules[0]->declarations;
    expect($d['color'])->toEqual(new Color(255, 0, 0));
    expect($d['margin-top'])->toEqual(LengthPercentage::px(8.0));
});

// --- M6 final-review fix, finding 1: !important cascade tier (CSS 2.2 §6.4.2) -------------

it('keeps a rule with no !important declarations as a single normal StyleRule (no regression)', function () {
    $result = new StylesheetParser()->parse('p { color: red; margin: 8px }');
    expect($result->rules)->toHaveCount(1);
    expect($result->rules[0]->important)->toBeFalse();
});

it('splits a block with a mix of !important and normal declarations into two StyleRule entries', function () {
    $result = new StylesheetParser()->parse('p { color: red !important; margin: 8px }');
    expect($result->rules)->toHaveCount(2);
    $important = array_values(array_filter($result->rules, static fn($r) => $r->important));
    $normal = array_values(array_filter($result->rules, static fn($r) => !$r->important));
    expect($important)->toHaveCount(1);
    expect($normal)->toHaveCount(1);
    expect($important[0]->declarations)->toHaveKey('color');
    expect($important[0]->declarations)->not->toHaveKey('margin-top');
    expect($normal[0]->declarations)->toHaveKey('margin-top');
    expect($normal[0]->declarations)->not->toHaveKey('color');
    // Same selector/specificity for both halves — only the tier differs.
    expect($important[0]->selector)->toEqual($normal[0]->selector);
});

it('marks the whole StyleRule important when every declaration in the block uses it', function () {
    $result = new StylesheetParser()->parse('p { color: red !important; background-color: blue !important }');
    expect($result->rules)->toHaveCount(1);
    expect($result->rules[0]->important)->toBeTrue();
});

it('splits !important on a custom property the same way as any other declaration', function () {
    $result = new StylesheetParser()->parse(':root { --sp: 6px !important; --other: 1px }');
    $important = array_values(array_filter($result->rules, static fn($r) => $r->important));
    $normal = array_values(array_filter($result->rules, static fn($r) => !$r->important));
    expect($important)->toHaveCount(1);
    expect($normal)->toHaveCount(1);
    expect($important[0]->declarations)->toBe(['--sp' => '6px']);
    expect($normal[0]->declarations)->toBe(['--other' => '1px']);
});

// --- M6 final-review fix, finding 3: @page accepts cm/mm/in/pt (Length::fromCss, shared with
// CssLength's exact factors — see LengthTest). em/rem/% still warn: no font context at @page. ----

it('accepts cm/mm/in/pt units on the @page margin-{side} longhands, folded to the same px factors as element margins', function () {
    $result = new StylesheetParser()->parse(
        '@page { margin-top: 1cm; margin-right: 1mm; margin-bottom: 1in; margin-left: 1pt }',
    );
    expect($result->pageRule?->margins)->toEqual([
        'top' => Length::px(96.0 / 2.54),
        'right' => Length::px(9.6 / 2.54),
        'bottom' => Length::px(96.0),
        'left' => Length::px(96.0 / 72.0),
    ]);
    expect($result->warnings)->toBeEmpty();
});

it('accepts cm units in the @page margin shorthand', function () {
    $result = new StylesheetParser()->parse('@page { margin: 2cm }');
    $expected = Length::px(2.0 * (96.0 / 2.54));
    expect($result->pageRule?->margins)->toEqual([
        'top' => $expected, 'right' => $expected, 'bottom' => $expected, 'left' => $expected,
    ]);
    expect($result->warnings)->toBeEmpty();
});

it('warns and declares no override for rem in @page margin (no font context at page level, documented)', function () {
    $result = new StylesheetParser()->parse('@page { margin: 1.5rem }');
    expect($result->pageRule?->margins)->toBe([]);
    expect($result->warnings)->not->toBeEmpty();
});
