<?php

declare(strict_types=1);

namespace Pliego\Text;

/**
 * A resolved (family, weight, style) combination bound to a loaded font
 * program. `$key` identifies the face uniquely for PDF embedding purposes
 * ("family:weight:normal|italic", e.g. "default:700:italic").
 */
final readonly class FontFace
{
    public function __construct(
        public string $key,
        public TtfFont $font,
    ) {}
}
