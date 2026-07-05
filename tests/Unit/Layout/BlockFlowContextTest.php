<?php

declare(strict_types=1);

use Pliego\Box\BoxTreeBuilder;
use Pliego\Css\StylesheetParser;
use Pliego\Dom\HtmlParser;
use Pliego\Layout\BlockFlowContext;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;
use Pliego\Layout\TextMeasurer;
use Pliego\Style\CssStyleSource;
use Pliego\Style\StyleResolver;
use Pliego\Text\FontCatalog;

function layoutHtml(string $html, string $css, float $width = 500.0): BoxFragment
{
    $doc = HtmlParser::parse($html);
    $map = new StyleResolver([new CssStyleSource(new StylesheetParser()->parse($css))])->resolve($doc);
    $root = new BoxTreeBuilder()->build($doc, $map);
    $measurer = new TextMeasurer();
    $catalog = FontCatalog::withDefaults();
    return new BlockFlowContext($measurer, $catalog)->layout($root, new Rect(0.0, 0.0, $width, INF));
}

function textFragments(BoxFragment $box): array
{
    $out = [];
    foreach ($box->children as $child) {
        if ($child instanceof TextFragment) {
            $out[] = $child;
        } elseif ($child instanceof BoxFragment) {
            $out = [...$out, ...textFragments($child)];
        }
    }
    return $out;
}

it('stacks blocks vertically honouring margins', function () {
    $frag = layoutHtml('<body><p>a</p><p>b</p></body>', 'p { margin: 10px 0 10px 0 }');
    $first = $frag->children[0];
    $second = $frag->children[1];
    assert($first instanceof BoxFragment && $second instanceof BoxFragment);
    expect($first->rect->y)->toBe(10.0);
    expect($second->rect->y)->toBe($first->rect->bottom() + 20.0);
});
it('wraps text greedily into multiple lines', function () {
    $frag = layoutHtml('<body><p>uno dos tres cuatro cinco seis siete ocho</p></body>', '', 120.0);
    $lines = textFragments($frag);
    expect(count($lines))->toBeGreaterThan(1);
    foreach ($lines as $line) {
        expect($line->rect->width)->toBeLessThanOrEqual(120.0);
    }
    expect($lines[1]->rect->y)->toBeGreaterThan($lines[0]->rect->y);
});
it('applies padding to content position and box height', function () {
    $frag = layoutHtml('<body><div>x</div></body>', 'div { padding: 20px }');
    $div = $frag->children[0];
    assert($div instanceof BoxFragment);
    $text = textFragments($div)[0];
    expect($text->rect->x)->toBe(20.0);
    expect($text->rect->y)->toBe(20.0);
    expect($div->rect->height)->toBe($text->rect->height + 40.0);
});
it('honours declared width', function () {
    $frag = layoutHtml('<body><div>x</div></body>', 'div { width: 200px }');
    $div = $frag->children[0];
    assert($div instanceof BoxFragment);
    expect($div->rect->width)->toBe(200.0);
});
it('excludes the last child margin-bottom from the parent content height', function () {
    // CSS 2.2 §10.6.3: la altura de contenido llega hasta el borde inferior del
    // border-box de la última caja en flujo; los márgenes se salen del cálculo.
    $frag = layoutHtml('<body><div class="box"><p>x</p></div></body>', '.box { padding: 10px } p { margin-bottom: 10px }');
    $box = $frag->children[0];
    assert($box instanceof BoxFragment);
    $p = $box->children[0];
    assert($p instanceof BoxFragment);
    $lineHeight = $p->rect->height;
    expect($box->rect->height)->toBe($lineHeight + 20.0);
});
