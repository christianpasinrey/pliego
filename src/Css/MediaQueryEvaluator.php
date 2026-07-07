<?php

declare(strict_types=1);

namespace Pliego\Css;

use Pliego\Css\Value\CssLength;
use Pliego\Css\Value\LengthUnit;

/**
 * M10-T2 (css-mediaqueries-4, reduced): extracted from StylesheetParser::mediaQueryApplies() (was
 * a two-value string comparison, M9-T2/M10-T1 — see that class's own docblock for the full history
 * of 'only'/print/all/screen handling this class now owns too) to add REAL width-feature
 * evaluation on top, per the M10-T2 brief: `(min-width: N)`/`(max-width: N)`/`(width: N)` compared
 * against the page's own CSS-px size — this is a print engine, so there is no browser viewport to
 * query; the adjudicated stand-in (same one T1 used for vw/vh, Css\Value\LengthUnit's docblock) is
 * the PAPER BOX width in CSS px, threaded in from the same place vw/vh's pageWidthPx already comes
 * from (Engine::render() → Page\PaperSize::widthPx(), see StylesheetParser::parse()'s new
 * parameter). Bootstrap prints its real breakpoints against THIS number: on A4 (793.70px), 576/768
 * min-widths apply, 992/1200/1400 do not — exactly what Chrome does when printing an A4 page (no
 * emulated device width, the paper IS the viewport).
 *
 * Evaluation grammar (css-mediaqueries-4 §3-4, reduced — no ranges like `(400px <= width)`, no
 * `not`, no boolean-context features like `(hover)`/`(color)`):
 *  - A media query LIST (comma-separated) applies if ANY of its comma-separated queries applies
 *    (OR semantics, css-mediaqueries-4 §3.1) — same as before this task.
 *  - A single query is an optional 'only' prefix (stripped, carries no evaluation semantics of its
 *    own — M10-T1) followed by an optional media type (`print`/`all`/`screen`) and zero or more
 *    `and (feature: value)` clauses, ALL of which must hold (AND semantics, §3.2) for the whole
 *    query to apply. `all`/no type present, is like `all` — a query with ONLY feature clauses and
 *    no leading type token (Bootstrap's actual shape: `@media (min-width: 768px) { ... }`, no
 *    `screen and` prefix) is evaluated purely on its feature clauses.
 *  - `screen` as the type token makes the WHOLE query never apply (this engine's implied medium is
 *    always 'print', css-mediaqueries-4 §2) regardless of any feature clauses that follow.
 *  - `min-width`/`max-width`/`width` accept px/rem/em (rem and em both resolve against a FIXED
 *    16px root — media-feature evaluation happens before any cascade exists, so there is no
 *    author root font-size to consult, unlike ComputedStyle's real $remBase) — a real numeric
 *    comparison against the page's CSS-px width, per the brief.
 *  - Any OTHER feature name (`hover`, `prefers-reduced-motion`, `prefers-color-scheme`, ...), or a
 *    length in a unit this evaluator doesn't resolve (`%`, `vw`/`vh`, or anything CssLength itself
 *    rejects), makes that CLAUSE not hold — conservative skip, same aggregated warning category as
 *    before (StylesheetParser counts skipped BLOCKS, not per-feature reasons; see its own
 *    docblock) — no new warning shape introduced by this class.
 */
final class MediaQueryEvaluator
{
    /**
     * css-mediaqueries-4 has no viewport-relative root font-size concept at evaluation time (no
     * cascade has run yet) — 16px is the universal CSS initial value (css-fonts-4 §4.3), used here
     * exactly as browsers do for any (rare) em/rem-valued media feature.
     */
    private const float ROOT_FONT_SIZE_PX = 16.0;

    public function applies(string $query, float $pageWidthPx): bool
    {
        foreach (explode(',', $query) as $part) {
            if ($this->partApplies($part, $pageWidthPx)) {
                return true;
            }
        }
        return false;
    }

    private function partApplies(string $part, float $pageWidthPx): bool
    {
        $normalized = trim($part);
        // M10-T1 (css-mediaqueries-3 §2.3): 'only' carries no evaluation semantics, stripped
        // before anything else sees the query — same normalization as before this task, now
        // applied ahead of the 'and' split so it also covers combos like 'only screen and
        // (min-width: 768px)', not just a bare type.
        $normalized = preg_replace('/^only\s+/i', '', $normalized) ?? $normalized;
        if ($normalized === '') {
            return false;
        }
        $tokens = preg_split('/\s+and\s+/i', $normalized) ?: [];
        foreach ($tokens as $token) {
            if (!$this->tokenApplies(trim($token), $pageWidthPx)) {
                return false;
            }
        }
        return true;
    }

    private function tokenApplies(string $token, float $pageWidthPx): bool
    {
        $lower = strtolower($token);
        if ($lower === 'all' || $lower === 'print') {
            return true;
        }
        if ($lower === 'screen') {
            return false;
        }
        if (preg_match('/^\(\s*([a-z-]+)\s*:\s*(.+?)\s*\)$/i', $token, $m) === 1) {
            $feature = strtolower($m[1]);
            $valuePx = $this->resolveLengthPx($m[2]);
            if ($valuePx === null) {
                return false;
            }
            return match ($feature) {
                'min-width' => $pageWidthPx >= $valuePx,
                'max-width' => $pageWidthPx <= $valuePx,
                'width' => abs($pageWidthPx - $valuePx) < 0.001,
                // Any other feature name (hover, prefers-reduced-motion, prefers-color-scheme,
                // ...) is unknown to this reduced evaluator -- conservative skip.
                default => false,
            };
        }
        // Unrecognized token shape: an unknown media type keyword, a boolean-context feature like
        // `(hover)` (no colon), a range syntax, etc. -- conservative skip, same as an unknown
        // feature name above.
        return false;
    }

    private function resolveLengthPx(string $value): ?float
    {
        $length = CssLength::fromCss($value);
        if ($length === null) {
            return null;
        }
        return match ($length->unit) {
            LengthUnit::Px => $length->value,
            LengthUnit::Rem, LengthUnit::Em => $length->value * self::ROOT_FONT_SIZE_PX,
            // %, vw, vh and any other symbolic unit have no meaning for a media feature length
            // (css-mediaqueries-4 §4.2 restricts these features to <length>, not <length-percentage>)
            // -- conservative skip via null, same as an unparseable value.
            default => null,
        };
    }
}
