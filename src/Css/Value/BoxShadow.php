<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/**
 * M8-T4 (css-backgrounds-3 §6, reducido): `box-shadow: <offset-x> <offset-y> <blur-radius>?
 * <color>?` -- UNA sola sombra (comma-multiple -> primera + warning, ver DeclarationParser::
 * parseBoxShadowValue()), sin `inset` (warning + declaración entera descartada) ni `spread`
 * (4a longitud -- warning + ignorada, geometría solo usa offset-x/offset-y/blur).
 *
 * A diferencia de Css\Value\BorderRadius (que guarda LengthPercentage SIN resolver, porque el %
 * de un radio depende del border-box final, solo conocido en Layout), $offsetX/$offsetY/
 * $blurRadius YA llegan resueltos a PX aquí -- ninguno de los 3 admite % en CSS real (siempre
 * <length>), así que ComputedStyle::compute() los resuelve en el MISMO punto donde resuelve
 * cualquier otra longitud pura (em/rem/calc() contra el font-size propio/raíz), sin necesitar una
 * contraparte "Layout\Fragment\BoxShadow" aparte: este ÚNICO VO viaja sin cambios desde
 * ComputedStyle::$boxShadow hasta Layout\Fragment\BoxFragment::$boxShadow (documentado ahí como
 * "px resuelto" porque, para cuando llega, ya lo está).
 *
 * $color siempre es un Color CONCRETO (nunca el sentinel currentColor sin resolver) -- si el
 * autor no declaró color, DeclarationParser::parseBoxShadowValue() deja el sentinel
 * Color::currentColor() en el raw value, y ComputedStyle::compute() lo resuelve contra el color
 * computado del propio elemento exactamente igual que border-*-color/background-color
 * (resolveCurrentColor), antes de construir este VO.
 */
final readonly class BoxShadow
{
    public function __construct(
        public float $offsetX,
        public float $offsetY,
        public float $blurRadius,
        public Color $color,
    ) {}
}
