<?php

declare(strict_types=1);

use Pliego\Css\Selector;

it('parses compound selectors', function () {
    $s = Selector::fromString('p.note');
    assert($s !== null);
    expect($s->specificity())->toBe(11);
});
it('orders specificity id > class > type', function () {
    $id = Selector::fromString('#top');
    $class = Selector::fromString('.note');
    $type = Selector::fromString('p');
    assert($id !== null && $class !== null && $type !== null);
    expect($id->specificity())->toBeGreaterThan($class->specificity());
    expect($class->specificity())->toBeGreaterThan($type->specificity());
});
it('rejects combinators in M0', fn() => expect(Selector::fromString('p > span'))->toBeNull());
it('matches elements by tag, class and id', function () {
    $doc = \Dom\HTMLDocument::createFromString('<p id="top" class="note big">x</p>', LIBXML_NOERROR);
    $p = $doc->querySelector('p');
    assert($p !== null);
    $matching = Selector::fromString('p.note');
    $failing = Selector::fromString('div.note');
    assert($matching !== null && $failing !== null);
    expect($matching->matches($p))->toBeTrue();
    expect($failing->matches($p))->toBeFalse();
});
