<?php

declare(strict_types=1);

namespace Pliego\Css;

/**
 * selectors-3 §6.6.1/§6.6.7: pseudo-clase con argumento opcional (`:pseudo` o `:pseudo(arg)`).
 * `:not()` es la única pseudo-clase con semántica de specificity distinta (§17): no cuenta como
 * pseudo-clase, cuenta la specificity de su argumento — de ahí $negatedSelector, poblado solo
 * cuando name es "not" y el argumento parsea como un ÚNICO selector simple válido (SelectorParser
 * tiene la gramática exacta: type/universal/.class/#id/[attr]/pseudo-class, sin anidar :not()).
 *
 * M6-T2: matching real vía matches(). $neverMatches marca las tres categorías de pseudo-clases que
 * SelectorParser acepta sintácticamente (cuentan para specificity) pero que NUNCA producen match:
 *   - dinámicas (:hover/:focus/:active/:visited/:link): no tienen sentido en medios paginados.
 *   - estructurales aún no soportadas (:nth-of-type y familia): "not supported yet".
 *   - desconocidas: cualquier otro nombre.
 * En los tres casos el warning correspondiente se emite UNA vez en SelectorParser, en tiempo de
 * parseo — no aquí, para no inundar de warnings un documento con miles de elementos.
 */
final readonly class PseudoClass
{
    public function __construct(
        public string $name,
        public ?string $argument = null,
        public ?CompoundSelector $negatedSelector = null,
        public ?int $nthA = null,
        public ?int $nthB = null,
        public bool $neverMatches = false,
    ) {}

    public function isNegation(): bool
    {
        return strtolower($this->name) === 'not';
    }

    public function matches(\Dom\Element $element): bool
    {
        if ($this->neverMatches) {
            return false;
        }

        return match (strtolower($this->name)) {
            'root' => $element->ownerDocument?->documentElement === $element,
            'first-child' => $element->previousElementSibling === null,
            'last-child' => $element->nextElementSibling === null,
            'nth-child' => $this->matchesNthChild($element),
            // Parser garantiza negatedSelector !== null para cualquier PseudoClass "not" construida
            // con éxito (ver SelectorParser::parseNegationArgument) — el null-check es defensivo.
            'not' => $this->negatedSelector !== null && !$this->negatedSelector->matchesElement($element),
            default => false, // inalcanzable: cualquier otro nombre se construye con neverMatches=true
        };
    }

    /** selectors-3 §6.5: posición (1-based) de $element entre los hijos ELEMENT de su padre. */
    private function matchesNthChild(\Dom\Element $element): bool
    {
        $a = $this->nthA;
        $b = $this->nthB;
        if ($a === null || $b === null) {
            return false; // inalcanzable: nth-child solo se construye con a/b ya parseados
        }

        $position = 1;
        for ($sibling = $element->previousElementSibling; $sibling !== null; $sibling = $sibling->previousElementSibling) {
            $position++;
        }

        $diff = $position - $b;
        if ($a === 0) {
            return $diff === 0;
        }
        if ($diff % $a !== 0) {
            return false;
        }
        return intdiv($diff, $a) >= 0;
    }
}
