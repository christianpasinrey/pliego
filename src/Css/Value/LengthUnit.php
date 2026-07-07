<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/**
 * css-values-3 §5-6: unidades de longitud reconocidas por CssLength::fromCss. Px/Em/Rem/
 * Percent son las que sobreviven al parseo (los físicos SIEMPRE se pliegan a Px en tiempo de
 * parseo, ver CssLength::fromCss) — Pt/Cm/Mm/In se declaran aquí solo para completar la
 * interfaz del milestone (M6-T3 brief); ningún CssLength producido por fromCss() los porta.
 *
 * M10-T1 (css-values-4 §5.1.1, viewport units in paged media): Vw/Vh se suman al mismo grupo
 * SIMBÓLICO que Em/Rem/Percent — un motor de paginación no tiene "viewport" en el sentido del
 * navegador, pero SÍ tiene un tamaño de página fijo y conocido (Page\PaperSize, en CSS px), que
 * hace exactamente el mismo papel: 1vw = 1% del ancho de la página, 1vh = 1% de su alto
 * (adjudicación M10-T1: contra el PAPER BOX completo, no el content box — ver
 * Style\ComputedStyle/Style\StyleResolver, que hilan pageWidthPx/pageHeightPx desde Engine igual
 * que ya hilan remBase).
 */
enum LengthUnit
{
    case Px;
    case Em;
    case Rem;
    case Percent;
    case Pt;
    case Cm;
    case Mm;
    case In;
    case Vw;
    case Vh;
}
