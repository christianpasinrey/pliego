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

// --- extractCss(): M9-T6, fixture 07's <link rel="stylesheet"> (Bootstrap's own 232KB minified
// sheet) + a <style> override AFTER it -- see FixtureHtml's own docblock for why a fixture needs
// BOTH a linked and an inline stylesheet, unlike fixtures 01-06's <style>-only shape. -------------

function fixtureHtmlTempCssDir(): string
{
    $dir = sys_get_temp_dir() . '/pliego-fixturehtml-' . uniqid();
    mkdir($dir);
    return $dir;
}

it('extractCss(): resolves a <link rel="stylesheet" href="..."> against $baseDir and reads its content', function () {
    $dir = fixtureHtmlTempCssDir();
    file_put_contents($dir . '/vendor.css', '.btn { color: blue; }');
    $html = '<html><head><link rel="stylesheet" href="vendor.css"></head><body></body></html>';

    expect(FixtureHtml::extractCss($html, $dir))->toBe('.btn { color: blue; }');
});

it('extractCss(): concatenates the linked stylesheet BEFORE inline <style> content, so a later inline rule wins the cascade (author order)', function () {
    $dir = fixtureHtmlTempCssDir();
    file_put_contents($dir . '/vendor.css', '.btn { color: blue; }');
    $html = '<link rel="stylesheet" href="vendor.css"><style>.btn { color: red; }</style>';

    expect(FixtureHtml::extractCss($html, $dir))->toBe(".btn { color: blue; }\n.btn { color: red; }");
});

it('extractCss(): ignores a <link> with no rel="stylesheet" (e.g. rel="icon")', function () {
    $dir = fixtureHtmlTempCssDir();
    file_put_contents($dir . '/favicon.ico', 'not-css');
    $html = '<link rel="icon" href="favicon.ico"><style>p{}</style>';

    expect(FixtureHtml::extractCss($html, $dir))->toBe('p{}');
});

it('extractCss(): behaves exactly like extractInlineCss() when there is no <link> at all (fixtures 01-06)', function () {
    $html = '<style>a{}</style><body><style>b{}</style></body>';
    expect(FixtureHtml::extractCss($html, sys_get_temp_dir()))->toBe(FixtureHtml::extractInlineCss($html));
});

it('extractCss(): throws when a linked stylesheet file does not exist', function () {
    $dir = fixtureHtmlTempCssDir();
    $html = '<link rel="stylesheet" href="missing.css">';

    FixtureHtml::extractCss($html, $dir);
})->throws(RuntimeException::class);
