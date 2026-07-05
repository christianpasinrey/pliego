<?php

declare(strict_types=1);

use Pliego\Box\BlockBox;
use Pliego\Box\BoxTreeBuilder;
use Pliego\Box\LineBreakRun;
use Pliego\Box\TextRun;
use Pliego\Css\StylesheetParser;
use Pliego\Dom\HtmlParser;
use Pliego\Style\CssStyleSource;
use Pliego\Style\FontStyle;
use Pliego\Style\StyleResolver;

function buildTree(string $html, string $css = ''): BlockBox
{
    $doc = HtmlParser::parse($html);
    $map = new StyleResolver([new CssStyleSource(new StylesheetParser()->parse($css))])->resolve($doc);
    return new BoxTreeBuilder()->build($doc, $map);
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
