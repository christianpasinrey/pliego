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

    /**
     * M9-T6: fixture 07 (the full Bootstrap page) is the first oracle fixture that pulls Bootstrap
     * itself in via `<link rel="stylesheet" href="../../../resources/presets/bootstrap.min.css">`
     * instead of inlining 232KB into a `<style>` block -- Playwright's file:// navigation resolves
     * that relative href exactly like a real page (see render-chrome.mjs's own docblock), but
     * Engine::render() has no HTML parsing of `<link>` at all (by design -- see this class's own
     * docblock on the CSS/HTML-as-two-strings API). This method extends extractInlineCss() to also
     * read every `<link rel="stylesheet">`'s `href`, resolved against $baseDir (the fixture's own
     * directory -- same convention Image\ImagePathResolver uses for `<img src>`), and concatenates
     * their content BEFORE the inline `<style>` blocks' -- so a fixture's own inline overrides
     * (e.g. the DejaVu Sans @font-face + a `:root` font-family override, both needed so pliego and
     * Chrome pick the same glyph metrics) still win the cascade by AUTHOR ORDER, exactly matching
     * the document order Chrome itself applies (`<link>` first, `<style>` after -- see fixture 07).
     *
     * Deliberately a document-order-of-TAG-TYPE approximation (all `<link>`s' content, then all
     * `<style>`s'), not a true position-in-document interleave -- correct for every fixture this
     * milestone ships (each has at most one `<link>`, always before its `<style>` block) and far
     * simpler than a real DOM walk; a fixture that needed a `<style>` BEFORE a `<link>` to still be
     * overridden by it would need this method extended, not fixture content worked around.
     *
     * Backward compatible with fixtures 01-06 (no `<link>` at all): behaves identically to
     * extractInlineCss() when none is found (see FixtureHtmlTest.php).
     */
    public static function extractCss(string $html, string $baseDir): string
    {
        if (preg_match_all('/<link\b[^>]*>/i', $html, $linkMatches) === false) {
            throw new \RuntimeException('FixtureHtml: regex failure while extracting <link> tags.');
        }

        $linkedCss = [];
        foreach ($linkMatches[0] as $linkTag) {
            if (preg_match('/\brel\s*=\s*["\']?stylesheet["\']?/i', $linkTag) !== 1) {
                continue;
            }
            if (preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $linkTag, $hrefMatch) !== 1) {
                continue;
            }
            $path = rtrim($baseDir, '/\\') . '/' . $hrefMatch[1];
            if (!is_file($path)) {
                throw new \RuntimeException("FixtureHtml: could not read linked stylesheet: $path");
            }
            $css = (string) file_get_contents($path);
            $linkedCss[] = $css;
        }

        $parts = array_filter([...$linkedCss, self::extractInlineCss($html)], static fn(string $css): bool => $css !== '');
        return implode("\n", $parts);
    }

    /**
     * Removes all `<style>...</style>` blocks from the HTML while preserving all other content.
     * This is needed to prevent Engine::render() from emitting a spurious "style-tag-ignored"
     * warning after the CSS has been extracted and applied via ->stylesheet() -- the engine
     * has no HTML parsing of <style> tags (by design: CSS and HTML are two separate strings),
     * so any remaining <style> tags are truly ignored (see Engine.php M9-T6 for the warning).
     *
     * Keeps <link> tags intact -- they are already ignored by the engine without warning.
     */
    public static function stripStyleTags(string $html): string
    {
        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $matches, PREG_OFFSET_CAPTURE) === false) {
            throw new \RuntimeException('FixtureHtml: regex failure while stripping <style> tags.');
        }

        // Process matches in reverse order to maintain correct string positions as we remove them
        $positions = array_reverse($matches[0]);
        foreach ($positions as [$fullMatch, $offset]) {
            $html = substr_replace($html, '', $offset, strlen($fullMatch));
        }

        return $html;
    }
}
