<?php

declare(strict_types=1);

use Pliego\Css\StylesheetParser;
use Pliego\Css\Value\Color;
use Pliego\Style\CssStyleSource;
use Pliego\Style\Display;
use Pliego\Style\FontStyle;
use Pliego\Style\StyleResolver;
use Pliego\Style\TextAlign;

function resolveDoc(string $css, string $html): array
{
    $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $map = new StyleResolver([new CssStyleSource(new StylesheetParser()->parse($css))])->resolve($doc);
    return [$doc, $map];
}

it('inherits color and font-size down the tree', function () {
    [$doc, $map] = resolveDoc('body { color: #f00; font-size: 20px }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->color)->toEqual(new Color(255, 0, 0));
    expect($map->get($p)->fontSizePx)->toBe(20.0);
});
it('does not inherit box properties', function () {
    [$doc, $map] = resolveDoc('body { padding-left: 40px }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->paddingLeft->px)->toBe(0.0);
});
it('applies specificity: class beats type', function () {
    [$doc, $map] = resolveDoc('.note { color: #00f } p { color: #f00 }', '<body><p class="note">x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->color)->toEqual(new Color(0, 0, 255));
});
it('breaks specificity ties by source order', function () {
    [$doc, $map] = resolveDoc('p { color: #f00 } p { color: #00f }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->color)->toEqual(new Color(0, 0, 255));
});
it('defaults head-content elements to display none', function () {
    [$doc, $map] = resolveDoc('', '<html><head><title>t</title></head><body></body></html>');
    $title = $doc->querySelector('title');
    assert($title !== null);
    expect($map->get($title)->display)->toBe(Display::None);
});

it('computes bold for strong via UA default', function () {
    [$doc, $map] = resolveDoc('', '<body><strong>x</strong></body>');
    $strong = $doc->querySelector('strong');
    assert($strong !== null);
    expect($map->get($strong)->fontWeight)->toBe(700);
});

it('computes bold for b via UA default', function () {
    [$doc, $map] = resolveDoc('', '<body><b>x</b></body>');
    $b = $doc->querySelector('b');
    assert($b !== null);
    expect($map->get($b)->fontWeight)->toBe(700);
});

it('computes italic for em and i via UA default', function () {
    [$doc, $map] = resolveDoc('', '<body><em>x</em><i>y</i></body>');
    $em = $doc->querySelector('em');
    $i = $doc->querySelector('i');
    assert($em !== null && $i !== null);
    expect($map->get($em)->fontStyle)->toBe(FontStyle::Italic);
    expect($map->get($i)->fontStyle)->toBe(FontStyle::Italic);
});

it('computes underline for a via UA default', function () {
    [$doc, $map] = resolveDoc('', '<body><a>x</a></body>');
    $a = $doc->querySelector('a');
    assert($a !== null);
    expect($map->get($a)->underline)->toBeTrue();
});

it('lets author declarations override UA defaults', function () {
    [$doc, $map] = resolveDoc('strong { font-weight: normal }', '<body><strong>x</strong></body>');
    $strong = $doc->querySelector('strong');
    assert($strong !== null);
    expect($map->get($strong)->fontWeight)->toBe(400);
});

it('inherits font-weight and text-align down the tree', function () {
    [$doc, $map] = resolveDoc('body { font-weight: bold; text-align: center }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->fontWeight)->toBe(700);
    expect($map->get($p)->textAlign)->toBe(TextAlign::Center);
});

it('resolves numeric line-height against own font-size', function () {
    [$doc, $map] = resolveDoc('p { font-size: 20px; line-height: 1.5 }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->lineHeightPx)->toBe(30.0);
});

it('passes px line-height through and treats normal as null', function () {
    [$doc, $map] = resolveDoc('p { line-height: 24px } span { line-height: normal }', '<body><p>x</p><span>y</span></body>');
    $p = $doc->querySelector('p');
    $span = $doc->querySelector('span');
    assert($p !== null && $span !== null);
    expect($map->get($p)->lineHeightPx)->toBe(24.0);
    expect($map->get($span)->lineHeightPx)->toBeNull();
});

it('inherits the resolved line-height px down the tree', function () {
    [$doc, $map] = resolveDoc('body { font-size: 10px; line-height: 2 }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->lineHeightPx)->toBe(20.0);
});

it('keeps M0 styles intact', function () {
    [$doc, $map] = resolveDoc('body { color: #f00; font-size: 20px }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    $style = $map->get($p);
    expect($style->color)->toEqual(new Color(255, 0, 0));
    expect($style->fontSizePx)->toBe(20.0);
    expect($style->display)->toBe(Display::Block);
    expect($style->paddingLeft->px)->toBe(0.0);
    expect($style->fontWeight)->toBe(400);
    expect($style->fontStyle)->toBe(FontStyle::Normal);
    expect($style->textAlign)->toBe(TextAlign::Left);
    expect($style->underline)->toBeFalse();
    expect($style->lineHeightPx)->toBeNull();
});
