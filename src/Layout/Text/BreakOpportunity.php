<?php

declare(strict_types=1);

namespace Pliego\Layout\Text;

/**
 * A single line-break opportunity found in a run of text.
 *
 * `byteOffset` is a BYTE offset into the original UTF-8 string (suitable for
 * direct use with `substr()`), pointing at the position immediately AFTER
 * the character that produced the opportunity (e.g. right after a space).
 *
 * `mandatory` distinguishes a forced break (e.g. a line feed) from a mere
 * opportunity where the layout engine may or may not break, depending on
 * available width.
 */
final readonly class BreakOpportunity
{
    public function __construct(
        public int $byteOffset,
        public bool $mandatory,
    ) {}
}
