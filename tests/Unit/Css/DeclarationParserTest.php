<?php

declare(strict_types=1);

use Pliego\Css\DeclarationParser;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\Color;
use Pliego\Css\Value\Length;
use Pliego\Css\Value\LengthPercentage;

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

it('warns on % for font-size (unsupported until M3+)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('font-size', '150%');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
});

it('warns on % for line-height (unsupported until M3+)', function () {
    $parser = new DeclarationParser();
    $result = $parser->parse('line-height', '150%');
    expect($result)->toBe([]);
    expect($parser->drainWarnings())->not->toBeEmpty();
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
