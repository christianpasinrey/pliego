<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/**
 * M8-T3 (css-images-3 §3.1 reducido, §3.4.1 "color stop list"): un color-stop YA resuelto -- por
 * el tiempo en que DeclarationParser construye la lista de stops de un Gradient, $positionPct
 * SIEMPRE trae un valor concreto 0-100 (nunca null "de verdad" en la práctica): css-images-3
 * §3.4.1 exige que TODO stop tenga una posición final antes de poder interpolar el gradiente --
 * un stop sin posición declarada por el autor se distribuye uniformemente entre sus vecinos
 * (DeclarationParser::distributeStopPositions(), "0/50/100" para 3 stops sin posición del brief)
 * antes de construir este VO. El tipo se deja ?float (en vez de float no-nullable) para no
 * sobre-restringir la interfaz del milestone -- ver el brief de M8, que declara este campo
 * explícitamente nullable.
 *
 * $color llega SIEMPRE opaco (alpha null) -- un stop con alpha declarado (rgba()/hsla() con
 * alpha<1) se avisa y se fuerza a opaco en DeclarationParser (shadings con alpha real requieren
 * soft masks, M9), así que ningún consumidor de Layout/Paint/Pdf necesita volver a chequear
 * $color->alpha para un GradientStop.
 */
final readonly class GradientStop
{
    public function __construct(
        public Color $color,
        public ?float $positionPct,
    ) {}
}
