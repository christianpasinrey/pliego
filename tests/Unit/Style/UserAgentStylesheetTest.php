<?php

declare(strict_types=1);

use Pliego\Css\StylesheetParser;
use Pliego\Style\UserAgentStylesheet;

it('parses as real CSS with zero warnings (self-check: the UA sheet must be valid CSS this engine understands)', function () {
    $result = new StylesheetParser()->parse(UserAgentStylesheet::css());
    expect($result->warnings)->toBeEmpty();
    expect($result->rules)->not->toBeEmpty();
});

it('tags every rule with userAgent=true', function () {
    foreach (UserAgentStylesheet::rules() as $rule) {
        expect($rule->userAgent)->toBeTrue();
        expect($rule->important)->toBeFalse();
    }
});

it('memoizes the parsed rule list (same array contents across calls, parsed only once)', function () {
    $first = UserAgentStylesheet::rules();
    $second = UserAgentStylesheet::rules();
    expect($second)->toBe($first);
});

it('StyleRule::withOrigin() returns a copy with only the origin flag changed', function () {
    $result = new StylesheetParser()->parse('p { color: red }');
    $rule = $result->rules[0];
    $ua = $rule->withOrigin(true);
    expect($ua->userAgent)->toBeTrue();
    expect($rule->userAgent)->toBeFalse();
    expect($ua->selector)->toBe($rule->selector);
    expect($ua->declarations)->toBe($rule->declarations);
    expect($ua->order)->toBe($rule->order);
    expect($ua->important)->toBe($rule->important);
});
