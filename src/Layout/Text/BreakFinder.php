<?php

declare(strict_types=1);

namespace Pliego\Layout\Text;

/**
 * Finds line-break opportunities in a run of text, implementing a small
 * subset of UAX#14 (Unicode Line Breaking Algorithm) sufficient for M1.
 *
 * Rules implemented (UAX#14 rule ids in parentheses):
 *  - LB18: an opportunity exists AFTER a space (U+0020, class SP).
 *  - LB21a (simplified): an opportunity exists after a hyphen (U+2010
 *    HYPHEN or U+002D HYPHEN-MINUS) when it is immediately followed by a
 *    letter. A trailing hyphen (end of string, or followed by anything
 *    other than a letter) does NOT produce an opportunity.
 *  - GL / LB12: U+00A0 (NO-BREAK SPACE) never produces a break opportunity
 *    (handled implicitly: no rule matches it).
 *  - LB4 / LB5: U+000A (LINE FEED) produces a MANDATORY break. In practice
 *    hard line breaks normally arrive from the box tree as a dedicated
 *    LineBreakRun rather than as a literal "\n" inside a TextRun's text,
 *    but the finder supports it directly for completeness/robustness.
 *  - An opportunity is never reported at byte offset 0 (breaking "before"
 *    the very first byte of a run is meaningless).
 *
 * Offsets are BYTE offsets into the original UTF-8 string (usable directly
 * with `substr()`), computed in a single pass over codepoints
 * (`mb_str_split`) while accumulating each character's byte length
 * (`strlen`).
 */
final class BreakFinder
{
    private const string HYPHEN = "\u{2010}";
    private const string HYPHEN_MINUS = '-';
    private const string SPACE = ' ';
    private const string LINE_FEED = "\n";

    /** @return list<BreakOpportunity> */
    public function find(string $text): array
    {
        $characters = mb_str_split($text);
        $opportunities = [];
        $byteOffset = 0;

        foreach ($characters as $index => $character) {
            $byteOffset += strlen($character);

            $mandatory = $character === self::LINE_FEED;
            $isBreakingSpace = $character === self::SPACE;
            $isBreakingHyphen = ($character === self::HYPHEN || $character === self::HYPHEN_MINUS)
                && $this->isLetter($characters[$index + 1] ?? null);

            if ($mandatory || $isBreakingSpace || $isBreakingHyphen) {
                // $byteOffset is guaranteed to be > 0 here: it only ever grows by each
                // character's (>= 1) byte length, so an opportunity is never reported
                // at offset 0.
                $opportunities[] = new BreakOpportunity($byteOffset, $mandatory);
            }
        }

        return $opportunities;
    }

    private function isLetter(?string $character): bool
    {
        return $character !== null && preg_match('/\p{L}/u', $character) === 1;
    }
}
