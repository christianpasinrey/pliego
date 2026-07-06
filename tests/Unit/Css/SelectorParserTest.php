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

// --- Basic matching (unchanged since M6-T1) --------------------------------------------------

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
it('does not warn for a plain simple selector that fully matches today', function () {
    $warnings = new WarningCollector();
    new SelectorParser($warnings)->parse('p.note');
    expect($warnings->drain())->toBe([]);
});

// --- M6-T2: combinators — real right-to-left matching with backtracking -----------------------

/** @return \Dom\Element */
function el(\Dom\HTMLDocument $doc, string $selector)
{
    $found = $doc->querySelector($selector);
    assert($found !== null);
    return $found;
}

function matchSelector(string $css, string $html, string $targetSelector): bool
{
    $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $complex = new SelectorParser()->parse($css);
    assert($complex !== null);
    return $complex->matches(el($doc, $targetSelector));
}

it('matches a descendant combinator across any depth', function () {
    expect(matchSelector('ul li', '<ul><li>x</li></ul>', 'li'))->toBeTrue();
    expect(matchSelector('ul li', '<ul><li><b><i>x</i></b></li></ul>', 'i'))->toBeFalse();
});
it('searches every ancestor for a descendant combinator, not just the immediate parent', function () {
    // The <b> between div and span is NOT a div: Descendant must keep walking up past it.
    expect(matchSelector('div span', '<div><b><span>x</span></b></div>', 'span'))->toBeTrue();
});
it('does not match a descendant combinator when no ancestor matches', function () {
    expect(matchSelector('table span', '<div><span>x</span></div>', 'span'))->toBeFalse();
});
it('matches a child combinator against the immediate parent only', function () {
    expect(matchSelector('.card > p', '<div class="card"><p>x</p></div>', 'p'))->toBeTrue();
    expect(matchSelector('.card > p', '<div class="card"><div><p>x</p></div></div>', 'p'))->toBeFalse();
});
it('matches a next-sibling combinator against the immediately preceding element sibling only', function () {
    expect(matchSelector('h2 + p', '<div><h2>t</h2><p>x</p></div>', 'p'))->toBeTrue();
    expect(matchSelector('h2 + p', '<div><h2>t</h2><span>s</span><p>x</p></div>', 'p'))->toBeFalse();
});
it('matches a subsequent-sibling combinator against any preceding element sibling', function () {
    expect(matchSelector('h2 ~ p', '<div><h2>t</h2><span>s</span><p>x</p></div>', 'p'))->toBeTrue();
    expect(matchSelector('h2 ~ p', '<div><p>x</p><h2>t</h2></div>', 'p'))->toBeFalse();
});
it('chains three compounds across mixed combinators (ul ol+li)', function () {
    expect(matchSelector('ul ol+li', '<ul><ol></ol><li>x</li></ul>', 'li'))->toBeTrue();
});
it('backtracks past a nearer ancestor that fails the rest of the chain to find one that matches', function () {
    // Two ".b" ancestors of <span>: the NEAREST one's parent is a plain <div> (fails the strict
    // Child combinator against ".a"), but the FARTHER ".b" is a direct child of ".a". A
    // non-backtracking implementation that commits to the first ".b" it finds (the nearest one)
    // would wrongly return false here; the real algorithm must keep searching upward.
    $html = '<div class="a"><div class="b"><div><div class="b"><span>x</span></div></div></div></div>';
    expect(matchSelector('.a > .b span', $html, 'span'))->toBeTrue();
});

// --- M6-T2: attribute selectors ------------------------------------------------------------

it('matches attribute presence', function () {
    expect(matchSelector('[data-x]', '<p data-x="1">x</p>', 'p'))->toBeTrue();
    expect(matchSelector('[data-x]', '<p>x</p>', 'p'))->toBeFalse();
});
it('matches attribute exact value', function () {
    expect(matchSelector('[type=text]', '<input type="text">', 'input'))->toBeTrue();
    expect(matchSelector('[type=text]', '<input type="password">', 'input'))->toBeFalse();
});
it('matches [attr^=] as a prefix, never matching an empty pattern', function () {
    expect(matchSelector('[data-x^="pre"]', '<p data-x="prefix">x</p>', 'p'))->toBeTrue();
    expect(matchSelector('[data-x^="pre"]', '<p data-x="nope">x</p>', 'p'))->toBeFalse();
    expect(matchSelector('[data-x^=""]', '<p data-x="anything">x</p>', 'p'))->toBeFalse();
});
it('matches [attr$=] as a suffix, never matching an empty pattern', function () {
    expect(matchSelector('[data-x$="fix"]', '<p data-x="prefix">x</p>', 'p'))->toBeTrue();
    expect(matchSelector('[data-x$=""]', '<p data-x="anything">x</p>', 'p'))->toBeFalse();
});
it('matches [attr*=] as a substring, never matching an empty pattern', function () {
    expect(matchSelector('[data-x*="efi"]', '<p data-x="prefix">x</p>', 'p'))->toBeTrue();
    expect(matchSelector('[data-x*=""]', '<p data-x="anything">x</p>', 'p'))->toBeFalse();
});
it('matches [attr~=] as one whole word in a space-separated list', function () {
    expect(matchSelector('[class~=big]', '<p class="note big">x</p>', 'p'))->toBeTrue();
    expect(matchSelector('[class~=big]', '<p class="bigger">x</p>', 'p'))->toBeFalse();
});
it('matches [attr|=] as an exact value or a hyphen-prefixed subcode', function () {
    expect(matchSelector('[lang|=en]', '<p lang="en">x</p>', 'p'))->toBeTrue();
    expect(matchSelector('[lang|=en]', '<p lang="en-US">x</p>', 'p'))->toBeTrue();
    expect(matchSelector('[lang|=en]', '<p lang="enough">x</p>', 'p'))->toBeFalse();
});
it('accepts and warns on the case-insensitive "i" flag, falling back to case-sensitive matching', function () {
    $warnings = new WarningCollector();
    $complex = new SelectorParser($warnings)->parse('[data-x="ABC" i]');
    assert($complex !== null);
    $doc = \Dom\HTMLDocument::createFromString('<p data-x="ABC">x</p><p data-x="abc">y</p>', LIBXML_NOERROR);
    $upper = $doc->querySelectorAll('p')[0];
    $lower = $doc->querySelectorAll('p')[1];
    expect($complex->matches($upper))->toBeTrue();
    expect($complex->matches($lower))->toBeFalse();
    expect($warnings->drain())->toContain(
        'Case-insensitive attribute matching ("i" flag) is not supported; '
        . 'falling back to case-sensitive matching: [data-x="ABC" i]',
    );
});

// --- M6-T2: structural pseudo-classes ------------------------------------------------------

it('matches :root only against the document element', function () {
    expect(matchSelector(':root', '<html><body><p>x</p></body></html>', 'html'))->toBeTrue();
    expect(matchSelector(':root', '<html><body><p>x</p></body></html>', 'p'))->toBeFalse();
});
it('matches :first-child and :last-child against element siblings only (surrounding text nodes do not count)', function () {
    // The stray text nodes around the <li>s must not shift the element-sibling positions: the
    // first <li> is still :first-child even though it is not literally the first child node.
    $html = '<ul>lead text<li>a</li><li>b</li><li>c</li>trailing text</ul>';
    $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $items = $doc->querySelectorAll('li');
    $complexFirst = new SelectorParser()->parse(':first-child');
    $complexLast = new SelectorParser()->parse(':last-child');
    assert($complexFirst !== null && $complexLast !== null);
    expect($complexFirst->matches($items[0]))->toBeTrue();
    expect($complexFirst->matches($items[1]))->toBeFalse();
    expect($complexLast->matches($items[2]))->toBeTrue();
    expect($complexLast->matches($items[1]))->toBeFalse();
});
it('matches a striped table via :nth-child(odd) and :nth-child(even)', function () {
    $html = '<table><tr>1</tr><tr>2</tr><tr>3</tr><tr>4</tr></table>';
    $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $rows = $doc->querySelectorAll('tr');
    $odd = new SelectorParser()->parse('tr:nth-child(odd)');
    $even = new SelectorParser()->parse('tr:nth-child(even)');
    assert($odd !== null && $even !== null);
    expect($odd->matches($rows[0]))->toBeTrue();
    expect($odd->matches($rows[1]))->toBeFalse();
    expect($odd->matches($rows[2]))->toBeTrue();
    expect($odd->matches($rows[3]))->toBeFalse();
    expect($even->matches($rows[0]))->toBeFalse();
    expect($even->matches($rows[1]))->toBeTrue();
});
it('matches :nth-child with the full An+B grammar (2n, 2n+1, -n+3, plain integer)', function () {
    $html = '<ul><li>1</li><li>2</li><li>3</li><li>4</li><li>5</li></ul>';
    $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $items = $doc->querySelectorAll('li');

    $twoN = new SelectorParser()->parse('li:nth-child(2n)');
    assert($twoN !== null);
    expect(array_map(fn($el) => $twoN->matches($el), iterator_to_array($items)))
        ->toBe([false, true, false, true, false]);

    $twoNPlus1 = new SelectorParser()->parse('li:nth-child(2n+1)');
    assert($twoNPlus1 !== null);
    expect(array_map(fn($el) => $twoNPlus1->matches($el), iterator_to_array($items)))
        ->toBe([true, false, true, false, true]);

    $negNPlus3 = new SelectorParser()->parse('li:nth-child(-n+3)');
    assert($negNPlus3 !== null);
    expect(array_map(fn($el) => $negNPlus3->matches($el), iterator_to_array($items)))
        ->toBe([true, true, true, false, false]);

    $three = new SelectorParser()->parse('li:nth-child(3)');
    assert($three !== null);
    expect(array_map(fn($el) => $three->matches($el), iterator_to_array($items)))
        ->toBe([false, false, true, false, false]);
});
it('rejects an invalid :nth-child() argument with a warning', function () {
    $warnings = new WarningCollector();
    $result = new SelectorParser($warnings)->parse('li:nth-child(foo)');
    expect($result)->toBeNull();
    expect($warnings->drain())->toContain('Invalid :nth-child() argument: "foo"');
});

// --- M6-T2: :not() ---------------------------------------------------------------------------

it('matches :not() with a type argument', function () {
    expect(matchSelector(':not(p)', '<div>x</div>', 'div'))->toBeTrue();
    expect(matchSelector(':not(p)', '<p>x</p>', 'p'))->toBeFalse();
});
it('matches :not() with a class argument', function () {
    expect(matchSelector(':not(.hidden)', '<p class="visible">x</p>', 'p'))->toBeTrue();
    expect(matchSelector(':not(.hidden)', '<p class="hidden">x</p>', 'p'))->toBeFalse();
});
it('matches :not() with an id argument', function () {
    expect(matchSelector(':not(#top)', '<p id="other">x</p>', 'p'))->toBeTrue();
    expect(matchSelector(':not(#top)', '<p id="top">x</p>', 'p'))->toBeFalse();
});
it('matches :not() with an attribute argument', function () {
    expect(matchSelector(':not([disabled])', '<input>', 'input'))->toBeTrue();
    expect(matchSelector(':not([disabled])', '<input disabled>', 'input'))->toBeFalse();
});
it('matches :not() with a pseudo-class argument', function () {
    expect(matchSelector('li:not(:first-child)', '<ul><li>a</li><li>b</li></ul>', 'li'))->toBeFalse();
    $doc = \Dom\HTMLDocument::createFromString('<ul><li>a</li><li>b</li></ul>', LIBXML_NOERROR);
    $second = $doc->querySelectorAll('li')[1];
    $complex = new SelectorParser()->parse('li:not(:first-child)');
    assert($complex !== null);
    expect($complex->matches($second))->toBeTrue();
});
it('rejects a :not() argument with more than one simple selector (compound, not a single simple selector)', function () {
    $warnings = new WarningCollector();
    $result = new SelectorParser($warnings)->parse(':not(p.foo)');
    expect($result)->toBeNull();
    expect($warnings->drain())->toContain(
        ':not() argument must be a single simple selector (no compounds, no nesting): "p.foo"',
    );
});
it('rejects a :not() argument with two classes', function () {
    $result = new SelectorParser()->parse(':not(.a.b)');
    expect($result)->toBeNull();
});
it('rejects a nested :not(:not(...))', function () {
    $result = new SelectorParser()->parse(':not(:not(p))');
    expect($result)->toBeNull();
});
it('rejects a bare :not without an argument', function () {
    $result = new SelectorParser()->parse('p:not');
    expect($result)->toBeNull();
});

// --- M6-T2: permanently-excluded and unsupported pseudo-classes --------------------------------

it('never matches a dynamic pseudo-class, but warns once at parse time', function () {
    $warnings = new WarningCollector();
    $complex = new SelectorParser($warnings)->parse('a:hover');
    assert($complex !== null);
    $doc = \Dom\HTMLDocument::createFromString('<a>x</a>', LIBXML_NOERROR);
    expect($complex->matches(el($doc, 'a')))->toBeFalse();
    expect($warnings->drain())->toContain('Dynamic pseudo-class has no effect in paged media: :hover');
});
it('never matches :focus/:active/:visited/:link either', function () {
    foreach (['focus', 'active', 'visited', 'link'] as $name) {
        $complex = new SelectorParser()->parse("a:$name");
        assert($complex !== null);
        $doc = \Dom\HTMLDocument::createFromString('<a>x</a>', LIBXML_NOERROR);
        expect($complex->matches(el($doc, 'a')))->toBeFalse();
    }
});
it('never matches :nth-of-type and warns "not supported yet"', function () {
    $warnings = new WarningCollector();
    $complex = new SelectorParser($warnings)->parse('p:nth-of-type(1)');
    assert($complex !== null);
    $doc = \Dom\HTMLDocument::createFromString('<p>x</p>', LIBXML_NOERROR);
    expect($complex->matches(el($doc, 'p')))->toBeFalse();
    expect($warnings->drain())->toContain('Pseudo-class not supported yet: :nth-of-type');
});
it('never matches an unknown pseudo-class and warns', function () {
    $warnings = new WarningCollector();
    $complex = new SelectorParser($warnings)->parse('p:made-up');
    assert($complex !== null);
    $doc = \Dom\HTMLDocument::createFromString('<p>x</p>', LIBXML_NOERROR);
    expect($complex->matches(el($doc, 'p')))->toBeFalse();
    expect($warnings->drain())->toContain('Unknown pseudo-class: :made-up');
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
