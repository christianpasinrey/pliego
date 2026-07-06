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
    // outright — see SelectorParserTest); float stays the unsupported bit here.
    $result = new StylesheetParser()->parse('p { float: left; color: red }');
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
