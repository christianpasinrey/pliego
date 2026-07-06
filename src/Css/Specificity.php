<?php

declare(strict_types=1);

namespace Pliego\Css;

/**
 * selectors-3 §17: especificidad como tripleta (a, b, c) — comparación lexicográfica, nunca
 * "colapsada" a un único entero (a diferencia del Selector::specificity(): int de M0, que asumía
 * que ningún selector podía tener 10+ clases/atributos/pseudo-clases o 10+ tipos).
 *
 * a = # de ID selectors.
 * b = # de class selectors + attribute selectors + pseudo-classes (el argumento de :not() cuenta
 *     para su propio tipo, no como pseudo-clase — ver CompoundSelector::specificity()).
 * c = # de type selectors (elementos); el universal selector (*) no cuenta para nada.
 */
final readonly class Specificity
{
    public function __construct(
        public int $a,
        public int $b,
        public int $c,
    ) {}

    public function compareTo(self $other): int
    {
        return [$this->a, $this->b, $this->c] <=> [$other->a, $other->b, $other->c];
    }

    public function plus(self $other): self
    {
        return new self($this->a + $other->a, $this->b + $other->b, $this->c + $other->c);
    }
}
