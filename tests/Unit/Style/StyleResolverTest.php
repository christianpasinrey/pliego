<?php

declare(strict_types=1);

use Pliego\Css\StylesheetParser;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\Color;
use Pliego\Css\Value\Gradient;
use Pliego\Css\Value\GradientKind;
use Pliego\Css\Value\GradientStop;
use Pliego\Css\Value\LengthPercentage;
use Pliego\Style\AlignItems;
use Pliego\Style\CssStyleSource;
use Pliego\Style\Display;
use Pliego\Style\FlexDirection;
use Pliego\Style\FlexWrap;
use Pliego\Style\FontStyle;
use Pliego\Style\JustifyContent;
use Pliego\Style\ListStyleType;
use Pliego\Style\StyleResolver;
use Pliego\Style\TextAlign;
use Pliego\Style\VerticalAlign;

function resolveDoc(string $css, string $html): array
{
    $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $map = new StyleResolver([new CssStyleSource(new StylesheetParser()->parse($css))])->resolve($doc);
    return [$doc, $map];
}

/**
 * Igual que Engine::render(): un calc()/var() SIN var() se tipa en tiempo de parseo (fast path,
 * warnings en ParseResult::warnings) — solo las declaraciones CON var() se difieren a
 * StyleResolver (warnings en el WarningCollector compartido). Esta función fusiona ambas fuentes,
 * como hace Engine, para que un test no tenga que saber por qué ruta pasó cada warning.
 *
 * @return array{0: \Dom\HTMLDocument, 1: Pliego\Style\StyleMap, 2: list<string>}
 */
function resolveDocWithWarnings(string $css, string $html): array
{
    $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $warnings = new \Pliego\Css\WarningCollector();
    $parseResult = new StylesheetParser()->parse($css);
    $map = new StyleResolver([new CssStyleSource($parseResult)], $warnings)->resolve($doc);
    return [$doc, $map, [...$parseResult->warnings, ...$warnings->drain()]];
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
    expect($map->get($p)->paddingLeft->value)->toBe(0.0);
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

it('defaults u elements to underline', function () {
    [$doc, $map] = resolveDoc('', '<body><p><u>x</u></p></body>');
    $u = $doc->querySelector('u');
    assert($u !== null);
    expect($map->get($u)->underline)->toBeTrue();
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
    expect($style->paddingLeft->value)->toBe(0.0);
    expect($style->fontWeight)->toBe(400);
    expect($style->fontStyle)->toBe(FontStyle::Normal);
    expect($style->textAlign)->toBe(TextAlign::Left);
    expect($style->underline)->toBeFalse();
    expect($style->lineHeightPx)->toBeNull();
});

// --- M2-T3: bordes, box-sizing y % en ComputedStyle -------------------------------------

it('computes all 4 border sides from the border shorthand', function () {
    [$doc, $map] = resolveDoc('p { border: 2px solid #ccc }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    $style = $map->get($p);
    foreach (['borderTop', 'borderRight', 'borderBottom', 'borderLeft'] as $side) {
        expect($style->$side->widthPx)->toBe(2.0);
        expect($style->$side->style)->toBe(BorderStyle::Solid);
        expect($style->$side->color)->toEqual(new Color(204, 204, 204));
    }
});

it('defaults border sides to none/0 when no border is declared', function () {
    [$doc, $map] = resolveDoc('', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    $style = $map->get($p);
    foreach (['borderTop', 'borderRight', 'borderBottom', 'borderLeft'] as $side) {
        expect($style->$side->widthPx)->toBe(0.0);
        expect($style->$side->style)->toBe(BorderStyle::None);
    }
});

it('resolves a border with no color declared to the element computed color (currentColor)', function () {
    [$doc, $map] = resolveDoc('p { color: #f00; border-top-style: solid; border-top-width: 3px }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    $style = $map->get($p);
    expect($style->borderTop->color)->toEqual(new Color(255, 0, 0));
});

// M6-T5: 'currentColor' as an EXPLICIT declared value (not just the implicit default above) —
// background-color/border-*-color resolve to the element's OWN computed color; 'color:
// currentColor' resolves to the INHERITED (parent) color instead (css-color-3 §4.4: it can't
// refer to itself).

it('resolves an explicit background-color:currentColor to the element computed color', function () {
    [$doc, $map] = resolveDoc('p { color: #f00; background-color: currentColor }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->backgroundColor)->toEqual(new Color(255, 0, 0));
});

it('resolves an explicit border-top-color:currentColor to the element computed color', function () {
    [$doc, $map] = resolveDoc(
        'p { color: #0f0; border-top-style: solid; border-top-width: 2px; border-top-color: currentColor }',
        '<body><p>x</p></body>',
    );
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->borderTop->color)->toEqual(new Color(0, 255, 0));
});

it('resolves color:currentColor to the INHERITED color, not a self-reference', function () {
    [$doc, $map] = resolveDoc('body { color: #00f } p { color: currentColor }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->color)->toEqual(new Color(0, 0, 255));
});

// M6-T5: opacity — non-inherited (initial value 1.0 regardless of the parent's own opacity).

it('defaults opacity to 1.0 and does not inherit a parent opacity', function () {
    [$doc, $map] = resolveDoc('body { opacity: 0.3 }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    $body = $doc->querySelector('body');
    assert($p !== null && $body !== null);
    expect($map->get($body)->opacity)->toBe(0.3);
    expect($map->get($p)->opacity)->toBe(1.0);
});

it('resolves a declared opacity, clamped to [0,1]', function () {
    [$doc, $map] = resolveDoc('p { opacity: 0.5 }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->opacity)->toBe(0.5);
});

it('zeroes the used border width when border-style is none (CSS 2.2 §8.5.3)', function () {
    [$doc, $map] = resolveDoc('p { border-top-width: 10px }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    $style = $map->get($p);
    expect($style->borderTop->style)->toBe(BorderStyle::None);
    expect($style->borderTop->widthPx)->toBe(0.0);
});

it('keeps the declared border width when border-style is solid', function () {
    [$doc, $map] = resolveDoc('p { border-top: 10px solid #000 }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    $style = $map->get($p);
    expect($style->borderTop->style)->toBe(BorderStyle::Solid);
    expect($style->borderTop->widthPx)->toBe(10.0);
});

it('defaults box-sizing to content-box and does not inherit it', function () {
    [$doc, $map] = resolveDoc('body { box-sizing: border-box }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    $body = $doc->querySelector('body');
    assert($p !== null && $body !== null);
    expect($map->get($body)->boxSizing)->toBe('border-box');
    expect($map->get($p)->boxSizing)->toBe('content-box');
});

it('applies box-sizing border-box when declared on the element itself', function () {
    [$doc, $map] = resolveDoc('p { box-sizing: border-box }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->boxSizing)->toBe('border-box');
});

it('keeps width % unresolved on ComputedStyle (used-value resolution is T4)', function () {
    [$doc, $map] = resolveDoc('p { width: 50% }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    $width = $map->get($p)->width;
    expect($width)->not->toBeNull();
    expect($width->isPercent)->toBeTrue();
    expect($width->value)->toBe(50.0);
});

// --- M3-T3: height (Length, no %, no inheritance) ----------------------------------------------

it('computes a declared height as a plain Length (px)', function () {
    [$doc, $map] = resolveDoc('img { height: 30px }', '<body><img></body>');
    $img = $doc->querySelector('img');
    assert($img !== null);
    expect($map->get($img)->height?->px)->toBe(30.0);
});

it('defaults height to null (auto) when not declared', function () {
    [$doc, $map] = resolveDoc('', '<body><img></body>');
    $img = $doc->querySelector('img');
    assert($img !== null);
    expect($map->get($img)->height)->toBeNull();
});

it('does not inherit height from the parent', function () {
    [$doc, $map] = resolveDoc('div { height: 100px }', '<body><div><img></div></body>');
    $img = $doc->querySelector('img');
    assert($img !== null);
    expect($map->get($img)->height)->toBeNull();
});

it('rejects a percentage height at parse time, leaving ComputedStyle::$height null (auto)', function () {
    // Height is LENGTH_PROPERTIES in DeclarationParser (Length, not LengthPercentage): "50%"
    // never parses into a value, so it never reaches ComputedStyle — the brief's adjudication
    // ("% height -> warning + auto") is enforced here, at the parser boundary.
    [$doc, $map] = resolveDoc('img { height: 50% }', '<body><img></body>');
    $img = $doc->querySelector('img');
    assert($img !== null);
    expect($map->get($img)->height)->toBeNull();
});

// --- M4-T1: flex properties on ComputedStyle (none inherit) -------------------------------

it('computes display:flex as Display::Flex', function () {
    [$doc, $map] = resolveDoc('div { display: flex }', '<body><div>x</div></body>');
    $div = $doc->querySelector('div');
    assert($div !== null);
    expect($map->get($div)->display)->toBe(Display::Flex);
});

it('defaults every flex property to its spec initial value, none inherited', function () {
    [$doc, $map] = resolveDoc(
        'body { flex-direction: column; flex-wrap: wrap; justify-content: center; align-items: center;'
        . ' row-gap: 10px; column-gap: 5px; flex-grow: 2; flex-shrink: 3; flex-basis: 40px }',
        '<body><p>x</p></body>',
    );
    $body = $doc->querySelector('body');
    $p = $doc->querySelector('p');
    assert($body !== null && $p !== null);

    $bodyStyle = $map->get($body);
    expect($bodyStyle->flexDirection)->toBe(FlexDirection::Column);
    expect($bodyStyle->flexWrap)->toBe(FlexWrap::Wrap);
    expect($bodyStyle->justifyContent)->toBe(JustifyContent::Center);
    expect($bodyStyle->alignItems)->toBe(AlignItems::Center);
    expect($bodyStyle->rowGapPx)->toBe(10.0);
    expect($bodyStyle->columnGapPx)->toBe(5.0);
    expect($bodyStyle->flexGrow)->toBe(2.0);
    expect($bodyStyle->flexShrink)->toBe(3.0);
    expect($bodyStyle->flexBasis)->toEqual(LengthPercentage::px(40.0));

    // None of these properties inherit (css-flexbox-1: item properties + container-only
    // properties are all non-inherited): the child falls back to the spec initial values,
    // not the parent's declared ones.
    $pStyle = $map->get($p);
    expect($pStyle->flexDirection)->toBe(FlexDirection::Row);
    expect($pStyle->flexWrap)->toBe(FlexWrap::NoWrap);
    expect($pStyle->justifyContent)->toBe(JustifyContent::FlexStart);
    expect($pStyle->alignItems)->toBe(AlignItems::Stretch);
    expect($pStyle->rowGapPx)->toBe(0.0);
    expect($pStyle->columnGapPx)->toBe(0.0);
    expect($pStyle->flexGrow)->toBe(0.0);
    expect($pStyle->flexShrink)->toBe(1.0);
    expect($pStyle->flexBasis)->toBeNull();
});

it('computes the flex shorthand end to end through DeclarationParser + ComputedStyle', function () {
    [$doc, $map] = resolveDoc('div { flex: 2 30px }', '<body><div>x</div></body>');
    $div = $doc->querySelector('div');
    assert($div !== null);
    $style = $map->get($div);
    expect($style->flexGrow)->toBe(2.0);
    expect($style->flexShrink)->toBe(1.0);
    expect($style->flexBasis)->toEqual(LengthPercentage::px(30.0));
});

// --- M5-T2: UA table display defaults, border-spacing, table-layout, vertical-align ------

it('defaults table/tr/td/thead/tbody to their table display UA values', function () {
    [$doc, $map] = resolveDoc(
        '',
        '<body><table><thead><tr><th>h</th></tr></thead><tbody><tr><td>d</td></tr></tbody></table></body>',
    );
    $table = $doc->querySelector('table');
    $thead = $doc->querySelector('thead');
    $tbody = $doc->querySelector('tbody');
    $tr = $doc->querySelectorAll('tr');
    $th = $doc->querySelector('th');
    $td = $doc->querySelector('td');
    assert($table !== null && $thead !== null && $tbody !== null && $th !== null && $td !== null);
    expect($map->get($table)->display)->toBe(Display::Table);
    expect($map->get($thead)->display)->toBe(Display::TableHeaderGroup);
    expect($map->get($tbody)->display)->toBe(Display::TableRowGroup);
    foreach ($tr as $row) {
        expect($map->get($row)->display)->toBe(Display::TableRow);
    }
    expect($map->get($th)->display)->toBe(Display::TableCell);
    expect($map->get($td)->display)->toBe(Display::TableCell);
});

it('lets author declarations override the UA table display defaults (cascade)', function () {
    [$doc, $map] = resolveDoc('td { display: block }', '<body><table><tr><td>x</td></tr></table></body>');
    $td = $doc->querySelector('td');
    assert($td !== null);
    expect($map->get($td)->display)->toBe(Display::Block);
});

it('computes display:table/table-row/table-cell/table-header-group/table-row-group from declarations on non-table tags', function () {
    [$doc, $map] = resolveDoc(
        'div { display: table } span { display: table-row-group }',
        '<body><div>x</div><span>y</span></body>',
    );
    $div = $doc->querySelector('div');
    $span = $doc->querySelector('span');
    assert($div !== null && $span !== null);
    expect($map->get($div)->display)->toBe(Display::Table);
    expect($map->get($span)->display)->toBe(Display::TableRowGroup);
});

it('defaults th to font-weight 700 and text-align center via UA default, td stays normal/left', function () {
    [$doc, $map] = resolveDoc('', '<body><table><tr><th>h</th><td>d</td></tr></table></body>');
    $th = $doc->querySelector('th');
    $td = $doc->querySelector('td');
    assert($th !== null && $td !== null);
    expect($map->get($th)->fontWeight)->toBe(700);
    expect($map->get($th)->textAlign)->toBe(TextAlign::Center);
    expect($map->get($td)->fontWeight)->toBe(400);
    expect($map->get($td)->textAlign)->toBe(TextAlign::Left);
});

it('lets author declarations override the th UA defaults', function () {
    [$doc, $map] = resolveDoc(
        'th { font-weight: normal; text-align: right }',
        '<body><table><tr><th>h</th></tr></table></body>',
    );
    $th = $doc->querySelector('th');
    assert($th !== null);
    expect($map->get($th)->fontWeight)->toBe(400);
    expect($map->get($th)->textAlign)->toBe(TextAlign::Right);
});

it('defaults border-spacing to 0 and inherits the declared px value down the tree (CSS 2.2 §17.6.1)', function () {
    [$doc, $map] = resolveDoc('table { border-spacing: 6px }', '<body><table><tr><td>x</td></tr></table></body>');
    $table = $doc->querySelector('table');
    $td = $doc->querySelector('td');
    assert($table !== null && $td !== null);
    expect($map->get($table)->borderSpacingPx)->toBe(6.0);
    // border-spacing SÍ hereda: td no declara nada propio, así que hereda el valor de table.
    expect($map->get($td)->borderSpacingPx)->toBe(6.0);

    [$docPlain, $mapPlain] = resolveDoc('', '<body><table><tr><td>x</td></tr></table></body>');
    $tablePlain = $docPlain->querySelector('table');
    assert($tablePlain !== null);
    expect($mapPlain->get($tablePlain)->borderSpacingPx)->toBe(0.0);
});

it('warns and drops a two-value border-spacing at parse time (single value only in M5)', function () {
    [$doc, $map] = resolveDoc('table { border-spacing: 4px 8px }', '<body><table></table></body>');
    $table = $doc->querySelector('table');
    assert($table !== null);
    expect($map->get($table)->borderSpacingPx)->toBe(0.0);
});

it('defaults table-layout to auto and does not inherit a declared fixed value', function () {
    [$doc, $map] = resolveDoc('table { table-layout: fixed }', '<body><table><tr><td>x</td></tr></table></body>');
    $table = $doc->querySelector('table');
    $td = $doc->querySelector('td');
    assert($table !== null && $td !== null);
    expect($map->get($table)->tableLayout)->toBe('fixed');
    // table-layout NO hereda: td cae al initial value 'auto', no al 'fixed' del padre.
    expect($map->get($td)->tableLayout)->toBe('auto');
});

it('defaults vertical-align to Top and does not inherit a declared value', function () {
    [$doc, $map] = resolveDoc(
        'td { vertical-align: middle }',
        '<body><table><tr><td>x<span>y</span></td></tr></table></body>',
    );
    $td = $doc->querySelector('td');
    $span = $doc->querySelector('span');
    assert($td !== null && $span !== null);
    expect($map->get($td)->verticalAlign)->toBe(VerticalAlign::Middle);
    // vertical-align NO hereda: span cae al default Top, no a Middle del padre.
    expect($map->get($span)->verticalAlign)->toBe(VerticalAlign::Top);
});

// --- M6-T2: real combinator matching (M6-T1 staging lifted) ------------------------------------

it('applies a descendant-combinator rule now that matching is real', function () {
    [$doc, $map] = resolveDoc('ul li { color: #00f }', '<body><ul><li>x</li></ul></body>');
    $li = $doc->querySelector('li');
    assert($li !== null);
    expect($map->get($li)->color)->toEqual(new Color(0, 0, 255));
});

it('matches multiple classes on the same compound (.a.b), a real behavior improvement over M0', function () {
    [$doc, $map] = resolveDoc('.a.b { color: #0f0 }', '<body><p class="a b">x</p><p class="a">y</p></body>');
    $withBoth = $doc->querySelectorAll('p')[0];
    $withOne = $doc->querySelectorAll('p')[1];
    assert($withBoth !== null && $withOne !== null);
    expect($map->get($withBoth)->color)->toEqual(new Color(0, 255, 0));
    expect($map->get($withOne)->color)->toEqual(new Color(0, 0, 0));
});

it('computes vertical-align top/bottom from declarations', function () {
    [$doc, $map] = resolveDoc(
        'td.a { vertical-align: top } td.b { vertical-align: bottom }',
        '<body><table><tr><td class="a">x</td><td class="b">y</td></tr></table></body>',
    );
    $a = $doc->querySelector('td.a');
    $b = $doc->querySelector('td.b');
    assert($a !== null && $b !== null);
    expect($map->get($a)->verticalAlign)->toBe(VerticalAlign::Top);
    expect($map->get($b)->verticalAlign)->toBe(VerticalAlign::Bottom);
});

// --- M6-T3: em/rem/pt/cm/mm/in resolved at computed-value time (css-values-3 §5-6) -------

it('resolves em in padding against the element\'s own font-size (2em @ 20px -> 40)', function () {
    [$doc, $map] = resolveDoc('p { font-size: 20px; padding-left: 2em }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->paddingLeft->value)->toBe(40.0);
});

it('resolves font-size in em against the PARENT font-size, never its own (the classic trap case)', function () {
    [$doc, $map] = resolveDoc('body { font-size: 10px } p { font-size: 2em }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->fontSizePx)->toBe(20.0);
});

it('resolves font-size in % against the parent font-size (150% of 10px -> 15px, warning gone)', function () {
    [$doc, $map] = resolveDoc('body { font-size: 10px } p { font-size: 150% }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->fontSizePx)->toBe(15.0);
});

it('resolves line-height in % against its own already-computed font-size (120% of 20px -> 24)', function () {
    [$doc, $map] = resolveDoc('p { font-size: 20px; line-height: 120% }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->lineHeightPx)->toBe(24.0);
});

it('defaults rem to the 16px initial value when the root has no declared font-size', function () {
    [$doc, $map] = resolveDoc('p { padding-left: 1rem }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->paddingLeft->value)->toBe(16.0);
});

it('threads html { font-size } as the rem base everywhere, even under a descendant with its own font-size', function () {
    [$doc, $map] = resolveDoc(
        'html { font-size: 20px } div { font-size: 10px } p { padding-left: 1rem }',
        '<body><div><p>x</p></div></body>',
    );
    $div = $doc->querySelector('div');
    $p = $doc->querySelector('p');
    assert($div !== null && $p !== null);
    // div's own font-size (10px) never leaks into rem resolution for its descendants.
    expect($map->get($div)->fontSizePx)->toBe(10.0);
    expect($map->get($p)->paddingLeft->value)->toBe(20.0);
});

it('resolves rem on the root\'s own font-size against the 16px initial value, not against itself (css-values-3 §5.2)', function () {
    [$doc, $map] = resolveDoc('html { font-size: 2rem }', '<body><p>x</p></body>');
    $html = $doc->documentElement;
    $p = $doc->querySelector('p');
    assert($html !== null && $p !== null);
    expect($map->get($html)->fontSizePx)->toBe(32.0);
    // Inherited down through body -> p (neither declares its own font-size).
    expect($map->get($p)->fontSizePx)->toBe(32.0);
});

it('folds physical units (in/pt/cm) to their exact px factor at parse time, visible on width', function () {
    [$doc, $map] = resolveDoc(
        '.a { width: 1in } .b { width: 1pt } .c { width: 1cm }',
        '<body><p class="a">a</p><p class="b">b</p><p class="c">c</p></body>',
    );
    $a = $doc->querySelector('.a');
    $b = $doc->querySelector('.b');
    $c = $doc->querySelector('.c');
    assert($a !== null && $b !== null && $c !== null);
    expect($map->get($a)->width?->value)->toBe(96.0);
    expect($map->get($b)->width?->value)->toBe(96.0 / 72.0);
    expect($map->get($c)->width?->value)->toBe(96.0 / 2.54);
});

it('mixes em/rem/px/% in the margin shorthand ("1em 2rem 10px 5%")', function () {
    [$doc, $map] = resolveDoc(
        'html { font-size: 20px } p { font-size: 10px; margin: 1em 2rem 10px 5% }',
        '<body><p>x</p></body>',
    );
    $p = $doc->querySelector('p');
    assert($p !== null);
    $style = $map->get($p);
    // top: 1em against p's own font-size (10px) -> 10
    expect($style->marginTop->value)->toBe(10.0);
    // right: 2rem against html's font-size (20px) -> 40
    expect($style->marginRight->isPercent)->toBeFalse();
    expect($style->marginRight->value)->toBe(40.0);
    // bottom: plain px, unaffected
    expect($style->marginBottom->value)->toBe(10.0);
    // left: %, still deferred to layout (LengthPercentage::percent, never resolved here)
    expect($style->marginLeft->isPercent)->toBeTrue();
    expect($style->marginLeft->value)->toBe(5.0);
});

// --- M6-T4: custom properties (var()) + calc() end-to-end (css-variables-1 §2-3, css-values-3 §8) --

it('resolves a var() with no fallback needed against a :root custom property (the --bs pattern)', function () {
    [$doc, $map] = resolveDoc(':root { --bs-primary: #0d6efd; } p { color: var(--bs-primary); }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->color)->toEqual(new Color(13, 110, 253));
});

it('falls back to the fallback value when the custom property is undeclared', function () {
    [$doc, $map] = resolveDoc('p { color: var(--missing, #ff0000); }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->color)->toEqual(new Color(255, 0, 0));
});

it('resolves a fallback chain with a nested var() inside the fallback', function () {
    [$doc, $map] = resolveDoc(
        ':root { --b: #00ff00; } p { color: var(--a, var(--b, #0000ff)); }',
        '<body><p>x</p></body>',
    );
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->color)->toEqual(new Color(0, 255, 0));
});

it('inherits a custom property three levels down and resolves var() at the leaf', function () {
    [$doc, $map] = resolveDoc(
        ':root { --sp: 20px; }',
        '<body><div><section><p>x</p></section></div></body>',
    );
    // Nothing actually USES --sp yet at any level; declare the usage only at the leaf to prove
    // the value survived two levels of pure inheritance (body -> div -> section -> p) untouched.
    [$doc2, $map2] = resolveDoc(
        ':root { --sp: 20px; } p { padding-left: var(--sp); }',
        '<body><div><section><p>x</p></section></div></body>',
    );
    $p2 = $doc2->querySelector('p');
    assert($p2 !== null);
    expect($map2->get($p2)->paddingLeft->value)->toBe(20.0);
});

it('lets a higher-specificity override win over a custom property default', function () {
    [$doc, $map] = resolveDoc(
        ':root { --c: #ff0000; } p { color: var(--c); } p.override { color: #00ff00; }',
        '<body><p class="override">x</p></body>',
    );
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->color)->toEqual(new Color(0, 255, 0));
});

it('drops a declaration referencing an unknown custom property with no fallback, with a warning', function () {
    [$doc, $map, $warnings] = resolveDocWithWarnings('p { color: var(--missing); }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    // color falls back to its initial value (black) — the declaration never took effect.
    expect($map->get($p)->color)->toEqual(new Color(0, 0, 0));
    expect($warnings)->not->toBeEmpty();
});

it('detects a direct custom property cycle and drops both usages, with warnings', function () {
    [$doc, $map, $warnings] = resolveDocWithWarnings(
        ':root { --a: var(--b); --b: var(--a); } p { color: var(--a); } span { background-color: var(--b); }',
        '<body><p>x</p><span>y</span></body>',
    );
    $p = $doc->querySelector('p');
    $span = $doc->querySelector('span');
    assert($p !== null && $span !== null);
    expect($map->get($p)->color)->toEqual(new Color(0, 0, 0));
    expect($map->get($span)->backgroundColor)->toBeNull();
    expect($warnings)->not->toBeEmpty();
});

it('resolves var() inside a shorthand, expanding correctly ("margin: var(--sp) 10px")', function () {
    [$doc, $map] = resolveDoc(
        ':root { --sp: 6px; } p { margin: var(--sp) 10px; }',
        '<body><p>x</p></body>',
    );
    $p = $doc->querySelector('p');
    assert($p !== null);
    $style = $map->get($p);
    expect($style->marginTop->value)->toBe(6.0);
    expect($style->marginRight->value)->toBe(10.0);
    expect($style->marginBottom->value)->toBe(6.0);
    expect($style->marginLeft->value)->toBe(10.0);
});

it('keeps the cascade order correct: a later, more specific longhand still wins over an earlier var() shorthand', function () {
    [$doc, $map] = resolveDoc(
        ':root { --sp: 6px; } p { margin: var(--sp) 10px; } p.win { margin-top: 99px; }',
        '<body><p class="win">x</p></body>',
    );
    $p = $doc->querySelector('p');
    assert($p !== null);
    $style = $map->get($p);
    expect($style->marginTop->value)->toBe(99.0);
    // The other 3 sides still come from the var() shorthand expansion.
    expect($style->marginRight->value)->toBe(10.0);
    expect($style->marginBottom->value)->toBe(6.0);
    expect($style->marginLeft->value)->toBe(10.0);
});

it('resolves calc() precedence end to end: "(2 + 3) * 4px" -> 20px', function () {
    [$doc, $map] = resolveDoc('p { padding-left: calc((2 + 3) * 4px) }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->paddingLeft->value)->toBe(20.0);
});

it('resolves calc() with mixed units and no parens: "2px + 3px * 2" -> 8px', function () {
    [$doc, $map] = resolveDoc('p { padding-left: calc(2px + 3px * 2) }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->paddingLeft->value)->toBe(8.0);
});

it('resolves calc() with em against the font-size in effect: "1em + 4px" at font-size 20 -> 24', function () {
    [$doc, $map] = resolveDoc('p { font-size: 20px; padding-left: calc(1em + 4px) }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->paddingLeft->value)->toBe(24.0);
});

it('defers calc() with % to Layout (LengthPercentage::calc), resolving "calc(100% - 20px)" against 400 -> 380', function () {
    [$doc, $map] = resolveDoc('p { width: calc(100% - 20px) }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    $width = $map->get($p)->width;
    expect($width)->not->toBeNull();
    expect($width->resolve(400.0))->toBe(380.0);
});

it('warns and drops a division-by-zero calc()', function () {
    [$doc, $map, $warnings] = resolveDocWithWarnings('p { padding-left: calc(10px / 0) }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->paddingLeft->value)->toBe(0.0);
    expect($warnings)->not->toBeEmpty();
});

it('combines var() and calc() end to end: "--w: 50%; width: calc(var(--w) - 10px)"', function () {
    [$doc, $map] = resolveDoc(':root { --w: 50%; } p { width: calc(var(--w) - 10px) }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    $width = $map->get($p)->width;
    expect($width)->not->toBeNull();
    expect($width->resolve(400.0))->toBe(190.0);
});

// --- M6-T4 fix, finding 1: bare-decimal numbers in calc() (Bootstrap's literal spacer pattern) --

it('THE acceptance probe: accepts calc(var(--bs-spacing) * .5) — Bootstrap spacer pattern — with zero warnings', function () {
    [$doc, $map, $warnings] = resolveDocWithWarnings(
        ':root { --bs-spacing: 1rem; } .btn { padding: calc(var(--bs-spacing) * .5) }',
        '<body><p class="btn">x</p></body>',
    );
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->paddingLeft->value)->toBe(8.0);
    expect($warnings)->toBeEmpty();
});

// --- M6-T4 fix, finding 2: calc() sign check for non-negative properties ------------------------

it('rejects a calc() with em that resolves negative for a non-negative property at compute time: padding-left:calc(-1em) at font-size 16', function () {
    [$doc, $map, $warnings] = resolveDocWithWarnings(
        'p { font-size: 16px; padding-left: calc(-1em) }',
        '<body><p>x</p></body>',
    );
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->paddingLeft->value)->toBe(0.0);
    expect($warnings)->not->toBeEmpty();
});

it('accepts a %-bearing calc() for a non-negative property end to end without a sign warning (documented gap): padding-left:calc(10% - 999px)', function () {
    [$doc, $map, $warnings] = resolveDocWithWarnings('p { padding-left: calc(10% - 999px) }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    $paddingLeft = $map->get($p)->paddingLeft;
    expect($paddingLeft->resolve(400.0))->toBe(-959.0);
    expect($warnings)->toBeEmpty();
});

// --- M6 final-review fix, finding 1: !important wins the cascade (CSS 2.2 §6.4.2) ---------

it('THE probe: an !important declaration of lower specificity beats a normal declaration of higher specificity', function () {
    [$doc, $map] = resolveDoc(
        '.b { color: red !important } #x { color: blue }',
        '<body><div id="x" class="b">y</div></body>',
    );
    $div = $doc->querySelector('#x');
    assert($div !== null);
    expect($map->get($div)->color)->toEqual(new Color(255, 0, 0));
});

it('lets specificity decide between two !important declarations (both important, higher specificity wins)', function () {
    [$doc, $map] = resolveDoc(
        '.b { color: red !important } #x { color: blue !important }',
        '<body><div id="x" class="b">y</div></body>',
    );
    $div = $doc->querySelector('#x');
    assert($div !== null);
    expect($map->get($div)->color)->toEqual(new Color(0, 0, 255));
});

it('lets a normal declaration with no !important keep losing to specificity (no regression when nothing is important)', function () {
    [$doc, $map] = resolveDoc(
        '.b { color: red } #x { color: blue }',
        '<body><div id="x" class="b">y</div></body>',
    );
    $div = $doc->querySelector('#x');
    assert($div !== null);
    expect($map->get($div)->color)->toEqual(new Color(0, 0, 255));
});

it('lets an !important declaration win over a LATER normal declaration of the same specificity (tier beats source order too)', function () {
    [$doc, $map] = resolveDoc('p { color: red !important } p { color: blue }', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->color)->toEqual(new Color(255, 0, 0));
});

it('lets an !important var() declaration beat a later, more specific normal declaration', function () {
    [$doc, $map] = resolveDoc(
        ':root { --c: #ff0000; } p { color: var(--c) !important; } p.override { color: #0000ff; }',
        '<body><p class="override">x</p></body>',
    );
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->color)->toEqual(new Color(255, 0, 0));
});

// --- M7-T1 housekeeping, finding 2: IACVT (css-variables-1 §3) — a var() with no fallback and
// no matching custom property must compute to INHERIT (inherited properties) / INITIAL
// (everything else), never to whatever a PREVIOUS, lower-priority rule already set for the same
// property. Review probe: "p{color:red} p{color:var(--missing)}" used to stay red.

it('THE probe: an unresolved var() with no fallback falls back to the inherited value, not the earlier cascade winner (inherited property)', function () {
    [$doc, $map, $warnings] = resolveDocWithWarnings(
        'body { color: blue } p { color: red } p { color: var(--missing) }',
        '<body><p>x</p></body>',
    );
    $p = $doc->querySelector('p');
    assert($p !== null);
    // color inherits: falls to the parent's computed color (blue), NOT the earlier "red" winner.
    expect($map->get($p)->color)->toEqual(new Color(0, 0, 255));
    expect($warnings)->not->toBeEmpty();
});

it('an unresolved var() with no fallback falls back to the initial value for a non-inherited property, not the earlier cascade winner', function () {
    [$doc, $map, $warnings] = resolveDocWithWarnings(
        'p { background-color: red } p { background-color: var(--missing) }',
        '<body><p>x</p></body>',
    );
    $p = $doc->querySelector('p');
    assert($p !== null);
    // background-color does not inherit; its initial value is "no background" (null), not red.
    expect($map->get($p)->backgroundColor)->toBeNull();
    expect($warnings)->not->toBeEmpty();
});

// --- M7-T1 housekeeping, finding 6: VarResolver never substitutes a var( that is written
// literally INSIDE a quoted string value of a custom property (css-syntax-3: a string token is
// opaque). There is no `content` property in this engine, so the probe uses font-family (any
// string is accepted there, see DeclarationParser::KEYWORD_PROPERTIES['font-family']).

it('keeps a var() literally inside a quoted custom property value un-substituted end to end (font-family)', function () {
    [$doc, $map] = resolveDoc(
        ':root { --a: "var(--b)"; --b: Arial; } p { font-family: var(--a); }',
        '<body><p>x</p></body>',
    );
    $p = $doc->querySelector('p');
    assert($p !== null);
    // font-family trims the surrounding quotes but must NOT have substituted --b: the literal
    // text "var(--b)" survives, proving --b (Arial) was never touched. (M7-T2: font-family is now
    // a fallback list — a single un-substituted name still yields a one-element list.)
    expect($map->get($p)->fontFamily)->toBe(['var(--b)']);
});

it('unsets every longhand of a shorthand when its var() substitution fails, not just the ones the fallback text would have produced', function () {
    [$doc, $map, $warnings] = resolveDocWithWarnings(
        'p { margin: 5px } p { margin: var(--missing) }',
        '<body><p>x</p></body>',
    );
    $p = $doc->querySelector('p');
    assert($p !== null);
    $style = $map->get($p);
    // margin does not inherit: every side falls back to the initial value 0, not the earlier 5px.
    expect($style->marginTop->value)->toBe(0.0);
    expect($style->marginRight->value)->toBe(0.0);
    expect($style->marginBottom->value)->toBe(0.0);
    expect($style->marginLeft->value)->toBe(0.0);
    expect($warnings)->not->toBeEmpty();
});

// --- M7-T2: complete UA stylesheet (CSS 2.2 Appendix D, adapted) + cascade origin ---------------

it('sizes and bolds h1..h6 per the UA stylesheet (2/1.5/1.17/1/.83/.75 em)', function () {
    [$doc, $map] = resolveDoc(
        '',
        '<body><h1>1</h1><h2>2</h2><h3>3</h3><h4>4</h4><h5>5</h5><h6>6</h6></body>',
    );
    $expectedEm = ['h1' => 2.0, 'h2' => 1.5, 'h3' => 1.17, 'h4' => 1.0, 'h5' => 0.83, 'h6' => 0.75];
    foreach ($expectedEm as $tag => $em) {
        $el = $doc->querySelector($tag);
        assert($el !== null);
        $style = $map->get($el);
        expect($style->fontSizePx)->toBe($em * 16.0);
        expect($style->fontWeight)->toBe(700);
    }
});

it('sizes h1..h6 top/bottom margins per the UA stylesheet, relative to each heading\'s OWN font-size', function () {
    [$doc, $map] = resolveDoc('', '<body><h1>1</h1><h6>6</h6></body>');
    $h1 = $doc->querySelector('h1');
    $h6 = $doc->querySelector('h6');
    assert($h1 !== null && $h6 !== null);
    // h1: font-size 2em de 16px = 32px; margin .67em 0 -> .67 * 32 = 21.44px arriba/abajo.
    expect($map->get($h1)->marginTop->value)->toBe(0.67 * 32.0);
    expect($map->get($h1)->marginBottom->value)->toBe(0.67 * 32.0);
    expect($map->get($h1)->marginLeft->value)->toBe(0.0);
    // h6: font-size .75em de 16px = 12.0px; margin 1.67em 0 -> 1.67 * 12.0 = 20.04px.
    expect($map->get($h6)->marginTop->value)->toEqualWithDelta(1.67 * (0.75 * 16.0), 0.0001);
});

it('gives p/ul/ol/dl a 1em 0 margin and blockquote/figure a 1em 40px margin', function () {
    [$doc, $map] = resolveDoc(
        '',
        '<body><p>p</p><ul><li>u</li></ul><ol><li>o</li></ol><dl><dt>d</dt></dl>'
        . '<blockquote>b</blockquote><figure>f</figure></body>',
    );
    foreach (['p', 'ul', 'ol', 'dl'] as $tag) {
        $el = $doc->querySelector($tag);
        assert($el !== null);
        $style = $map->get($el);
        expect($style->marginTop->value)->toBe(16.0);
        expect($style->marginBottom->value)->toBe(16.0);
        expect($style->marginLeft->value)->toBe(0.0);
        expect($style->marginRight->value)->toBe(0.0);
    }
    foreach (['blockquote', 'figure'] as $tag) {
        $el = $doc->querySelector($tag);
        assert($el !== null);
        $style = $map->get($el);
        expect($style->marginTop->value)->toBe(16.0);
        expect($style->marginBottom->value)->toBe(16.0);
        expect($style->marginLeft->value)->toBe(40.0);
        expect($style->marginRight->value)->toBe(40.0);
    }
});

it('gives ul/ol a 40px left padding (M7-T2; the padding is what fits the M7-T3 marker into later)', function () {
    [$doc, $map] = resolveDoc('', '<body><ul><li>x</li></ul></body>');
    $ul = $doc->querySelector('ul');
    assert($ul !== null);
    expect($map->get($ul)->paddingLeft->value)->toBe(40.0);
});

it('gives pre a monospace font, 1em 0 margin and white-space:pre', function () {
    [$doc, $map] = resolveDoc('', '<body><pre>x</pre></body>');
    $pre = $doc->querySelector('pre');
    assert($pre !== null);
    $style = $map->get($pre);
    expect($style->fontFamily)->toBe(['monospace']);
    expect($style->marginTop->value)->toBe(16.0);
    expect($style->marginBottom->value)->toBe(16.0);
    expect($style->whiteSpace)->toBe('pre');
});

it('gives code/kbd/samp a monospace font-family (inline, per BoxTreeBuilder::INLINE_TAGS)', function () {
    [$doc, $map] = resolveDoc('', '<body><p><code>c</code><kbd>k</kbd><samp>s</samp></p></body>');
    foreach (['code', 'kbd', 'samp'] as $tag) {
        $el = $doc->querySelector($tag);
        assert($el !== null);
        expect($map->get($el)->fontFamily)->toBe(['monospace']);
    }
});

it('gives hr a 1px solid top border and .5em 0 margin (auto side margins simplified to 0, documented)', function () {
    [$doc, $map] = resolveDoc('', '<body><hr></body>');
    $hr = $doc->querySelector('hr');
    assert($hr !== null);
    $style = $map->get($hr);
    expect($style->borderTop->widthPx)->toBe(1.0);
    expect($style->borderTop->style)->toBe(BorderStyle::Solid);
    expect($style->marginTop->value)->toBe(8.0);
    expect($style->marginBottom->value)->toBe(8.0);
    expect($style->marginLeft->value)->toBe(0.0);
});

it('gives small a .83em font-size relative to the PARENT font-size (em, not the UA rule\'s own)', function () {
    [$doc, $map] = resolveDoc('', '<body><p>x <small>y</small></p></body>');
    $small = $doc->querySelector('small');
    assert($small !== null);
    expect($map->get($small)->fontSizePx)->toBe(0.83 * 16.0);
});

// --- M7-T2: cascade origin (CSS 2.2 §6.4.1) -- UA loses to author EVEN AT LOWER SPECIFICITY ----

it('an author rule of LOWER specificity than the matching UA rule still wins (origin beats specificity)', function () {
    // '*' universal selector: specificity (0,0,0), strictly lower than the UA `th { ... }` type
    // selector (0,0,1) -- in real CSS cascade, origin is compared BEFORE specificity, so author
    // always beats UA regardless of who has the "stronger" selector.
    [$doc, $map] = resolveDoc('* { font-weight: normal }', '<body><table><tr><th>x</th></tr></table></body>');
    $th = $doc->querySelector('th');
    assert($th !== null);
    expect($map->get($th)->fontWeight)->toBe(400);
});

it('an author !important rule always wins, regardless of origin or specificity', function () {
    [$doc, $map] = resolveDoc('th { font-weight: normal !important }', '<body><table><tr><th>x</th></tr></table></body>');
    $th = $doc->querySelector('th');
    assert($th !== null);
    expect($map->get($th)->fontWeight)->toBe(400);
});

it('an author rule can override display:none for a normally-hidden UA tag (head/script/...)', function () {
    // Antes de M7-T2 (hardcoded HIDDEN_BY_DEFAULT), esto era IMPOSIBLE: el autor no tenía forma
    // de ganarle a un tag-check en compute(). Ahora es solo otra regla en el cascade.
    [$doc, $map] = resolveDoc('head { display: block }', '<html><head><title>t</title></head><body></body></html>');
    $head = $doc->querySelector('head');
    assert($head !== null);
    expect($map->get($head)->display)->toBe(Display::Block);
});

// --- M7-T3: css-lists-3 §3 -- <li> display:list-item + list-style-type UA defaults/inheritance --

it('gives li a Display::ListItem via the UA stylesheet default', function () {
    [$doc, $map] = resolveDoc('', '<body><ul><li>x</li></ul></body>');
    $li = $doc->querySelector('li');
    assert($li !== null);
    expect($map->get($li)->display)->toBe(Display::ListItem);
});

it('defaults ul to list-style-type:disc and ol to :decimal via the UA stylesheet', function () {
    [$doc, $map] = resolveDoc('', '<body><ul>u</ul><ol>o</ol></body>');
    $ul = $doc->querySelector('ul');
    $ol = $doc->querySelector('ol');
    assert($ul !== null && $ol !== null);
    expect($map->get($ul)->listStyleType)->toBe(ListStyleType::Disc);
    expect($map->get($ol)->listStyleType)->toBe(ListStyleType::Decimal);
});

it('inherits list-style-type from the ul/ol ancestor down onto its li children', function () {
    [$doc, $map] = resolveDoc('', '<body><ol><li>x</li></ol></body>');
    $li = $doc->querySelector('li');
    assert($li !== null);
    expect($map->get($li)->listStyleType)->toBe(ListStyleType::Decimal);
});

it('cycles disc -> circle -> square for ul nesting depth via UA descendant combinators', function () {
    [$doc, $map] = resolveDoc('', '<body><ul><li><ul><li><ul><li>x</li></ul></li></ul></li></ul></body>');
    $lis = $doc->querySelectorAll('li');
    $depth1 = $lis[0];
    $depth2 = $lis[1];
    $depth3 = $lis[2];
    expect($map->get($depth1)->listStyleType)->toBe(ListStyleType::Disc);
    expect($map->get($depth2)->listStyleType)->toBe(ListStyleType::Circle);
    expect($map->get($depth3)->listStyleType)->toBe(ListStyleType::Square);
});

it('lets an author rule override the UA list-style-type default (cascade)', function () {
    [$doc, $map] = resolveDoc('ul { list-style-type: square }', '<body><ul><li>x</li></ul></body>');
    $ul = $doc->querySelector('ul');
    assert($ul !== null);
    expect($map->get($ul)->listStyleType)->toBe(ListStyleType::Square);
});

it('resolves the list-style shorthand end to end through DeclarationParser + ComputedStyle', function () {
    [$doc, $map] = resolveDoc('ul { list-style: none }', '<body><ul><li>x</li></ul></body>');
    $ul = $doc->querySelector('ul');
    assert($ul !== null);
    expect($map->get($ul)->listStyleType)->toBe(ListStyleType::None);
});

it('defaults the root (documentElement) list-style-type to the spec initial value disc', function () {
    // Ningún <ul>/<ol> en el documento -- verifica que el INITIAL VALUE real de css-lists-3 §3
    // (disc) es el que ComputedStyle::root() fija, no un valor arbitrario tipo None.
    [$doc, $map] = resolveDoc('', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    expect($map->get($p)->listStyleType)->toBe(ListStyleType::Disc);
});

// --- M7-T5 (CSS 2.2 §10.4/§10.7): min/max-width/height + overflow ------------------------------

it('defaults minWidth/maxWidth/minHeight/maxHeight to null and overflow to visible when undeclared', function () {
    [$doc, $map] = resolveDoc('', '<body><p>x</p></body>');
    $p = $doc->querySelector('p');
    assert($p !== null);
    $style = $map->get($p);
    expect($style->minWidth)->toBeNull();
    expect($style->maxWidth)->toBeNull();
    expect($style->minHeight)->toBeNull();
    expect($style->maxHeight)->toBeNull();
    expect($style->overflow)->toBe('visible');
});

it('computes minWidth/maxWidth as LengthPercentage and minHeight/maxHeight as px-only Length', function () {
    [$doc, $map] = resolveDoc(
        'p { min-width: 50px; max-width: 80%; min-height: 30px; max-height: 200px }',
        '<body><p>x</p></body>',
    );
    $p = $doc->querySelector('p');
    assert($p !== null);
    $style = $map->get($p);
    expect($style->minWidth?->resolve(400.0))->toBe(50.0);
    expect($style->maxWidth?->resolve(400.0))->toBe(320.0); // 80% of 400
    expect($style->minHeight?->px)->toBe(30.0);
    expect($style->maxHeight?->px)->toBe(200.0);
});

it('does not inherit minWidth/maxWidth/minHeight/maxHeight/overflow down the tree', function () {
    [$doc, $map] = resolveDoc(
        'div { min-width: 50px; max-width: 200px; min-height: 10px; max-height: 300px; overflow: hidden }',
        '<body><div><p>x</p></div></body>',
    );
    $p = $doc->querySelector('p');
    assert($p !== null);
    $style = $map->get($p);
    expect($style->minWidth)->toBeNull();
    expect($style->maxWidth)->toBeNull();
    expect($style->minHeight)->toBeNull();
    expect($style->maxHeight)->toBeNull();
    expect($style->overflow)->toBe('visible');
});

it('computes overflow: hidden', function () {
    [$doc, $map] = resolveDoc('div { overflow: hidden }', '<body><div>x</div></body>');
    $div = $doc->querySelector('div');
    assert($div !== null);
    expect($map->get($div)->overflow)->toBe('hidden');
});

it('coerces overflow: scroll/auto to hidden end to end, with a warning surfaced through the WarningCollector', function () {
    [$doc, $map, $warnings] = resolveDocWithWarnings('div { overflow: scroll }', '<body><div>x</div></body>');
    $div = $doc->querySelector('div');
    assert($div !== null);
    expect($map->get($div)->overflow)->toBe('hidden');
    expect($warnings)->not->toBeEmpty();
});

// --- code review Finding 1 (css-backgrounds-3 §5): the `background` SHORTHAND must reset every
// sub-property it covers, not just the one it declares a value for -- a cascaded gradient (or
// color) from a LESS-specific rule must NOT survive a more-specific `background: <color>` (or
// `background: <gradient>`) declaration on the same element. This is a cascade-MERGE bug, not a
// single-declaration parsing bug: DeclarationParser::parse() only ever sees ONE declaration at a
// time, so the repro needs two real cascading rules resolved through StyleResolver, exactly like
// the brief's exact repro (`.box{background:linear-gradient(red,blue)} .box.override{background:
// yellow}`).

it('lets a more-specific "background:<color>" reset a less-specific cascaded gradient (Finding 1 exact repro)', function () {
    [$doc, $map] = resolveDoc(
        '.box { background: linear-gradient(red, blue); } .box.override { background: yellow; }',
        '<body><div class="box override">x</div></body>',
    );
    $div = $doc->querySelector('div');
    assert($div !== null);
    $style = $map->get($div);
    expect($style->backgroundGradient)->toBeNull();
    expect($style->backgroundColor)->toEqual(new Color(255, 255, 0));
});

it('lets a more-specific "background:<gradient>" reset a less-specific cascaded color (Finding 1, reverse order)', function () {
    [$doc, $map] = resolveDoc(
        '.box { background: yellow; } .box.override { background: linear-gradient(red, blue); }',
        '<body><div class="box override">x</div></body>',
    );
    $div = $doc->querySelector('div');
    assert($div !== null);
    $style = $map->get($div);
    expect($style->backgroundColor)->toBeNull();
    expect($style->backgroundGradient)->toEqual(new Gradient(GradientKind::Linear, 180.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]));
});

it('does NOT let the background-color LONGHAND reset a cascaded gradient (only the shorthand resets)', function () {
    [$doc, $map] = resolveDoc(
        '.box { background: linear-gradient(red, blue); } .box.override { background-color: yellow; }',
        '<body><div class="box override">x</div></body>',
    );
    $div = $doc->querySelector('div');
    assert($div !== null);
    $style = $map->get($div);
    expect($style->backgroundColor)->toEqual(new Color(255, 255, 0));
    expect($style->backgroundGradient)->toEqual(new Gradient(GradientKind::Linear, 180.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]));
});

// M8-T3 fix: background-image: none is an explicit declaration of the initial value
// and must win the cascade over a less-specific gradient
it('lets background-image: none reset a less-specific cascaded gradient (cascade-win test)', function () {
    [$doc, $map] = resolveDoc(
        '.box { background-image: linear-gradient(red, blue); } .box.override { background-image: none; }',
        '<body><div class="box override">x</div></body>',
    );
    $div = $doc->querySelector('div');
    assert($div !== null);
    $style = $map->get($div);
    expect($style->backgroundGradient)->toBeNull();
});

it('lets background-image: none reset a less-specific cascaded gradient from background shorthand', function () {
    [$doc, $map] = resolveDoc(
        '.box { background: linear-gradient(red, blue); } .box.override { background-image: none; }',
        '<body><div class="box override">x</div></body>',
    );
    $div = $doc->querySelector('div');
    assert($div !== null);
    $style = $map->get($div);
    expect($style->backgroundGradient)->toBeNull();
});
