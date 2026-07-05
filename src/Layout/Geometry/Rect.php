<?php

declare(strict_types=1);

namespace Pliego\Layout\Geometry;

final readonly class Rect
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {}

    public function bottom(): float
    {
        return $this->y + $this->height;
    }
    public function right(): float
    {
        return $this->x + $this->width;
    }
}
