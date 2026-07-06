<?php

declare(strict_types=1);

namespace Pliego\Style;

/** css-flexbox-1 §5.1. row-reverse/column-reverse (M4 fuera de alcance) caen a warning en
 * DeclarationParser y nunca llegan aquí. */
enum FlexDirection
{
    case Row;
    case Column;
}
