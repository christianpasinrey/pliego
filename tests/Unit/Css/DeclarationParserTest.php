<?php

declare(strict_types=1);

use Pliego\Css\DeclarationParser;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\CalcExpr;
use Pliego\Css\Value\Color;
use Pliego\Css\Value\CssLength;
use Pliego\Css\Value\Gradient;
use Pliego\Css\Value\GradientCorner;
use Pliego\Css\Value\GradientKind;
use Pliego\Css\Value\GradientStop;
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
// M8 final-review Finding A: `height` is px-only (LENGTH_PROPERTIES) -- a percentage is rejected
// at PARSE time (never reaches ComputedStyle::$height at all), regardless of what element it's
// declared on. StyleResolverTest.php already covers this for an <img> specifically; this is the
// generic parser-level check the Finding A integration audit asked for ("% height -> warning
// convention", now also exercised for a plain block, see BlockFlowContextTest.php).
it('rejects a percentage height with a warning, regardless of element (px-only, no containing-height tracking)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('height', '50%');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->toBe(['Unsupported length for height: 50%']);
});

it('accepts negative margin (valid per CSS 2.2)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('margin-left', '-5px');
    expect($result)->toEqual(['margin-left' => LengthPercentage::px(-5.0)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

// --- M7-T1 housekeeping, finding 3: padding shorthand sign-check parity with the longhands -----

it('drops the whole padding shorthand with one warning when the single value is negative', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('padding', '-5px');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->toHaveCount(1);
});

it('drops the whole padding shorthand with one warning when only one of several values is negative', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('padding', '10px -5px');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->toHaveCount(1);
});

it('accepts an all-non-negative padding shorthand unchanged', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('padding', '10px 5%');
    expect($result)->toEqual([
        'padding-top' => LengthPercentage::px(10.0),
        'padding-right' => LengthPercentage::percent(5.0),
        'padding-bottom' => LengthPercentage::px(10.0),
        'padding-left' => LengthPercentage::percent(5.0),
    ]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('still accepts a negative margin shorthand (margin stays permissive, unlike padding)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('margin', '10px -5px');
    expect($result)->toEqual([
        'margin-top' => LengthPercentage::px(10.0),
        'margin-right' => LengthPercentage::px(-5.0),
        'margin-bottom' => LengthPercentage::px(10.0),
        'margin-left' => LengthPercentage::px(-5.0),
    ]);
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

it('parses border-{side}-style solid/none/dashed/dotted and warns on unsupported styles', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('border-top-style', 'solid'))->toBe(['border-top-style' => BorderStyle::Solid]);
    expect($parser->parse('border-top-style', 'none'))->toBe(['border-top-style' => BorderStyle::None]);
    // M8-T4 (css-backgrounds-3 §4.3): dashed/dotted dejan de estar en la lista de "no soportado"
    // (antes de esta tarea, 'dashed' era justo el ejemplo usado para el caso de warning de abajo).
    expect($parser->parse('border-top-style', 'dashed'))->toBe(['border-top-style' => BorderStyle::Dashed]);
    expect($parser->parse('border-top-style', 'dotted'))->toBe(['border-top-style' => BorderStyle::Dotted]);
    $result = $parser->parse('border-top-style', 'double');
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
    // M8-T4: 'dotted' dejó de ser un ejemplo válido de estilo NO reconocido (ahora es un
    // BorderStyle real) -- 'double' sigue fuera de alcance.
    $parser = new DeclarationParser();
    $result = $parser->parse('border', '1px double #ccc');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('expands the border shorthand with dashed/dotted styles (M8-T4)', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('border', '2px dashed #ccc'))->toEqual([
        'border-top-width' => Length::px(2.0), 'border-top-style' => BorderStyle::Dashed, 'border-top-color' => new Color(204, 204, 204),
        'border-right-width' => Length::px(2.0), 'border-right-style' => BorderStyle::Dashed, 'border-right-color' => new Color(204, 204, 204),
        'border-bottom-width' => Length::px(2.0), 'border-bottom-style' => BorderStyle::Dashed, 'border-bottom-color' => new Color(204, 204, 204),
        'border-left-width' => Length::px(2.0), 'border-left-style' => BorderStyle::Dashed, 'border-left-color' => new Color(204, 204, 204),
    ]);
    expect($parser->drainWarnings())->toBeEmpty();
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

// --- M6-T4 fix, finding 2: a calc() with NO em/rem/% is a definite px value already knowable at
// parse time — fold it and run the same non-negative check a literal value would get. ----------

it('rejects a definite negative calc() for a non-negative property at parse time: padding-left:calc(-5px)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('padding-left', 'calc(-5px)');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('accepts a definite negative calc() for margin at parse time (margins may be negative)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('margin-left', 'calc(-5px)');
    expect($result)->toEqual(['margin-left' => CalcExpr::of(0.0, 0.0, 0.0, -5.0)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('accepts a %-bearing calc() regardless of apparent sign at parse time (documented gap): padding-left:calc(10% - 999px)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('padding-left', 'calc(10% - 999px)');
    expect($result)->toEqual(['padding-left' => CalcExpr::of(10.0, 0.0, 0.0, -999.0)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

// --- M6 final-review fix, finding 2: font-size/line-height calc() gets the SAME definite-negative
// check padding/width/etc. already got in M6-T4 — closes the one property family left out there. --

it('rejects a definite negative calc() font-size at parse time: font-size:calc(-5px)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('font-size', 'calc(-5px)');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('accepts a definite positive calc() font-size at parse time: font-size:calc(5px)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('font-size', 'calc(5px)');
    expect($result)->toEqual(['font-size' => CalcExpr::of(0.0, 0.0, 0.0, 5.0)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('still accepts a calc() with % or em on font-size (sign not knowable until compute-time, unchanged)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('font-size', 'calc(1em - 999px)');
    expect($result)->toEqual(['font-size' => CalcExpr::of(0.0, 1.0, 0.0, -999.0)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('rejects a definite negative calc() line-height at parse time: line-height:calc(-5px)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('line-height', 'calc(-5px)');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('accepts a definite positive calc() line-height at parse time: line-height:calc(5px)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('line-height', 'calc(5px)');
    expect($result)->toEqual(['line-height' => CalcExpr::of(0.0, 0.0, 0.0, 5.0)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

// --- M6-T5: full color syntax reaches color/background-color/border-*-color via the SAME
// Color::fromCss() call already exercised above for hex/keywords — these just prove rgb()/hsl()/
// currentColor flow through the property dispatcher unchanged. -------------------------------

it('parses color/background-color with rgb()/rgba()', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('color', 'rgb(139, 94, 52)'))->toEqual(['color' => new Color(139, 94, 52)]);
    expect($parser->parse('background-color', 'rgba(0, 0, 255, 0.5)'))
        ->toEqual(['background-color' => new Color(0, 0, 255, 0.5)]);
});

it('parses color/background-color with hsl()/hsla()', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('color', 'hsl(0, 100%, 50%)'))->toEqual(['color' => new Color(255, 0, 0)]);
});

it('parses currentColor for color/background-color/border-color as the sentinel', function () {
    $parser = new DeclarationParser();
    $color = $parser->parse('color', 'currentColor');
    expect($color['color'])->toBeInstanceOf(Color::class);
    expect($color['color']->isCurrentColor)->toBeTrue();
    $bg = $parser->parse('background-color', 'currentColor');
    expect($bg['background-color']->isCurrentColor)->toBeTrue();
    $border = $parser->parse('border-top-color', 'currentColor');
    expect($border['border-top-color']->isCurrentColor)->toBeTrue();
});

it('parses "transparent" for background-color as alpha 0', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-color', 'transparent');
    expect($result['background-color'])->toEqual(new Color(0, 0, 0, 0.0));
});

// --- M6-T5: opacity — clamped to [0,1], NOT a warning when out of range (css-values-3 §4.3). ---

it('parses opacity as a float', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('opacity', '0.5'))->toBe(['opacity' => 0.5]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('clamps opacity above 1 to 1.0 and below 0 to 0.0, without a warning', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('opacity', '2'))->toBe(['opacity' => 1.0]);
    expect($parser->parse('opacity', '-1'))->toBe(['opacity' => 0.0]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('warns on a non-numeric opacity value', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('opacity', 'transparent');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

// --- M7-T2: font-family becomes a fallback list -------------------------------------------

it('parses a single unquoted font-family into a one-element list', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('font-family', 'Arial'))->toBe(['font-family' => ['Arial']]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('splits a font-family fallback list on commas, trimming quotes and whitespace per name', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('font-family', 'Arial, "Helvetica Neue", sans-serif');
    expect($result)->toBe(['font-family' => ['Arial', 'Helvetica Neue', 'sans-serif']]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('drops empty entries from a malformed font-family list without warning', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('font-family', ' , Arial ,, '))->toBe(['font-family' => ['Arial']]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('accepts a single-quoted font-family name', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('font-family', "'Courier New'"))->toBe(['font-family' => ['Courier New']]);
});

// --- M7-T2: white-space (minimal: normal|pre) ----------------------------------------------

it('parses white-space: normal and pre', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('white-space', 'normal'))->toBe(['white-space' => 'normal']);
    expect($parser->parse('white-space', 'pre'))->toBe(['white-space' => 'pre']);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('warns on an unsupported white-space keyword (nowrap/pre-wrap/pre-line out of scope M7)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('white-space', 'nowrap');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

// --- M7-T3: list-style-type / list-style-position / list-style shorthand (css-lists-3 §3) -----

it('parses all 5 supported list-style-type keywords', function () {
    $parser = new DeclarationParser();
    foreach (['disc', 'circle', 'square', 'decimal', 'none'] as $keyword) {
        expect($parser->parse('list-style-type', $keyword))->toBe(['list-style-type' => $keyword]);
    }
    expect($parser->drainWarnings())->toBeEmpty();
});

it('warns on an unsupported list-style-type keyword', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('list-style-type', 'georgian');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('accepts list-style-position: outside silently (no warning; the value is never consumed by ComputedStyle)', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('list-style-position', 'outside'))->toBe(['list-style-position' => 'outside']);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('warns on list-style-position: inside (unsupported in M7)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('list-style-position', 'inside');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('expands the list-style shorthand to list-style-type when only a type keyword is given', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('list-style', 'square'))->toBe(['list-style-type' => 'square']);
    expect($parser->parse('list-style', 'none'))->toBe(['list-style-type' => 'none']);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('accepts "outside" alongside a type in the list-style shorthand (default position, no-op)', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('list-style', 'square outside'))->toBe(['list-style-type' => 'square']);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('warns and drops the whole list-style shorthand when "inside" is present (position unsupported)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('list-style', 'square inside');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('warns and drops the whole list-style shorthand on a list-style-image value (unsupported in M7)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('list-style', 'url(bullet.png)');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

// --- M7-T5 (CSS 2.2 §10.4/§10.7): min-width/max-width/min-height/max-height + overflow ---------

it('parses min-width/max-width as length-percentage, same as width', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('min-width', '50px'))->toEqual(['min-width' => LengthPercentage::px(50.0)]);
    expect($parser->parse('max-width', '80%'))->toEqual(['max-width' => LengthPercentage::percent(80.0)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('parses min-height/max-height as PX-ONLY lengths (no percentage, unlike min/max-width)', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('min-height', '30px'))->toEqual(['min-height' => Length::px(30.0)]);
    expect($parser->parse('max-height', '200px'))->toEqual(['max-height' => Length::px(200.0)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('rejects a percentage min-height/max-height with a warning (containing height not tracked)', function () {
    foreach (['min-height', 'max-height'] as $property) {
        $parser = new DeclarationParser();
        $result = $parser->parse($property, '50%');
        expect($result)->toBe([]);
        expect($parser->drainWarnings())->not->toBeEmpty();
    }
});

it('rejects negative min-width/max-width/min-height/max-height with a warning', function () {
    foreach (['min-width', 'max-width', 'min-height', 'max-height'] as $property) {
        $parser = new DeclarationParser();
        $result = $parser->parse($property, '-5px');
        expect($result)->toBe([]);
        expect($parser->drainWarnings())->not->toBeEmpty();
    }
});

it('silently drops "min-width/min-height: auto" and "max-width/max-height: none" (both collapse to the initial no-constraint value)', function () {
    foreach (['min-width' => 'auto', 'min-height' => 'auto', 'max-width' => 'none', 'max-height' => 'none'] as $property => $keyword) {
        $parser = new DeclarationParser();
        $result = $parser->parse($property, $keyword);
        expect($result)->toBe([]);
        expect($parser->drainWarnings())->toBeEmpty();
    }
});

it('parses overflow: visible/hidden as-is', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('overflow', 'visible'))->toBe(['overflow' => 'visible']);
    expect($parser->parse('overflow', 'hidden'))->toBe(['overflow' => 'hidden']);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('coerces overflow: scroll/auto to hidden with a warning (no real scrolling in a print engine)', function () {
    foreach (['scroll', 'auto'] as $keyword) {
        $parser = new DeclarationParser();
        $result = $parser->parse('overflow', $keyword);
        expect($result)->toBe(['overflow' => 'hidden']);
        expect($parser->drainWarnings())->not->toBeEmpty();
    }
});

it('rejects an unsupported overflow keyword with a warning', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('overflow', 'clip');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

// --- M7-T6 (CSS 2.2 §9.4.3/§9.5, floats + position reducido) -----------------------------------

it('parses float: left/right/none', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('float', 'left'))->toBe(['float' => 'left']);
    expect($parser->parse('float', 'right'))->toBe(['float' => 'right']);
    expect($parser->parse('float', 'none'))->toBe(['float' => 'none']);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('parses clear: left/right/both/none', function () {
    $parser = new DeclarationParser();
    foreach (['left', 'right', 'both', 'none'] as $keyword) {
        expect($parser->parse('clear', $keyword))->toBe(['clear' => $keyword]);
    }
    expect($parser->drainWarnings())->toBeEmpty();
});

it('parses position: static/relative/absolute', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('position', 'static'))->toBe(['position' => 'static']);
    expect($parser->parse('position', 'relative'))->toBe(['position' => 'relative']);
    expect($parser->parse('position', 'absolute'))->toBe(['position' => 'absolute']);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('warns on position: sticky and position: fixed (both out of scope, discarded)', function () {
    foreach (['sticky', 'fixed'] as $keyword) {
        $parser = new DeclarationParser();
        $result = $parser->parse('position', $keyword);
        expect($result)->toBe([]);
        expect($parser->drainWarnings())->toContain("Unsupported keyword for position: $keyword");
    }
});

it('parses left/right as length-percentage (same as width), negative allowed', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('left', '10px'))->toHaveKey('left');
    expect($parser->parse('right', '50%'))->toHaveKey('right');
    expect($parser->parse('left', '-10px'))->toHaveKey('left');
    expect($parser->drainWarnings())->toBeEmpty();
});

it('parses top/bottom as PX-ONLY lengths (no percentage), negative allowed (unlike height)', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('top', '10px'))->toHaveKey('top');
    expect($parser->parse('top', '-10px'))->toHaveKey('top');
    expect($parser->parse('bottom', '-5px'))->toHaveKey('bottom');
    expect($parser->drainWarnings())->toBeEmpty();
});

it('rejects a percentage top/bottom with a warning (containing height not tracked, same as height)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('top', '50%');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->toContain('Unsupported length for top: 50%');
});

// --- M8-T2 (css-backgrounds-3 §5 reducido): border-radius shorthand + 4 longhands -------------

it('expands a single-value border-radius shorthand to all 4 corners', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('border-radius', '10px');
    expect($result)->toEqual([
        'border-top-left-radius' => LengthPercentage::px(10.0),
        'border-top-right-radius' => LengthPercentage::px(10.0),
        'border-bottom-right-radius' => LengthPercentage::px(10.0),
        'border-bottom-left-radius' => LengthPercentage::px(10.0),
    ]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('expands a 2-value border-radius shorthand (tl/br pair, tr/bl pair)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('border-radius', '10px 5%');
    expect($result)->toEqual([
        'border-top-left-radius' => LengthPercentage::px(10.0),
        'border-top-right-radius' => LengthPercentage::percent(5.0),
        'border-bottom-right-radius' => LengthPercentage::px(10.0),
        'border-bottom-left-radius' => LengthPercentage::percent(5.0),
    ]);
});

it('expands a 3-value border-radius shorthand (tl, tr+bl, br)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('border-radius', '1px 2px 3px');
    expect($result)->toEqual([
        'border-top-left-radius' => LengthPercentage::px(1.0),
        'border-top-right-radius' => LengthPercentage::px(2.0),
        'border-bottom-right-radius' => LengthPercentage::px(3.0),
        'border-bottom-left-radius' => LengthPercentage::px(2.0),
    ]);
});

it('expands a 4-value border-radius shorthand (tl, tr, br, bl clockwise)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('border-radius', '1px 2px 3px 4px');
    expect($result)->toEqual([
        'border-top-left-radius' => LengthPercentage::px(1.0),
        'border-top-right-radius' => LengthPercentage::px(2.0),
        'border-bottom-right-radius' => LengthPercentage::px(3.0),
        'border-bottom-left-radius' => LengthPercentage::px(4.0),
    ]);
});

it('parses each border-*-radius longhand independently', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('border-top-left-radius', '8px'))->toEqual(['border-top-left-radius' => LengthPercentage::px(8.0)]);
    expect($parser->parse('border-top-right-radius', '8px'))->toEqual(['border-top-right-radius' => LengthPercentage::px(8.0)]);
    expect($parser->parse('border-bottom-right-radius', '8px'))->toEqual(['border-bottom-right-radius' => LengthPercentage::px(8.0)]);
    expect($parser->parse('border-bottom-left-radius', '8px'))->toEqual(['border-bottom-left-radius' => LengthPercentage::px(8.0)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('warns and drops an elliptical border-radius shorthand ("/" horizontal/vertical split, unsupported in M8)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('border-radius', '10px / 20px');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->toHaveCount(1);
});

it('warns and drops an elliptical border-*-radius longhand (2 space-separated values, unsupported in M8)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('border-top-left-radius', '10px 20px');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->toHaveCount(1);
});

it('rejects a negative value anywhere in the border-radius shorthand with one warning', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('border-radius', '10px -5px');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->toHaveCount(1);
});

it('rejects a negative border-*-radius longhand with a warning', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('border-top-left-radius', '-5px');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('warns on an unparsable border-radius shorthand token', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('border-radius', 'banana');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->toHaveCount(1);
});

// --- M8-T3 (css-images-3 §3.1 reducido): linear-gradient()/radial-gradient() ------------------

/**
 * Narrows the `array<string, mixed>` returned by DeclarationParser::parse() down to the concrete
 * Gradient stored under 'background-gradient' — an `instanceof` guard (not assert()/inline @var,
 * both banned by the gate: this is a REAL runtime check PHPStan already understands as narrowing)
 * so every gradient test below can read ->angleDeg/->stops with a real type instead of `mixed`.
 *
 * @param array<string, mixed> $result
 */
function gradientFrom(array $result): Gradient
{
    $gradient = $result['background-gradient'] ?? null;
    if (!$gradient instanceof Gradient) {
        throw new \RuntimeException('Expected a Gradient under "background-gradient"');
    }
    return $gradient;
}

/**
 * Same narrowing idiom as gradientFrom() (see its docblock) for the raw ['offsetX'=>..,
 * 'offsetY'=>.., 'blur'=>.., 'color'=>Color] shape DeclarationParser::parseBoxShadowValue() puts
 * under 'box-shadow' (see its docblock — NOT yet the final Css\Value\BoxShadow VO, ComputedStyle
 * resolves that).
 *
 * @param array<string, mixed> $result
 * @return array{offsetX: Length|CssLength|CalcExpr, offsetY: Length|CssLength|CalcExpr, blur: Length|CssLength|CalcExpr, color: Color}
 */
function boxShadowRaw(array $result): array
{
    $raw = $result['box-shadow'] ?? null;
    if (!is_array($raw)) {
        throw new \RuntimeException('Expected a raw box-shadow array under "box-shadow"');
    }
    $offsetX = $raw['offsetX'] ?? null;
    $offsetY = $raw['offsetY'] ?? null;
    $blur = $raw['blur'] ?? null;
    $color = $raw['color'] ?? null;
    if (
        !($offsetX instanceof Length || $offsetX instanceof CssLength || $offsetX instanceof CalcExpr)
        || !($offsetY instanceof Length || $offsetY instanceof CssLength || $offsetY instanceof CalcExpr)
        || !($blur instanceof Length || $blur instanceof CssLength || $blur instanceof CalcExpr)
        || !$color instanceof Color
    ) {
        throw new \RuntimeException('Malformed raw box-shadow array');
    }
    return ['offsetX' => $offsetX, 'offsetY' => $offsetY, 'blur' => $blur, 'color' => $color];
}

it('parses a numeric-angle linear-gradient() with 2 explicit color stops, zero warnings', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', 'linear-gradient(45deg, red, blue)');
    expect($result)->toEqual([
        'background-gradient' => new Gradient(GradientKind::Linear, 45.0, [
            new GradientStop(new Color(255, 0, 0), 0.0),
            new GradientStop(new Color(0, 0, 255), 100.0),
        ]),
        // M8-T6: the gradient branch of 'background-image' now ALSO resets a previously-cascaded
        // background-image to null (reset-trio discipline, see the M8-T6 tests below).
        'background-image' => null,
    ]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('defaults a linear-gradient() with no direction to 180deg ("to bottom"), per spec', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', 'linear-gradient(red, blue)');
    expect($result['background-gradient'])->toEqual(new Gradient(GradientKind::Linear, 180.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]));
});

it('maps the 4 cardinal "to <side>" keywords to their angle, regardless of side order, with NO corner set', function () {
    $parser = new DeclarationParser();
    expect(gradientFrom($parser->parse('background-image', 'linear-gradient(to top, red, blue)'))->angleDeg)->toBe(0.0);
    expect(gradientFrom($parser->parse('background-image', 'linear-gradient(to right, red, blue)'))->angleDeg)->toBe(90.0);
    expect(gradientFrom($parser->parse('background-image', 'linear-gradient(to bottom, red, blue)'))->angleDeg)->toBe(180.0);
    expect(gradientFrom($parser->parse('background-image', 'linear-gradient(to left, red, blue)'))->angleDeg)->toBe(270.0);
    // M8 final-review Finding B: a cardinal side's angle is ALWAYS correct regardless of the box's
    // aspect ratio (unlike a corner) -- ->corner stays null, so PdfCanvas never re-derives it.
    expect(gradientFrom($parser->parse('background-image', 'linear-gradient(to top, red, blue)'))->corner)->toBeNull();
    expect($parser->drainWarnings())->toBeEmpty();
});

// M8 final-review Finding B (css-images-3 §3.4.2): the TRUE angle of a `to <corner>` gradient
// depends on the box's aspect ratio, which DeclarationParser (Css\ layer) never knows -- fixed
// 45/135/225/315deg was only ever a square-box approximation. This parser's job is now just to
// record WHICH corner was requested (Css\Value\GradientCorner) -- Pdf\PdfCanvas::resolveAngleDeg()
// computes the real angle at paint time, once the box's final px dimensions are known (see
// PdfCanvasTest for the 400x100 hand-computed 104.04deg case and the 4-corner formulas).
it('maps the 4 corner "to <corner>" keywords to a GradientCorner (real angle resolved at paint time), order-insensitive', function () {
    $parser = new DeclarationParser();
    expect(gradientFrom($parser->parse('background-image', 'linear-gradient(to top right, red, blue)'))->corner)->toBe(GradientCorner::TopRight);
    expect(gradientFrom($parser->parse('background-image', 'linear-gradient(to right top, red, blue)'))->corner)->toBe(GradientCorner::TopRight);
    expect(gradientFrom($parser->parse('background-image', 'linear-gradient(to bottom right, red, blue)'))->corner)->toBe(GradientCorner::BottomRight);
    expect(gradientFrom($parser->parse('background-image', 'linear-gradient(to bottom left, red, blue)'))->corner)->toBe(GradientCorner::BottomLeft);
    expect(gradientFrom($parser->parse('background-image', 'linear-gradient(to top left, red, blue)'))->corner)->toBe(GradientCorner::TopLeft);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('still carries the square-box-approximation angleDeg alongside the corner (fallback/dedup-signature value, unchanged square-box numbers)', function () {
    $parser = new DeclarationParser();
    expect(gradientFrom($parser->parse('background-image', 'linear-gradient(to top right, red, blue)'))->angleDeg)->toBe(45.0);
    expect(gradientFrom($parser->parse('background-image', 'linear-gradient(to bottom right, red, blue)'))->angleDeg)->toBe(135.0);
    expect(gradientFrom($parser->parse('background-image', 'linear-gradient(to bottom left, red, blue)'))->angleDeg)->toBe(225.0);
    expect(gradientFrom($parser->parse('background-image', 'linear-gradient(to top left, red, blue)'))->angleDeg)->toBe(315.0);
});

it('distributes 3 color stops with no explicit position evenly to 0%/50%/100% (css-images-3 §3.4.1 simple rule)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', 'linear-gradient(to right, red, lime, blue)');
    $stops = gradientFrom($result)->stops;
    expect($stops)->toHaveCount(3);
    expect($stops[0]->positionPct)->toBe(0.0);
    expect($stops[1]->positionPct)->toBe(50.0);
    expect($stops[2]->positionPct)->toBe(100.0);
});

it('keeps an explicit stop position and only distributes the ones left unset around it', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', 'linear-gradient(to right, red, lime 20%, blue, yellow)');
    $stops = gradientFrom($result)->stops;
    expect($stops[0]->positionPct)->toBe(0.0);
    expect($stops[1]->positionPct)->toBe(20.0);
    // (blue) is the single gap between the explicit 20% and the implicit 100% end -> midpoint 60%.
    expect($stops[2]->positionPct)->toBe(60.0);
    expect($stops[3]->positionPct)->toBe(100.0);
});

it('clamps a decreasing explicit stop position up to the previous one (monotonic positions, css-images-3 §3.4.1)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', 'linear-gradient(to right, red 50%, blue 10%)');
    $stops = gradientFrom($result)->stops;
    expect($stops[0]->positionPct)->toBe(50.0);
    expect($stops[1]->positionPct)->toBe(50.0);
});

it('parses radial-gradient(circle at center, ...) with zero warnings', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', 'radial-gradient(circle at center, red, blue)');
    expect(gradientFrom($result))->toEqual(new Gradient(GradientKind::Radial, 0.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]));
    expect($parser->drainWarnings())->toBeEmpty();
});

it('parses a bare radial-gradient(red, blue) (no shape/position argument at all) with zero warnings', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', 'radial-gradient(red, blue)');
    expect(gradientFrom($result))->toEqual(new Gradient(GradientKind::Radial, 0.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]));
    expect($parser->drainWarnings())->toBeEmpty();
});

it('falls back to circle-at-center + a warning for a complex radial-gradient() form (ellipse/explicit position)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', 'radial-gradient(ellipse at top left, red, blue)');
    expect(gradientFrom($result))->toEqual(new Gradient(GradientKind::Radial, 0.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]));
    expect($parser->drainWarnings())->toHaveCount(1);
});

it('keeps the alpha channel of a gradient color-stop, with NO warning (M9-T3: soft masks support it)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', 'linear-gradient(rgba(255, 0, 0, 0.5), blue)');
    $stops = gradientFrom($result)->stops;
    expect($stops[0]->color)->toEqual(new Color(255, 0, 0, 0.5));
    expect($parser->drainWarnings())->toBeEmpty();
});

it('rejects a gradient with fewer than 2 color stops with a warning', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', 'linear-gradient(red)');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->toHaveCount(1);
});

it('detects a gradient inside the "background" shorthand', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background', 'linear-gradient(to right, red, blue)');
    expect($result)->toHaveKey('background-gradient');
    expect($parser->drainWarnings())->toBeEmpty();
});

it('detects a plain color inside the "background" shorthand (gradient/color/image detection)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background', '#ff0000');
    // Finding 1 fix (css-backgrounds-3 §5, shorthand reset semantics): the color branch of the
    // `background` shorthand now ALSO emits an explicit 'background-gradient' => null -- a
    // shorthand resets EVERY sub-property it covers, not just the one it declares a value for.
    // A null here (vs. simply omitting the key) is what lets a cascaded gradient from a
    // LESS-specific rule get overridden -- see the dedicated "resets" block below and the
    // cascade-level repro in StyleResolverTest.php.
    // M8-T6: the color branch also resets background-image to null (reset-trio discipline).
    expect($result)->toEqual(['background-color' => new Color(255, 0, 0), 'background-gradient' => null, 'background-image' => null]);
    expect($parser->drainWarnings())->toBeEmpty();
});

// --- M8-T6 (css-backgrounds-3 §4 reducido): background-image: url(...) is now implemented for
// real (the M8-T3 stub used to warn "lands in a later milestone" -- this milestone IS that later
// milestone). Both quoted forms (single/double) are optional per spec; the raw path (unresolved
// against any basePath -- that happens at PAINT time, see Paint\Painter) is what ComputedStyle
// carries under 'background-image'.

it('parses background-image: url(...) with no quotes, storing the raw path', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', 'url(photo.jpg)');
    expect($result)->toEqual(['background-image' => 'photo.jpg', 'background-gradient' => null]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('parses background-image: url(...) with single quotes, stripping them', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', "url('photo.jpg')");
    expect($result)->toEqual(['background-image' => 'photo.jpg', 'background-gradient' => null]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('parses background-image: url(...) with double quotes, stripping them', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', 'url("photo.jpg")');
    expect($result)->toEqual(['background-image' => 'photo.jpg', 'background-gradient' => null]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('parses url() inside the "background" shorthand, resetting color AND gradient', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background', 'url(photo.jpg)');
    expect($result)->toEqual([
        'background-image' => 'photo.jpg',
        'background-color' => null,
        'background-gradient' => null,
    ]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('warns and discards an unbalanced background-image: url(...)', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('background-image', 'url(photo.jpg'))->toBe([]);
    expect($parser->drainWarnings())->toHaveCount(1);
});

it('treats background-image: none as an explicit declaration that wins the cascade (clears gradients AND images)', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('background-image', 'none'))->toBe(['background-gradient' => null, 'background-image' => null]);
    expect($parser->drainWarnings())->toBeEmpty();
});

// --- M8 final-review Finding E: `background: none` must reset the trio, mirroring
// `background-image: none` (mirrored by parseBackgroundImageValue()'s 'none' branch above) --
// before this fix, `none` inside the `background` SHORTHAND fell through every detection branch
// (not a gradient function, not url(), and Color::fromCss('none') returns null -- 'none' is not a
// CSS color keyword) straight to the generic "Unsupported background shorthand" warn+drop, instead
// of being recognized as the explicit reset it is per the spec (background-color/-image/-gradient
// all have 'none'/'transparent' as their initial value).

it('treats background: none as the explicit reset trio (color/gradient/image all null), zero warnings', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background', 'none');
    expect($result)->toEqual(['background-color' => null, 'background-gradient' => null, 'background-image' => null]);
    expect($parser->drainWarnings())->toBeEmpty();
});

// --- RESTRICCIONES GLOBALES M8 ("multiple backgrounds -> primera capa se usa + warning") -------

it('uses only the first layer of a multi-layer background-image, with a warning', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', 'linear-gradient(red, blue), url(photo.jpg)');
    expect(gradientFrom($result))->toEqual(new Gradient(GradientKind::Linear, 180.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]));
    expect($parser->drainWarnings())->toHaveCount(1);
});

it('uses only the first layer of a multi-layer "background" shorthand, with a warning', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background', '#ff0000, linear-gradient(red, blue)');
    // See the Finding 1 fix note above: the color branch always resets background-gradient (and,
    // since M8-T6, background-image too).
    expect($result)->toEqual(['background-color' => new Color(255, 0, 0), 'background-gradient' => null, 'background-image' => null]);
    expect($parser->drainWarnings())->toHaveCount(1);
});

it('does NOT confuse a single gradient function\'s own internal commas with multiple background layers', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', 'linear-gradient(red, lime, blue)');
    expect(gradientFrom($result)->stops)->toHaveCount(3);
    expect($parser->drainWarnings())->toBeEmpty();
});

// --- code review Finding 1 fix (css-backgrounds-3 §5, shorthand reset semantics): `background`
// is a SHORTHAND -- CSS 2.2 §12.5.3 (and css-backgrounds-3 §5 for the modern grammar): a
// shorthand sets EVERY sub-property it covers on EVERY use, resetting the ones it doesn't
// mention to their initial value. Before this fix, the color branch of parseBackgroundShorthand()
// returned ONLY ['background-color' => ...] (no 'background-gradient' key at all) -- a cascaded
// gradient from a LESS-specific rule for the same element survived untouched (both painted). The
// fix: every branch of the shorthand ALSO emits an explicit reset (`null`) for the OTHER
// sub-property -- see StyleResolverTest.php for the cascade-level (two-rule) repro that this unit
// test can't exercise on its own (DeclarationParser::parse() never sees more than one
// declaration at a time; the actual bug lived in how StyleResolver MERGES declarations across
// rules, not in parse() itself).

it('the "background" shorthand color branch explicitly resets background-gradient to null', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background', 'yellow');
    expect($result)->toHaveKey('background-gradient');
    expect($result['background-gradient'])->toBeNull();
    expect($result['background-color'])->toEqual(new Color(255, 255, 0));
});

it('the "background" shorthand gradient branch explicitly resets background-color to null (initial/transparent)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background', 'linear-gradient(red, blue)');
    expect($result)->toHaveKey('background-color');
    expect($result['background-color'])->toBeNull();
    expect(gradientFrom($result))->toEqual(new Gradient(GradientKind::Linear, 180.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]));
});

// --- code review Finding 2 fix (css-images-3 §3.1): looksLikeRadialPrefix() only recognized
// 'circle'/'ellipse'/a leading 'at ' as shape/position geometry -- extent keywords
// (closest-side/closest-corner/farthest-side/farthest-corner), a bare size length
// (`radial-gradient(50px, ...)`), and an explicit size pair (`radial-gradient(50% 50%, ...)`)
// were NOT recognized, so the first argument was treated as an (invalid) color-stop instead --
// Color::fromCss() rejects it, parseGradientStops() returns null, and the WHOLE gradient is
// dropped with a misleading "unsupported gradient stop color" warning instead of degrading via
// the existing circle-at-center fallback. None of the 3 new prefixes can ever collide with a
// real color-stop: no named color contains a hyphen, and no named color parses as a CssLength
// (which requires a leading digit) -- `radial-gradient(red, blue)` (color-first) keeps producing
// zero warnings (already covered above, "parses a bare radial-gradient(...)").

it('degrades a radial-gradient() with an extent-keyword prefix (closest-side) to circle-at-center, with ONE warning (not dropped)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', 'radial-gradient(closest-side, red, blue)');
    expect(gradientFrom($result))->toEqual(new Gradient(GradientKind::Radial, 0.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]));
    expect($parser->drainWarnings())->toHaveCount(1);
});

it('degrades a radial-gradient() with a bare length size prefix (50px) to circle-at-center, with ONE warning (not dropped)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', 'radial-gradient(50px, red, blue)');
    expect(gradientFrom($result))->toEqual(new Gradient(GradientKind::Radial, 0.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]));
    expect($parser->drainWarnings())->toHaveCount(1);
});

it('degrades a radial-gradient() with a percentage-pair size prefix (50% 50%) to circle-at-center, with ONE warning (not dropped)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', 'radial-gradient(50% 50%, red, blue)');
    expect(gradientFrom($result))->toEqual(new Gradient(GradientKind::Radial, 0.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]));
    expect($parser->drainWarnings())->toHaveCount(1);
});

it('still parses radial-gradient(red, blue) (color first, no prefix at all) with zero warnings', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-image', 'radial-gradient(red, blue)');
    expect(gradientFrom($result))->toEqual(new Gradient(GradientKind::Radial, 0.0, [
        new GradientStop(new Color(255, 0, 0), 0.0),
        new GradientStop(new Color(0, 0, 255), 100.0),
    ]));
    expect($parser->drainWarnings())->toBeEmpty();
});

// --- M8-T6 (css-backgrounds-3 §4 reducido): background-size/background-repeat/background-position

it('parses background-size: auto|cover|contain', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('background-size', 'auto'))->toBe(['background-size' => 'auto']);
    expect($parser->parse('background-size', 'cover'))->toBe(['background-size' => 'cover']);
    expect($parser->parse('background-size', 'contain'))->toBe(['background-size' => 'contain']);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('warns and discards background-size when given a concrete length/percentage (only keywords supported in M8)', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('background-size', '50%'))->toBe([]);
    expect($parser->drainWarnings())->toHaveCount(1);
    expect($parser->parse('background-size', '100px'))->toBe([]);
    expect($parser->drainWarnings())->toHaveCount(1);
    expect($parser->parse('background-size', '100px 50px'))->toBe([]);
    expect($parser->drainWarnings())->toHaveCount(1);
});

it('parses background-repeat: no-repeat|repeat to bool', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('background-repeat', 'no-repeat'))->toBe(['background-repeat' => false]);
    expect($parser->parse('background-repeat', 'repeat'))->toBe(['background-repeat' => true]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('warns and discards unsupported background-repeat values (repeat-x/repeat-y/space/round), naming the value', function () {
    $parser = new DeclarationParser();
    foreach (['repeat-x', 'repeat-y', 'space', 'round'] as $value) {
        expect($parser->parse('background-repeat', $value))->toBe([]);
        $warnings = $parser->drainWarnings();
        expect($warnings)->toHaveCount(1);
        expect($warnings[0])->toContain($value);
    }
});

it('parses background-position: center and both spellings of top-left (top left / left top)', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('background-position', 'center'))->toBe(['background-position' => 'center']);
    expect($parser->parse('background-position', 'top left'))->toBe(['background-position' => 'top left']);
    expect($parser->parse('background-position', 'left top'))->toBe(['background-position' => 'top left']);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('normalizes whitespace/case in background-position (e.g. "  Top   Left  ")', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('background-position', '  Top   Left  '))->toBe(['background-position' => 'top left']);
    expect($parser->parse('background-position', 'CENTER'))->toBe(['background-position' => 'center']);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('warns and discards an unsupported background-position (e.g. bottom right)', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('background-position', 'bottom right'))->toBe([]);
    expect($parser->drainWarnings())->toHaveCount(1);
});

// --- M8-T4 (css-backgrounds-3 §6 reducido): box-shadow ------------------------------------------

it('parses a 2-length box-shadow (offset-x/offset-y only, blur defaults to 0px) with an explicit color', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('box-shadow', '4px 6px red');
    expect($result)->toHaveKey('box-shadow');
    $raw = boxShadowRaw($result);
    expect($raw['offsetX'])->toEqual(Length::px(4.0));
    expect($raw['offsetY'])->toEqual(Length::px(6.0));
    expect($raw['blur'])->toEqual(Length::px(0.0));
    expect($raw['color'])->toEqual(new Color(255, 0, 0));
    expect($parser->drainWarnings())->toBeEmpty();
});

it('parses a 3-length box-shadow (offset-x/offset-y/blur-radius)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('box-shadow', '2px 4px 8px #000000');
    $raw = boxShadowRaw($result);
    expect($raw['offsetX'])->toEqual(Length::px(2.0));
    expect($raw['offsetY'])->toEqual(Length::px(4.0));
    expect($raw['blur'])->toEqual(Length::px(8.0));
    expect($raw['color'])->toEqual(new Color(0, 0, 0));
    expect($parser->drainWarnings())->toBeEmpty();
});

it('defaults box-shadow color to the currentColor sentinel when no color is declared', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('box-shadow', '4px 6px');
    $raw = boxShadowRaw($result);
    expect($raw['color']->isCurrentColor)->toBeTrue();
    expect($parser->drainWarnings())->toBeEmpty();
});

it('accepts offset-x/offset-y before OR after the color, in any order', function () {
    $parser = new DeclarationParser();
    $a = $parser->parse('box-shadow', 'red 4px 6px');
    $b = $parser->parse('box-shadow', '4px 6px red');
    expect($a)->toEqual($b);
});

it('warns and DROPS the whole box-shadow when a 4th length (spread) is present, but still emits the shadow with the first 3 (M8: spread ignored)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('box-shadow', '2px 4px 8px 3px red');
    $raw = boxShadowRaw($result);
    expect($raw['offsetX'])->toEqual(Length::px(2.0));
    expect($raw['offsetY'])->toEqual(Length::px(4.0));
    expect($raw['blur'])->toEqual(Length::px(8.0));
    expect($raw['color'])->toEqual(new Color(255, 0, 0));
    expect($parser->drainWarnings())->toBe(['box-shadow spread not supported (ignored): 2px 4px 8px 3px red']);
});

it('warns and DROPS the entire box-shadow declaration when inset is present (M8: no inset support)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('box-shadow', 'inset 2px 4px red');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('warns and drops box-shadow with fewer than 2 lengths', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('box-shadow', '4px red');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('warns and drops box-shadow with a negative blur radius', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('box-shadow', '2px 4px -8px red');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('uses only the first shadow of a comma-separated multi-shadow list, with a warning', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('box-shadow', '2px 2px red, 4px 4px blue');
    $raw = boxShadowRaw($result);
    expect($raw['color'])->toEqual(new Color(255, 0, 0));
    expect($parser->drainWarnings())->toBe(['Multiple box-shadows not supported (using the first only): 2px 2px red, 4px 4px blue']);
});

it('treats box-shadow: none as an explicit reset (null value, no warning)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('box-shadow', 'none');
    expect($result)->toBe(['box-shadow' => null]);
    expect($parser->drainWarnings())->toBeEmpty();
});

// --- M8-T5 (css-text-3 §8 reducido): letter-spacing/word-spacing/text-transform ---------------

it('parses letter-spacing/word-spacing as normal (explicit null reset) or a px length', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('letter-spacing', 'normal'))->toBe(['letter-spacing' => null]);
    expect($parser->parse('letter-spacing', '2px'))->toEqual(['letter-spacing' => Length::px(2.0)]);
    expect($parser->parse('word-spacing', 'normal'))->toBe(['word-spacing' => null]);
    expect($parser->parse('word-spacing', '4px'))->toEqual(['word-spacing' => Length::px(4.0)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('accepts em/rem for letter-spacing/word-spacing as symbolic CssLength (resolved in ComputedStyle::compute)', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('letter-spacing', '0.1em'))->toEqual(['letter-spacing' => CssLength::of(0.1, LengthUnit::Em)]);
    expect($parser->parse('word-spacing', '0.5rem'))->toEqual(['word-spacing' => CssLength::of(0.5, LengthUnit::Rem)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('accepts negative letter-spacing/word-spacing (CSS 2.2 §16.3.1/§16.4 allow negative, unlike most lengths)', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('letter-spacing', '-1px'))->toEqual(['letter-spacing' => Length::px(-1.0)]);
    expect($parser->parse('word-spacing', '-2px'))->toEqual(['word-spacing' => Length::px(-2.0)]);
    expect($parser->drainWarnings())->toBeEmpty();
});

it('warns on an unsupported letter-spacing/word-spacing value (e.g. a percentage)', function () {
    foreach (['letter-spacing', 'word-spacing'] as $property) {
        $parser = new DeclarationParser();
        $result = $parser->parse($property, '10%');
        expect($result)->toBe([]);
        expect($parser->drainWarnings())->not->toBeEmpty();
    }
});

it('parses text-transform none/uppercase/lowercase/capitalize and warns on an unsupported keyword', function () {
    $parser = new DeclarationParser();
    expect($parser->parse('text-transform', 'none'))->toBe(['text-transform' => 'none']);
    expect($parser->parse('text-transform', 'uppercase'))->toBe(['text-transform' => 'uppercase']);
    expect($parser->parse('text-transform', 'lowercase'))->toBe(['text-transform' => 'lowercase']);
    expect($parser->parse('text-transform', 'capitalize'))->toBe(['text-transform' => 'capitalize']);
    expect($parser->drainWarnings())->toBeEmpty();

    $parser = new DeclarationParser();
    $result = $parser->parse('text-transform', 'full-width');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

// --- M9-T2: transition/animation are a SILENT no-op in print media (RESTRICCIONES GLOBALES) -----
// A static paginated document has no dynamic state to transition between and no time axis to
// animate over -- these declarations are a legitimate print no-op, same spirit as @keyframes
// (already skipped for free -- KeyFrame doesn't extend CSSBlockList, see StylesheetParser's
// docblock) -- so unlike every OTHER unsupported property, they must NOT warn at all.

it('silently drops transition (shorthand) with no warning and no declaration recorded', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('transition', 'color .15s ease-in-out, background-color .15s ease-in-out');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->toBe([]);
});

it('silently drops transition longhands and vendor-prefixed variants, with no warning', function () {
    $parser = new DeclarationParser();
    foreach (['transition-property', 'transition-duration', '-webkit-transition', '-moz-transition', '-o-transition'] as $property) {
        expect($parser->parse($property, 'opacity'))->toBe([]);
    }
    expect($parser->drainWarnings())->toBe([]);
});

it('silently drops animation (shorthand) and longhands, with no warning', function () {
    $parser = new DeclarationParser();
    foreach (['animation', 'animation-name', 'animation-duration', '-webkit-animation'] as $property) {
        expect($parser->parse($property, 'spin 1s linear infinite'))->toBe([]);
    }
    expect($parser->drainWarnings())->toBe([]);
});

it('does not silence an unrelated property that merely starts with "trans" (no false-positive match)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('transform', 'rotate(45deg)');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

// --- M10-T3: color-mix() (css-color-4 §16) -- warning + fallback to the first color -------------

it('warns and resolves color-mix() to its first color argument (background-color)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('background-color', 'color-mix(in srgb, red 50%, blue)');
    expect($result['background-color'])->toEqual(Color::fromCss('red'));
    $warnings = $parser->drainWarnings();
    expect($warnings)->toHaveCount(1);
    expect($warnings[0])->toContain('color-mix() is not supported');
    expect($warnings[0])->toContain('falling back to its first color');
});

it('warns and resolves color-mix() to currentcolor for the Tailwind preflight ::placeholder shape', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('color', 'color-mix(in oklab, currentcolor 50%, transparent)');
    expect($result['color'])->toEqual(Color::currentColor());
    expect($parser->drainWarnings())->toHaveCount(1);
});

it('warns exactly once per declaration for color-mix() used as a border shorthand color component', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('border', '1px solid color-mix(in srgb, green 50%, black)');
    expect($result['border-top-color'] ?? null)->toEqual(Color::fromCss('green'));
    expect($parser->drainWarnings())->toHaveCount(1);
});
