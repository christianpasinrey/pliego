<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/**
 * Nodo intermedio de evaluación de CalcParser (no sobrevive fuera de esa clase). css-values-3 §8
 * exige distinguir dos "tipos" mientras se evalúa el árbol: un número puro sin unidad (solo válido
 * como operando de * // o combinado por +/- con OTRO número puro) y una cantidad longitud/
 * porcentaje (nuestro vector de 4 componentes, ver CalcExpr). Mezclar los dos en +/- o multiplicar/
 * dividir dos cantidades longitud/porcentaje entre sí no tiene interpretación — CalcParser lo
 * detecta comparando $isNumber de ambos operandos antes de combinar.
 */
final readonly class CalcNode
{
    private function __construct(
        public bool $isNumber,
        public float $number,
        public float $percentFactor,
        public float $emFactor,
        public float $remFactor,
        public float $pxOffset,
    ) {}

    public static function number(float $value): self
    {
        return new self(true, $value, 0.0, 0.0, 0.0, 0.0);
    }

    public static function dimension(float $percentFactor, float $emFactor, float $remFactor, float $pxOffset): self
    {
        return new self(false, 0.0, $percentFactor, $emFactor, $remFactor, $pxOffset);
    }
}
