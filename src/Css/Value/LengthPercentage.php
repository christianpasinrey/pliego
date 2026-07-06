<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/**
 * Sustituye a Length en las propiedades CSS 2.2 §10 que admiten porcentaje
 * (width, margin-*, padding-*). El % se resuelve contra el containing block
 * en tiempo de layout vía resolve(); font-size/line-height NO usan este tipo
 * (% ahí queda fuera de M2, ver DeclarationParser).
 */
final readonly class LengthPercentage
{
    /**
     * M6-T4: $calc absorbe la variante calc()-con-% (ver CalcValue) sin tocar $isPercent/$value
     * (los dos campos que ya consumen tests/Layout existentes) — cuando $calc no es null, es la
     * ÚNICA fuente de verdad para resolve() y $isPercent/$value quedan en su valor por defecto
     * (false/0.0), sin significado.
     */
    private function __construct(public bool $isPercent, public float $value, public ?CalcValue $calc = null) {}

    public static function px(float $px): self
    {
        return new self(false, $px);
    }

    public static function percent(float $percent): self
    {
        return new self(true, $percent);
    }

    public static function zero(): self
    {
        return new self(false, 0.0);
    }

    /** css-values-3 §8: calc() diferido a Layout porque involucra % (ver CalcExpr::fold()). */
    public static function calc(CalcValue $calc): self
    {
        return new self(false, 0.0, $calc);
    }

    public static function fromCss(string $value): ?self
    {
        $value = strtolower(trim($value));
        if ($value === '0') {
            return self::zero();
        }
        if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $value, $m) === 1) {
            return self::percent((float) $m[1]);
        }
        if (preg_match('/^(-?\d+(?:\.\d+)?)px$/', $value, $m) === 1) {
            return self::px((float) $m[1]);
        }
        return null;
    }

    /** CSS 2.2 §10: % se resuelve contra $containingBlockPx; px pasa directo; calc() (M6-T4)
     * delega en CalcValue::resolve(), que ya sigue exactamente el mismo contrato. */
    public function resolve(float $containingBlockPx): float
    {
        if ($this->calc !== null) {
            return $this->calc->resolve($containingBlockPx);
        }
        return $this->isPercent ? ($this->value / 100.0) * $containingBlockPx : $this->value;
    }
}
