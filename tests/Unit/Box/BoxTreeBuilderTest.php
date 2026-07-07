<?php

declare(strict_types=1);

use Pliego\Box\BlockBox;
use Pliego\Box\BoxTreeBuilder;
use Pliego\Box\ImageBox;
use Pliego\Box\LineBreakRun;
use Pliego\Box\TableBox;
use Pliego\Box\TextRun;
use Pliego\Css\StylesheetParser;
use Pliego\Css\WarningCollector;
use Pliego\Dom\HtmlParser;
use Pliego\Image\ImageLoader;
use Pliego\Style\CssStyleSource;
use Pliego\Style\Display;
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

/**
 * M5-T3: \Dom\HTMLDocument::createFromString() runs the FULL WHATWG HTML5 tree-construction
 * algorithm, which FOSTER-PARENTS any non-whitespace text or non-table-structure element found
 * directly inside <table>/<tr> — it is moved OUT, as a preceding sibling of the table, never
 * becomes an actual child (verified empirically: parsing '<table>loose</table>' yields a body
 * with a #text "loose" sibling BEFORE an empty <table>). This is real browser behavior, but it
 * means the "minimal anonymous structure" code path (BoxTreeBuilder::collectTableRows()/
 * buildTableRow()) is UNREACHABLE through HtmlParser::parse() on an HTML string — there is no
 * markup that survives parsing with that shape. Imperative DOM construction (createElement +
 * appendChild), used below, bypasses the parser's insertion-mode state machine entirely (it is
 * parsing-time-only behavior, not a tree invariant), so it CAN build the shape CSS 2.2 §17.2.1
 * anonymous-box generation is written against — the same shape a non-HTML5 DOM source (XML,
 * XHTML, or a future non-parser Dom\* producer) could hand to BoxTreeBuilder.
 */
function domBuild(\Closure $build): \Dom\HTMLDocument
{
    $doc = HtmlParser::parse('<!doctype html><body></body>');
    $body = $doc->body;
    assert($body instanceof \Dom\Element);
    $build($doc, $body);
    return $doc;
}

function buildTreeFromDoc(\Dom\HTMLDocument $doc, string $css = ''): BlockBox
{
    $map = new StyleResolver([new CssStyleSource(new StylesheetParser()->parse($css))])->resolve($doc);
    return new BoxTreeBuilder(new ImageLoader(), new WarningCollector(), __DIR__)->build($doc, $map);
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

it('treats non-positive width/height attributes as absent, per browser behavior', function () {
    $root = buildTree('<body><img src="tiny.jpg" width="-5" height="0"></body>', '', null, IMAGE_FIXTURES_DIR);
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

// M5-T1 (Minor, deferred from review): an <img> without a src attribute has nothing to append
// after the hoist message, so the warning must NOT carry a dangling "$message: " with an empty
// tail -- it should read as a clean sentence with no trailing ": ".
it('hoists an inline image without a src attribute (nested in <span>): warning has no trailing ": "', function () {
    [$root, $warnings] = buildTreeCollectingWarnings(
        '<body><span><img></span></body>',
        IMAGE_FIXTURES_DIR,
    );
    expect($root->children)->toHaveCount(0);
    expect($warnings)->toHaveCount(2); // hoist warning + buildImage()'s own "no src" warning
    expect($warnings[0])->toContain('inline image hoisted to block level');
    expect($warnings[0])->not->toEndWith(': ');
});

// M4-T2: css-flexbox-1 §4 — un contenedor flex convierte cada hijo en un "flex item". Un tramo
// contiguo de TextRun|LineBreakRun se envuelve en un ÚNICO BlockBox anónimo ("anonymous", estilo
// heredado del contenedor vía ComputedStyle::compute([], $containerStyle, 'div')); BlockBox e
// ImageBox ya son items directos por sí mismos y NUNCA entran en el anónimo (un replaced box
// corta el tramo de texto igual que ya hace LineBreakRun en collapse()). display:none sigue
// podando items antes de llegar aquí (mismo mecanismo que en flujo normal).

it('wraps a mixed text+div+img flex container into 3 items: anonymous, div, img', function () {
    $root = buildTree(
        '<body><div class="flex">texto<div>Bloque</div><img src="tiny.jpg"></div></body>',
        '.flex { display: flex }',
        null,
        IMAGE_FIXTURES_DIR,
    );
    $flex = $root->children[0];
    assert($flex instanceof BlockBox);
    expect($flex->style->display)->toBe(Display::Flex);
    expect($flex->children)->toHaveCount(3);
    [$anon, $div, $img] = $flex->children;
    assert($anon instanceof BlockBox && $div instanceof BlockBox && $img instanceof ImageBox);
    expect($anon->tag)->toBe('anonymous');
    expect($anon->children)->toHaveCount(1);
    $textRun = $anon->children[0];
    assert($textRun instanceof TextRun);
    expect($textRun->text)->toBe('texto');
    expect($div->tag)->toBe('div');
});

it('anonymous flex item style is block display and inherits container text properties', function () {
    $root = buildTree(
        '<body><div class="flex">hola</div></body>',
        '.flex { display: flex; color: #ff0000; font-weight: bold; }',
    );
    $flex = $root->children[0];
    assert($flex instanceof BlockBox);
    $anon = $flex->children[0];
    assert($anon instanceof BlockBox);
    expect($anon->tag)->toBe('anonymous');
    expect($anon->style->display)->toBe(Display::Block);
    expect($anon->style->fontWeight)->toBe(700);
    expect($anon->style->color->r)->toBe(255);
    expect($anon->style->color->g)->toBe(0);
    // display/flex properties never inherit (M4-T1): the anonymous wrapper is not itself a flex
    // container even though its parent is.
    expect($anon->style->display)->not->toBe(Display::Flex);
});

it('keeps <br> inside the anonymous wrapper as part of the same text run', function () {
    $root = buildTree('<body><div class="flex">a<br>b</div></body>', '.flex { display: flex }');
    $flex = $root->children[0];
    assert($flex instanceof BlockBox);
    expect($flex->children)->toHaveCount(1);
    $anon = $flex->children[0];
    assert($anon instanceof BlockBox);
    expect($anon->children)->toHaveCount(3);
    [$first, $break, $second] = $anon->children;
    assert($first instanceof TextRun && $second instanceof TextRun);
    expect($first->text)->toBe('a');
    expect($break)->toBeInstanceOf(LineBreakRun::class);
    expect($second->text)->toBe('b');
});

it('prunes display:none items inside a flex container', function () {
    $root = buildTree(
        '<body><div class="flex"><div class="hidden">a</div><div>b</div></div></body>',
        '.flex { display: flex } .hidden { display: none }',
    );
    $flex = $root->children[0];
    assert($flex instanceof BlockBox);
    expect($flex->children)->toHaveCount(1);
    $only = $flex->children[0];
    assert($only instanceof BlockBox);
    expect($only->children[0])->toBeInstanceOf(TextRun::class);
});

it('recurses into a nested flex container, each level with its own anonymous items', function () {
    $root = buildTree(
        '<body><div class="outer">outer-text<div class="inner">inner-text<div>inner-block</div></div></div></body>',
        '.outer { display: flex } .inner { display: flex }',
    );
    $outer = $root->children[0];
    assert($outer instanceof BlockBox);
    expect($outer->style->display)->toBe(Display::Flex);
    expect($outer->children)->toHaveCount(2);
    [$outerAnon, $inner] = $outer->children;
    assert($outerAnon instanceof BlockBox && $inner instanceof BlockBox);
    expect($outerAnon->tag)->toBe('anonymous');
    expect($inner->tag)->toBe('div');
    expect($inner->style->display)->toBe(Display::Flex);

    expect($inner->children)->toHaveCount(2);
    [$innerAnon, $innerBlock] = $inner->children;
    assert($innerAnon instanceof BlockBox && $innerBlock instanceof BlockBox);
    expect($innerAnon->tag)->toBe('anonymous');
    expect($innerAnon->children[0])->toBeInstanceOf(TextRun::class);
    expect($innerBlock->tag)->toBe('div');
});

// M5-T3: css-tables-3 §2 — un elemento con Display::Table (UA default para <table>, ver
// ComputedStyle::TABLE_DISPLAY_BY_TAG del M5-T2) construye un TableBox en vez de un BlockBox
// plano; sus filas <tr> reales construyen TableCellBox por cada td/th. thead/tbody son
// TRANSPARENTES: sus <tr> se aplanan en TableBox::$rows marcados isHeader. colspan es un entero
// ≥1 (default 1, inválido/0 cae a 1); rowspan solo dispara un warning (no soportado, tratado
// como 1). La variante MÍNIMA de estructura anónima (§17.2.1 reducido) envuelve texto suelto y
// elementos no-fila/no-celda en filas/celdas anónimas propias, sin fusionar hermanos adyacentes.

it('builds a full table into TableBox/TableRowBox/TableCellBox', function () {
    $root = buildTree('<body><table><tr><td>a</td><td>b</td></tr><tr><td>c</td><td>d</td></tr></table></body>');
    $table = $root->children[0];
    assert($table instanceof TableBox);
    expect($table->tag)->toBe('table');
    expect($table->rows)->toHaveCount(2);
    foreach ($table->rows as $row) {
        expect($row->isHeader)->toBeFalse();
        expect($row->cells)->toHaveCount(2);
        foreach ($row->cells as $cell) {
            expect($cell->tag)->toBe('td');
            expect($cell->colspan)->toBe(1);
        }
    }
    $firstCellText = $table->rows[0]->cells[0]->children[0];
    assert($firstCellText instanceof TextRun);
    expect($firstCellText->text)->toBe('a');
});

it('flattens thead/tbody rows into the table row list, marking thead rows as header', function () {
    $root = buildTree(
        '<body><table><thead><tr><th>H1</th></tr></thead><tbody><tr><td>a</td></tr><tr><td>b</td></tr></tbody></table></body>',
    );
    $table = $root->children[0];
    assert($table instanceof TableBox);
    expect($table->rows)->toHaveCount(3);
    [$header, $rowA, $rowB] = $table->rows;
    expect($header->isHeader)->toBeTrue();
    expect($rowA->isHeader)->toBeFalse();
    expect($rowB->isHeader)->toBeFalse();
    expect($header->cells[0]->tag)->toBe('th');
    expect($rowA->cells[0]->tag)->toBe('td');
});

it('parses colspan as an int >= 1, falling back to 1 when invalid/0/absent', function () {
    $root = buildTree(
        '<body><table><tr><td colspan="3">wide</td><td>x</td><td colspan="0">z</td><td colspan="abc">w</td></tr></table></body>',
    );
    $table = $root->children[0];
    assert($table instanceof TableBox);
    [$wide, $plain, $zero, $garbage] = $table->rows[0]->cells;
    expect($wide->colspan)->toBe(3);
    expect($plain->colspan)->toBe(1);
    expect($zero->colspan)->toBe(1);
    expect($garbage->colspan)->toBe(1);
});

it('warns and treats rowspan as 1 when the attribute is present', function () {
    [$root, $warnings] = buildTreeCollectingWarnings('<body><table><tr><td rowspan="2">a</td></tr></table></body>', __DIR__);
    $table = $root->children[0];
    assert($table instanceof TableBox);
    $cell = $table->rows[0]->cells[0];
    expect($cell->colspan)->toBe(1);
    expect($warnings)->toHaveCount(1);
    expect($warnings[0])->toBe('rowspan not supported yet: treated as 1');
});

it('wraps loose text directly in <table> in an anonymous row and cell', function () {
    $doc = domBuild(function (\Dom\HTMLDocument $doc, \Dom\Element $body): void {
        $table = $doc->createElement('table');
        $table->appendChild($doc->createTextNode('loose'));
        $body->appendChild($table);
    });
    $root = buildTreeFromDoc($doc);
    $table = $root->children[0];
    assert($table instanceof TableBox);
    expect($table->rows)->toHaveCount(1);
    $row = $table->rows[0];
    expect($row->isHeader)->toBeFalse();
    expect($row->cells)->toHaveCount(1);
    $cell = $row->cells[0];
    expect($cell->tag)->toBe('anonymous');
    $text = $cell->children[0];
    assert($text instanceof TextRun);
    expect($text->text)->toBe('loose');
});

it('wraps loose text directly in <tr> in an anonymous cell only (row already exists)', function () {
    $doc = domBuild(function (\Dom\HTMLDocument $doc, \Dom\Element $body): void {
        $table = $doc->createElement('table');
        $tr = $doc->createElement('tr');
        $tr->appendChild($doc->createTextNode('loose'));
        $table->appendChild($tr);
        $body->appendChild($table);
    });
    $root = buildTreeFromDoc($doc);
    $table = $root->children[0];
    assert($table instanceof TableBox);
    expect($table->rows)->toHaveCount(1);
    $row = $table->rows[0];
    expect($row->cells)->toHaveCount(1);
    $cell = $row->cells[0];
    expect($cell->tag)->toBe('anonymous');
    $text = $cell->children[0];
    assert($text instanceof TextRun);
    expect($text->text)->toBe('loose');
});

it('wraps a non-row element child of table in its own anonymous row+cell', function () {
    $doc = domBuild(function (\Dom\HTMLDocument $doc, \Dom\Element $body): void {
        $table = $doc->createElement('table');
        $div = $doc->createElement('div');
        $div->appendChild($doc->createTextNode('Bloque'));
        $table->appendChild($div);
        $body->appendChild($table);
    });
    $root = buildTreeFromDoc($doc);
    $table = $root->children[0];
    assert($table instanceof TableBox);
    expect($table->rows)->toHaveCount(1);
    $cell = $table->rows[0]->cells[0];
    expect($cell->tag)->toBe('anonymous');
    $div = $cell->children[0];
    assert($div instanceof BlockBox);
    expect($div->tag)->toBe('div');
    $text = $div->children[0];
    assert($text instanceof TextRun);
    expect($text->text)->toBe('Bloque');
});

it('wraps a non-cell element child of tr in its own anonymous cell', function () {
    $doc = domBuild(function (\Dom\HTMLDocument $doc, \Dom\Element $body): void {
        $table = $doc->createElement('table');
        $tr = $doc->createElement('tr');
        $div = $doc->createElement('div');
        $div->appendChild($doc->createTextNode('Bloque'));
        $tr->appendChild($div);
        $table->appendChild($tr);
        $body->appendChild($table);
    });
    $root = buildTreeFromDoc($doc);
    $table = $root->children[0];
    assert($table instanceof TableBox);
    $row = $table->rows[0];
    expect($row->cells)->toHaveCount(1);
    $cell = $row->cells[0];
    expect($cell->tag)->toBe('anonymous');
    $div = $cell->children[0];
    assert($div instanceof BlockBox);
    expect($div->tag)->toBe('div');
});

it('does not merge adjacent loose siblings: each gets its own anonymous row (documented minimal-variant divergence)', function () {
    $doc = domBuild(function (\Dom\HTMLDocument $doc, \Dom\Element $body): void {
        $table = $doc->createElement('table');
        $table->appendChild($doc->createTextNode('foo'));
        $div = $doc->createElement('div');
        $div->appendChild($doc->createTextNode('bar'));
        $table->appendChild($div);
        $body->appendChild($table);
    });
    $root = buildTreeFromDoc($doc);
    $table = $root->children[0];
    assert($table instanceof TableBox);
    expect($table->rows)->toHaveCount(2);
});

it('ignores whitespace-only text between table/tr/thead/tbody children', function () {
    $root = buildTree("<body><table>\n  <thead>\n    <tr><th>H</th></tr>\n  </thead>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table></body>");
    $table = $root->children[0];
    assert($table instanceof TableBox);
    expect($table->rows)->toHaveCount(2);
});

it('prunes a display:none tr, and a display:none td within a surviving tr', function () {
    $root = buildTree(
        '<body><table><tr class="hidden"><td>a</td></tr><tr><td class="hidden">b</td><td>c</td></tr></table></body>',
        '.hidden { display: none }',
    );
    $table = $root->children[0];
    assert($table instanceof TableBox);
    expect($table->rows)->toHaveCount(1);
    $row = $table->rows[0];
    expect($row->cells)->toHaveCount(1);
    $text = $row->cells[0]->children[0];
    assert($text instanceof TextRun);
    expect($text->text)->toBe('c');
});

it('prunes an entire display:none table', function () {
    $root = buildTree('<body><table class="hidden"><tr><td>a</td></tr></table></body>', '.hidden { display: none }');
    expect($root->children)->toHaveCount(0);
});

it('builds cell content via the normal pipeline: blocks, inline styling and images all work inside a cell', function () {
    $root = buildTree(
        '<body><table><tr><td><p>Hi <b>there</b></p><img src="tiny.jpg"></td></tr></table></body>',
        '',
        null,
        IMAGE_FIXTURES_DIR,
    );
    $table = $root->children[0];
    assert($table instanceof TableBox);
    $cell = $table->rows[0]->cells[0];
    expect($cell->children)->toHaveCount(2);
    [$p, $img] = $cell->children;
    assert($p instanceof BlockBox && $img instanceof ImageBox);
    expect($p->tag)->toBe('p');
    [$plain, $bold] = $p->children;
    assert($plain instanceof TextRun && $bold instanceof TextRun);
    expect($bold->style->fontWeight)->toBe(700);
});

it('builds a nested table inside a cell as a TableBox (not a plain BlockBox)', function () {
    $root = buildTree('<body><table><tr><td><table><tr><td>inner</td></tr></table></td></tr></table></body>');
    $outer = $root->children[0];
    assert($outer instanceof TableBox);
    $innerTable = $outer->rows[0]->cells[0]->children[0];
    assert($innerTable instanceof TableBox);
    $innerText = $innerTable->rows[0]->cells[0]->children[0];
    assert($innerText instanceof TextRun);
    expect($innerText->text)->toBe('inner');
});

it('treats a table as a direct flex item, never merged into the anonymous text-run wrapper', function () {
    $root = buildTree(
        '<body><div class="flex"><table><tr><td>a</td></tr></table></div></body>',
        '.flex { display: flex }',
    );
    $flex = $root->children[0];
    assert($flex instanceof BlockBox);
    expect($flex->children)->toHaveCount(1);
    expect($flex->children[0])->toBeInstanceOf(TableBox::class);
});

// --- M7-T2: white-space:pre (UA default on <pre>, ver Style\UserAgentStylesheet) --------------

it('white-space:pre preserves internal whitespace and turns literal newlines into hard line breaks', function () {
    $html = "<body><pre>line one\n  indented line\nline three</pre></body>";
    $root = buildTree($html);
    $pre = $root->children[0];
    assert($pre instanceof BlockBox);
    expect($pre->tag)->toBe('pre');
    expect($pre->style->whiteSpace)->toBe('pre');

    // 3 líneas -> 3 TextRun separados por 2 LineBreakRun reales (BoxTreeBuilder::textRunTokensFor()).
    expect($pre->children)->toHaveCount(5);
    [$l1, $br1, $l2, $br2, $l3] = $pre->children;
    assert($l1 instanceof TextRun && $l2 instanceof TextRun && $l3 instanceof TextRun);
    expect($br1)->toBeInstanceOf(LineBreakRun::class);
    expect($br2)->toBeInstanceOf(LineBreakRun::class);
    expect($l1->text)->toBe('line one');
    // Los dos espacios iniciales de "  indented line" sobreviven -- collapseInternalWhitespace()
    // NUNCA se llama bajo white-space:pre (ver textRunTokensFor()).
    expect($l2->text)->toBe('  indented line');
    expect($l3->text)->toBe('line three');
});

it('a blank line inside a <pre> (two consecutive newlines) produces no empty TextRun, just the two LineBreakRun', function () {
    $html = "<body><pre>uno\n\ndos</pre></body>";
    $root = buildTree($html);
    $pre = $root->children[0];
    assert($pre instanceof BlockBox);
    expect($pre->children)->toHaveCount(4);
    [$l1, $br1, $br2, $l2] = $pre->children;
    assert($l1 instanceof TextRun && $l2 instanceof TextRun);
    expect($br1)->toBeInstanceOf(LineBreakRun::class);
    expect($br2)->toBeInstanceOf(LineBreakRun::class);
    expect($l1->text)->toBe('uno');
    expect($l2->text)->toBe('dos');
});

it('inherits white-space:pre into a nested inline element (e.g. <code> inside <pre>)', function () {
    $html = "<body><pre><code>a\nb</code></pre></body>";
    $root = buildTree($html);
    $pre = $root->children[0];
    assert($pre instanceof BlockBox);
    // <code> es inline (INLINE_TAGS): su contenido se aplana en la secuencia del <pre>, pero
    // conserva white-space:pre por HERENCIA (ver ComputedStyle::compute()).
    expect($pre->children)->toHaveCount(3);
    [$l1, $br, $l2] = $pre->children;
    assert($l1 instanceof TextRun && $l2 instanceof TextRun);
    expect($br)->toBeInstanceOf(LineBreakRun::class);
    expect($l1->style->whiteSpace)->toBe('pre');
    expect($l1->text)->toBe('a');
    expect($l2->text)->toBe('b');
});

it('a normal (non-pre) paragraph is unaffected: internal whitespace still collapses to one space', function () {
    $root = buildTree('<body><p>uno    dos</p></body>');
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    $text = $p->children[0];
    assert($text instanceof TextRun);
    expect($text->text)->toBe('uno dos');
    expect($p->style->whiteSpace)->toBe('normal');
});

// --- M7-T2: kbd/samp/sub/sup become inline; sub/sup warn (vertical-align deferred to M8) ------

it('treats kbd/samp as inline (monospace via the UA stylesheet), not block', function () {
    $root = buildTree('<body><p>Press <kbd>Ctrl</kbd> or <samp>OK</samp>.</p></body>');
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    // Todo el contenido de <p> se aplana a una única secuencia de TextRun (kbd/samp inline,
    // fusionados/colapsados por collapse() igual que cualquier otro inline sin borde/fondo).
    foreach ($p->children as $child) {
        expect($child)->toBeInstanceOf(TextRun::class);
    }
});

it('warns exactly once per <sub>/<sup> occurrence and still renders their text inline', function () {
    [$root, $warnings] = buildTreeCollectingWarnings('<body><p>H<sub>2</sub>O<sup>+</sup></p></body>', __DIR__);
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    foreach ($p->children as $child) {
        expect($child)->toBeInstanceOf(TextRun::class);
    }
    $subWarnings = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, '<sub>')));
    $supWarnings = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, '<sup>')));
    expect($subWarnings)->toHaveCount(1);
    expect($supWarnings)->toHaveCount(1);
});

// --- M7 final-review Finding D: float/position on an inline element warn (no behavioral change) -

it('Finding D: float on an inline element (direct child) warns once and still flattens to plain inline text', function () {
    [$root, $warnings] = buildTreeCollectingWarnings(
        '<body><p>before <span class="floaty">middle</span> after</p></body>',
        __DIR__,
        '.floaty { float: left }',
    );
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    // No behavioral change: still flattens to plain TextRun(s), float never applies to an inline
    // element in this engine (InlineFlowContext never looks at $style->float).
    foreach ($p->children as $child) {
        expect($child)->toBeInstanceOf(TextRun::class);
    }
    $floatWarnings = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'float on an inline-level element')));
    expect($floatWarnings)->toHaveCount(1);
});

it('Finding D: float on a NESTED inline element (descendant, via collectInline()) warns once too', function () {
    [, $warnings] = buildTreeCollectingWarnings(
        '<body><p><span>outer <strong class="floaty">inner</strong></span></p></body>',
        __DIR__,
        '.floaty { float: left }',
    );
    $floatWarnings = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'float on an inline-level element')));
    expect($floatWarnings)->toHaveCount(1);
});

it('Finding D: position:relative on an inline element warns once and applies no offset (no behavioral change)', function () {
    [$root, $warnings] = buildTreeCollectingWarnings(
        '<body><p>before <span class="rel">middle</span> after</p></body>',
        __DIR__,
        '.rel { position: relative; top: 50px; left: 50px }',
    );
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    foreach ($p->children as $child) {
        expect($child)->toBeInstanceOf(TextRun::class);
    }
    $posWarnings = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'position:relative/absolute on an inline-level element')));
    expect($posWarnings)->toHaveCount(1);
});

it('Finding D: position:absolute on an inline element warns once too', function () {
    [, $warnings] = buildTreeCollectingWarnings(
        '<body><p>before <span class="abs">middle</span> after</p></body>',
        __DIR__,
        '.abs { position: absolute; top: 10px }',
    );
    $posWarnings = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'position:relative/absolute on an inline-level element')));
    expect($posWarnings)->toHaveCount(1);
});

it('Finding D: float/position on an inline element only warn ONCE each even with multiple occurrences (addWarningOnce dedup)', function () {
    [, $warnings] = buildTreeCollectingWarnings(
        '<body><p><span class="a">one</span> <span class="b">two</span> <span class="c">three</span></p></body>',
        __DIR__,
        '.a { float: left } .b { float: right } .c { position: relative; top: 5px }',
    );
    $floatWarnings = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'float on an inline-level element')));
    $posWarnings = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'position:relative/absolute on an inline-level element')));
    expect($floatWarnings)->toHaveCount(1);
    expect($posWarnings)->toHaveCount(1);
});

it('Finding D: a plain inline element with neither float nor position emits neither warning (no false positive)', function () {
    [, $warnings] = buildTreeCollectingWarnings('<body><p>before <span>middle</span> after</p></body>', __DIR__);
    $relevant = array_filter($warnings, static fn(string $w): bool => str_contains($w, 'inline-level element'));
    expect($relevant)->toBeEmpty();
});

// --- M8-T1 housekeeping (M7 final-review Finding D, remaining gap): float/position:absolute on a
// display:inline-block element warn (no behavioral change) -------------------------------------

it('Finding D: float on a display:inline-block element (direct child) warns once and still builds a normal BlockBox atomic token', function () {
    [$root, $warnings] = buildTreeCollectingWarnings(
        '<body><p>before <a class="btn">middle</a> after</p></body>',
        __DIR__,
        '.btn { display: inline-block; float: left }',
    );
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    // No behavioral change: the inline-block still becomes a real BlockBox token in the same
    // place as before (InlineFlowContext::layoutInlineBlockAtomic() never looks at $style->float).
    $btn = null;
    foreach ($p->children as $child) {
        if ($child instanceof BlockBox) {
            $btn = $child;
        }
    }
    expect($btn)->not->toBeNull();
    $floatWarnings = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'float on a display:inline-block element')));
    expect($floatWarnings)->toHaveCount(1);
});

it('Finding D: float on a NESTED display:inline-block element (descendant, via collectInline()) warns once too', function () {
    [, $warnings] = buildTreeCollectingWarnings(
        '<body><p><span>outer <a class="btn">inner</a></span></p></body>',
        __DIR__,
        '.btn { display: inline-block; float: left }',
    );
    $floatWarnings = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'float on a display:inline-block element')));
    expect($floatWarnings)->toHaveCount(1);
});

it('Finding D: position:absolute on a display:inline-block element warns once and still lays out as a normal atomic token', function () {
    [$root, $warnings] = buildTreeCollectingWarnings(
        '<body><p>before <a class="btn">middle</a> after</p></body>',
        __DIR__,
        '.btn { display: inline-block; position: absolute; top: 10px }',
    );
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    $btn = null;
    foreach ($p->children as $child) {
        if ($child instanceof BlockBox) {
            $btn = $child;
        }
    }
    expect($btn)->not->toBeNull();
    $posWarnings = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'position:absolute on a display:inline-block element')));
    expect($posWarnings)->toHaveCount(1);
});

it('Finding D: position:relative on a display:inline-block element does NOT warn (it already works, applied by BlockFlowContext itself)', function () {
    [, $warnings] = buildTreeCollectingWarnings(
        '<body><p>before <a class="btn">middle</a> after</p></body>',
        __DIR__,
        '.btn { display: inline-block; position: relative; top: 10px }',
    );
    $relevant = array_filter($warnings, static fn(string $w): bool => str_contains($w, 'display:inline-block element'));
    expect($relevant)->toBeEmpty();
});

it('Finding D: float/position:absolute on a display:inline-block element only warn ONCE each even with multiple occurrences (addWarningOnce dedup)', function () {
    [, $warnings] = buildTreeCollectingWarnings(
        '<body><p><a class="a">one</a> <a class="b">two</a> <a class="c">three</a></p></body>',
        __DIR__,
        '.a { display: inline-block; float: left } .b { display: inline-block; float: right } .c { display: inline-block; position: absolute; top: 5px }',
    );
    $floatWarnings = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'float on a display:inline-block element')));
    $posWarnings = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'position:absolute on a display:inline-block element')));
    expect($floatWarnings)->toHaveCount(1);
    expect($posWarnings)->toHaveCount(1);
});

it('Finding D: a plain display:inline-block with neither float nor position emits neither warning (no false positive)', function () {
    [, $warnings] = buildTreeCollectingWarnings(
        '<body><p>before <a class="btn">middle</a> after</p></body>',
        __DIR__,
        '.btn { display: inline-block }',
    );
    $relevant = array_filter($warnings, static fn(string $w): bool => str_contains($w, 'display:inline-block element'));
    expect($relevant)->toBeEmpty();
});

// --- M7-T3: <li> display:list-item + <ol start> ------------------------------------------------

it('gives <li> a Display::ListItem BlockBox via the UA stylesheet default', function () {
    $root = buildTree('<body><ul><li>a</li></ul></body>');
    $ul = $root->children[0];
    assert($ul instanceof BlockBox);
    $li = $ul->children[0];
    assert($li instanceof BlockBox);
    expect($li->style->display)->toBe(Display::ListItem);
});

it('leaves BlockBox::$listStart null for an <ol> without a start attribute', function () {
    $root = buildTree('<body><ol><li>a</li></ol></body>');
    $ol = $root->children[0];
    assert($ol instanceof BlockBox);
    expect($ol->listStart)->toBeNull();
});

it('parses a numeric start attribute on <ol> into BlockBox::$listStart', function () {
    $root = buildTree('<body><ol start="5"><li>a</li></ol></body>');
    $ol = $root->children[0];
    assert($ol instanceof BlockBox);
    expect($ol->listStart)->toBe(5);
});

it('parses a negative start attribute on <ol>', function () {
    $root = buildTree('<body><ol start="-3"><li>a</li></ol></body>');
    $ol = $root->children[0];
    assert($ol instanceof BlockBox);
    expect($ol->listStart)->toBe(-3);
});

it('treats a non-numeric start attribute on <ol> as absent (null)', function () {
    $root = buildTree('<body><ol start="abc"><li>a</li></ol></body>');
    $ol = $root->children[0];
    assert($ol instanceof BlockBox);
    expect($ol->listStart)->toBeNull();
});

// --- M8-T5 (css-text-3 §8 reducido): text-transform applied to run TEXT before measurement -----

it('leaves text untouched with text-transform: none (the default)', function () {
    $root = buildTree('<body><p>Hola mundo</p></body>');
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    $run = $p->children[0];
    assert($run instanceof TextRun);
    expect($run->text)->toBe('Hola mundo');
});

it('uppercases run text with text-transform: uppercase, preserving accented characters (á -> Á)', function () {
    $root = buildTree('<body><p>café ñoño</p></body>', 'p { text-transform: uppercase }');
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    $run = $p->children[0];
    assert($run instanceof TextRun);
    expect($run->text)->toBe('CAFÉ ÑOÑO');
});

it('lowercases run text with text-transform: lowercase, preserving accented characters', function () {
    $root = buildTree('<body><p>CAFÉ ÑOÑO</p></body>', 'p { text-transform: lowercase }');
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    $run = $p->children[0];
    assert($run instanceof TextRun);
    expect($run->text)->toBe('café ñoño');
});

it('capitalizes the first letter of each word with text-transform: capitalize (space/tab boundaries)', function () {
    $root = buildTree('<body><p>hello world</p></body>', 'p { text-transform: capitalize }');
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    $run = $p->children[0];
    assert($run instanceof TextRun);
    expect($run->text)->toBe('Hello World');
});

it('does NOT treat a hyphen as a word boundary for capitalize (documented divergence from some browsers)', function () {
    $root = buildTree('<body><p>hello-world</p></body>', 'p { text-transform: capitalize }');
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    $run = $p->children[0];
    assert($run instanceof TextRun);
    expect($run->text)->toBe('Hello-world');
});

it('capitalizes an accented first letter (á -> Á)', function () {
    $root = buildTree('<body><p>árbol alto</p></body>', 'p { text-transform: capitalize }');
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    $run = $p->children[0];
    assert($run instanceof TextRun);
    expect($run->text)->toBe('Árbol Alto');
});

it('inherits text-transform from an ancestor onto a nested inline run', function () {
    $root = buildTree('<body><p>Hola <strong>mundo</strong></p></body>', 'p { text-transform: uppercase }');
    $p = $root->children[0];
    assert($p instanceof BlockBox);
    [$first, $second] = $p->children;
    assert($first instanceof TextRun && $second instanceof TextRun);
    expect($first->text)->toBe('HOLA ');
    expect($second->text)->toBe('MUNDO');
});

it('applies text-transform to each line of a white-space:pre run independently', function () {
    $root = buildTree("<body><pre>hello world\nsecond line</pre></body>", 'pre { text-transform: capitalize }');
    $pre = $root->children[0];
    assert($pre instanceof BlockBox);
    $firstLine = $pre->children[0];
    assert($firstLine instanceof TextRun);
    expect($firstLine->text)->toBe('Hello World');
});

// --- M9-T1 housekeeping: inline style="" attributes are not supported, warn once -----------------
// This engine only parses <style> stylesheets (StyleResolver/CssStyleSource never read an
// element's own `style` attribute) -- an inline style is silently dropped with no warning until
// this task. warnIfInlineStyleAttribute() is wired into every dispatch point that inspects a real
// element, so a style="" attribute anywhere in the document (block, inline, image, table
// structure) triggers the SAME one-time warning.

it('warns once when a block element carries a style="" attribute', function () {
    [, $warnings] = buildTreeCollectingWarnings('<body><div style="color: red">text</div></body>', __DIR__);
    $relevant = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'inline style')));
    expect($relevant)->toBe(['inline style="" attributes are not supported; use a stylesheet']);
});

it('warns once when the <body> root itself carries a style="" attribute', function () {
    [, $warnings] = buildTreeCollectingWarnings('<body style="margin: 0">text</body>', __DIR__);
    $relevant = array_filter($warnings, static fn(string $w): bool => str_contains($w, 'inline style'));
    expect($relevant)->toHaveCount(1);
});

it('warns once for a style="" attribute on a plain inline element (nested, via collectInline())', function () {
    [, $warnings] = buildTreeCollectingWarnings(
        '<body><p>before <span>outer <strong style="color: blue">inner</strong></span> after</p></body>',
        __DIR__,
    );
    $relevant = array_filter($warnings, static fn(string $w): bool => str_contains($w, 'inline style'));
    expect($relevant)->toHaveCount(1);
});

it('warns once for a style="" attribute on an <img>', function () {
    [, $warnings] = buildTreeCollectingWarnings('<body><img src="tiny.jpg" style="border: 0"></body>', IMAGE_FIXTURES_DIR);
    $relevant = array_filter($warnings, static fn(string $w): bool => str_contains($w, 'inline style'));
    expect($relevant)->toHaveCount(1);
});

it('warns once for a style="" attribute on a table structure element (<td>)', function () {
    [, $warnings] = buildTreeCollectingWarnings(
        '<body><table><tr><td style="text-align: center">cell</td></tr></table></body>',
        __DIR__,
    );
    $relevant = array_filter($warnings, static fn(string $w): bool => str_contains($w, 'inline style'));
    expect($relevant)->toHaveCount(1);
});

it('warns only ONCE total even with multiple style="" attributes across the document (addWarningOnce dedup)', function () {
    [, $warnings] = buildTreeCollectingWarnings(
        '<body><div style="color: red">a</div><p>text <span style="color: blue">b</span></p></body>',
        __DIR__,
    );
    $relevant = array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'inline style')));
    expect($relevant)->toHaveCount(1);
});

it('does not warn when no element in the document has a style="" attribute (no false positive)', function () {
    [, $warnings] = buildTreeCollectingWarnings('<body><div class="foo">text <span>inline</span></div></body>', __DIR__);
    $relevant = array_filter($warnings, static fn(string $w): bool => str_contains($w, 'inline style'));
    expect($relevant)->toBeEmpty();
});
