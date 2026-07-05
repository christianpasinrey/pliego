<?php

declare(strict_types=1);

use Pliego\Box\BoxTreeBuilder;
use Pliego\Css\StylesheetParser;
use Pliego\Css\WarningCollector;
use Pliego\Dom\HtmlParser;
use Pliego\Image\ImageLoader;
use Pliego\Layout\BlockFlowContext;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\ImageFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;
use Pliego\Layout\TextMeasurer;
use Pliego\Style\CssStyleSource;
use Pliego\Style\StyleResolver;
use Pliego\Text\FontCatalog;

// tests/resources/images fixtures (M3-T3): tiny.jpg is a real 4x3px JPEG (ratio height/width = 0.75).
const BLOCK_FLOW_IMAGE_FIXTURES_DIR = __DIR__ . '/../../../resources/images';

function layoutHtml(string $html, string $css, float $width = 500.0, string $basePath = __DIR__): BoxFragment
{
    $doc = HtmlParser::parse($html);
    $map = new StyleResolver([new CssStyleSource(new StylesheetParser()->parse($css))])->resolve($doc);
    $root = new BoxTreeBuilder(new ImageLoader(), new WarningCollector(), $basePath)->build($doc, $map);
    $measurer = new TextMeasurer();
    $catalog = FontCatalog::withDefaults();
    return new BlockFlowContext($measurer, $catalog)->layout($root, new Rect(0.0, 0.0, $width, INF));
}

function layoutImageHtml(string $html, string $css, float $width = 500.0): BoxFragment
{
    return layoutHtml($html, $css, $width, BLOCK_FLOW_IMAGE_FIXTURES_DIR);
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

// --- M3-T3: replaced box sizing (CSS 2.2 §10.3.4/§10.6.2) + ImageFragment ---------------------
// tiny.jpg fixture: 4x3px, aspect ratio height/width = 0.75.

it('emits a BoxFragment wrapping an ImageFragment for the content box', function () {
    $frag = layoutImageHtml('<body><img src="tiny.jpg"></body>', '', 500.0);
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    expect($img->children)->toHaveCount(1);
    expect($img->children[0])->toBeInstanceOf(ImageFragment::class);
    $imageFragment = $img->children[0];
    assert($imageFragment instanceof ImageFragment);
    expect($imageFragment->imageKey)->toBe(BLOCK_FLOW_IMAGE_FIXTURES_DIR . '/tiny.jpg');
});

it('sizes an image using its intrinsic dimensions when nothing else is specified', function () {
    $frag = layoutImageHtml('<body><img src="tiny.jpg"></body>', '', 500.0);
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    $content = $img->children[0];
    assert($content instanceof ImageFragment);
    expect($content->rect->width)->toBe(4.0);
    expect($content->rect->height)->toBe(3.0);
});

it('caps an oversized image to the containing block width, preserving the intrinsic aspect ratio', function () {
    // Containing block narrower (2px) than the 4x3 intrinsic image: neither CSS nor HTML attrs
    // give a size, so both dims fall back to intrinsic — then get capped to fit cbWidth=2,
    // scaling height by the same factor (2/4 = 0.5) to preserve the 0.75 ratio: 3 * 0.5 = 1.5.
    $frag = layoutImageHtml('<body><img src="tiny.jpg"></body>', '', 2.0);
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    $content = $img->children[0];
    assert($content instanceof ImageFragment);
    expect($content->rect->width)->toBe(2.0);
    expect($content->rect->height)->toBe(1.5);
});

it('sizes an image from HTML width/height attributes when no CSS size is declared', function () {
    $frag = layoutImageHtml('<body><img src="tiny.jpg" width="40" height="20"></body>', '', 500.0);
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    $content = $img->children[0];
    assert($content instanceof ImageFragment);
    // Both axes explicitly given (even though 40x20 does not match the 4x3 intrinsic ratio):
    // per CSS 2.2 §10.3.4, when BOTH dimensions are resolved, neither is derived from the ratio.
    expect($content->rect->width)->toBe(40.0);
    expect($content->rect->height)->toBe(20.0);
});

it('derives the missing HTML attribute dimension from the intrinsic aspect ratio', function () {
    $frag = layoutImageHtml('<body><img src="tiny.jpg" width="40"></body>', '', 500.0);
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    $content = $img->children[0];
    assert($content instanceof ImageFragment);
    expect($content->rect->width)->toBe(40.0);
    expect($content->rect->height)->toBe(30.0); // 40 * 0.75
});

it('CSS width takes priority over the HTML width attribute', function () {
    $frag = layoutImageHtml(
        '<body><img src="tiny.jpg" width="40" height="20"></body>',
        'img { width: 100px }',
        500.0,
    );
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    $content = $img->children[0];
    assert($content instanceof ImageFragment);
    // CSS width (100) wins over the attr (40); the attr height (20) still applies since it was
    // never overridden by a CSS height — both axes resolved, so no ratio derivation happens.
    expect($content->rect->width)->toBe(100.0);
    expect($content->rect->height)->toBe(20.0);
});

it('derives height from a CSS width via the intrinsic aspect ratio when no height is given anywhere', function () {
    $frag = layoutImageHtml('<body><img src="tiny.jpg"></body>', 'img { width: 100px }', 500.0);
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    $content = $img->children[0];
    assert($content instanceof ImageFragment);
    expect($content->rect->width)->toBe(100.0);
    expect($content->rect->height)->toBe(75.0); // 100 * 0.75
});

it('resolves CSS width % against the containing block width for images', function () {
    $frag = layoutImageHtml('<body><img src="tiny.jpg"></body>', 'img { width: 50% }', 200.0);
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    $content = $img->children[0];
    assert($content instanceof ImageFragment);
    expect($content->rect->width)->toBe(100.0);
    expect($content->rect->height)->toBe(75.0); // 100 * 0.75, derived (no CSS/attr height)
});

it('a CSS % height is unsupported (rejected by the parser) and falls back to auto, same as undeclared', function () {
    // DeclarationParser only accepts px for height (LENGTH_PROPERTIES, no %); "50%" is rejected
    // with a warning and never reaches ComputedStyle::$height, which stays null (auto) — the
    // brief's adjudication ("% height -> warning + auto") is satisfied at the parser boundary,
    // so sizing here falls back exactly as if height had never been declared at all.
    $frag = layoutImageHtml('<body><img src="tiny.jpg" width="40"></body>', 'img { height: 50% }', 500.0);
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    $content = $img->children[0];
    assert($content instanceof ImageFragment);
    expect($content->rect->width)->toBe(40.0);
    expect($content->rect->height)->toBe(30.0); // derived from width via ratio, height:50% ignored
});

it('applies margin/border/padding to the image using the normal box model', function () {
    $frag = layoutImageHtml(
        '<body><img src="tiny.jpg" width="40" height="30"></body>',
        'img { margin: 5px 0 0 5px; padding: 5px; border: 2px solid #000000 }',
        500.0,
    );
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    // border-box positioned after the margin.
    expect($img->rect->x)->toBe(5.0);
    expect($img->rect->y)->toBe(5.0);
    // border-box size = content (40x30) + 2*padding (10) + 2*border (4) = 54x44.
    expect($img->rect->width)->toBe(54.0);
    expect($img->rect->height)->toBe(44.0);
    expect($img->borders->isVisible())->toBeTrue();

    $content = $img->children[0];
    assert($content instanceof ImageFragment);
    // content box offset from the border-box origin by border+padding (2+5=7) on each side.
    expect($content->rect->x)->toBe(12.0);
    expect($content->rect->y)->toBe(12.0);
    expect($content->rect->width)->toBe(40.0);
    expect($content->rect->height)->toBe(30.0);
});

it('paints the image background on the wrapping BoxFragment', function () {
    $frag = layoutImageHtml(
        '<body><img src="tiny.jpg" width="10" height="10"></body>',
        'img { background-color: #ff0000 }',
        500.0,
    );
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    expect($img->background?->r)->toBe(255);
});

it('advances the cursor for the next sibling using the image margin-bottom, like a normal block', function () {
    $frag = layoutImageHtml(
        '<body><img src="tiny.jpg" width="10" height="20"><p>after</p></body>',
        'img { margin-bottom: 15px }',
        500.0,
    );
    [$img, $p] = $frag->children;
    assert($img instanceof BoxFragment && $p instanceof BoxFragment);
    expect($img->rect->height)->toBe(20.0);
    expect($p->rect->y)->toBe(20.0 + 15.0);
});
