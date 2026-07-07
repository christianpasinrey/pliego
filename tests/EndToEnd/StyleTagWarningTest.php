<?php

// tests/EndToEnd/StyleTagWarningTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * M9-T6 (controller addition #1, T5-review): a real engine gap discovered while building the
 * oracle fixtures (tools/oracle/src/FixtureHtml.php's own docblock tells the same story from the
 * oracle side) -- this codebase's whole public API is CSS and HTML as two SEPARATE strings
 * (`Engine::make()->stylesheet($css)->render($html)`), but nothing stops a caller from pasting a
 * normal HTML document that ALSO happens to carry its own inline `<style>` block (in `<head>` or
 * `<body>` -- HTML5 allows either). Dom\HtmlParser::parse() hands back the full
 * `\Dom\HTMLDocument`, but Box\BoxTreeBuilder::build() only ever walks `$document->body` (see its
 * own docblock) and never visits `<head>`; a `<style>` element inside `<body>` is walked too, but
 * BoxTreeBuilder has no case for the `style` tag name so it is silently skipped as an unknown
 * element with no children painted. Either way: the CSS inside is NEVER parsed, NEVER applied, and
 * -- before this task -- NEVER mentioned. A caller who pastes a self-contained HTML page (the
 * single most natural thing to try first) gets an unstyled document with zero explanation.
 *
 * This task does NOT auto-extract and apply `<style>` content (a real feature, M10 candidate,
 * needs its own design re: cascade ordering against ->stylesheet() calls) -- it only makes the gap
 * VISIBLE: one warning, emitted at most once per render (addWarningOnce, same "one per instance"
 * discipline as every other structural warning in this codebase -- see Css\WarningCollector's own
 * docblock), whenever the parsed document contains at least one `<style>` element ANYWHERE (head
 * OR body).
 *
 * Helper functions prefixed `styleTagWarning` (unique-per-file convention, see every other
 * EndToEnd test file's own docblock -- Pest loads every file's top-level functions into ONE
 * process).
 */

const STYLE_TAG_WARNING_MESSAGE = 'style tags are ignored; pass CSS via stylesheet()';

/** @return array{0: string, 1: \Pliego\RenderReport} */
function styleTagWarningRender(string $html): array
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::make()->render($html)->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

it('warns when the document has a <style> element in <head>', function () {
    $html = '<html><head><style>p { color: red }</style></head><body><p>Texto</p></body></html>';
    [$pdf, $report] = styleTagWarningRender($html);

    expect($pdf)->toStartWith('%PDF-1.7');
    expect($report->warnings)->toContain(STYLE_TAG_WARNING_MESSAGE);
});

it('warns when the document has a <style> element in <body> (not just <head>)', function () {
    $html = '<body><style>p { color: red }</style><p>Texto</p></body>';
    [, $report] = styleTagWarningRender($html);

    expect($report->warnings)->toContain(STYLE_TAG_WARNING_MESSAGE);
});

it('emits the warning only ONCE even with multiple <style> elements', function () {
    $html = '<body><style>p { color: red }</style><style>h1 { color: blue }</style><p>Texto</p></body>';
    [, $report] = styleTagWarningRender($html);

    expect(array_count_values($report->warnings)[STYLE_TAG_WARNING_MESSAGE] ?? 0)->toBe(1);
});

it('never warns when the document has no <style> element at all', function () {
    $html = '<body><p>Texto sin estilos embebidos.</p></body>';
    [, $report] = styleTagWarningRender($html);

    expect($report->warnings)->not->toContain(STYLE_TAG_WARNING_MESSAGE);
});

it('still ignores the <style> content -- it is a warning, not auto-application (M10 candidate)', function () {
    // p stays at the UA default color (not red) -- the CSS text inside <style> genuinely never
    // reaches StyleResolver; only ->stylesheet() (or Engine::bootstrap()'s preset) does.
    $html = '<body><style>p { color: rgb(255, 0, 0) }</style><p>Texto</p></body>';
    [$pdf, ] = styleTagWarningRender($html);

    $redFill = sprintf('%.3F %.3F %.3F rg', 1.0, 0.0, 0.0);
    expect($pdf)->not->toContain($redFill);
});
