<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/**
 * Token del tokenizer de CalcParser. Un value object con nombres propios (en vez de una tupla
 * posicional array{0:string,1?:float,2?:string}) porque PHPStan no puede seguir la correlación
 * entre $type y qué offsets están presentes en una tupla con campos opcionales — encadena en
 * falsos positivos ("Offset 1 might not exist...", "Negated boolean expression is always true")
 * incluso cuando el código ya lo comprueba correctamente en cada punto de uso.
 */
final readonly class CalcToken
{
    private function __construct(
        public string $type,
        public float $number = 0.0,
        public string $text = '',
    ) {}

    public static function op(string $op): self
    {
        return new self('op', 0.0, $op);
    }

    public static function lparen(): self
    {
        return new self('lparen');
    }

    public static function rparen(): self
    {
        return new self('rparen');
    }

    public static function num(float $value): self
    {
        return new self('num', $value);
    }

    public static function dim(float $value, string $unit): self
    {
        return new self('dim', $value, $unit);
    }
}
