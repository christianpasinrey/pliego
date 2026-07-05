<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/**
 * Un lado de borde ya ensamblado (width+style+color). DeclarationParser (T2) produce los
 * longhands por separado (border-{side}-width/style/color); ensamblarlos en BorderSide con la
 * cascada + default color=currentColor es responsabilidad de ComputedStyle (Style, T3).
 */
final readonly class BorderSide
{
    public function __construct(
        public float $widthPx,
        public BorderStyle $style,
        public ?Color $color,
    ) {}
}
