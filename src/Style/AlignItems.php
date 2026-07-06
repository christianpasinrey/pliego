<?php

declare(strict_types=1);

namespace Pliego\Style;

/** css-flexbox-1 §8.3 (cross-axis alignment). baseline (M4 fuera de alcance) cae a warning en
 * DeclarationParser y nunca llega aquí. */
enum AlignItems
{
    case Stretch;
    case FlexStart;
    case Center;
    case FlexEnd;
}
