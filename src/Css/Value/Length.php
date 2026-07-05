<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

final readonly class Length
{
    private function __construct(public float $px) {}

    public static function px(float $px): self
    {
        return new self($px);
    }

    public static function zero(): self
    {
        return new self(0.0);
    }

    public static function fromCss(string $value): ?self
    {
        $value = strtolower(trim($value));
        if ($value === '0') {
            return self::zero();
        }
        if (preg_match('/^(-?\d+(?:\.\d+)?)px$/', $value, $m) === 1) {
            return new self((float) $m[1]);
        }
        return null;
    }
}
