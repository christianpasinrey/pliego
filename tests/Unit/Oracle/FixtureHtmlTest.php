<?php

// tests/Unit/Oracle/FixtureHtmlTest.php
declare(strict_types=1);

use PliegoOracle\FixtureHtml;

it('extracts a single inline <style> block\'s contents', function () {
    $html = "<html><head><style>h1 { color: red; }</style></head><body></body></html>";
    expect(FixtureHtml::extractInlineCss($html))->toBe('h1 { color: red; }');
});

it('concatenates multiple <style> blocks in document order', function () {
    $html = '<style>a{}</style><body><style>b{}</style></body>';
    expect(FixtureHtml::extractInlineCss($html))->toBe("a{}\nb{}");
});

it('returns an empty string when there is no <style> tag at all', function () {
    expect(FixtureHtml::extractInlineCss('<body><p>no css here</p></body>'))->toBe('');
});

it('matches a <style> tag carrying attributes (e.g. type="text/css")', function () {
    $html = '<style type="text/css">p { margin: 0; }</style>';
    expect(FixtureHtml::extractInlineCss($html))->toBe('p { margin: 0; }');
});
