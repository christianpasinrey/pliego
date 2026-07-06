<?php

declare(strict_types=1);

namespace Pliego\Layout\Fragment;

use Pliego\Css\Value\Color;
use Pliego\Layout\Geometry\Rect;

final readonly class BoxFragment implements Fragment
{
    /**
     * M4-T5: $atomic marca un fragmento como INDIVISIBLE frente a Paginator (por ahora, solo el
     * contenedor de un FlexFormattingContext lo pone a true — ver su docblock de clase). Default
     * false para todos los demás construction sites (M0-M3 y el resto de M4 intactos: un
     * BoxFragment normal se sigue aplanando hijo a hijo por Paginator::flatten(), exactamente
     * igual que antes de esta tarea).
     */
    /** @param list<Fragment> $children */
    public function __construct(
        public Rect $rect,
        public ?Color $background,
        public array $children,
        public BorderSet $borders,
        public bool $atomic = false,
    ) {}

    public function rect(): Rect
    {
        return $this->rect;
    }
}
