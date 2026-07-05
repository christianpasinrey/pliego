<?php

declare(strict_types=1);

namespace Pliego\Page;

use Pliego\Layout\Fragment\Fragment;

final readonly class Page
{
    /** @param list<Fragment> $fragments en coordenadas locales de página, orden de pintado */
    public function __construct(public int $number, public array $fragments) {}
}
