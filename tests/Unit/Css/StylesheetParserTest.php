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

// --- M8-T7: @font-face (css-fonts-4 §4 reducido) ------------------------------------------

use Pliego\Css\Value\FontFaceRule;

it('parses a minimal @font-face into a FontFaceRule with default weight/style', function () {
    $result = new StylesheetParser()->parse("@font-face { font-family: 'MiSerif'; src: url('fonts/MiSerif.ttf') }");
    expect($result->fontFaceRules)->toEqual([
        new FontFaceRule('MiSerif', 'fonts/MiSerif.ttf', 400, false),
    ]);
    expect($result->warnings)->toBe([]);
});

it('strips double AND single quotes from font-family', function () {
    $result = new StylesheetParser()->parse('@font-face { font-family: "Mi Serif"; src: url(fonts/x.ttf) }');
    expect($result->fontFaceRules[0]->family)->toBe('Mi Serif');
});

it('ignores a format() hint alongside url() and still extracts the path', function () {
    $result = new StylesheetParser()->parse("@font-face { font-family: 'X'; src: url('fonts/x.ttf') format('truetype') }");
    expect($result->fontFaceRules[0]->srcPath)->toBe('fonts/x.ttf');
});

it('maps font-weight bold and normal keywords to 700/400', function () {
    $bold = new StylesheetParser()->parse("@font-face { font-family: 'X'; src: url('a.ttf'); font-weight: bold }");
    $normal = new StylesheetParser()->parse("@font-face { font-family: 'X'; src: url('a.ttf'); font-weight: normal }");
    expect($bold->fontFaceRules[0]->weight)->toBe(700);
    expect($normal->fontFaceRules[0]->weight)->toBe(400);
});

it('accepts a numeric font-weight outside 400/700 as-is (Engine maps it to the nearest slot later)', function () {
    $result = new StylesheetParser()->parse("@font-face { font-family: 'X'; src: url('a.ttf'); font-weight: 500 }");
    expect($result->fontFaceRules[0]->weight)->toBe(500);
    expect($result->warnings)->toBe([]);
});

it('collapses a font-weight range to its first value, with a warning', function () {
    $result = new StylesheetParser()->parse("@font-face { font-family: 'X'; src: url('a.ttf'); font-weight: 100 900 }");
    expect($result->fontFaceRules[0]->weight)->toBe(100);
    expect($result->warnings)->not->toBeEmpty();
});

it('parses font-style: italic', function () {
    $result = new StylesheetParser()->parse("@font-face { font-family: 'X'; src: url('a.ttf'); font-style: italic }");
    expect($result->fontFaceRules[0]->italic)->toBeTrue();
});

it('parses four separate @font-face rules for the same family into four FontFaceRule entries', function () {
    $css = <<<'CSS'
        @font-face { font-family: 'Acme'; src: url('acme-regular.ttf') }
        @font-face { font-family: 'Acme'; src: url('acme-bold.ttf'); font-weight: bold }
        @font-face { font-family: 'Acme'; src: url('acme-italic.ttf'); font-style: italic }
        @font-face { font-family: 'Acme'; src: url('acme-bolditalic.ttf'); font-weight: bold; font-style: italic }
        CSS;
    $result = new StylesheetParser()->parse($css);
    expect($result->fontFaceRules)->toHaveCount(4);
    expect($result->fontFaceRules)->toEqual([
        new FontFaceRule('Acme', 'acme-regular.ttf', 400, false),
        new FontFaceRule('Acme', 'acme-bold.ttf', 700, false),
        new FontFaceRule('Acme', 'acme-italic.ttf', 400, true),
        new FontFaceRule('Acme', 'acme-bolditalic.ttf', 700, true),
    ]);
});

it('falls back past a woff src to the next ttf src, with a warning', function () {
    $result = new StylesheetParser()->parse(
        "@font-face { font-family: 'X'; src: url('a.woff') format('woff'), url('a.ttf') format('truetype') }",
    );
    expect($result->fontFaceRules[0]->srcPath)->toBe('a.ttf');
    expect($result->warnings)->not->toBeEmpty();
});

it('warns and drops the rule when every src is unusable (woff-only)', function () {
    $result = new StylesheetParser()->parse("@font-face { font-family: 'X'; src: url('a.woff') format('woff') }");
    expect($result->fontFaceRules)->toBe([]);
    expect($result->warnings)->not->toBeEmpty();
});

it('skips a remote http(s) src with a warning', function () {
    $result = new StylesheetParser()->parse("@font-face { font-family: 'X'; src: url('https://example.com/a.ttf') }");
    expect($result->fontFaceRules)->toBe([]);
    expect($result->warnings)->not->toBeEmpty();
});

it('falls back past a remote src to a local ttf src', function () {
    $result = new StylesheetParser()->parse(
        "@font-face { font-family: 'X'; src: url('https://example.com/a.ttf'), url('local.ttf') }",
    );
    expect($result->fontFaceRules[0]->srcPath)->toBe('local.ttf');
});

it('skips local() with a warning (no system font access in M8)', function () {
    $result = new StylesheetParser()->parse("@font-face { font-family: 'X'; src: local('Georgia') }");
    expect($result->fontFaceRules)->toBe([]);
    expect($result->warnings)->not->toBeEmpty();
});

it('drops the rule and warns when font-family is missing', function () {
    $result = new StylesheetParser()->parse("@font-face { src: url('a.ttf') }");
    expect($result->fontFaceRules)->toBe([]);
    expect($result->warnings)->not->toBeEmpty();
});

it('drops the rule and warns when src is missing entirely', function () {
    $result = new StylesheetParser()->parse("@font-face { font-family: 'X' }");
    expect($result->fontFaceRules)->toBe([]);
    expect($result->warnings)->not->toBeEmpty();
});

it('warns but keeps the rule when unicode-range is declared (whole font loads anyway)', function () {
    $result = new StylesheetParser()->parse(
        "@font-face { font-family: 'X'; src: url('a.ttf'); unicode-range: U+0-FF }",
    );
    expect($result->fontFaceRules)->toHaveCount(1);
    expect($result->warnings)->not->toBeEmpty();
});

it('parses regular rules alongside an @font-face block', function () {
    $result = new StylesheetParser()->parse("@font-face { font-family: 'X'; src: url('a.ttf') } p { color: red }");
    expect($result->fontFaceRules)->toHaveCount(1);
    expect($result->rules)->toHaveCount(1);
});

it('has an empty fontFaceRules list when there is no @font-face block', function () {
    $result = new StylesheetParser()->parse('p { color: red }');
    expect($result->fontFaceRules)->toBe([]);
});

// --- M8 final-review Finding D: extractAtRuleBlocks() ran BEFORE comment-stripping -------------
// The at-rule extraction regex (`/@font-face\b[^{]*\{/i`) scanned the RAW css string, so a comment
// merely MENTIONING "@font-face" (`/* @font-face */`) matched the literal text inside the comment;
// `[^{]*` then greedily ate everything up to the NEXT `{` in the document -- which, for
// `/* @font-face */ p{color:red}`, is `p`'s own opening brace -- so the brace-matcher captured
// `color:red` as if it were the @font-face BODY, deleted `p`'s entire rule from the css handed to
// sabberworm, and (since that "body" has no font-family/src) dropped it with bogus descriptor
// warnings. Same latent bug for @page. Fix: strip /*...*/ comments (quote-aware, so a literal "/*"
// inside a url()/content string survives) before either at-rule extraction runs.

it('Finding D: a comment merely mentioning "@font-face" does not hijack the following rule\'s body', function () {
    $result = new StylesheetParser()->parse('/* @font-face */ p { color: red }');
    expect($result->rules)->toHaveCount(1);
    expect($result->rules[0]->declarations['color'])->toEqual(new Color(255, 0, 0));
    expect($result->fontFaceRules)->toBe([]);
    expect($result->warnings)->toBe([]);
});

it('Finding D: a comment merely mentioning "@page" does not hijack the following rule\'s body', function () {
    $result = new StylesheetParser()->parse('/* @page */ p { color: red }');
    expect($result->rules)->toHaveCount(1);
    expect($result->rules[0]->declarations['color'])->toEqual(new Color(255, 0, 0));
    expect($result->pageRule)->toBeNull();
    expect($result->warnings)->toBe([]);
});

it('Finding D (bundled Minor 4): a comment INSIDE a real @font-face body is stripped, no spurious descriptor warnings', function () {
    $result = new StylesheetParser()->parse(
        "@font-face { /* a comment */ font-family: 'X'; src: url('a.ttf') /* trailing */ }",
    );
    expect($result->fontFaceRules)->toHaveCount(1);
    expect($result->fontFaceRules[0]->family)->toBe('X');
    expect($result->warnings)->toBe([]);
});

it('Finding D: a real @font-face block still extracts correctly when a comment PRECEDES it', function () {
    $result = new StylesheetParser()->parse(
        "/* leading comment */ @font-face { font-family: 'X'; src: url('a.ttf') } p { color: red }",
    );
    expect($result->fontFaceRules)->toHaveCount(1);
    expect($result->rules)->toHaveCount(1);
    expect($result->warnings)->toBe([]);
});

it('Finding D: a literal "/*" inside a url() is NOT treated as a comment start', function () {
    // A pathological but well-formed url() containing a comment-like substring must survive intact
    // -- the quote-aware stripper must not start "eating" from inside a quoted string.
    $result = new StylesheetParser()->parse("@font-face { font-family: 'X'; src: url('a/*b.ttf') }");
    expect($result->fontFaceRules)->toHaveCount(1);
    expect($result->fontFaceRules[0]->srcPath)->toBe('a/*b.ttf');
});
