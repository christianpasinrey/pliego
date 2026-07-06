<?php

declare(strict_types=1);

use Pliego\Box\BoxTreeBuilder;
use Pliego\Css\StylesheetParser;
use Pliego\Css\Value\Color;
use Pliego\Css\WarningCollector;
use Pliego\Dom\HtmlParser;
use Pliego\Image\ImageLoader;
use Pliego\Layout\BlockFlowContext;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\Fragment;
use Pliego\Layout\Fragment\ImageFragment;
use Pliego\Layout\Fragment\InlineBoxFragment;
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

/** @return array{0: BoxFragment, 1: list<string>} */
function layoutHtmlCollectingWarnings(string $html, string $css, float $width = 500.0, string $basePath = __DIR__): array
{
    $collector = new WarningCollector();
    $doc = HtmlParser::parse($html);
    $map = new StyleResolver([new CssStyleSource(new StylesheetParser()->parse($css))])->resolve($doc);
    $root = new BoxTreeBuilder(new ImageLoader(), $collector, $basePath)->build($doc, $map);
    $measurer = new TextMeasurer();
    $catalog = FontCatalog::withDefaults();
    $frag = new BlockFlowContext($measurer, $catalog, $collector)->layout($root, new Rect(0.0, 0.0, $width, INF));
    return [$frag, $collector->drain()];
}

/** @return array{0: BoxFragment, 1: list<string>} */
function layoutImageHtmlCollectingWarnings(string $html, string $css, float $width = 500.0): array
{
    return layoutHtmlCollectingWarnings($html, $css, $width, BLOCK_FLOW_IMAGE_FIXTURES_DIR);
}

/** @return list<TextFragment> */
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

/** M7-T4: recorrido recursivo de InlineBoxFragment (caja inline real, ver su docblock) --
 * descendemos a través de BoxFragment (bloques normales, incluido el propio BoxFragment de un
 * inline-block, ver layoutInlineBlockAtomic()) pero NUNCA a través de un InlineBoxFragment (no
 * tiene hijos propios).
 * @return list<InlineBoxFragment> */
function inlineBoxFragments(BoxFragment $box): array
{
    $out = [];
    foreach ($box->children as $child) {
        if ($child instanceof InlineBoxFragment) {
            $out[] = $child;
        } elseif ($child instanceof BoxFragment) {
            $out = [...$out, ...inlineBoxFragments($child)];
        }
    }
    return $out;
}

/** M7-T4: todos los Fragment hoja (Text/InlineBox/Box-anidado), en el ORDEN en que
 * BlockFlowContext los deja en $children -- preserva el orden de pintado real (a diferencia de
 * textFragments()/inlineBoxFragments(), que solo filtran por tipo).
 * @return list<Fragment> */
function allLeaves(BoxFragment $box): array
{
    $out = [];
    foreach ($box->children as $child) {
        $out[] = $child;
        if ($child instanceof BoxFragment) {
            $out = [...$out, ...allLeaves($child)];
        }
    }
    return $out;
}

/** M7-T4: formatea un Color NO-nulo como hex -- solo se llama tras comprobar (con
 * expect(...)->not->toBeNull(), ver los call sites) que el background/borde en cuestión existe;
 * el assert() aquí es la narrowing habitual de este fichero de test (ver los `assert($x
 * instanceof Y)` ya presentes en el resto de la suite), nunca alcanzado de otra forma. */
function hexColor(?Color $color): string
{
    assert($color instanceof Color);
    return sprintf('#%02x%02x%02x', $color->r, $color->g, $color->b);
}

/** M7-T4: BoxFragment hijo cuyo background coincide con $hex -- usado para localizar la caja
 * PROPIA de un elemento display:inline-block dentro del árbol de fragments (distinguible de
 * cualquier BoxFragment ancestro por su color de fondo propio). */
function findBoxByBackground(BoxFragment $box, string $hex): ?BoxFragment
{
    foreach ($box->children as $child) {
        if (!$child instanceof BoxFragment) {
            continue;
        }
        if ($child->background !== null && sprintf('#%02x%02x%02x', $child->background->r, $child->background->g, $child->background->b) === $hex) {
            return $child;
        }
        $found = findBoxByBackground($child, $hex);
        if ($found !== null) {
            return $found;
        }
    }
    return null;
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
    // M7-T2: margin-top:0 anula el default UA de <p> (margin: 1em 0, ver UserAgentStylesheet) —
    // esta prueba verifica exclusivamente la exclusión del margin-BOTTOM final, no la aritmética
    // de la hoja UA (cubierta aparte en FragmentDumperGoldenTest/StyleResolverTest).
    $frag = layoutHtml('<body><div class="box"><p>x</p></div></body>', '.box { padding: 10px } p { margin-top: 0; margin-bottom: 10px }');
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

// --- M6-T4: calc(100% - 20px) resolved end to end through real layout, not just LengthPercentage::resolve() in isolation ---

it('resolves calc(100% - 20px) against the containing block width (400 -> 380) through the real layout pipeline', function () {
    $frag = layoutHtml('<body><div>x</div></body>', 'div { width: calc(100% - 20px) }', 400.0);
    $div = $frag->children[0];
    assert($div instanceof BoxFragment);
    expect($div->rect->width)->toBe(380.0);
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

// --- M7-T5 (CSS 2.2 §10.4 table, simplified): min/max-width on a replaced element (<img>) -------

it('min-width beats a smaller declared width on an image, rederiving height by the intrinsic ratio (height was auto)', function () {
    // tiny.jpg is 4x3 intrinsic (ratio height/width = 0.75) -- width clamped 40 -> 100 (min-width
    // wins), height was never declared (auto) so it re-derives from the CLAMPED width: 100*0.75=75.
    $frag = layoutImageHtml('<body><img src="tiny.jpg"></body>', 'img { width: 40px; min-width: 100px }', 500.0);
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    $content = $img->children[0];
    assert($content instanceof ImageFragment);
    expect($content->rect->width)->toBe(100.0);
    expect($content->rect->height)->toBe(75.0);
});

it('max-width clamps a larger declared width on an image, rederiving height by the intrinsic ratio', function () {
    $frag = layoutImageHtml('<body><img src="tiny.jpg"></body>', 'img { width: 500px; max-width: 100px }', 500.0);
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    $content = $img->children[0];
    assert($content instanceof ImageFragment);
    expect($content->rect->width)->toBe(100.0);
    expect($content->rect->height)->toBe(75.0);
});

it('does NOT rederive height when height was explicitly declared (only auto height re-derives, per the simplified table)', function () {
    $frag = layoutImageHtml(
        '<body><img src="tiny.jpg"></body>',
        'img { width: 40px; height: 50px; min-width: 100px }',
        500.0,
    );
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    $content = $img->children[0];
    assert($content instanceof ImageFragment);
    expect($content->rect->width)->toBe(100.0); // clamped
    expect($content->rect->height)->toBe(50.0); // untouched: height was explicit, not auto
});

// --- M7 final-review Finding E: min/max-height on a replaced element now warns (behavior unchanged) -

it('Finding E: min-height on an <img> warns once and still has no effect on the resolved height', function () {
    [$frag, $warnings] = layoutImageHtmlCollectingWarnings(
        '<body><img src="tiny.jpg" width="40" height="30"></body>',
        'img { min-height: 500px }',
    );
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    $content = $img->children[0];
    assert($content instanceof ImageFragment);
    // No behavioral change: min-height is still ignored, the declared height (30) wins as before.
    expect($content->rect->height)->toBe(30.0);
    $relevant = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'min/max-height on replaced elements not supported yet')));
    expect($relevant)->toHaveCount(1);
});

it('Finding E: max-height on an <img> warns once too, no effect on the resolved height', function () {
    [$frag, $warnings] = layoutImageHtmlCollectingWarnings(
        '<body><img src="tiny.jpg" width="40" height="30"></body>',
        'img { max-height: 5px }',
    );
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    $content = $img->children[0];
    assert($content instanceof ImageFragment);
    expect($content->rect->height)->toBe(30.0);
    $relevant = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'min/max-height on replaced elements not supported yet')));
    expect($relevant)->toHaveCount(1);
});

it('Finding E: min/max-height together on an <img> only warn ONCE (addWarningOnce dedup)', function () {
    [, $warnings] = layoutImageHtmlCollectingWarnings(
        '<body><img src="tiny.jpg" width="40" height="30"></body>',
        'img { min-height: 5px; max-height: 500px }',
    );
    $relevant = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'min/max-height on replaced elements not supported yet')));
    expect($relevant)->toHaveCount(1);
});

it('Finding E: an <img> with no min/max-height at all emits no such warning (no false positive)', function () {
    [, $warnings] = layoutImageHtmlCollectingWarnings(
        '<body><img src="tiny.jpg" width="40" height="30"></body>',
        'img { min-width: 10px; max-width: 200px }',
    );
    $relevant = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'min/max-height on replaced elements')));
    expect($relevant)->toBeEmpty();
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

// --- M3-T3 fix: box-sizing: border-box on replaced elements (<img>) --------------------------
// Bug: resolveReplacedSize() ignored box-sizing entirely, treating declared CSS width/height as
// content-box always. Per CSS 2.2 §8.3 + css-sizing-3, box-sizing reinterprets ONLY the declared
// CSS width/height — HTML attrs and intrinsic dims are always content-box measures — so the
// padding+border subtraction happens BEFORE the ratio derivation, on the declared value only.

it('box-sizing: border-box subtracts padding+border from BOTH declared CSS dimensions on an image', function () {
    // width:50 height:40, padding:5 (both sides=10), border:2 solid (both sides=4) -> per axis
    // subtract 14: content 50-14=36, 40-14=26. Border-box stays exactly the declared 50x40.
    $frag = layoutImageHtml(
        '<body><img src="tiny.jpg"></body>',
        'img { width: 50px; height: 40px; padding: 5px; border: 2px solid #000; box-sizing: border-box }',
        500.0,
    );
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    expect($img->rect->width)->toBe(50.0);
    expect($img->rect->height)->toBe(40.0);
    $content = $img->children[0];
    assert($content instanceof ImageFragment);
    expect($content->rect->width)->toBe(36.0);
    expect($content->rect->height)->toBe(26.0);
});

it('box-sizing: border-box with only width declared derives height from the RATIO OF THE CONTENT box', function () {
    // Only width:50 declared; height is undeclared (auto) so it must be derived from the
    // intrinsic aspect ratio (tiny.jpg: 0.75) applied to the CONTENT width (36, i.e. AFTER
    // subtracting padding+border), not to the declared 50 — the ratio is a content-box relation
    // (css-images-3 §4: the "used value" the ratio produces is a content-box dimension).
    $frag = layoutImageHtml(
        '<body><img src="tiny.jpg"></body>',
        'img { width: 50px; padding: 5px; border: 2px solid #000; box-sizing: border-box }',
        500.0,
    );
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    $content = $img->children[0];
    assert($content instanceof ImageFragment);
    expect($content->rect->width)->toBe(36.0);
    expect($content->rect->height)->toBe(27.0); // 36 * 0.75
    expect($img->rect->width)->toBe(50.0);
    expect($img->rect->height)->toBe(41.0); // 27 + 14 (padding+border)
});

it('content-box (default) on an image is unaffected by the border-box fix (regression)', function () {
    $frag = layoutImageHtml(
        '<body><img src="tiny.jpg"></body>',
        'img { width: 50px; height: 40px; padding: 5px; border: 2px solid #000 }',
        500.0,
    );
    $img = $frag->children[0];
    assert($img instanceof BoxFragment);
    $content = $img->children[0];
    assert($content instanceof ImageFragment);
    expect($content->rect->width)->toBe(50.0);
    expect($content->rect->height)->toBe(40.0);
    // border-box = declared content (50x40) + padding (10) + border (4) = 64x54.
    expect($img->rect->width)->toBe(64.0);
    expect($img->rect->height)->toBe(54.0);
});

it('advances the cursor for the next sibling using the image margin-bottom, like a normal block', function () {
    // M7-T2: p { margin-top: 0 } anula el default UA de <p> (margin: 1em 0) -- esta prueba
    // verifica el avance de cursor por el margin-bottom de la IMAGEN, no la hoja UA.
    $frag = layoutImageHtml(
        '<body><img src="tiny.jpg" width="10" height="20"><p>after</p></body>',
        'img { margin-bottom: 15px } p { margin-top: 0 }',
        500.0,
    );
    [$img, $p] = $frag->children;
    assert($img instanceof BoxFragment && $p instanceof BoxFragment);
    expect($img->rect->height)->toBe(20.0);
    expect($p->rect->y)->toBe(20.0 + 15.0);
});

// M4-T2: BoxTreeBuilder envuelve el texto de un contenedor flex en un BlockBox anónimo
// (tag "anonymous", estilo heredado del contenedor). En T2, BlockFlowContext todavía no
// distinguía Display::Flex y TODO el subárbol (contenedor + anónimo) fluía como bloques
// normales (de ahí que el test original comparara el CONTENEDOR flex completo contra un <div>
// normal y salieran idénticos: ambos width:auto llenando el body). M4-T4 conecta
// FlexFormattingContext de verdad, pero solo cambia el sizing del ITEM (el anónimo) — el
// CONTENEDOR flex en sí sigue siendo un hijo de bloque normal del body (width:auto llena los
// 500px igual que antes, ver BlockFlowContext::layout(), que solo mira el display de sus HIJOS,
// nunca el propio). Lo que SÍ cambia es el anónimo DENTRO: sin flex-grow (default 0) ya no se
// estira al content width del contenedor — se encoge a su base (auto → max-content del texto,
// css-flexbox-1 §9.2), a diferencia de como fluía en T2 (como bloque normal, width:auto llenando
// el contenedor). El TextFragment en sí sigue siendo idéntico en ambos casos (flex vs. plano):
// ninguno de los anchos disponibles (500px en bloque, max-content en flex) fuerza un salto de
// línea para una frase tan corta.
it('the anonymous flex item shrinks to its text\'s max-content; the container and the text itself are unaffected (M4-T2 A/B, updated for M4-T4 sizing)', function () {
    $flexFrag = layoutHtml('<body><div class="flex">hola mundo</div></body>', '.flex { display: flex }');
    $plainFrag = layoutHtml('<body><div>hola mundo</div></body>', '');

    $flexText = textFragments($flexFrag)[0];
    $plainText = textFragments($plainFrag)[0];

    expect($flexText->rect->x)->toBe($plainText->rect->x);
    expect($flexText->rect->y)->toBe($plainText->rect->y);
    expect($flexText->rect->width)->toBe($plainText->rect->width);
    expect($flexText->rect->height)->toBe($plainText->rect->height);

    // El CONTENEDOR flex (hijo directo del body) sigue siendo un bloque normal width:auto: llena
    // el content width del body exactamente igual que el <div> plano — sin cambios de T2.
    $flexContainer = $flexFrag->children[0];
    $plainDiv = $plainFrag->children[0];
    assert($flexContainer instanceof BoxFragment && $plainDiv instanceof BoxFragment);
    expect($flexContainer->rect)->toEqual($plainDiv->rect);
    expect($plainDiv->rect->width)->toBe(500.0);

    // El ITEM anónimo (hijo del contenedor flex) es donde M4-T4 cambia el comportamiento: se
    // encoge exactamente al max-content del texto en vez de llenar los 500px del contenedor.
    $anonymousItem = $flexContainer->children[0];
    assert($anonymousItem instanceof BoxFragment);
    expect($anonymousItem->rect->width)->toBeLessThan($plainDiv->rect->width);
    expect($anonymousItem->rect->width)->toBe($flexText->rect->width); // shrink-to-fit exacto
    expect($anonymousItem->rect->x)->toBe($plainDiv->rect->x); // arranca en el mismo borde izq.
});

// M4-T4: prueba de WIRING end-to-end (CSS real -> BoxTreeBuilder -> BlockFlowContext ->
// delegación perezosa a FlexFormattingContext), complementaria a los tests de algoritmo puro de
// FlexFormattingContextTest.php (que construyen el árbol a mano). `flex: 2`/`flex: 1` sobre dos
// hijos de un contenedor `display:flex; width:300px` reparte el ancho 200/100 exactamente.
it('BlockFlowContext delegates a display:flex child to FlexFormattingContext end-to-end', function () {
    $frag = layoutHtml(
        '<body><div class="flex"><div class="a"></div><div class="b"></div></div></body>',
        '.flex { display: flex; width: 300px } .a { flex: 2 } .b { flex: 1 }',
    );
    $flexDiv = $frag->children[0];
    assert($flexDiv instanceof BoxFragment);
    [$a, $b] = $flexDiv->children;
    assert($a instanceof BoxFragment && $b instanceof BoxFragment);

    expect($a->rect->width)->toBe(200.0);
    expect($b->rect->width)->toBe(100.0);
    expect($b->rect->x)->toBe($a->rect->right());
});

// M5-T4: TableBox ya tiene layout real (TableFormattingContext) — BlockFlowContext la delega
// ENTERA (reemplaza el skip de T3, ver su docblock) y el cursor avanza tras ella, así que el
// hermano siguiente ya no se solapa con donde la tabla cayó.
it('delegates a TableBox child to TableFormattingContext end-to-end, advancing the cursor past it', function () {
    // M7-T2: p { margin-top: 0 } anula el default UA de <p> -- esta prueba verifica que el
    // cursor avanza exactamente hasta el borde inferior de la TABLA, no la hoja UA.
    $frag = layoutHtml('<body><table><tr><td>a</td></tr></table><p>after</p></body>', 'p { margin-top: 0 }');
    expect($frag->children)->toHaveCount(2);
    [$table, $after] = $frag->children;
    assert($table instanceof BoxFragment && $after instanceof BoxFragment);

    $text = textFragments($after)[0];
    expect($text->text)->toBe('after');
    // No overlap: the paragraph starts exactly where the table's border-box ends.
    expect($after->rect->y)->toBe($table->rect->bottom());
});

// --- M7-T3: list-item markers (css-lists-3 §3, reducido) ---------------------------------------
// Convención de estos tests: listMarkerFragment() SIEMPRE añade el marcador como ÚLTIMO hijo del
// BoxFragment del <li> (después de todo su contenido normal, ver BlockFlowContext::layout()) --
// `$li->children[count($li->children) - 1]` es, por tanto, siempre el marcador cuando existe.

function markerOf(BoxFragment $li): TextFragment
{
    $marker = $li->children[count($li->children) - 1];
    assert($marker instanceof TextFragment);
    return $marker;
}

it('emits a disc bullet (U+2022) for a plain <ul><li>', function () {
    $frag = layoutHtml('<body><ul><li>a</li></ul></body>', '');
    $ul = $frag->children[0];
    assert($ul instanceof BoxFragment);
    $li = $ul->children[0];
    assert($li instanceof BoxFragment);
    expect(markerOf($li)->text)->toBe("\u{2022}");
});

it('emits a decimal marker ("1.") for a plain <ol><li>', function () {
    $frag = layoutHtml('<body><ol><li>a</li></ol></body>', '');
    $ol = $frag->children[0];
    assert($ol instanceof BoxFragment);
    $li = $ol->children[0];
    assert($li instanceof BoxFragment);
    expect(markerOf($li)->text)->toBe('1.');
});

it('cycles disc -> circle -> square per ul nesting level via the UA stylesheet combinators', function () {
    $frag = layoutHtml('<body><ul><li>a<ul><li>b<ul><li>c</li></ul></li></ul></li></ul></body>', '');
    $ul1 = $frag->children[0];
    assert($ul1 instanceof BoxFragment);
    $li1 = $ul1->children[0];
    assert($li1 instanceof BoxFragment);
    expect(markerOf($li1)->text)->toBe("\u{2022}"); // disc, depth 1

    $ul2 = $li1->children[1];
    assert($ul2 instanceof BoxFragment);
    $li2 = $ul2->children[0];
    assert($li2 instanceof BoxFragment);
    expect(markerOf($li2)->text)->toBe("\u{25E6}"); // circle, depth 2

    $ul3 = $li2->children[1];
    assert($ul3 instanceof BoxFragment);
    $li3 = $ul3->children[0];
    assert($li3 instanceof BoxFragment);
    expect(markerOf($li3)->text)->toBe("\u{25AA}"); // square, depth 3
});

it('numbers ol siblings sequentially and right-aligns the markers despite differing digit widths', function () {
    $items = str_repeat('<li>x</li>', 10); // 10 <li> -> markers "1." .. "10."
    $frag = layoutHtml("<body><ol>$items</ol></body>", '');
    $ol = $frag->children[0];
    assert($ol instanceof BoxFragment);
    $first = $ol->children[0];
    $last = $ol->children[9];
    assert($first instanceof BoxFragment && $last instanceof BoxFragment);

    $firstMarker = markerOf($first);
    $lastMarker = markerOf($last);
    expect($firstMarker->text)->toBe('1.');
    expect($lastMarker->text)->toBe('10.');
    // "10." is wider than "1." -- right-aligned means the RIGHT edge stays put (same gap from the
    // li's content box) while the LEFT edge moves further left for the wider marker.
    expect($lastMarker->rect->width)->toBeGreaterThan($firstMarker->rect->width);
    expect($lastMarker->rect->right())->toEqualWithDelta($firstMarker->rect->right(), 0.001);
    expect($lastMarker->rect->x)->toBeLessThan($firstMarker->rect->x);
});

it('honours <ol start="5">, numbering the first <li> "5."', function () {
    $frag = layoutHtml('<body><ol start="5"><li>a</li><li>b</li></ol></body>', '');
    $ol = $frag->children[0];
    assert($ol instanceof BoxFragment);
    [$li1, $li2] = $ol->children;
    assert($li1 instanceof BoxFragment && $li2 instanceof BoxFragment);
    expect(markerOf($li1)->text)->toBe('5.');
    expect(markerOf($li2)->text)->toBe('6.');
});

it('emits no marker fragment at all for list-style-type: none', function () {
    $frag = layoutHtml('<body><ul><li>x</li></ul></body>', 'ul { list-style-type: none }');
    $ul = $frag->children[0];
    assert($ul instanceof BoxFragment);
    $li = $ul->children[0];
    assert($li instanceof BoxFragment);
    // Solo el TextFragment del contenido "x" -- ningún marcador extra al final.
    expect($li->children)->toHaveCount(1);
    expect($li->children[0])->toBeInstanceOf(TextFragment::class);
});

it('shares the baseline of only the FIRST line when the <li> wraps onto multiple lines', function () {
    $frag = layoutHtml(
        '<body><ul><li>uno dos tres cuatro cinco seis siete ocho nueve diez</li></ul></body>',
        '',
        80.0,
    );
    $ul = $frag->children[0];
    assert($ul instanceof BoxFragment);
    $li = $ul->children[0];
    assert($li instanceof BoxFragment);
    $lines = textFragments($li);
    expect(count($lines))->toBeGreaterThan(1); // confirms the wrap actually happened
    $marker = markerOf($li);
    expect($marker->baselineY)->toBe($lines[0]->baselineY);
    expect($marker->baselineY)->not->toBe($lines[1]->baselineY);
});

it('restarts the decimal counter for a nested <ol>, independent of the outer <ul> position', function () {
    $frag = layoutHtml('<body><ul><li>a<ol><li>x</li><li>y</li></ol></li><li>b</li></ul></body>', '');
    $ul = $frag->children[0];
    assert($ul instanceof BoxFragment);
    [$li1, $li2] = $ul->children;
    assert($li1 instanceof BoxFragment && $li2 instanceof BoxFragment);

    $innerOl = $li1->children[1];
    assert($innerOl instanceof BoxFragment);
    [$innerLi1, $innerLi2] = $innerOl->children;
    assert($innerLi1 instanceof BoxFragment && $innerLi2 instanceof BoxFragment);

    expect(markerOf($innerLi1)->text)->toBe('1.'); // restarts at 1, not "2" (after outer li "a")
    expect(markerOf($innerLi2)->text)->toBe('2.');
    expect(markerOf($li1)->text)->toBe("\u{2022}"); // outer <ul> stays disc, unaffected by nesting
    expect(markerOf($li2)->text)->toBe("\u{2022}");
});

it('aligns an empty <li> marker to its content-box top + ascent (documented fallback, no text present)', function () {
    $frag = layoutHtml('<body><ul><li></li></ul></body>', '');
    $ul = $frag->children[0];
    assert($ul instanceof BoxFragment);
    $li = $ul->children[0];
    assert($li instanceof BoxFragment);
    // No content at all: the marker is the ONLY child.
    expect($li->children)->toHaveCount(1);
    $marker = markerOf($li);
    expect($marker->baselineY)->toBeGreaterThan($li->rect->y);
});

// --- M7-T4: real inline boxes (css-inline-3 reducido) + inline-block ---------------------------

it('M7-T4 fast path: an unstyled inline element emits no InlineBoxFragment (byte-identical to pre-M7-T4)', function () {
    $frag = layoutHtml('<body><p>Hola <span>mundo</span></p></body>', '');
    $p = $frag->children[0];
    assert($p instanceof BoxFragment);

    expect(inlineBoxFragments($p))->toBe([]);
    $lines = textFragments($p);
    expect($lines)->toHaveCount(2);
    expect($lines[0]->text)->toBe('Hola ');
    expect($lines[1]->text)->toBe('mundo');
    // Fragments sit flush against each other -- exactly the pre-M7-T4 geometry (M1-T6 test).
    expect($lines[1]->rect->x)->toBe($lines[0]->rect->x + $lines[0]->rect->width);
});

it('M7-T4: a styled inline span paints an InlineBoxFragment sized to its content + horizontal padding (hand-computed advance)', function () {
    $frag = layoutHtml(
        '<body><p>before <span class="tag">mid</span> after</p></body>',
        '.tag { background-color: #cccccc; padding: 0 5px; }',
    );
    $p = $frag->children[0];
    assert($p instanceof BoxFragment);

    $measurer = new TextMeasurer();
    $catalog = FontCatalog::withDefaults();
    $face = $catalog->select('default', 400, false);
    // Convención de whitespace-collapsing PRE-EXISTENTE (M1-T4, ver BoxTreeBuilder::collapse()):
    // el espacio de frontera entre "mid" y "after" se adjunta SIEMPRE al run YA EMITIDO
    // precedente ("mid", dentro de la caja) -- no es un efecto nuevo de esta tarea, solo se hace
    // VISIBLE ahora porque ese run vive dentro de una caja pintable con padding.
    $midWidth = $measurer->widthOf('mid ', $face, 16.0);

    $boxes = inlineBoxFragments($p);
    expect($boxes)->toHaveCount(1);
    $box = $boxes[0];
    expect($box->rect->width)->toEqualWithDelta(5.0 + $midWidth + 5.0, 0.001);
    expect($box->isFirstSlice)->toBeTrue();
    expect($box->isLastSlice)->toBeTrue();
    expect($box->background)->not->toBeNull();
    expect(hexColor($box->background))->toBe('#cccccc');

    $lines = textFragments($p);
    [$before, $mid, $after] = $lines;
    expect($before->text)->toBe('before ');
    expect($mid->text)->toBe('mid ');
    expect($after->text)->toBe('after');
    // The box starts right where the preceding text ends, padding-left sits INSIDE it.
    expect($box->rect->x)->toEqualWithDelta($before->rect->right(), 0.001);
    expect($mid->rect->x)->toEqualWithDelta($box->rect->x + 5.0, 0.001);
    // The following text starts right after the box's right edge (padding-right consumed).
    expect($after->rect->x)->toEqualWithDelta($box->rect->right(), 0.001);
});

it('M7-T4: slices a bordered inline span across two wrapped lines (lateral border only on the extreme slices)', function () {
    $measurer = new TextMeasurer();
    $catalog = FontCatalog::withDefaults();
    $face = $catalog->select('default', 400, false);
    $aaaSpaceWidth = $measurer->widthOf('aaa ', $face, 16.0);
    $bbbWidth = $measurer->widthOf('bbb', $face, 16.0);
    // Cabe "aaa" pero NO "aaa bbb" -- fuerza el wrap entre las dos palabras.
    $availableWidth = $aaaSpaceWidth + $bbbWidth * 0.5;

    $frag = layoutHtml(
        '<body><p><span class="tag">aaa bbb</span></p></body>',
        '.tag { border: 2px solid #000000; }',
        $availableWidth,
    );
    $p = $frag->children[0];
    assert($p instanceof BoxFragment);

    $lines = textFragments($p);
    expect($lines)->toHaveCount(2);
    // Convención pre-existente (ver el test anterior): el espacio de frontera cuelga del final del
    // run YA EMITIDO ("aaa"), sin retirarse del texto -- solo su contribución al ANCHO reportado
    // de la línea se descuenta (ver InlineFlowContext::closeLine()).
    expect($lines[0]->text)->toBe('aaa ');
    expect($lines[1]->text)->toBe('bbb');
    expect($lines[1]->rect->y)->toBeGreaterThan($lines[0]->rect->y);

    $boxes = inlineBoxFragments($p);
    expect($boxes)->toHaveCount(2);
    [$firstSlice, $lastSlice] = $boxes;

    expect($firstSlice->isFirstSlice)->toBeTrue();
    expect($firstSlice->isLastSlice)->toBeFalse();
    expect($firstSlice->borders->left->widthPx)->toBeGreaterThan(0.0);
    expect($firstSlice->borders->right->widthPx)->toBe(0.0);
    expect($firstSlice->borders->top->widthPx)->toBeGreaterThan(0.0);
    expect($firstSlice->borders->bottom->widthPx)->toBeGreaterThan(0.0);

    expect($lastSlice->isFirstSlice)->toBeFalse();
    expect($lastSlice->isLastSlice)->toBeTrue();
    expect($lastSlice->borders->left->widthPx)->toBe(0.0);
    expect($lastSlice->borders->right->widthPx)->toBeGreaterThan(0.0);
    expect($lastSlice->borders->top->widthPx)->toBeGreaterThan(0.0);
    expect($lastSlice->borders->bottom->widthPx)->toBeGreaterThan(0.0);
});

it('M7-T4: nested inline boxes (span > strong > em, distinct backgrounds) each get their own InlineBoxFragment, painted outer-before-inner', function () {
    $frag = layoutHtml(
        '<body><p><span class="a"><strong class="b"><em class="c">x</em></strong></span></p></body>',
        '.a { background-color: #ff0000; } .b { background-color: #00ff00; } .c { background-color: #0000ff; }',
    );
    $p = $frag->children[0];
    assert($p instanceof BoxFragment);

    // 3 InlineBoxFragment (span/strong/em) + 1 TextFragment ("x") -- el orden real de pintado
    // (allLeaves, no filtrado) confirma que las 3 cajas van ANTES que el texto, exterior primero.
    $leaves = allLeaves($p);
    expect($leaves)->toHaveCount(4);
    [$outer, $middle, $inner, $text] = $leaves;
    assert($outer instanceof InlineBoxFragment && $middle instanceof InlineBoxFragment && $inner instanceof InlineBoxFragment);
    assert($text instanceof TextFragment);

    expect(hexColor($outer->background))->toBe('#ff0000');
    expect(hexColor($middle->background))->toBe('#00ff00');
    expect(hexColor($inner->background))->toBe('#0000ff');
    expect($text->text)->toBe('x');
    // Las 3 cajas comparten exactamente la misma geometría horizontal (mismo único hijo de texto,
    // sin padding declarado en ninguna) -- lo que las distingue es el color, no el rect.
    expect($middle->rect->x)->toBe($outer->rect->x);
    expect($inner->rect->x)->toBe($outer->rect->x);
});

it('M7-T4: a Bootstrap-.btn-like inline-block (padding+bg+border, shrink-to-fit width) paints inline with surrounding text', function () {
    $frag = layoutHtml(
        '<body><p>Text <a class="btn">Click</a> more</p></body>',
        '.btn { display: inline-block; padding: 6px 12px; background-color: #007bff; border: 1px solid #0056b3; }',
    );
    $p = $frag->children[0];
    assert($p instanceof BoxFragment);

    $measurer = new TextMeasurer();
    $catalog = FontCatalog::withDefaults();
    $face = $catalog->select('default', 400, false);
    $clickWidth = $measurer->widthOf('Click', $face, 16.0);
    $expectedBorderBoxWidth = $clickWidth + 12.0 * 2 + 1.0 * 2;

    $btn = findBoxByBackground($p, '#007bff');
    expect($btn)->not->toBeNull();
    assert($btn instanceof BoxFragment);
    expect($btn->rect->width)->toEqualWithDelta($expectedBorderBoxWidth, 0.5);

    // baseline = bottom MARGIN edge (M7 approximation, ver InlineFlowContext docblock de clase) --
    // sin margin propio declarado, el borde inferior del btn coincide EXACTAMENTE con la baseline
    // del texto que lo rodea (misma línea). textFragments() recorre TODO el subárbol, incluido el
    // texto PROPIO del btn ("Click", en su propia línea interna, con su propia baseline) -- se
    // filtra a los dos tramos EXTERIORES ("Text "/"more") para comparar baselines de la MISMA línea.
    $lines = textFragments($p);
    $outerTexts = array_values(array_filter($lines, static fn($l) => $l->text === 'Text ' || $l->text === 'more'));
    expect($outerTexts)->toHaveCount(2);
    $surroundingBaseline = $outerTexts[0]->baselineY;
    foreach ($outerTexts as $line) {
        expect($line->baselineY)->toEqualWithDelta($surroundingBaseline, 0.001);
    }
    expect($btn->rect->bottom())->toEqualWithDelta($surroundingBaseline, 0.5);

    // El botón se sitúa a la derecha del primer tramo de texto ("Text ") y antes del último
    // (" more" / "more").
    expect($btn->rect->x)->toBeGreaterThanOrEqual($outerTexts[0]->rect->right());
});

it('M7-T4: an inline-block taller than the surrounding text grows the line height (strut + item model)', function () {
    $frag = layoutHtml(
        '<body><p>Row one <a class="btn">Tall</a><br>Row two</p></body>',
        '.btn { display: inline-block; padding: 40px 12px; background-color: #000000; }',
        800.0,
    );
    $p = $frag->children[0];
    assert($p instanceof BoxFragment);

    // "Row one" pierde el espacio de frontera que le seguía (convención pre-existente: el btn
    // atómico y el <br> inmediatamente después nunca llegan a consumirlo -- ver el test de la
    // caja con bordes más arriba para el mismo mecanismo).
    $lines = textFragments($p);
    $rowOne = null;
    $rowTwo = null;
    foreach ($lines as $line) {
        if ($line->text === 'Row one') {
            $rowOne = $line;
        }
        if ($line->text === 'Row two') {
            $rowTwo = $line;
        }
    }
    expect($rowOne)->not->toBeNull();
    expect($rowTwo)->not->toBeNull();
    assert($rowOne instanceof TextFragment && $rowTwo instanceof TextFragment);

    // El btn mide (línea de "Tall" ~19.2px + 40px arriba + 40px abajo) ~= 99px de margin-box --
    // muy por encima del lineHeight normal (~19.2px) -- la línea entera debe crecer para
    // contenerlo, empujando "Row two" mucho más abajo que un simple avance de línea normal.
    $btn = findBoxByBackground($p, '#000000');
    expect($btn)->not->toBeNull();
    assert($btn instanceof BoxFragment);
    expect($btn->rect->height)->toBeGreaterThan(80.0);
    expect($rowTwo->rect->y - $rowOne->rect->y)->toBeGreaterThanOrEqual($btn->rect->height - 0.5);
});

// --- M7-T4 code review Finding 1: inline-block declared width must honor box-sizing -----------
// Bug: layoutInlineBlockAtomic() passed the declared width straight through as $usedWidthOverride,
// which BlockFlowContext::layout() ALWAYS treats as the BORDER-BOX width (ver su docblock M4-T5).
// CSS default box-sizing:content-box means a declared `width` is the CONTENT width, not the
// border-box width -- reviewer repro: .btn{width:100px;padding:6px 20px;border:1px} rendered as
// a 100px border-box (spec: 142px, since 100 + 20*2 padding + 1*2 border = 142).

it('M7-T4 fix: inline-block declared width (box-sizing:content-box, the CSS default) converts to the correct border-box override', function () {
    $frag = layoutHtml(
        '<body><p>x <a class="btn">Y</a></p></body>',
        '.btn { display: inline-block; width: 100px; padding: 6px 20px; border: 1px solid #000000; background-color: #ff00ff; }',
    );
    $p = $frag->children[0];
    assert($p instanceof BoxFragment);

    $btn = findBoxByBackground($p, '#ff00ff');
    expect($btn)->not->toBeNull();
    assert($btn instanceof BoxFragment);
    // content-box (default): declared width IS the content width -- border-box override passed to
    // BlockFlowContext must be 100 (content) + 20*2 (padding) + 1*2 (border) = 142.
    expect($btn->rect->width)->toBe(142.0);
});

it('M7-T4 fix: inline-block declared width with box-sizing:border-box passes through unchanged (declared width IS the border-box width)', function () {
    $frag = layoutHtml(
        '<body><p>x <a class="btn">Y</a></p></body>',
        '.btn { display: inline-block; width: 100px; padding: 6px 20px; border: 1px solid #000000; box-sizing: border-box; background-color: #ff00ff; }',
    );
    $p = $frag->children[0];
    assert($p instanceof BoxFragment);

    $btn = findBoxByBackground($p, '#ff00ff');
    expect($btn)->not->toBeNull();
    assert($btn instanceof BoxFragment);
    expect($btn->rect->width)->toBe(100.0);
});

it('M7-T4 fix: inline-block declared % width (content-box) is resolved against availableWidth THEN converted to border-box (same root cause as the px case)', function () {
    $frag = layoutHtml(
        '<body><p><a class="btn">Y</a></p></body>',
        '.btn { display: inline-block; width: 50%; padding: 10px; background-color: #ff00ff; }',
        200.0,
    );
    $p = $frag->children[0];
    assert($p instanceof BoxFragment);

    $btn = findBoxByBackground($p, '#ff00ff');
    expect($btn)->not->toBeNull();
    assert($btn instanceof BoxFragment);
    // 50% of the <p> content width (200, no margins/padding of its own in this fixture) = 100
    // content width + 10*2 padding (no border declared) = 120 border-box.
    expect($btn->rect->width)->toBe(120.0);
});

// --- M7-T4 code review Finding 2: an empty inline box with a visible box must still PAINT ------
// Bug: closeLine()'s `hasContent` gate silently dropped an InlineBoxFragment for a completely
// empty inline element (<span class="tag"></span>, no text/children between open and close) even
// when it declares a visible background/padding/border -- adjudicated: CSS 2.2 says an empty
// inline box still generates its own box (width = horizontal paddings, minimal line participation).
// InlineBoxStart/InlineBoxEnd only ever reach InlineFlowContext for a box BoxTreeBuilder already
// confirmed visible (ver BoxTreeBuilder::hasVisibleInlineBox()), so the extra hasContent check was
// both redundant AND the bug: it discarded exactly the "no content" case that must still paint.

it('M7-T4 fix: an empty inline span with a visible box (bg+padding) still emits ONE InlineBoxFragment (CSS: an empty inline box still generates a box)', function () {
    $frag = layoutHtml(
        '<body><p>before<span class="tag"></span>after</p></body>',
        '.tag { background-color: #cccccc; padding: 0 5px; }',
    );
    $p = $frag->children[0];
    assert($p instanceof BoxFragment);

    $boxes = inlineBoxFragments($p);
    expect($boxes)->toHaveCount(1);
    $box = $boxes[0];
    // Width = padding-left + padding-right only (no content, no border declared here) -- exactly
    // what a "populated" box's rect already computes naturally with zero-width content, ver
    // InlineFlowContext::closeLine().
    expect($box->rect->width)->toEqualWithDelta(10.0, 0.001);
    expect($box->isFirstSlice)->toBeTrue();
    expect($box->isLastSlice)->toBeTrue();
    expect($box->background)->not->toBeNull();
    expect(hexColor($box->background))->toBe('#cccccc');

    // The empty box still occupies real horizontal advance (its padding) -- "after" starts right
    // where the box's right edge sits, immediately following "before" via the box's left edge.
    $lines = textFragments($p);
    [$before, $after] = $lines;
    expect($before->text)->toBe('before');
    expect($after->text)->toBe('after');
    expect($box->rect->x)->toEqualWithDelta($before->rect->right(), 0.001);
    expect($after->rect->x)->toEqualWithDelta($box->rect->right(), 0.001);
});

it('M7-T4 fix: an empty inline span WITHOUT any visible box stays on the fast path (byte-stable, nothing emitted)', function () {
    $frag = layoutHtml(
        '<body><p>before<span></span>after</p></body>',
        '',
    );
    $p = $frag->children[0];
    assert($p instanceof BoxFragment);

    expect(inlineBoxFragments($p))->toBe([]);
    $lines = textFragments($p);
    // Pre-existing collapse() behavior (unrelated to this fix, see BoxTreeBuilder::collapse()):
    // an invisible span contributes NEITHER InlineBoxStart NOR InlineBoxEnd to the token sequence
    // (fast path), so nothing separates "before" and "after" -- they merge into a single TextRun,
    // exactly as if the empty <span></span> were not there at all (byte-stable, no box fragment).
    expect($lines)->toHaveCount(1);
    expect($lines[0]->text)->toBe('beforeafter');
});

it('M7-T4 fix: an empty inline box ALONE on a line (no other text/atomic content) gives the line its own strut height, from the box\'s own font-size', function () {
    $frag = layoutHtml(
        '<body><p><span class="tag"></span></p></body>',
        '.tag { background-color: #cccccc; padding: 0 5px; font-size: 40px; }',
    );
    $p = $frag->children[0];
    assert($p instanceof BoxFragment);

    $boxes = inlineBoxFragments($p);
    expect($boxes)->toHaveCount(1);
    $box = $boxes[0];
    // No text/atomic entry exists on this line to derive lineHeight from -- the empty box's own
    // font-size (1.2x convention, ver TextMeasurer::lineHeight()) is the ONLY thing giving this
    // line a height (documented fallback, ver InlineFlowContext::closeLine()).
    expect($box->rect->height)->toEqualWithDelta(48.0, 0.001);
});

// --- M7-T5 (CSS 2.2 §10.4): min/max-width/height clamp on a normal block -----------------------

it('min-width beats a smaller declared width', function () {
    $frag = layoutHtml('<body><div>x</div></body>', 'div { width: 100px; min-width: 200px }');
    $div = $frag->children[0];
    assert($div instanceof BoxFragment);
    expect($div->rect->width)->toBe(200.0);
});

it('max-width clamps an auto (fill-available) width', function () {
    // containingWidth 500 (layoutHtml default) -- width:auto would otherwise fill it entirely.
    $frag = layoutHtml('<body><div>x</div></body>', 'div { max-width: 150px }');
    $div = $frag->children[0];
    assert($div instanceof BoxFragment);
    expect($div->rect->width)->toBe(150.0);
});

it('min-width wins over max-width when min > max (per the CSS 2.2 §10.4 algorithm text)', function () {
    $frag = layoutHtml('<body><div>x</div></body>', 'div { min-width: 300px; max-width: 100px }');
    $div = $frag->children[0];
    assert($div instanceof BoxFragment);
    expect($div->rect->width)->toBe(300.0);
});

it('resolves a percentage max-width against the containing block width', function () {
    $frag = layoutHtml('<body><div>x</div></body>', 'div { max-width: 50% }', 400.0);
    $div = $frag->children[0];
    assert($div instanceof BoxFragment);
    expect($div->rect->width)->toBe(200.0);
});

it('min-width in border-box sizing subtracts the box\'s own padding+border before comparing to content width', function () {
    // box-sizing:border-box: width declared 60px -> content 40 (minus 2x10 padding); min-width
    // 100px -> content-space equivalent 80 (minus 2x10 padding) -- content clamped up to 80,
    // final border-box = 80+20 = 100.
    $frag = layoutHtml(
        '<body><div>x</div></body>',
        'div { width: 60px; min-width: 100px; padding: 0 10px; box-sizing: border-box }',
    );
    $div = $frag->children[0];
    assert($div instanceof BoxFragment);
    expect($div->rect->width)->toBe(100.0);
});

it('min-height grows the box past its natural content height (content stays anchored at the top, background covers the extra space)', function () {
    $frag = layoutHtml('<body><div>x</div></body>', 'div { min-height: 100px; background-color: #ff0000 }');
    $div = $frag->children[0];
    assert($div instanceof BoxFragment);
    expect($div->rect->height)->toBe(100.0);
    expect($div->background)->not->toBeNull();
    // Content (the single text line) stays at the natural top position -- unaffected by the
    // box growing underneath it.
    $line = textFragments($div)[0];
    expect($line->rect->y)->toBe($div->rect->y);
});

it('max-height with overflow:visible (default) clamps the BOX height but leaves the overflowing content fragment untouched (no clip)', function () {
    $frag = layoutHtml(
        '<body><div>uno dos tres cuatro cinco seis siete ocho nueve diez</div></body>',
        'div { max-height: 10px; width: 60px }',
    );
    $div = $frag->children[0];
    assert($div instanceof BoxFragment);
    expect($div->rect->height)->toBe(10.0);
    expect($div->clipsChildren)->toBeFalse();
    // Natural content (several wrapped lines) is taller than the clamped box -- overflow visible,
    // documented: the lines are still there, just extending past the box's own bottom edge.
    $lines = textFragments($div);
    expect(count($lines))->toBeGreaterThan(1);
    expect($lines[count($lines) - 1]->rect->bottom())->toBeGreaterThan($div->rect->bottom());
});

it('overflow:hidden sets clipsChildren on the BoxFragment regardless of max-height', function () {
    $frag = layoutHtml('<body><div>x</div></body>', 'div { overflow: hidden }');
    $div = $frag->children[0];
    assert($div instanceof BoxFragment);
    expect($div->clipsChildren)->toBeTrue();
});

// --- M7-T5: min/max-width integrated into inline-block shrink-to-fit --------------------------

it('min-width floors an inline-block\'s shrink-to-fit width (min(max(minW, fit), maxW))', function () {
    $frag = layoutHtml(
        '<body><p><a class="tag">x</a></p></body>',
        '.tag { display: inline-block; min-width: 150px; }',
    );
    $p = $frag->children[0];
    assert($p instanceof BoxFragment);
    $tag = null;
    foreach ($p->children as $child) {
        if ($child instanceof BoxFragment) {
            $tag = $child;
        }
    }
    assert($tag instanceof BoxFragment);
    // Shrink-to-fit (a single "x" glyph) would be far narrower than 150px -- min-width floors it.
    expect($tag->rect->width)->toBe(150.0);
});

it('max-width caps an inline-block\'s declared width', function () {
    $frag = layoutHtml(
        '<body><p><a class="tag">x</a></p></body>',
        '.tag { display: inline-block; width: 300px; max-width: 100px; }',
    );
    $p = $frag->children[0];
    assert($p instanceof BoxFragment);
    $tag = null;
    foreach ($p->children as $child) {
        if ($child instanceof BoxFragment) {
            $tag = $child;
        }
    }
    assert($tag instanceof BoxFragment);
    expect($tag->rect->width)->toBe(100.0);
});

// M7-T6 (CSS 2.2 §9.5/§9.4.3/§10, floats + position:relative/absolute reducido). Este motor NO
// soporta el atributo HTML `style="..."` (todo CSS llega vía hoja de estilos con selectores, ver
// BoxTreeBuilder/StyleResolver) -- estos tests usan clases + un stylesheet, como el resto del
// fichero.

it('M7-T6: two left floats sit side by side; a third that no longer fits stacks BELOW both', function () {
    $frag = layoutHtml(
        '<body><div class="a"></div><div class="b"></div><div class="c"></div></body>',
        '.a { float: left; width: 200px; min-height: 50px; background-color: #ff0000 }'
        . '.b { float: left; width: 200px; min-height: 50px; background-color: #00ff00 }'
        . '.c { float: left; width: 200px; min-height: 50px; background-color: #0000ff }',
        500.0,
    );

    $red = findBoxByBackground($frag, '#ff0000');
    $green = findBoxByBackground($frag, '#00ff00');
    $blue = findBoxByBackground($frag, '#0000ff');
    assert($red instanceof BoxFragment);
    assert($green instanceof BoxFragment);
    assert($blue instanceof BoxFragment);

    // Primer float: banda completa libre -- se coloca en el borde izquierdo.
    expect($red->rect->x)->toBe(0.0);
    expect($red->rect->y)->toBe(0.0);
    // Segundo float: cabe a la derecha del primero (200 + 200 = 400 <= 500).
    expect($green->rect->x)->toBe(200.0);
    expect($green->rect->y)->toBe(0.0);
    // Tercer float: 200(rojo)+200(verde)+200 = 600 > 500 no cabe junto a ninguno de los dos ->
    // baja hasta Y=50 (el borde inferior de AMBOS, que comparten altura) donde el hueco vuelve a
    // ser el ancho completo.
    expect($blue->rect->x)->toBe(0.0);
    expect($blue->rect->y)->toBe(50.0);
});

it('M7-T6: a floated block is removed from flow -- the next normal sibling does not skip past it', function () {
    $frag = layoutHtml(
        '<body><div class="float-box"></div><div class="sibling">sibling</div></body>',
        '.float-box { float: left; width: 100px; min-height: 200px; background-color: #ff0000 }'
        . '.sibling { background-color: #00ff00 }',
        500.0,
    );
    $sibling = findBoxByBackground($frag, '#00ff00');
    assert($sibling instanceof BoxFragment);
    // El float NO avanza el cursor de flujo -- el hermano normal empieza en y=0, no en y=200.
    expect($sibling->rect->y)->toBe(0.0);
});

it('M7-T6: clear:both jumps the cleared element below the tallest relevant float band', function () {
    $frag = layoutHtml(
        '<body><div class="left"></div><div class="right"></div><div class="cleared">cleared</div></body>',
        '.left { float: left; width: 100px; min-height: 80px; background-color: #ff0000 }'
        . '.right { float: right; width: 100px; min-height: 40px; background-color: #00ff00 }'
        . '.cleared { clear: both; background-color: #0000ff }',
        500.0,
    );
    $cleared = findBoxByBackground($frag, '#0000ff');
    assert($cleared instanceof BoxFragment);
    // El float izquierdo (80px) es más alto que el derecho (40px) -- clear:both baja hasta el
    // más alto de los dos, 80.
    expect($cleared->rect->y)->toBe(80.0);
});

it('M7-T6: a float taller than its (non-BFC) container does NOT extend the container\'s own height', function () {
    $frag = layoutHtml(
        '<body><div class="outer"><div class="float-box"></div></div></body>',
        '.outer { background-color: #eeeeee }'
        . '.float-box { float: left; width: 50px; min-height: 300px; background-color: #ff0000 }',
        500.0,
    );
    $outer = findBoxByBackground($frag, '#eeeeee');
    assert($outer instanceof BoxFragment);
    // El outer div NO establece su propio BFC (overflow:visible, position:static) -- el float
    // "escapa" hacia el BFC de la raíz y NO cuenta para la altura de ESTE contenedor (CSS 2.2
    // §9.5 default: floats no contribuyen a la altura del contenedor).
    expect($outer->rect->height)->toBe(0.0);
});

it('M7-T6: overflow:hidden makes the BFC root CONTAIN its float\'s height (CSS 2.2 §10.6.7)', function () {
    $frag = layoutHtml(
        '<body><div class="outer"><div class="float-box"></div></div></body>',
        '.outer { overflow: hidden; background-color: #eeeeee }'
        . '.float-box { float: left; width: 50px; min-height: 300px; background-color: #ff0000 }',
        500.0,
    );
    $outer = findBoxByBackground($frag, '#eeeeee');
    assert($outer instanceof BoxFragment);
    // overflow:hidden establece su PROPIO BFC -- a diferencia del test anterior, este SÍ contiene
    // la altura de su float.
    expect($outer->rect->height)->toBe(300.0);
});

it('M7-T6: position:relative shifts top/left (siblings/layout untouched)', function () {
    $frag = layoutHtml(
        '<body><div class="rel">x</div></body>',
        '.rel { position: relative; top: 10px; left: 20px; background-color: #ff0000 }',
        500.0,
    );
    $div = findBoxByBackground($frag, '#ff0000');
    assert($div instanceof BoxFragment);
    // Sin ningún margen/padding, la posición ESTÁTICA sería (0,0) -- el shift visual la mueve
    // exactamente por (left, top).
    expect($div->rect->x)->toBe(20.0);
    expect($div->rect->y)->toBe(10.0);
});

it('M7-T6: position:relative with bottom/right (negated) shifts up/left', function () {
    $frag = layoutHtml(
        '<body><div class="rel">x</div></body>',
        '.rel { position: relative; bottom: 10px; right: 20px; background-color: #ff0000 }',
        500.0,
    );
    $div = findBoxByBackground($frag, '#ff0000');
    assert($div instanceof BoxFragment);
    expect($div->rect->x)->toBe(-20.0);
    expect($div->rect->y)->toBe(-10.0);
});

it('M7-T6: position:relative -- when BOTH pairs are given, top/left win over bottom/right', function () {
    $frag = layoutHtml(
        '<body><div class="rel">x</div></body>',
        '.rel { position: relative; top: 5px; bottom: 100px; left: 7px; right: 200px; background-color: #ff0000 }',
        500.0,
    );
    $div = findBoxByBackground($frag, '#ff0000');
    assert($div instanceof BoxFragment);
    expect($div->rect->x)->toBe(7.0);
    expect($div->rect->y)->toBe(5.0);
});

it('M7-T6: position:absolute resolves against a position:relative ancestor\'s CONTENT box', function () {
    $frag = layoutHtml(
        '<body><div class="ancestor"><div class="abs"></div></div></body>',
        '.ancestor { position: relative; width: 300px; padding: 10px; background-color: #eeeeee }'
        . '.abs { position: absolute; top: 5px; left: 15px; width: 40px; min-height: 20px; background-color: #ff0000 }',
        500.0,
    );
    $abs = findBoxByBackground($frag, '#ff0000');
    assert($abs instanceof BoxFragment);
    // Ancestro: content box en (10,10) (padding 10 en los 4 lados, sin borde). Absolute:
    // left=15/top=5 se resuelven contra ESE content box -- (10+15, 10+5).
    expect($abs->rect->x)->toBe(25.0);
    expect($abs->rect->y)->toBe(15.0);
});

it('M7-T6: position:absolute resolves against the ROOT/initial containing block when no positioned ancestor exists', function () {
    $frag = layoutHtml(
        '<body><div class="outer"><div class="abs"></div></div></body>',
        '.outer { background-color: #eeeeee }'
        . '.abs { position: absolute; top: 5px; left: 15px; width: 40px; min-height: 20px; background-color: #ff0000 }',
        500.0,
    );
    $abs = findBoxByBackground($frag, '#ff0000');
    assert($abs instanceof BoxFragment);
    // El outer div NO es positioned -- el CB sigue siendo la caja raíz (content box en (0,0)).
    expect($abs->rect->x)->toBe(15.0);
    expect($abs->rect->y)->toBe(5.0);
});

it('M7-T6: position:absolute does not advance the flow cursor -- siblings are unaffected', function () {
    $frag = layoutHtml(
        '<body><div class="outer"><div class="abs"></div><div class="sibling">sibling</div></div></body>',
        '.abs { position: absolute; top: 100px; left: 100px; width: 40px; min-height: 20px; background-color: #ff0000 }'
        . '.sibling { background-color: #00ff00 }',
        500.0,
    );
    $sibling = findBoxByBackground($frag, '#00ff00');
    assert($sibling instanceof BoxFragment);
    expect($sibling->rect->y)->toBe(0.0);
});

// --- Critical review fix (task M7-T6, reviewer-reproduced): position:relative offsets were
// leaking into normal flow -- CSS 2.2 §9.4.3 says the top/left/bottom/right offset is a PURELY
// visual/paint shift; it must NEVER move where the NEXT sibling starts, nor grow/shrink the
// container's own content-driven auto-height. Before this fix, every sibling-advance site in
// BlockFlowContext::layout()'s loop read `$childFragment->rect->bottom()` -- but $childFragment
// was already the POST-shift fragment (GeometryShift::translateXY() is applied at the very end
// of layout()/layoutImage(), see their position:relative branch), so a `top`/`left` offset bled
// straight into the cursor/height math. Fixed by deriving flow geometry from PRE-shift numbers
// (see BlockFlowContext::flowBottom()) at all five sibling-advance sites (generic block, image,
// table, flex, list-item).

it('M7-T6 fix: position:relative top+left offsets do not leak into sibling advance, container auto-height, or the horizontal axis (reviewer repro)', function () {
    $frag = layoutHtml(
        '<body><div class="rel">x</div><div class="sib">sibling</div></body>',
        '.rel { position: relative; top: 50px; left: 30px; min-height: 20px; background-color: #ff0000 }'
        . '.sib { background-color: #00ff00 }',
        500.0,
    );
    $rel = findBoxByBackground($frag, '#ff0000');
    $sib = findBoxByBackground($frag, '#00ff00');
    assert($rel instanceof BoxFragment);
    assert($sib instanceof BoxFragment);

    // Flow math (vertical): the sibling starts exactly where .rel's UNSHIFTED border-box would
    // have ended (min-height:20, no margin) -- NOT at 20+50=70.
    expect($sib->rect->y)->toBe(20.0);
    // Flow math (horizontal): a child never derives its sibling's X from anything but contentX in
    // this engine (block children always span the same start X) -- left:30px must not move it.
    expect($sib->rect->x)->toBe(0.0);
    // Container (body) auto-height is content-driven from PRE-shift geometry too: 20 (.rel,
    // min-height-driven) + 19.2 (.sib's single line of text, default line-height 1.2 * 16px
    // font-size) = 39.2 -- NOT 20+50+19.2=89.2 (the bug's reported height).
    expect($frag->rect->height)->toBe(39.2);
    // Container width is containing-block-driven in this engine (never content-driven for a
    // plain block), so it was never at risk -- asserted anyway for completeness.
    expect($frag->rect->width)->toBe(500.0);

    // The relative child's OWN painted position DOES move by (+30, +50) -- existing/preserved
    // behavior; this fix only changes what FLOW math reads, never what gets PAINTED.
    expect($rel->rect->x)->toBe(30.0);
    expect($rel->rect->y)->toBe(50.0);
});

it('M7-T6 fix: a container with ONLY a position:relative child gets its auto-height from PRE-shift geometry', function () {
    $frag = layoutHtml(
        '<body><div class="outer"><div class="rel"></div></div></body>',
        '.outer { background-color: #eeeeee }'
        . '.rel { position: relative; top: 50px; min-height: 20px; background-color: #ff0000 }',
        500.0,
    );
    $outer = findBoxByBackground($frag, '#eeeeee');
    $rel = findBoxByBackground($frag, '#ff0000');
    assert($outer instanceof BoxFragment);
    assert($rel instanceof BoxFragment);

    // No sibling below to mask the bug -- the container's OWN auto-height must still be 20
    // (min-height, pre-shift), not 70 (20+50, the shifted bottom).
    expect($outer->rect->height)->toBe(20.0);
    // The child itself is still painted shifted -- (0,50) instead of the static (0,0).
    expect($rel->rect->y)->toBe(50.0);
});

it('M7-T6 fix: position:relative margin-bottom still advances the cursor correctly ON TOP OF the (unshifted) flow bottom', function () {
    $frag = layoutHtml(
        '<body><div class="rel">x</div><div class="sib">sibling</div></body>',
        '.rel { position: relative; top: 50px; min-height: 20px; margin-bottom: 15px; background-color: #ff0000 }'
        . '.sib { background-color: #00ff00 }',
        500.0,
    );
    $sib = findBoxByBackground($frag, '#00ff00');
    assert($sib instanceof BoxFragment);
    // Pre-shift flow bottom (20) + margin-bottom (15) = 35 -- the 50px top offset must not appear
    // anywhere in this sum.
    expect($sib->rect->y)->toBe(35.0);
});

it('M7-T6 fix: position:relative on an <img> (ImageBox branch) does not leak its offset into the next sibling', function () {
    $frag = layoutImageHtml(
        '<body><img class="rel" src="tiny.jpg"><div class="sib">sibling</div></body>',
        '.rel { position: relative; top: 50px; width: 40px; height: 20px; display: block; background-color: #ff0000 }'
        . '.sib { background-color: #00ff00 }',
        500.0,
    );
    $img = findBoxByBackground($frag, '#ff0000');
    $sib = findBoxByBackground($frag, '#00ff00');
    assert($img instanceof BoxFragment);
    assert($sib instanceof BoxFragment);

    // Same invariant as the generic block branch: the sibling reads the UNSHIFTED bottom (20),
    // never 20+50=70.
    expect($sib->rect->y)->toBe(20.0);
    // The image's own painted position DOES still move (+50) -- preserved behavior.
    expect($img->rect->y)->toBe(50.0);
});

it('M7-T6 fix: position:relative on a list-item (ListItem branch) does not leak its offset into the next sibling <li>', function () {
    $frag = layoutHtml(
        '<body><ul><li class="rel">x</li><li class="sib">sibling</li></ul></body>',
        // ul's UA default (`margin: 1em 0`, see UserAgentStylesheet) is zeroed out so the expected
        // numbers below isolate ONLY the relative-offset-leak math, same as every other test here.
        'ul { margin: 0 }'
        . '.rel { position: relative; top: 50px; min-height: 20px; background-color: #ff0000 }'
        . '.sib { background-color: #00ff00 }',
        500.0,
    );
    $rel = findBoxByBackground($frag, '#ff0000');
    $sib = findBoxByBackground($frag, '#00ff00');
    assert($rel instanceof BoxFragment);
    assert($sib instanceof BoxFragment);

    // Same invariant as the generic block branch, via the ListItem recursive layout() call:
    // sibling <li> reads the UNSHIFTED bottom of the first <li> (20), never 20+50=70.
    expect($sib->rect->y)->toBe(20.0);
    // The relatively-positioned <li>'s OWN painted position DOES still move (+50) -- preserved.
    expect($rel->rect->y)->toBe(50.0);
});

// --- M7 final-review Finding C: min/max-width was silently dropped on floats/absolutes ---------
// shrinkToFitWidth()'s docblock claimed layout() clamps the $usedWidthOverride it returns -- FALSE:
// layout() only applies that clamp in the NO-override branch (see its M7-T5 docblock). Any float or
// position:absolute box, which ALWAYS goes through the override, silently ignored its own
// min/max-width entirely before this fix.

it('Finding C: a float with a declared width beyond its max-width is clamped to the max-width (declared-width path)', function () {
    $frag = layoutHtml(
        '<body><div class="float-box"></div></body>',
        '.float-box { float: left; width: 300px; max-width: 100px; min-height: 10px }',
        500.0,
    );
    $floatBox = $frag->children[0];
    assert($floatBox instanceof BoxFragment);
    expect($floatBox->rect->width)->toBe(100.0);
});

it('Finding C: a float with no declared width but wide content is clamped to its max-width (shrink-to-fit path)', function () {
    $frag = layoutHtml(
        '<body><div class="float-box">a very long line of text that would otherwise shrink-to-fit far wider than one hundred pixels</div></body>',
        '.float-box { float: left; max-width: 100px }',
        500.0,
    );
    $floatBox = $frag->children[0];
    assert($floatBox instanceof BoxFragment);
    expect($floatBox->rect->width)->toBe(100.0);
});

it('Finding C: a position:absolute box with a declared width beyond its max-width is clamped (declared-width path)', function () {
    $frag = layoutHtml(
        '<body><div class="abs"></div></body>',
        '.abs { position: absolute; width: 300px; max-width: 100px; min-height: 10px }',
        500.0,
    );
    $abs = $frag->children[0];
    assert($abs instanceof BoxFragment);
    expect($abs->rect->width)->toBe(100.0);
});

it('Finding C: a position:absolute box with no declared width but wide content is clamped to its max-width (shrink-to-fit path)', function () {
    $frag = layoutHtml(
        '<body><div class="abs">a very long line of text that would otherwise shrink-to-fit far wider than one hundred pixels</div></body>',
        '.abs { position: absolute; max-width: 100px }',
        500.0,
    );
    $abs = $frag->children[0];
    assert($abs instanceof BoxFragment);
    expect($abs->rect->width)->toBe(100.0);
});

it('Finding C: a min-width floors a float narrower than it (declared-width path), same criterion as a normal block', function () {
    $frag = layoutHtml(
        '<body><div class="float-box"></div></body>',
        '.float-box { float: left; width: 50px; min-width: 150px; min-height: 10px }',
        500.0,
    );
    $floatBox = $frag->children[0];
    assert($floatBox instanceof BoxFragment);
    expect($floatBox->rect->width)->toBe(150.0);
});

it('Finding C control: a normal block (no float/absolute) still honours min/max-width exactly as before (regression guard)', function () {
    $frag = layoutHtml('<body><div>x</div></body>', 'div { width: 300px; max-width: 100px }');
    $block = $frag->children[0];
    assert($block instanceof BoxFragment);
    expect($block->rect->width)->toBe(100.0);
});

// --- M7 final-review Finding D: warning discipline for float/position no-ops -------------------

it('Finding D: float on a <table> warns exactly once and leaves the table in normal flow (no behavioral change)', function () {
    [$frag, $warnings] = layoutHtmlCollectingWarnings(
        '<body><table class="t"><tr><td>x</td></tr></table><div class="sibling">sibling</div></body>',
        '.t { float: left; width: 100px }',
    );
    expect($warnings)->toHaveCount(1);
    expect($warnings[0])->toContain('float on a <table> has no effect');

    $sibling = $frag->children[1];
    assert($sibling instanceof BoxFragment);
    // Normal flow: the table's own content height (not zero, it has one row) advances the
    // cursor exactly as if float didn't exist -- the sibling starts AFTER it, not at y=0.
    $table = $frag->children[0];
    assert($table instanceof BoxFragment);
    expect($sibling->rect->y)->toBeGreaterThan(0.0);
    expect($sibling->rect->y)->toBe($table->rect->height);
});

it('Finding D: float on a <table> only warns ONCE even with two floated tables (addWarningOnce dedup)', function () {
    [, $warnings] = layoutHtmlCollectingWarnings(
        '<body><table class="t"><tr><td>x</td></tr></table><table class="t"><tr><td>y</td></tr></table></body>',
        '.t { float: left; width: 100px }',
    );
    expect($warnings)->toHaveCount(1);
});
