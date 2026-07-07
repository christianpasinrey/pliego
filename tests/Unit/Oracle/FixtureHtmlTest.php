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

// --- stripStyleTags(): M9-T5, removes <style>...</style> blocks while keeping <link> tags
// intact -- needed to prevent spurious "style-tag-ignored" warnings after CSS extraction. ---

it('stripStyleTags(): removes a single <style> block', function () {
    $html = '<html><head><style>h1 { color: red; }</style></head><body><p>text</p></body></html>';
    $stripped = FixtureHtml::stripStyleTags($html);

    expect($stripped)->not()->toContain('<style>');
    expect($stripped)->not()->toContain('h1 { color: red; }');
    expect($stripped)->toContain('<p>text</p>');
    expect($stripped)->toContain('<html>');
    expect($stripped)->toContain('</body>');
});

it('stripStyleTags(): removes multiple <style> blocks in any location', function () {
    $html = '<head><style>a{}</style></head><body><style>b{}</style><p>content</p></body>';
    $stripped = FixtureHtml::stripStyleTags($html);

    expect($stripped)->toBe('<head></head><body><p>content</p></body>');
});

it('stripStyleTags(): preserves <link> tags (e.g. Bootstrap)', function () {
    $html = '<head><link rel="stylesheet" href="bootstrap.css"><style>p{margin:0}</style></head><body>text</body>';
    $stripped = FixtureHtml::stripStyleTags($html);

    expect($stripped)->toContain('<link rel="stylesheet" href="bootstrap.css">');
    expect($stripped)->not()->toContain('<style>');
    expect($stripped)->toContain('text');
});

it('stripStyleTags(): preserves <style> tags with attributes (type, media, etc.) by removing the whole tag', function () {
    $html = '<style type="text/css" media="print">h1 { font-size: 20pt; }</style><p>content</p>';
    $stripped = FixtureHtml::stripStyleTags($html);

    expect($stripped)->not()->toContain('<style');
    expect($stripped)->not()->toContain('font-size');
    expect($stripped)->toContain('<p>content</p>');
});

it('stripStyleTags(): returns unchanged HTML when there is no <style> tag', function () {
    $html = '<html><head><link rel="stylesheet" href="style.css"></head><body><p>no css</p></body></html>';
    $stripped = FixtureHtml::stripStyleTags($html);

    expect($stripped)->toBe($html);
});

// --- HTML comment blindness (M9 final-review Finding 1): a fixture's own prose comment
// containing the literal text "<style>" must not confuse the tag-matching regexes. Both
// stripStyleTags() and extractInlineCss()/extractCss() match `<style\b[^>]*>(.*?)<\/style>`
// non-greedily -- if that literal text appears inside a `<!-- ... -->` comment BEFORE a real
// `<style>` block, the match starts at the comment's fake occurrence and runs through the first
// REAL `</style>`, eating the comment's own `-->` terminator plus everything in between (a real
// `<link>` and the real `<style>` open tag). That leaves an unterminated `<!--` that swallows the
// rest of the document from an HTML parser's point of view -- exactly what happened to
// tools/oracle/fixtures/07-bootstrap-page.html's head comment (mentions "<style> block" in prose
// right before the real `<link>` + `<style>`), blanking the whole rendered page. Comments carry
// nothing for rendering, so both methods now strip/ignore them entirely before any tag matching.

it('stripStyleTags(): ignores a literal "<style>" mention inside an HTML comment (fixture-07 shape) and does not swallow the rest of the document', function () {
    $html = '<head><!-- see the <style> block below --><link rel="stylesheet" href="v.css"><style>h1{color:red}</style></head><body><p>text</p></body>';
    $stripped = FixtureHtml::stripStyleTags($html);

    expect($stripped)->toContain('<link rel="stylesheet" href="v.css">');
    expect($stripped)->toContain('<p>text</p>');
    expect($stripped)->not()->toContain('<style');
    expect($stripped)->not()->toContain('h1{color:red}');
    expect($stripped)->not()->toContain('<!--');
});

it('extractInlineCss(): ignores a literal "<style>" mention inside an HTML comment (fixture-07 shape)', function () {
    $html = '<head><!-- see the <style> block below --><style>h1{color:red}</style></head>';
    expect(FixtureHtml::extractInlineCss($html))->toBe('h1{color:red}');
});

it('extractCss(): ignores a literal "<style>" mention inside an HTML comment, still reads the real <link> AND <style>', function () {
    $dir = fixtureHtmlTempCssDir();
    file_put_contents($dir . '/v.css', '.btn{color:blue}');
    $html = '<head><!-- see the <style> block below --><link rel="stylesheet" href="v.css"><style>h1{color:red}</style></head>';

    expect(FixtureHtml::extractCss($html, $dir))->toBe(".btn{color:blue}\nh1{color:red}");
});
