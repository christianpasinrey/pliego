<?php

declare(strict_types=1);

namespace Pliego\Css;

final readonly class StyleRule
{
    /** @param array<string, mixed> $declarations claves canónicas => Length|Color|string */
    public function __construct(
        public ComplexSelector $selector,
        public array $declarations,
        public int $order,
    ) {}
}
