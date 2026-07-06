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
