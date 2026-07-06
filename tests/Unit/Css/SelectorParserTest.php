<?php

declare(strict_types=1);

use Pliego\Css\Combinator;
use Pliego\Css\SelectorParser;
use Pliego\Css\WarningCollector;

/** @return array{int, int, int} */
function specTuple(string $selector): array
{
    $complex = new SelectorParser()->parse($selector);
    assert($complex !== null);
    $s = $complex->specificity();
    return [$s->a, $s->b, $s->c];
}

// --- selectors-3 §17: batería de specificity (adaptada de los ejemplos de la spec) --------------

it('gives the universal selector zero specificity', function () {
    expect(specTuple('*'))->toBe([0, 0, 0]);
});
it('gives a single type selector specificity 0,0,1', function () {
    expect(specTuple('li'))->toBe([0, 0, 1]);
});
it('gives a descendant of two types specificity 0,0,2', function () {
    expect(specTuple('ul li'))->toBe([0, 0, 2]);
});
it('gives ul ol+li specificity 0,0,3 (three types across a next-sibling combinator)', function () {
    expect(specTuple('ul ol+li'))->toBe([0, 0, 3]);
});
it('gives h1 + *[rel=up] specificity 0,1,1 (universal counts nothing, attribute counts as b)', function () {
    expect(specTuple('h1 + *[rel=up]'))->toBe([0, 1, 1]);
});
it('gives ul ol li.red specificity 0,1,3', function () {
    expect(specTuple('ul ol li.red'))->toBe([0, 1, 3]);
});
it('gives li.red.level specificity 0,2,1 (two classes on the same compound)', function () {
    expect(specTuple('li.red.level'))->toBe([0, 2, 1]);
});
it('gives a lone id selector specificity 1,0,0', function () {
    expect(specTuple('#x34y'))->toBe([1, 0, 0]);
});
it('gives #s12:not(FOO) specificity 1,0,1 (the id plus :not()\'s type argument)', function () {
    expect(specTuple('#s12:not(FOO)'))->toBe([1, 0, 1]);
});
it('gives :not(FOO) alone specificity 0,0,1 (:not() itself never counts, only its argument)', function () {
    expect(specTuple(':not(FOO)'))->toBe([0, 0, 1]);
});
it('gives :not(.foo) specificity 0,1,0 (class argument)', function () {
    expect(specTuple(':not(.foo)'))->toBe([0, 1, 0]);
});
it('gives :not(#foo) specificity 1,0,0 (id argument)', function () {
    expect(specTuple(':not(#foo)'))->toBe([1, 0, 0]);
});
it('gives a class selector specificity 0,1,0', function () {
    expect(specTuple('.note'))->toBe([0, 1, 0]);
});
it('gives an attribute selector specificity 0,1,0', function () {
    expect(specTuple('[disabled]'))->toBe([0, 1, 0]);
});
it('gives a type selector with a generic pseudo-class specificity 0,1,1', function () {
    expect(specTuple('a:hover'))->toBe([0, 1, 1]);
});
it('gives a child-combinator selector specificity 0,0,2', function () {
    expect(specTuple('ul > li'))->toBe([0, 0, 2]);
});
it('gives a subsequent-sibling-combinator selector specificity 0,0,2', function () {
    expect(specTuple('h1 ~ p'))->toBe([0, 0, 2]);
});
it('combines id, class and type on one compound: p#top.note is 1,1,1', function () {
    expect(specTuple('p#top.note'))->toBe([1, 1, 1]);
});
it('gives a type selector with a quoted-value attribute specificity 0,1,1', function () {
    expect(specTuple('input[type="text"]'))->toBe([0, 1, 1]);
});
it('gives universal combined with a class specificity 0,1,0', function () {
    expect(specTuple('*.note'))->toBe([0, 1, 0]);
});

// --- Combinators: tokenization -------------------------------------------------------------

it('tokenizes whitespace as the Descendant combinator', function () {
    $complex = new SelectorParser()->parse('ul li');
    assert($complex !== null);
    expect($complex->compounds)->toHaveCount(2);
    [$combinator] = $complex->compounds[1];
    expect($combinator)->toBe(Combinator::Descendant);
});
it('tokenizes > as the Child combinator regardless of surrounding whitespace', function () {
    foreach (['ul>li', 'ul > li', 'ul >li', 'ul> li'] as $selector) {
        $complex = new SelectorParser()->parse($selector);
        assert($complex !== null);
        [$combinator] = $complex->compounds[1];
        expect($combinator)->toBe(Combinator::Child);
    }
});
it('tokenizes + as the NextSibling combinator regardless of surrounding whitespace', function () {
    foreach (['h1+p', 'h1 + p', 'h1 +p', 'h1+ p'] as $selector) {
        $complex = new SelectorParser()->parse($selector);
        assert($complex !== null);
        [$combinator] = $complex->compounds[1];
        expect($combinator)->toBe(Combinator::NextSibling);
    }
});
it('tokenizes ~ as the SubsequentSibling combinator', function () {
    $complex = new SelectorParser()->parse('h1~p');
    assert($complex !== null);
    [$combinator] = $complex->compounds[1];
    expect($combinator)->toBe(Combinator::SubsequentSibling);
});

// --- Multiple classes per compound (behavior improvement over the old M0 regex) -------------

it('parses multiple classes on the same compound (.a.b), an improvement over the old regex-only matcher', function () {
    $doc = \Dom\HTMLDocument::createFromString('<p class="a b">x</p>', LIBXML_NOERROR);
    $p = $doc->querySelector('p');
    assert($p !== null);
    $complex = new SelectorParser()->parse('.a.b');
    assert($complex !== null);
    expect($complex->specificity()->b)->toBe(2);
    expect($complex->matches($p))->toBeTrue();
});
it('does not match when only one of two required classes is present', function () {
    $doc = \Dom\HTMLDocument::createFromString('<p class="a">x</p>', LIBXML_NOERROR);
    $p = $doc->querySelector('p');
    assert($p !== null);
    $complex = new SelectorParser()->parse('.a.b');
    assert($complex !== null);
    expect($complex->matches($p))->toBeFalse();
});

// --- Matching staging (M6-T1 adjudication) ---------------------------------------------------

it('matches a simple compound selector exactly like the old M0 Selector', function () {
    $doc = \Dom\HTMLDocument::createFromString('<p id="top" class="note big">x</p>', LIBXML_NOERROR);
    $p = $doc->querySelector('p');
    assert($p !== null);
    $matching = new SelectorParser()->parse('p.note');
    $failing = new SelectorParser()->parse('div.note');
    assert($matching !== null && $failing !== null);
    expect($matching->matches($p))->toBeTrue();
    expect($failing->matches($p))->toBeFalse();
});
it('matches the universal selector against any element', function () {
    $doc = \Dom\HTMLDocument::createFromString('<p>x</p>', LIBXML_NOERROR);
    $p = $doc->querySelector('p');
    assert($p !== null);
    $complex = new SelectorParser()->parse('*');
    assert($complex !== null);
    expect($complex->matches($p))->toBeTrue();
});
it('stages a selector with a combinator: parses fine but matches() is false', function () {
    $doc = \Dom\HTMLDocument::createFromString('<ul><li>x</li></ul>', LIBXML_NOERROR);
    $li = $doc->querySelector('li');
    assert($li !== null);
    $complex = new SelectorParser()->parse('ul li');
    assert($complex !== null);
    expect($complex->matches($li))->toBeFalse();
});
it('stages a selector with an attribute on a single compound: parses fine but matches() is false', function () {
    $doc = \Dom\HTMLDocument::createFromString('<input type="text">', LIBXML_NOERROR);
    $input = $doc->querySelector('input');
    assert($input !== null);
    $complex = new SelectorParser()->parse('input[type=text]');
    assert($complex !== null);
    expect($complex->matches($input))->toBeFalse();
});
it('stages a selector with a pseudo-class on a single compound: parses fine but matches() is false', function () {
    $doc = \Dom\HTMLDocument::createFromString('<a>x</a>', LIBXML_NOERROR);
    $a = $doc->querySelector('a');
    assert($a !== null);
    $complex = new SelectorParser()->parse('a:hover');
    assert($complex !== null);
    expect($complex->matches($a))->toBeFalse();
});
it('emits the one-time staging warning exactly once per staged selector', function () {
    $warnings = new WarningCollector();
    new SelectorParser($warnings)->parse('ul li.red[data-x] :hover');
    // Un único warning de staging por selector, no uno por combinador/atributo/pseudo-clase.
    $stagingWarnings = array_filter($warnings->drain(), static fn(string $w): bool => str_contains($w, 'M6-T2'));
    expect($stagingWarnings)->toHaveCount(1);
});
it('does not warn for a plain simple selector that fully matches today', function () {
    $warnings = new WarningCollector();
    new SelectorParser($warnings)->parse('p.note');
    expect($warnings->drain())->toBe([]);
});

// --- Parse errors: null + warning -------------------------------------------------------------

it('rejects an empty selector with a warning', function () {
    $warnings = new WarningCollector();
    $result = new SelectorParser($warnings)->parse('   ');
    expect($result)->toBeNull();
    expect($warnings->drain())->not->toBe([]);
});
it('rejects a class starting with a digit with a warning', function () {
    $warnings = new WarningCollector();
    $result = new SelectorParser($warnings)->parse('.123abc');
    expect($result)->toBeNull();
    expect($warnings->drain())->not->toBe([]);
});
it('rejects a trailing combinator with a warning', function () {
    $warnings = new WarningCollector();
    $result = new SelectorParser($warnings)->parse('ul >');
    expect($result)->toBeNull();
    expect($warnings->drain())->not->toBe([]);
});
it('rejects an unclosed attribute selector with a warning', function () {
    $warnings = new WarningCollector();
    $result = new SelectorParser($warnings)->parse('p[foo');
    expect($result)->toBeNull();
    expect($warnings->drain())->not->toBe([]);
});
it('rejects a pseudo-element (::before) as a parse error in M6', function () {
    $result = new SelectorParser()->parse('p::before');
    expect($result)->toBeNull();
});
it('rejects a stray character with no combinator or separator with a warning', function () {
    $warnings = new WarningCollector();
    $result = new SelectorParser($warnings)->parse('p*span');
    expect($result)->toBeNull();
    expect($warnings->drain())->not->toBe([]);
});
