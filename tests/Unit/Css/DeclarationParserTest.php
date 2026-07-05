<?php

declare(strict_types=1);

use Pliego\Css\DeclarationParser;
use Pliego\Css\Value\Length;

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
    expect($result)->toEqual(['margin-left' => Length::px(-5.0)]);
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
