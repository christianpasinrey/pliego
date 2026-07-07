<?php

// tools/oracle/src/FixtureHtml.php
declare(strict_types=1);

namespace PliegoOracle;

/**
 * M9-T5: the oracle's fixtures are self-contained HTML files (`<style>` inline in `<head>`) --
 * Chrome parses that natively via file://, but Engine::render() has NO auto-extraction of
 * `<style>` tags (by design: this codebase's whole API is CSS and HTML as two separate strings,
 * see every EndToEnd test's `Engine::make()->stylesheet($css)->render($html)` shape; ext-dom's
 * `\Dom\HTMLDocument`, which BoxTreeBuilder walks from `$document->body` -- see its own docblock
 * -- never even visits `<head>`, so an inline `<style>` block is silently invisible to it, not
 * garbled into the page as text).
 *
 * Discovered the hard way (M9-T5 calibration): passing a fixture's full HTML straight to
 * ->render() with no ->stylesheet() call renders every element with the UA default stylesheet
 * only (Style\UserAgentStylesheet) -- h1 at UA's 2em/bold instead of the fixture's own 24px rule,
 * body copy at UA's default instead of the fixture's 13px, etc. Silent, not an exception: this
 * class exists so render-pliego.php (and the Pest smoke test) extract the SAME `<style>` text
 * Chrome parsed and hand it to the engine explicitly, restoring the "same CSS, same HTML, two
 * renderers" premise the oracle depends on.
 */
final class FixtureHtml
{
    /** Concatenates every inline `<style>...</style>` block's contents, in document order. */
    public static function extractInlineCss(string $html): string
    {
        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $matches) === false) {
            throw new \RuntimeException('FixtureHtml: regex failure while extracting inline <style> blocks.');
        }
        return implode("\n", $matches[1]);
    }
}
