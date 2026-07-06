<?php

declare(strict_types=1);

namespace Pliego\Style;

/**
 * M7-T6 (CSS 2.2 §9.5, floats reducido): los dos lados soportados por `float`. El tercer valor
 * CSS real, 'none' (con diferencia el más común: "no floated"), NUNCA es un case de este enum —
 * se representa como `ComputedStyle::$float === null`, el MISMO patrón que otras propiedades
 * opcionales del motor (p.ej. `$width`/`$minWidth`: ausencia = null, no un valor "None" dentro
 * del propio tipo). `shape-outside`/float de imágenes con recorte no rectangular quedan fuera de
 * alcance (RESTRICCIONES GLOBALES del milestone, "Excluidos M7 con warning").
 */
enum FloatSide
{
    case Left;
    case Right;
}
