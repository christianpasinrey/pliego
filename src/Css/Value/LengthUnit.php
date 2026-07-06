<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/**
 * css-values-3 §5-6: unidades de longitud reconocidas por CssLength::fromCss. Px/Em/Rem/
 * Percent son las que sobreviven al parseo (los físicos SIEMPRE se pliegan a Px en tiempo de
 * parseo, ver CssLength::fromCss) — Pt/Cm/Mm/In se declaran aquí solo para completar la
 * interfaz del milestone (M6-T3 brief); ningún CssLength producido por fromCss() los porta.
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
}
