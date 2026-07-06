<?php

declare(strict_types=1);

use Pliego\Css\DeclarationParser;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\CalcExpr;
use Pliego\Css\Value\Color;
use Pliego\Css\Value\CssLength;
use Pliego\Css\Value\Length;
use Pliego\Css\Value\LengthPercentage;
use Pliego\Css\Value\LengthUnit;

it('rejects negative padding as an invalid length with a warning', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('padding-left', '-5px');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});
it('rejects negative width/height/font-size with a warning', function () {
    foreach (['width', 'height', 'font-size'] as $property) {
        $parser = new DeclarationParser();
        $result = $parser->parse($property, '-5px');
        expect($result)->toBe([]);
        expect($parser->drainWarnings())->not->toBeEmpty();
    }
});
it('accepts negative margin (valid per CSS 2.2)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('margin-left', '-5px');
    expect($result)->toEqual(['margin-left' => LengthPercentage::px(-5.0)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('parses font-weight keywords and numeric 400/700', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('font-weight', 'normal'))->toBe(['font-weight' => 400]);
    expect($parser->parse('font-weight', 'bold'))->toBe(['font-weight' => 700]);
    expect($parser->parse('font-weight', '400'))->toBe(['font-weight' => 400]);
    expect($parser->parse('font-weight', '700'))->toBe(['font-weight' => 700]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('warns on unsupported font-weight values', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('font-weight', '550');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('parses font-style normal/italic and approximates oblique as italic with a warning', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('font-style', 'normal'))->toBe(['font-style' => 'normal']);
    expect($parser->parse('font-style', 'italic'))->toBe(['font-style' => 'italic']);
    $result = $parser->parse('font-style', 'oblique');
    expect($result)->toBe(['font-style' => 'italic']);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('warns on unsupported font-style values', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('font-style', 'bogus');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('parses line-height as unitless multiplier, px length, or normal', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('line-height', '1.5'))->toBe(['line-height' => 1.5]);
    expect($parser->parse('line-height', '20px'))->toEqual(['line-height' => Length::px(20.0)]);
    expect($parser->parse('line-height', 'normal'))->toBe(['line-height' => null]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('warns on unsupported line-height values', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('line-height', 'auto');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('rejects negative line-height (px length or unitless multiplier) with a warning', function () {
    // CSS 2.2 §10.8.1 y consistente con NON_NEGATIVE_PROPERTIES: line-height negativo no
    // tiene interpretación válida (una altura de línea negativa invertiría el flujo vertical).
    $parser = new DeclarationParser();
    $result = $parser->parse('line-height', '-5px');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();

    $parser = new DeclarationParser();
    $result = $parser->parse('line-height', '-1.5');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('parses text-align left/center/right and warns on justify', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('text-align', 'left'))->toBe(['text-align' => 'left']);
    expect($parser->parse('text-align', 'center'))->toBe(['text-align' => 'center']);
    expect($parser->parse('text-align', 'right'))->toBe(['text-align' => 'right']);
    $result = $parser->parse('text-align', 'justify');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('parses text-decoration none/underline and warns on unsupported values', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('text-decoration', 'none'))->toBe(['text-decoration' => false]);
    expect($parser->parse('text-decoration', 'underline'))->toBe(['text-decoration' => true]);
    $result = $parser->parse('text-decoration', 'line-through');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

// --- M2-T2: % en width/margin-*/padding-* -----------------------------------------------

it('accepts % on width/margin-*/padding-* as LengthPercentage', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('width', '50%'))->toEqual(['width' => LengthPercentage::percent(50.0)]);
    expect($parser->parse('margin-left', '10%'))->toEqual(['margin-left' => LengthPercentage::percent(10.0)]);
    expect($parser->parse('padding-top', '5%'))->toEqual(['padding-top' => LengthPercentage::percent(5.0)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('still accepts plain px for width/margin-*/padding-* as LengthPercentage', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('width', '200px'))->toEqual(['width' => LengthPercentage::px(200.0)]);
});

it('expands mixed px/percent margin shorthand values', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('margin', '10px 5%');
    expect($result)->toEqual([
        'margin-top' => LengthPercentage::px(10.0),
        'margin-right' => LengthPercentage::percent(5.0),
        'margin-bottom' => LengthPercentage::px(10.0),
        'margin-left' => LengthPercentage::percent(5.0),
    ]);
    expect($parser->drainWarnings())->toBeEmpty();
});

// --- M6-T3: em/rem/pt/cm/mm/in (css-values-3 §5-6) --------------------------------------

it('exactly folds 1in/1pt/1cm/1mm to px at parse time on width', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('width', '1in'))->toEqual(['width' => LengthPercentage::px(96.0)]);
    expect($parser->parse('width', '1pt'))->toEqual(['width' => LengthPercentage::px(96.0 / 72.0)]);
    expect($parser->parse('width', '1cm'))->toEqual(['width' => LengthPercentage::px(96.0 / 2.54)]);
    expect($parser->parse('width', '1mm'))->toEqual(['width' => LengthPercentage::px(9.6 / 2.54)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('keeps em/rem symbolic (CssLength) on width/margin/padding until ComputedStyle::compute resolves them', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('width', '2em'))->toEqual(['width' => CssLength::of(2.0, LengthUnit::Em)]);
    expect($parser->parse('margin-left', '1.5rem'))->toEqual(['margin-left' => CssLength::of(1.5, LengthUnit::Rem)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('expands a margin shorthand mixing em/rem/px/% ("1em 2rem 10px 5%")', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('margin', '1em 2rem 10px 5%');
    expect($result)->toEqual([
        'margin-top' => CssLength::of(1.0, LengthUnit::Em),
        'margin-right' => CssLength::of(2.0, LengthUnit::Rem),
        'margin-bottom' => LengthPercentage::px(10.0),
        'margin-left' => LengthPercentage::percent(5.0),
    ]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('keeps em/rem symbolic on font-size/height/border-width until ComputedStyle::compute resolves them', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('font-size', '2em'))->toEqual(['font-size' => CssLength::of(2.0, LengthUnit::Em)]);
    expect($parser->parse('height', '1.5rem'))->toEqual(['height' => CssLength::of(1.5, LengthUnit::Rem)]);
    expect($parser->parse('border-top-width', '0.1em'))->toEqual(['border-top-width' => CssLength::of(0.1, LengthUnit::Em)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('accepts % for font-size as a symbolic CssLength (M6-T3: resolved against the parent font-size in ComputedStyle::compute)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('font-size', '150%');
    expect($result)->toEqual(['font-size' => CssLength::of(150.0, LengthUnit::Percent)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('accepts % for line-height as a symbolic CssLength (M6-T3: resolved against the own font-size in ComputedStyle::compute)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('line-height', '150%');
    expect($result)->toEqual(['line-height' => CssLength::of(150.0, LengthUnit::Percent)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

// --- M2-T2: bordes (longhands + shorthands) ---------------------------------------------

it('parses border-{side}-width in px and as thin/medium/thick keywords', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('border-top-width', '2px'))->toEqual(['border-top-width' => Length::px(2.0)]);
    expect($parser->parse('border-top-width', 'thin'))->toEqual(['border-top-width' => Length::px(1.0)]);
    expect($parser->parse('border-top-width', 'medium'))->toEqual(['border-top-width' => Length::px(3.0)]);
    expect($parser->parse('border-top-width', 'thick'))->toEqual(['border-top-width' => Length::px(5.0)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('rejects negative border-{side}-width with a warning', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('border-top-width', '-2px');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('parses border-{side}-style solid/none and warns on unsupported styles', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('border-top-style', 'solid'))->toBe(['border-top-style' => BorderStyle::Solid]);
    expect($parser->parse('border-top-style', 'none'))->toBe(['border-top-style' => BorderStyle::None]);
    $result = $parser->parse('border-top-style', 'dashed');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('parses border-{side}-color reusing Color::fromCss', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('border-top-color', '#ccc'))->toEqual(['border-top-color' => new Color(204, 204, 204)]);
    $result = $parser->parse('border-top-color', 'not-a-color');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('expands the border shorthand for all 3 component orders', function () {
    $expected = [
        'border-top-width' => Length::px(1.0),
        'border-top-style' => BorderStyle::Solid,
        'border-top-color' => new Color(204, 204, 204),
        'border-right-width' => Length::px(1.0),
        'border-right-style' => BorderStyle::Solid,
        'border-right-color' => new Color(204, 204, 204),
        'border-bottom-width' => Length::px(1.0),
        'border-bottom-style' => BorderStyle::Solid,
        'border-bottom-color' => new Color(204, 204, 204),
        'border-left-width' => Length::px(1.0),
        'border-left-style' => BorderStyle::Solid,
        'border-left-color' => new Color(204, 204, 204),
    ];
    foreach (['1px solid #ccc', 'solid #ccc 1px', '#ccc 1px solid'] as $value) {
        $parser = new DeclarationParser();
        expect($parser->parse('border', $value))->toEqual($expected);
        expect($parser->drainWarnings())->toBeEmpty();
    }
});

it('expands border-{side} shorthand to only that side longhands', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('border-top', '2px solid #000');
    expect($result)->toEqual([
        'border-top-width' => Length::px(2.0),
        'border-top-style' => BorderStyle::Solid,
        'border-top-color' => new Color(0, 0, 0),
    ]);
});

it('allows each border shorthand component to be omitted', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('border', 'solid'))->toEqual(['border-top-style' => BorderStyle::Solid,
        'border-right-style' => BorderStyle::Solid, 'border-bottom-style' => BorderStyle::Solid,
        'border-left-style' => BorderStyle::Solid]);
});

it('warns on an unrecognized border shorthand component', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('border', '1px dotted #ccc');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

// --- M2-T3: box-sizing -------------------------------------------------------------------

it('parses box-sizing content-box/border-box and warns on unsupported values', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('box-sizing', 'content-box'))->toBe(['box-sizing' => 'content-box']);
    expect($parser->parse('box-sizing', 'border-box'))->toBe(['box-sizing' => 'border-box']);
    $result = $parser->parse('box-sizing', 'padding-box');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

// --- M4-T1: display:flex, longhands, flex shorthand ---------------------------------------

it('accepts display:flex as a keyword alongside block/none', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('display', 'flex'))->toBe(['display' => 'flex']);
    expect($parser->parse('display', 'block'))->toBe(['display' => 'block']);
    expect($parser->parse('display', 'none'))->toBe(['display' => 'none']);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('parses flex-direction row/column and warns on row-reverse/column-reverse', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('flex-direction', 'row'))->toBe(['flex-direction' => 'row']);
    expect($parser->parse('flex-direction', 'column'))->toBe(['flex-direction' => 'column']);
    foreach (['row-reverse', 'column-reverse'] as $value) {
        $parser = new DeclarationParser();
        $result = $parser->parse('flex-direction', $value);
        expect($result)->toBe([]);
        expect($parser->drainWarnings())->not->toBeEmpty();
    }
});

it('parses flex-wrap nowrap/wrap and warns on wrap-reverse', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('flex-wrap', 'nowrap'))->toBe(['flex-wrap' => 'nowrap']);
    expect($parser->parse('flex-wrap', 'wrap'))->toBe(['flex-wrap' => 'wrap']);
    $result = $parser->parse('flex-wrap', 'wrap-reverse');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('parses justify-content values and warns on space-around/space-evenly', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('justify-content', 'flex-start'))->toBe(['justify-content' => 'flex-start']);
    expect($parser->parse('justify-content', 'center'))->toBe(['justify-content' => 'center']);
    expect($parser->parse('justify-content', 'flex-end'))->toBe(['justify-content' => 'flex-end']);
    expect($parser->parse('justify-content', 'space-between'))->toBe(['justify-content' => 'space-between']);
    foreach (['space-around', 'space-evenly'] as $value) {
        $parser = new DeclarationParser();
        $result = $parser->parse('justify-content', $value);
        expect($result)->toBe([]);
        expect($parser->drainWarnings())->not->toBeEmpty();
    }
});

it('parses align-items values and warns on baseline', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('align-items', 'stretch'))->toBe(['align-items' => 'stretch']);
    expect($parser->parse('align-items', 'flex-start'))->toBe(['align-items' => 'flex-start']);
    expect($parser->parse('align-items', 'center'))->toBe(['align-items' => 'center']);
    expect($parser->parse('align-items', 'flex-end'))->toBe(['align-items' => 'flex-end']);
    $result = $parser->parse('align-items', 'baseline');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('parses gap/row-gap/column-gap in px and warns on percentages', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('row-gap', '10px'))->toEqual(['row-gap' => Length::px(10.0)]);
    expect($parser->parse('column-gap', '5px'))->toEqual(['column-gap' => Length::px(5.0)]);
    expect($parser->drainWarnings())->toBeEmpty();

    foreach (['row-gap', 'column-gap'] as $property) {
        $parser = new DeclarationParser();
        $result = $parser->parse($property, '10%');
        expect($result)->toBe([]);
        expect($parser->drainWarnings())->not->toBeEmpty();
    }
});

it('rejects negative row-gap/column-gap with a warning', function () {
    foreach (['row-gap', 'column-gap'] as $property) {
        $parser = new DeclarationParser();
        $result = $parser->parse($property, '-5px');
        expect($result)->toBe([]);
        expect($parser->drainWarnings())->not->toBeEmpty();
    }
});

it('expands the gap shorthand: one value sets both axes, two set row then column', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('gap', '10px'))->toEqual([
        'row-gap' => Length::px(10.0),
        'column-gap' => Length::px(10.0),
    ]);
    expect($parser->drainWarnings())->toBeEmpty();

    $parser = new DeclarationParser();
    expect($parser->parse('gap', '10px 5px'))->toEqual([
        'row-gap' => Length::px(10.0),
        'column-gap' => Length::px(5.0),
    ]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('warns on an unsupported gap shorthand (percent, extra values, garbage)', function () {
    foreach (['10%', '1px 2px 3px', 'auto', ''] as $value) {
        $parser = new DeclarationParser();
        $result = $parser->parse('gap', $value);
        expect($result)->toBe([]);
        expect($parser->drainWarnings())->not->toBeEmpty();
    }
});

it('parses flex-grow/flex-shrink as non-negative numbers and warns otherwise', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('flex-grow', '2'))->toBe(['flex-grow' => 2.0]);
    expect($parser->parse('flex-grow', '0'))->toBe(['flex-grow' => 0.0]);
    expect($parser->parse('flex-shrink', '1.5'))->toBe(['flex-shrink' => 1.5]);
    expect($parser->drainWarnings())->toBeEmpty();

    foreach (['flex-grow', 'flex-shrink'] as $property) {
        foreach (['-1', 'auto', ''] as $value) {
            $parser = new DeclarationParser();
            $result = $parser->parse($property, $value);
            expect($result)->toBe([]);
            expect($parser->drainWarnings())->not->toBeEmpty();
        }
    }
});

it('parses flex-basis as px/%/auto and warns on content or negative values', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('flex-basis', '30px'))->toEqual(['flex-basis' => LengthPercentage::px(30.0)]);
    expect($parser->parse('flex-basis', '50%'))->toEqual(['flex-basis' => LengthPercentage::percent(50.0)]);
    expect($parser->parse('flex-basis', 'auto'))->toBe(['flex-basis' => 'auto']);
    expect($parser->drainWarnings())->toBeEmpty();

    foreach (['content', '-10px'] as $value) {
        $parser = new DeclarationParser();
        $result = $parser->parse('flex-basis', $value);
        expect($result)->toBe([]);
        expect($parser->drainWarnings())->not->toBeEmpty();
    }
});

// --- M4-T1: flex shorthand, css-flexbox-1 §7.1.1 (cada fila de la tabla) -----------------

it('expands "flex: none" to grow 0, shrink 0, basis auto', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('flex', 'none'))->toBe([
        'flex-grow' => 0.0, 'flex-shrink' => 0.0, 'flex-basis' => 'auto',
    ]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('expands "flex: initial" to grow 0, shrink 1, basis auto', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('flex', 'initial'))->toBe([
        'flex-grow' => 0.0, 'flex-shrink' => 1.0, 'flex-basis' => 'auto',
    ]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('expands "flex: auto" to grow 1, shrink 1, basis auto', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('flex', 'auto'))->toBe([
        'flex-grow' => 1.0, 'flex-shrink' => 1.0, 'flex-basis' => 'auto',
    ]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('expands a single unitless number "flex: N" to grow N, shrink 1, basis 0% (M5-T1: §7.1.1 says 0%, not 0px)', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('flex', '2'))->toEqual([
        'flex-grow' => 2.0, 'flex-shrink' => 1.0, 'flex-basis' => LengthPercentage::percent(0.0),
    ]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('expands a single width "flex: Npx" to grow 1, shrink 1, basis N', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('flex', '30px'))->toEqual([
        'flex-grow' => 1.0, 'flex-shrink' => 1.0, 'flex-basis' => LengthPercentage::px(30.0),
    ]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('expands two numbers "flex: N M" to grow N, shrink M, basis 0% (M5-T1: §7.1.1 says 0%, not 0px)', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('flex', '2 3'))->toEqual([
        'flex-grow' => 2.0, 'flex-shrink' => 3.0, 'flex-basis' => LengthPercentage::percent(0.0),
    ]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('expands number + width "flex: N Mpx" to grow N, shrink 1, basis M', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('flex', '2 30px'))->toEqual([
        'flex-grow' => 2.0, 'flex-shrink' => 1.0, 'flex-basis' => LengthPercentage::px(30.0),
    ]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('expands three values "flex: N M Ppx" to grow N, shrink M, basis P', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('flex', '2 3 30px'))->toEqual([
        'flex-grow' => 2.0, 'flex-shrink' => 3.0, 'flex-basis' => LengthPercentage::px(30.0),
    ]);
    expect($parser->drainWarnings())->toBeEmpty();

    $parser = new DeclarationParser();
    expect($parser->parse('flex', '0 0 auto'))->toBe([
        'flex-grow' => 0.0, 'flex-shrink' => 0.0, 'flex-basis' => 'auto',
    ]);
});

it('warns on unsupported flex shorthand values', function () {
    foreach (['2 3 4 5', 'red', '2 red', '2 3 red', ''] as $value) {
        $parser = new DeclarationParser();
        $result = $parser->parse('flex', $value);
        expect($result)->toBe([]);
        expect($parser->drainWarnings())->not->toBeEmpty();
    }
});

// --- M5-T2: display:table*, border-spacing, table-layout, vertical-align -----------------

it('accepts the 5 table display keywords alongside block/none/flex', function () {
    $parser = new DeclarationParser();
    foreach (['table', 'table-row', 'table-cell', 'table-header-group', 'table-row-group'] as $value) {
        expect($parser->parse('display', $value))->toBe(['display' => $value]);
    }
    expect($parser->drainWarnings())->toBeEmpty();
});

it('parses border-spacing as a single px length', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('border-spacing', '4px'))->toEqual(['border-spacing' => Length::px(4.0)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('warns on a two-value border-spacing (only one value supported in M5)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('border-spacing', '4px 8px');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('warns on a percentage or garbage border-spacing', function () {
    foreach (['50%', 'auto', ''] as $value) {
        $parser = new DeclarationParser();
        $result = $parser->parse('border-spacing', $value);
        expect($result)->toBe([]);
        expect($parser->drainWarnings())->not->toBeEmpty();
    }
});

it('rejects a negative border-spacing with a warning', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('border-spacing', '-4px');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('parses table-layout auto/fixed and warns on unsupported values', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('table-layout', 'auto'))->toBe(['table-layout' => 'auto']);
    expect($parser->parse('table-layout', 'fixed'))->toBe(['table-layout' => 'fixed']);
    $result = $parser->parse('table-layout', 'bogus');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('parses vertical-align top/middle/bottom and warns on baseline/sub/super/percentages', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('vertical-align', 'top'))->toBe(['vertical-align' => 'top']);
    expect($parser->parse('vertical-align', 'middle'))->toBe(['vertical-align' => 'middle']);
    expect($parser->parse('vertical-align', 'bottom'))->toBe(['vertical-align' => 'bottom']);
    foreach (['baseline', 'sub', 'super', 'text-top', 'text-bottom', '50%'] as $value) {
        $parser = new DeclarationParser();
        $result = $parser->parse('vertical-align', $value);
        expect($result)->toBe([]);
        expect($parser->drainWarnings())->not->toBeEmpty();
    }
});

// --- M6-T4: calc() parsed into a symbolic CalcExpr (css-values-3 §8) ---------------------

it('parses calc() on width into a symbolic CalcExpr, not yet resolved', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('width', 'calc(100% - 20px)');
    expect($result)->toEqual(['width' => CalcExpr::of(100.0, 0.0, 0.0, -20.0)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('parses calc() with em on font-size into a symbolic CalcExpr', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('font-size', 'calc(1em + 4px)');
    expect($result)->toEqual(['font-size' => CalcExpr::of(0.0, 1.0, 0.0, 4.0)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('parses calc() on a pure-length property (height)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('height', 'calc(2px + 3px)');
    expect($result)->toEqual(['height' => CalcExpr::of(0.0, 0.0, 0.0, 5.0)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('splits a shorthand token containing an internal-space calc() correctly (no naive whitespace split)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('margin', 'calc(1em + 4px) 10px');
    expect($result)->toEqual([
        'margin-top' => CalcExpr::of(0.0, 1.0, 0.0, 4.0),
        'margin-right' => LengthPercentage::px(10.0),
        'margin-bottom' => CalcExpr::of(0.0, 1.0, 0.0, 4.0),
        'margin-left' => LengthPercentage::px(10.0),
    ]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('warns and drops the declaration when calc() itself is invalid (division by zero)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('width', 'calc(10px / 0)');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('does not fall back to plain length parsing when a value looks like calc() but is malformed', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('width', 'calc(100% - )');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});
