<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/**
 * M8-T2 (css-backgrounds-3 §5, reducido): un radio POR ESQUINA (circular, no elíptico) — un valor
 * `border-radius: 10px / 20px` (radios horizontal/vertical distintos) es rechazado con warning por
 * DeclarationParser (elíptico, fuera de alcance M8) antes de llegar aquí, así que esta clase nunca
 * necesita más que UN LengthPercentage por esquina. NO se resuelve aquí (% sigue simbólico,
 * ComputedStyle::compute() lo deja intacto, igual que width/margin/padding) — la resolución contra
 * el border-box (adjudicación M8: % siempre contra el ANCHO, ver el brief) y el clamp de solapes
 * proporcional (§5.5) ocurren en Layout, solo cuando se conoce el tamaño final de la caja (ver
 * Layout\Fragment\BorderRadius::fromCss()).
 */
final readonly class BorderRadius
{
    public function __construct(
        public LengthPercentage $tl,
        public LengthPercentage $tr,
        public LengthPercentage $br,
        public LengthPercentage $bl,
    ) {}

    /** Caja sin border-radius declarado (initial value real: 0 en las 4 esquinas). */
    public static function zero(): self
    {
        $zero = LengthPercentage::zero();
        return new self($zero, $zero, $zero, $zero);
    }
}
