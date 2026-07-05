<?php

declare(strict_types=1);

use Pliego\Box\BlockBox;
use Pliego\Box\BoxTreeBuilder;
use Pliego\Box\TextRun;
use Pliego\Css\StylesheetParser;
use Pliego\Dom\HtmlParser;
use Pliego\Style\CssStyleSource;
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
it('flattens inline elements into the parent text run', function () {
    $root = buildTree('<body><p>Hola <strong>mundo</strong> feliz</p></body>');
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    $run = $p->children[0];
    assert($run instanceof TextRun);
    expect($run->text)->toBe('Hola mundo feliz');
});
it('collapses whitespace', function () {
    $root = buildTree("<body><p>  Hola \n\t mundo  </p></body>");
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    $run = $p->children[0];
    assert($run instanceof TextRun);
    expect($run->text)->toBe('Hola mundo');
});
