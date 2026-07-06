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
    /**
     * M6-T5: $opacity es la opacity PROPIA del elemento que generó esta caja (ComputedStyle::
     * $opacity, default 1.0/opaco) — se combina con el alpha de $background y de cada
     * BorderSide::$color en el PUNTO DE PINTADO (Paint\Painter, vía Color::withOpacity()), nunca
     * horneada aquí (ver el docblock de ComputedStyle::$opacity: los hijos de esta caja NO
     * heredan esta opacity, divergencia M6 documentada frente a las "transparency groups" reales
     * de CSS/PDF — cada BoxFragment del subárbol trae su PROPIA opacity, resuelta de forma
     * independiente por ComputedStyle::compute() en su propio elemento).
     */
    /** @param list<Fragment> $children */
    public function __construct(
        public Rect $rect,
        public ?Color $background,
        public array $children,
        public BorderSet $borders,
        public bool $atomic = false,
        public float $opacity = 1.0,
    ) {}

    public function rect(): Rect
    {
        return $this->rect;
    }
}
