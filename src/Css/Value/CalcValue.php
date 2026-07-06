<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/**
 * css-values-3 §8: forma DIFERIDA de un calc() que involucra %. Cualquier árbol de calc() con
 * unidades mixtas (número, px/pt/cm/mm/in/em/rem, %) se pliega ALGEBRAICAMENTE (ver CalcExpr,
 * CalcParser) en tiempo de parseo/computed-value hasta quedar reducido a esta forma canónica:
 * "a% del containing block + b px" — un par (percentFactor, pxOffset) — porque suma y resta son
 * combinaciones lineales, y multiplicación/división (que exigen que un lado sea un número sin
 * unidad) escalan ambos componentes por igual; nunca aparece un tercer grado de libertad. Ver
 * CalcExpr::fold() para la prueba/derivación
 * completa y LengthPercentage::calc()/resolve() para el consumidor (Layout, sin cambios: sigue
 * siendo resolve($containingBlockPx): float, igual que el % simple de hoy).
 */
final readonly class CalcValue
{
    private function __construct(public float $percentFactor, public float $pxOffset) {}

    public static function of(float $percentFactor, float $pxOffset): self
    {
        return new self($percentFactor, $pxOffset);
    }

    public function resolve(float $containingBlockPx): float
    {
        return ($this->percentFactor / 100.0) * $containingBlockPx + $this->pxOffset;
    }
}
