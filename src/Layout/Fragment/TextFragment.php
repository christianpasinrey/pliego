<?php

declare(strict_types=1);

namespace Pliego\Layout\Fragment;

use Pliego\Css\Value\Color;
use Pliego\Layout\Geometry\Rect;

final readonly class TextFragment implements Fragment
{
    public function __construct(
        public Rect $rect,
        public string $text,
        public float $baselineY,
        public float $fontSizePx,
        public Color $color,
    ) {}

    public function rect(): Rect
    {
        return $this->rect;
    }
}
