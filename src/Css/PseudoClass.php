<?php

declare(strict_types=1);

namespace Pliego\Css;

/**
 * selectors-3 §6.6.1/§6.6.7: pseudo-clase con argumento opcional (`:pseudo` o `:pseudo(arg)`).
 * `:not()` es la única pseudo-clase con semántica de specificity distinta (§17): no cuenta como
 * pseudo-clase, cuenta la specificity de su argumento — de ahí $negatedSelector, poblado solo
 * cuando name es "not" y el argumento parsea como un simple selector válido.
 *
 * El matching real (incluido `:not()`) llega en M6-T2 — en T1 el compuesto que la contenga
 * queda en staging (ComplexSelector::matches() -> false + warning), igual que combinadores.
 */
final readonly class PseudoClass
{
    public function __construct(
        public string $name,
        public ?string $argument = null,
        public ?CompoundSelector $negatedSelector = null,
    ) {}

    public function isNegation(): bool
    {
        return strtolower($this->name) === 'not';
    }
}
