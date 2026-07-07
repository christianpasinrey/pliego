<?php

declare(strict_types=1);

namespace Pliego\Style;

/**
 * M8-T6 (css-backgrounds-3 §4 reducido): `background-size` -- SOLO los 3 keywords de este subset
 * reducido de milestone (dimensiones concretas -- longitudes/porcentajes/formas de dos valores --
 * quedan fuera de alcance M8, ver DeclarationParser::parse(), rama 'background-size': warning +
 * sin declaración, cae al default Auto de abajo). Auto es el initial value real del spec (sin
 * escalado, tamaño intrínseco de la imagen); Cover/Contain son los dos keywords de encaje
 * proporcional (ver Paint\Painter::paintBackgroundImage() para la aritmética hand-verificada de
 * cada uno).
 */
enum BackgroundSize
{
    case Auto;
    case Cover;
    case Contain;
}
