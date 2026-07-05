<?php

declare(strict_types=1);

namespace Pliego\Paint;

use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Page\Page;

final readonly class Painter
{
    public function paint(Page $page, Canvas $canvas): void
    {
        foreach ($page->fragments as $fragment) {
            if ($fragment instanceof BoxFragment && $fragment->background !== null) {
                $canvas->fillRect($fragment->rect, $fragment->background);
            } elseif ($fragment instanceof TextFragment) {
                $canvas->fillText($fragment);
            }
        }
    }
}
