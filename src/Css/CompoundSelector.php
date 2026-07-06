<?php

declare(strict_types=1);

namespace Pliego\Css;

/**
 * selectors-3 §6/§6.1: secuencia de selectores simples sin combinador entre ellos
 * (type/universal + #id* + .class* + [attr]* + :pseudo*, en cualquier orden salvo que
 * type/universal, si aparece, va primero).
 *
 * M6-T2: matchesElement() ya resuelve el compuesto completo (type/universal/clases/id/atributos/
 * pseudo-clases) — ComplexSelector::matches() lo invoca para cada compuesto de la cadena durante
 * el walk right-to-left.
 */
final readonly class CompoundSelector
{
    /**
     * @param list<string> $classes
     * @param list<AttributeSelector> $attributes
     * @param list<PseudoClass> $pseudoClasses
     */
    public function __construct(
        public bool $universal,
        public ?string $type,
        public array $classes = [],
        public ?string $id = null,
        public array $attributes = [],
        public array $pseudoClasses = [],
    ) {}

    /** selectors-3 §17: a=ids, b=clases+atributos+pseudo-clases (:not() delega en su argumento), c=tipos (* no cuenta). */
    public function specificity(): Specificity
    {
        $specificity = new Specificity(
            $this->id !== null ? 1 : 0,
            count($this->classes) + count($this->attributes),
            $this->type !== null ? 1 : 0,
        );
        foreach ($this->pseudoClasses as $pseudoClass) {
            if ($pseudoClass->isNegation() && $pseudoClass->negatedSelector !== null) {
                $specificity = $specificity->plus($pseudoClass->negatedSelector->specificity());
                continue;
            }
            $specificity = $specificity->plus(new Specificity(0, 1, 0));
        }
        return $specificity;
    }

    /** M6-T2: extiende la lógica de M0 (type/clases/id) con atributos y pseudo-clases. */
    public function matchesElement(\Dom\Element $element): bool
    {
        if ($this->type !== null && strtolower($element->tagName) !== $this->type) {
            return false;
        }
        foreach ($this->classes as $class) {
            if (!$element->classList->contains($class)) {
                return false;
            }
        }
        if ($this->id !== null && $element->getAttribute('id') !== $this->id) {
            return false;
        }
        foreach ($this->attributes as $attribute) {
            if (!$attribute->matches($element)) {
                return false;
            }
        }
        foreach ($this->pseudoClasses as $pseudoClass) {
            if (!$pseudoClass->matches($element)) {
                return false;
            }
        }
        return true;
    }
}
