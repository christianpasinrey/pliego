<?php

declare(strict_types=1);

use Pliego\Css\StylesheetParser;
use Pliego\Css\Value\Color;
use Pliego\Css\Value\Length;

it('parses rules into typed declarations', function () {
    $result = new StylesheetParser()->parse('p { color: #f00; margin: 8px; font-size: 14px }');
    expect($result->rules)->toHaveCount(1);
    $d = $result->rules[0]->declarations;
    expect($d['color'])->toEqual(new Color(255, 0, 0));
    expect($d['margin-top'])->toEqual(Length::px(8.0));
    expect($d['margin-left'])->toEqual(Length::px(8.0));
    expect($d['font-size'])->toEqual(Length::px(14.0));
});
it('expands two-value margin shorthand', function () {
    $d = new StylesheetParser()->parse('div { margin: 4px 12px }')->rules[0]->declarations;
    expect($d['margin-top'])->toEqual(Length::px(4.0));
    expect($d['margin-right'])->toEqual(Length::px(12.0));
    expect($d['margin-bottom'])->toEqual(Length::px(4.0));
    expect($d['margin-left'])->toEqual(Length::px(12.0));
});
it('keeps rule order for the cascade', function () {
    $rules = new StylesheetParser()->parse('p { color: red } p { color: blue }')->rules;
    expect($rules[0]->order)->toBeLessThan($rules[1]->order);
});
it('warns on unsupported properties and selectors without failing', function () {
    $result = new StylesheetParser()->parse('p > span { float: left; color: red }');
    expect($result->rules)->toHaveCount(0);
    expect($result->warnings)->not->toBeEmpty();
});
it('lets the last declaration of a property within a rule win', function () {
    $d = new StylesheetParser()->parse('p { color: #f00; color: #00f }')->rules[0]->declarations;
    expect($d['color'])->toEqual(new Color(0, 0, 255));
});
