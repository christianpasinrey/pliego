<?php

declare(strict_types=1);

use Pliego\Box\BoxTreeBuilder;
use Pliego\Css\StylesheetParser;
use Pliego\Css\WarningCollector;
use Pliego\Dom\HtmlParser;
use Pliego\Image\ImageLoader;
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
    $root = new BoxTreeBuilder(new ImageLoader(), new WarningCollector(), __DIR__)->build($doc, $map);
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

// --- M2-T4: bordes en la geometría y % contra el ancho del containing block --------------

it('a solid border displaces the content and grows the border-box height', function () {
    $frag = layoutHtml('<body><div>x</div></body>', 'div { border: 2px solid #000 }');
    $div = $frag->children[0];
    assert($div instanceof BoxFragment);
    $text = textFragments($div)[0];
    expect($text->rect->x)->toBe(2.0);
    expect($text->rect->y)->toBe(2.0);
    expect($div->rect->height)->toBe($text->rect->height + 4.0);
});

it('does not displace content when border-width is declared without a border-style (CSS 2.2 §8.5.3)', function () {
    $frag = layoutHtml('<body><div>x</div></body>', 'div { border-top-width: 10px }');
    $div = $frag->children[0];
    assert($div instanceof BoxFragment);
    $text = textFragments($div)[0];
    expect($text->rect->y)->toBe(0.0);
    expect($div->rect->height)->toBe($text->rect->height);
});

it('carries the computed border set onto the fragment for painting', function () {
    $frag = layoutHtml('<body><div>x</div></body>', 'div { border: 2px solid #ff0000 }');
    $div = $frag->children[0];
    assert($div instanceof BoxFragment);
    expect($div->borders->top->widthPx)->toBe(2.0);
    expect($div->borders->isVisible())->toBeTrue();
});

it('reports no visible border when none is declared', function () {
    $frag = layoutHtml('<body><div>x</div></body>', '');
    $div = $frag->children[0];
    assert($div instanceof BoxFragment);
    expect($div->borders->isVisible())->toBeFalse();
});

it('box-sizing: border-box subtracts padding and border from the content width', function () {
    $frag = layoutHtml(
        '<body><div class="outer"><div class="inner">x</div></div></body>',
        '.outer { width: 100px; padding: 10px; border: 5px solid #000; box-sizing: border-box }',
    );
    $outer = $frag->children[0];
    assert($outer instanceof BoxFragment);
    expect($outer->rect->width)->toBe(100.0);
    $inner = $outer->children[0];
    assert($inner instanceof BoxFragment);
    // content width = 100 - 2*10 (padding) - 2*5 (border) = 70; inner width:auto fills it fully.
    expect($inner->rect->width)->toBe(70.0);
});

it('content-box (default) adds padding and border on top of the declared width', function () {
    $frag = layoutHtml(
        '<body><div class="outer"><div class="inner">x</div></div></body>',
        '.outer { width: 100px; padding: 10px; border: 5px solid #000 }',
    );
    $outer = $frag->children[0];
    assert($outer instanceof BoxFragment);
    // border box = declared content width (100) + paddings (20) + borders (10) = 130
    expect($outer->rect->width)->toBe(130.0);
    $inner = $outer->children[0];
    assert($inner instanceof BoxFragment);
    expect($inner->rect->width)->toBe(100.0);
});

it('resolves width % against the containing block width', function () {
    $frag = layoutHtml('<body><div>x</div></body>', 'div { width: 50% }', 400.0);
    $div = $frag->children[0];
    assert($div instanceof BoxFragment);
    expect($div->rect->width)->toBe(200.0);
});

it('resolves nested percentages against each ancestor\'s own content width', function () {
    $frag = layoutHtml(
        '<body><div class="outer"><div class="inner">x</div></div></body>',
        '.outer { width: 50% } .inner { width: 50% }',
        400.0,
    );
    $outer = $frag->children[0];
    assert($outer instanceof BoxFragment);
    expect($outer->rect->width)->toBe(200.0);
    $inner = $outer->children[0];
    assert($inner instanceof BoxFragment);
    expect($inner->rect->width)->toBe(100.0);
});

it('resolves margin % against the containing block WIDTH, never height', function () {
    $frag = layoutHtml('<body><div>x</div></body>', 'div { margin-left: 10% }', 400.0);
    $div = $frag->children[0];
    assert($div instanceof BoxFragment);
    expect($div->rect->x)->toBe(40.0);
});

it('resolves padding-top % against the containing block WIDTH, not any height', function () {
    $frag = layoutHtml('<body><div>x</div></body>', 'div { padding-top: 10% }', 300.0);
    $div = $frag->children[0];
    assert($div instanceof BoxFragment);
    $text = textFragments($div)[0];
    expect($text->rect->y)->toBe(30.0);
});
