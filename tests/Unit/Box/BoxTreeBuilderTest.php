<?php

declare(strict_types=1);

use Pliego\Box\BlockBox;
use Pliego\Box\BoxTreeBuilder;
use Pliego\Box\ImageBox;
use Pliego\Box\LineBreakRun;
use Pliego\Box\TextRun;
use Pliego\Css\StylesheetParser;
use Pliego\Css\WarningCollector;
use Pliego\Dom\HtmlParser;
use Pliego\Image\ImageLoader;
use Pliego\Style\CssStyleSource;
use Pliego\Style\FontStyle;
use Pliego\Style\StyleResolver;

const IMAGE_FIXTURES_DIR = __DIR__ . '/../../../resources/images';

function buildTree(string $html, string $css = '', ?WarningCollector $warnings = null, string $basePath = __DIR__): BlockBox
{
    $doc = HtmlParser::parse($html);
    $map = new StyleResolver([new CssStyleSource(new StylesheetParser()->parse($css))])->resolve($doc);
    return new BoxTreeBuilder(new ImageLoader(), $warnings ?? new WarningCollector(), $basePath)->build($doc, $map);
}

/** @return array{0: BlockBox, 1: list<string>} */
function buildTreeCollectingWarnings(string $html, string $basePath, string $css = ''): array
{
    $collector = new WarningCollector();
    $tree = buildTree($html, $css, $collector, $basePath);
    return [$tree, $collector->drain()];
}

it('builds nested block boxes with text runs', function () {
    $root = buildTree('<body><h1>Hola</h1><p>Mundo cruel</p></body>');
    expect($root->tag)->toBe('body');
    expect($root->children)->toHaveCount(2);
    $h1 = $root->children[0];
    assert($h1 instanceof BlockBox);
    expect($h1->children[0])->toBeInstanceOf(TextRun::class);
});
it('prunes display none subtrees', function () {
    $root = buildTree('<body><p>a</p><p class="x">b</p></body>', '.x { display: none }');
    expect($root->children)->toHaveCount(1);
});
it('collapses whitespace', function () {
    $root = buildTree("<body><p>  Hola \n\t mundo  </p></body>");
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    $run = $p->children[0];
    assert($run instanceof TextRun);
    expect($run->text)->toBe('Hola mundo');
});

// M1-T4: fin del aplanado M0. Cada inline conserva su propio ComputedStyle
// (heredado del bloque vía StyleResolver); el whitespace collapsing pasa a operar
// sobre la secuencia COMPLETA de runs del bloque, no sobre un string ya aplanado.
it('creates styled runs for inline elements', function () {
    $root = buildTree('<body><p>Hola <strong>mundo</strong></p></body>');
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    expect($p->children)->toHaveCount(2);
    [$first, $second] = $p->children;
    assert($first instanceof TextRun && $second instanceof TextRun);
    expect($first->text)->toBe('Hola ');
    expect($second->text)->toBe('mundo');
    expect($second->style->fontWeight)->toBe(700);
    // El run del texto plano conserva el estilo del bloque (peso normal).
    expect($first->style->fontWeight)->toBe(400);
});

it('keeps a single boundary space between runs', function () {
    // Convención documentada (BoxTreeBuilder::collapse): el espacio de frontera vive
    // siempre al final del run PRECEDENTE ya emitido, sin importar si en el DOM
    // original era el espacio final del nodo anterior o el inicial del siguiente.
    $root = buildTree('<body><p>Hola <strong>mundo</strong> feliz</p></body>');
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    expect($p->children)->toHaveCount(3);
    [$first, $second, $third] = $p->children;
    assert($first instanceof TextRun && $second instanceof TextRun && $third instanceof TextRun);
    expect($first->text)->toBe('Hola ');
    expect($second->text)->toBe('mundo ');
    expect($third->text)->toBe('feliz');

    // Caso de doble espacio de frontera (final del anterior + inicial del inline):
    // se colapsa a UNO solo, adjunto al run precedente.
    $root2 = buildTree('<body><p>Hola <b> mundo</b></p></body>');
    $p2 = $root2->children[0];
    assert($p2 instanceof BlockBox);
    expect($p2->children)->toHaveCount(2);
    [$a, $b] = $p2->children;
    assert($a instanceof TextRun && $b instanceof TextRun);
    expect($a->text)->toBe('Hola ');
    expect($b->text)->toBe('mundo');
});

it('nests inline styles', function () {
    $root = buildTree('<body><p><b><i>x</i></b></p></body>');
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    expect($p->children)->toHaveCount(1);
    $run = $p->children[0];
    assert($run instanceof TextRun);
    expect($run->text)->toBe('x');
    expect($run->style->fontWeight)->toBe(700);
    expect($run->style->fontStyle)->toBe(FontStyle::Italic);
});

it('emits LineBreakRun for br', function () {
    $root = buildTree('<body><p>line1<br>line2</p></body>');
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    expect($p->children)->toHaveCount(3);
    [$first, $break, $second] = $p->children;
    assert($first instanceof TextRun && $second instanceof TextRun);
    expect($first->text)->toBe('line1');
    expect($break)->toBeInstanceOf(LineBreakRun::class);
    expect($second->text)->toBe('line2');
});

it('prunes display:none inside inline elements', function () {
    $root = buildTree('<body><p><span>a<script>x</script>b</span></p></body>');
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    expect($p->children)->toHaveCount(1);
    $run = $p->children[0];
    assert($run instanceof TextRun);
    expect($run->text)->toBe('ab');
});

// M3-T2: <img> es replaced block-level — resuelto contra basePath, dims intrínsecas vía
// ImageLoader, atributos width/height HTML numéricos, y errores suaves (warning + omitir caja)
// para src remoto, fichero ausente o formato no soportado.

it('creates an ImageBox with intrinsic dimensions read from ImageLoader', function () {
    $expected = new ImageLoader()->load(IMAGE_FIXTURES_DIR . '/tiny.jpg');
    $root = buildTree('<body><img src="tiny.jpg"></body>', '', null, IMAGE_FIXTURES_DIR);
    expect($root->children)->toHaveCount(1);
    $img = $root->children[0];
    assert($img instanceof ImageBox);
    expect($img->intrinsicWidth)->toBe($expected->widthPx());
    expect($img->intrinsicHeight)->toBe($expected->heightPx());
});

it('resolves a relative src against the given basePath', function () {
    $root = buildTree('<body><img src="tiny.jpg"></body>', '', null, IMAGE_FIXTURES_DIR);
    $img = $root->children[0];
    assert($img instanceof ImageBox);
    expect($img->src)->toBe(IMAGE_FIXTURES_DIR . '/tiny.jpg');
});

it('parses numeric width/height attributes into attrWidth/attrHeight', function () {
    $root = buildTree('<body><img src="tiny.jpg" width="200" height="150"></body>', '', null, IMAGE_FIXTURES_DIR);
    $img = $root->children[0];
    assert($img instanceof ImageBox);
    expect($img->attrWidth)->toBe(200.0);
    expect($img->attrHeight)->toBe(150.0);
});

it('ignores non-numeric width/height attributes', function () {
    $root = buildTree('<body><img src="tiny.jpg" width="50%" height="auto"></body>', '', null, IMAGE_FIXTURES_DIR);
    $img = $root->children[0];
    assert($img instanceof ImageBox);
    expect($img->attrWidth)->toBeNull();
    expect($img->attrHeight)->toBeNull();
});

it('prunes an img with display:none', function () {
    $root = buildTree('<body><img src="tiny.jpg" class="x"></body>', '.x { display: none }', null, IMAGE_FIXTURES_DIR);
    expect($root->children)->toHaveCount(0);
});

it('warns and skips remote http(s) images without building a box', function () {
    [$root, $warnings] = buildTreeCollectingWarnings('<body><img src="https://example.com/a.jpg"></body>', IMAGE_FIXTURES_DIR);
    expect($root->children)->toHaveCount(0);
    expect($warnings)->toHaveCount(1);
    expect($warnings[0])->toContain('remote images not supported yet');

    [$rootSecure, $warningsSecure] = buildTreeCollectingWarnings('<body><img src="http://example.com/a.jpg"></body>', IMAGE_FIXTURES_DIR);
    expect($rootSecure->children)->toHaveCount(0);
    expect($warningsSecure)->toHaveCount(1);
});

it('warns and skips a missing image file', function () {
    [$root, $warnings] = buildTreeCollectingWarnings('<body><img src="does-not-exist.png"></body>', IMAGE_FIXTURES_DIR);
    expect($root->children)->toHaveCount(0);
    expect($warnings)->toHaveCount(1);
});

it('warns and skips an unsupported image format', function () {
    $path = tempnam(sys_get_temp_dir(), 'gif') . '.gif';
    file_put_contents($path, 'GIF89a' . str_repeat("\x00", 20));
    try {
        [$root, $warnings] = buildTreeCollectingWarnings(
            '<body><img src="' . basename($path) . '"></body>',
            dirname($path),
        );
        expect($root->children)->toHaveCount(0);
        expect($warnings)->toHaveCount(1);
    } finally {
        unlink($path);
    }
});

it('warns and skips an img without a src attribute', function () {
    [$root, $warnings] = buildTreeCollectingWarnings('<body><img></body>', IMAGE_FIXTURES_DIR);
    expect($root->children)->toHaveCount(0);
    expect($warnings)->toHaveCount(1);
});

// M3-T2 defect fix: <img> nested inside an inline element (<a>, <span>, ...) used to recurse
// into a childless node and vanish silently — no ImageBox, no warning. collectInline now hoists
// it to block level (same buildImage() soft-failure path) and ALWAYS reports the approximation
// via a dedicated warning, so the drop is never silent again.

it('hoists an inline image (nested in <a>) to block level with a visible warning', function () {
    [$root, $warnings] = buildTreeCollectingWarnings(
        '<body><p><a href="x"><img src="tiny.jpg"></a> texto</p></body>',
        IMAGE_FIXTURES_DIR,
    );
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    expect($p->children)->toHaveCount(2);
    [$img, $text] = $p->children;
    assert($img instanceof ImageBox && $text instanceof TextRun);
    expect($text->text)->toBe('texto');
    expect($warnings)->toHaveCount(1);
    expect($warnings[0])->toContain('inline image hoisted to block level');
    expect($warnings[0])->toContain('tiny.jpg');
});

it('preserves ordering when hoisting: text before, image, text after', function () {
    [$root, $warnings] = buildTreeCollectingWarnings(
        '<body><p>antes <a href="x"><img src="tiny.jpg"></a> despues</p></body>',
        IMAGE_FIXTURES_DIR,
    );
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    expect($p->children)->toHaveCount(3);
    [$first, $img, $last] = $p->children;
    assert($first instanceof TextRun && $last instanceof TextRun);
    assert($img instanceof ImageBox);
    // El separador de secuencia (ImageBox, igual que LineBreakRun ya documentado en collapse())
    // recorta el espacio de frontera pendiente en vez de arrastrarlo, igual que un límite de bloque.
    expect($first->text)->toBe('antes');
    expect($last->text)->toBe('despues');
    expect($warnings)->toHaveCount(1);
});

it('hoists a failing inline image (nested in <span>): no ImageBox, both warnings reported', function () {
    [$root, $warnings] = buildTreeCollectingWarnings(
        '<body><span><img src="no-existe.png"></span></body>',
        IMAGE_FIXTURES_DIR,
    );
    expect($root->children)->toHaveCount(0);
    expect($warnings)->toHaveCount(2);
    expect($warnings[0])->toContain('inline image hoisted to block level');
    expect($warnings[1])->toContain('Could not load image');
});
