<?php

declare(strict_types=1);

namespace Pliego\Box;

use Pliego\Style\ComputedStyle;

final readonly class BlockBox
{
    /** @param list<BlockBox|TextRun|LineBreakRun|ImageBox> $children */
    public function __construct(
        public ComputedStyle $style,
        public array $children,
        public string $tag,
    ) {}
}
