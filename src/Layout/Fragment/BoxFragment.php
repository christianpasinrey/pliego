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
    /**
     * M7-T5 (css-overflow-3, css-box-3 §4): $clipsChildren marca esta caja como un clipping
     * container (ComputedStyle::$overflow === 'hidden' en el elemento que la generó, ver
     * BlockFlowContext::layout()) — Paint\Painter envuelve el pintado de TODOS sus descendientes
     * en un clip path PDF (`q ... re W n ... Q`, ver PdfCanvas::clipRect()/restoreClip()) al rect
     * BORDER-BOX de ESTA caja (el propio fondo/borde de la caja NO necesita clip: ya coincide
     * exactamente con ese rect). Adjudicación del brief M7-T5: Paginator::flatten() trata
     * $clipsChildren igual que $atomic (composite preservado entero, nunca descompuesto hoja a
     * hoja) — sin esto, un push-down de página aplanaría los hijos de la caja por separado y el
     * clip quedaría huérfano de la caja que lo aplica (ver Paginator::flatten()/paginate()).
     * Ortogonal a $atomic: una caja puede ser clipsChildren sin ser un contenedor flex (el caso
     * normal, un <div> con overflow:hidden), y viceversa.
     */
    /**
     * M8-T2 (css-backgrounds-3 §5): $borderRadius llega YA resuelto a px y clampeado (§5.5) --
     * ver Layout\Fragment\BorderRadius::fromCss(), invocado por cada FormattingContext que
     * construye un BoxFragment a partir de un ComputedStyle propio (BlockFlowContext/
     * FlexFormattingContext/TableFormattingContext). Default "new BorderRadius()" (PHP 8.1+ "new
     * in initializers", sin argumentos -> las 4 esquinas caen a su propio default 0.0) para que
     * los ~40 construction sites preexistentes (tests + GeometryShift/Paginator, ver grep) seguir
     * compilando sin tocarlos: sin radio, el comportamiento de pintado es BYTE IDÉNTICO al de
     * antes de esta tarea (ver Paint\Painter: $radius->isZero() hace caer el pintado por el mismo
     * camino fillRect/paintBordersFlat pre-M8-T2).
     */
    /** @param list<Fragment> $children */
    public function __construct(
        public Rect $rect,
        public ?Color $background,
        public array $children,
        public BorderSet $borders,
        public bool $atomic = false,
        public float $opacity = 1.0,
        public bool $clipsChildren = false,
        public BorderRadius $borderRadius = new BorderRadius(),
    ) {}

    public function rect(): Rect
    {
        return $this->rect;
    }
}
