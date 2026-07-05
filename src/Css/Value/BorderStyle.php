<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/** M2: solo solid|none son soportados (el resto de estilos CSS 2.2 §8.5.3 generan warning). */
enum BorderStyle
{
    case None;
    case Solid;
}
