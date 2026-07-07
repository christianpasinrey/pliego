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
 * $color puede traer alpha (rgba()/hsla() con alpha<1, M9-T3 en adelante) -- DeclarationParser ya
 * NO lo fuerza a opaco (M8-T3 lo hacía, con un warning; ver su historial). Pdf\PdfCanvas::
 * paintGradient() es quien inspecciona $color->alpha en cada stop: si alguno es <1.0, pinta un
 * /SMask /Luminosity (ISO 32000-1 §11.6.5.2) en vez de leer el canal alfa directamente en el
 * /Function del shading de color (un FunctionType 2/3 de PDF es RGB puro, sin canal alfa -- ver el
 * docblock de esa clase para el mecanismo completo).
 */
final readonly class GradientStop
{
    public function __construct(
        public Color $color,
        public ?float $positionPct,
    ) {}
}
