<?php

declare(strict_types=1);

namespace Pliego\Layout\Fragment;

use Pliego\Css\Value\Color;
use Pliego\Layout\Geometry\Rect;

/**
 * M7-T4 (css-inline-3 reducido, box-decoration-break:slice): un tramo PINTABLE de una caja inline
 * REAL sobre UNA línea concreta — InlineFlowContext emite uno de estos por cada línea en la que
 * una caja abierta (InlineBoxStart..InlineBoxEnd) tiene contenido, nunca uno por caja "entera" (una
 * caja que envuelve texto en 3 líneas produce 3 InlineBoxFragment, uno por línea, cada uno con su
 * propio $rect). Vive como HERMANO de los TextFragment/otras hojas del bloque (NO como contenedor:
 * el contenido "dentro" de la caja son los propios TextFragment vecinos, esta clase solo aporta
 * fondo/borde/geometría, sin lista de children) — Paginator::flatten() ya la trata como una hoja
 * más sin cambios (no es un BoxFragment, cae al `else { yield $child; }` genérico).
 *
 * $rect: unión horizontal de los fragments de contenido de la caja en ESTA línea, expandida por
 * padding-left (solo si $isFirstSlice) / padding-right (solo si $isLastSlice) — box-decoration-
 * break:slice, CSS 2.2 §9.2.2 simplificado. VERTICALMENTE, en cambio, el padding top/bottom se
 * añade SIEMPRE (en cada slice, sin importar first/last — a diferencia del horizontal, ver el
 * docblock de InlineFlowContext): la caja se pinta desbordando la línea (overflow), sin que esto
 * afecte al lineHeight/baseline del texto (documented, spec strut model).
 *
 * $borders ya llega con los lados laterales SUPRIMIDOS (BorderStyle::None) cuando corresponde —
 * ver InlineFlowContext, que construye el BorderSet por-slice ANTES de emitir el fragment, así que
 * Painter no necesita ninguna lógica de slice-awareness propia: solo pinta lo que trae el
 * BorderSet, exactamente igual que para un BoxFragment normal. $isFirstSlice/$isLastSlice se
 * conservan como campos propios (contrato del milestone) aunque Painter no los consulte
 * directamente — son informativos/verificables por tests.
 */
final readonly class InlineBoxFragment implements Fragment
{
    /**
     * M8-T2: $borderRadius sigue la MISMA convención de slice que $borders (ver docblock de
     * clase) -- InlineFlowContext::buildInlineBoxFragment() ya suprime tl/bl cuando NO es la
     * primera slice y tr/br cuando NO es la última, así que este campo llega SIEMPRE resuelto a la
     * forma final que hay que pintar, sin lógica de slice-awareness propia en Paint\Painter
     * (idéntico patrón que $borders). Default "new BorderRadius()" por el mismo motivo que
     * BoxFragment (construction sites preexistentes sin tocar).
     */
    public function __construct(
        public Rect $rect,
        public ?Color $background,
        public BorderSet $borders,
        public float $opacity,
        public bool $isFirstSlice,
        public bool $isLastSlice,
        public BorderRadius $borderRadius = new BorderRadius(),
    ) {}

    public function rect(): Rect
    {
        return $this->rect;
    }
}
