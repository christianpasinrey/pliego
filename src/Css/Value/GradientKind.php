<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/** css-images-3 §3.1 reducido: solo linear-gradient()/radial-gradient() -- conic-gradient() queda
 *  fuera de alcance M8 (ver RESTRICCIONES GLOBALES del brief), así que este enum nunca necesita un
 *  tercer caso. */
enum GradientKind
{
    case Linear;
    case Radial;
}
