<?php

declare(strict_types=1);

namespace Pliego\Layout\Fragment;

use Pliego\Css\Value\Color;
use Pliego\Layout\Geometry\Rect;

final readonly class BoxFragment implements Fragment
{
    /** @param list<Fragment> $children */
    public function __construct(
        public Rect $rect,
        public ?Color $background,
        public array $children,
    ) {}

    public function rect(): Rect
    {
        return $this->rect;
    }
}
