<?php

declare(strict_types=1);

namespace Pliego\Layout;

use Pliego\Box\BlockBox;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Geometry\Rect;

interface FormattingContext
{
    public function layout(BlockBox $box, Rect $containingBlock): BoxFragment;
}
