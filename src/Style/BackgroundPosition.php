<?php

declare(strict_types=1);

namespace Pliego\Style;

/**
 * M8-T6 (css-backgrounds-3 §4 reducido): `background-position` -- SOLO 'center' y el default
 * 'top left' (el ÚNICO valor real del spec -- 'top left'/'left top'/'0% 0%' -- son sinónimos del
 * mismo punto, ver DeclarationParser::parse(), rama 'background-position': ambas grafías colapsan
 * a este mismo case). Cualquier otro valor (bottom right, porcentajes, longitudes, un solo keyword
 * lateral como "right", etc.) cae al warning genérico de esa rama y la propiedad queda sin
 * declarar, que resuelve al default TopLeft de ComputedStyle::compute() -- no hay un tercer case
 * "otro" en este enum, exactamente el mismo criterio "solo lo soportado tiene representación" que
 * Style\BackgroundSize/TextTransform.
 */
enum BackgroundPosition
{
    case TopLeft;
    case Center;
}
