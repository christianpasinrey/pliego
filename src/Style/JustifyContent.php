<?php

declare(strict_types=1);

namespace Pliego\Style;

/** css-flexbox-1 §8.2 (main-axis alignment). space-around/space-evenly (M4 fuera de alcance)
 * caen a warning en DeclarationParser y nunca llegan aquí. */
enum JustifyContent
{
    case FlexStart;
    case Center;
    case FlexEnd;
    case SpaceBetween;
}
