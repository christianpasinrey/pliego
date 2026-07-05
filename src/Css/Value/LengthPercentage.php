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
    private function __construct(public bool $isPercent, public float $value) {}

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

    /** CSS 2.2 §10: % se resuelve contra $containingBlockPx; px pasa directo. */
    public function resolve(float $containingBlockPx): float
    {
        return $this->isPercent ? ($this->value / 100.0) * $containingBlockPx : $this->value;
    }
}
