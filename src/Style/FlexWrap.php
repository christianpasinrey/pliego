<?php

declare(strict_types=1);

namespace Pliego\Style;

/** css-flexbox-1 §5.2. wrap-reverse (M4 fuera de alcance) cae a warning en DeclarationParser y
 * nunca llega aquí. */
enum FlexWrap
{
    case NoWrap;
    case Wrap;
}
