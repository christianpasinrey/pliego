<?php

declare(strict_types=1);

namespace Pliego\Box;

use Pliego\Style\ComputedStyle;

final readonly class TextRun
{
    public function __construct(public string $text, public ComputedStyle $style) {}
}
