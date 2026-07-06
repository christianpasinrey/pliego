<?php

declare(strict_types=1);

namespace Pliego\Css;

/**
 * selectors-3 §6.3: [attr], [attr=val], [attr~=val], [attr|=val], [attr^=val], [attr$=val],
 * [attr*=val]. M6-T2: matching real, case-sensitive siempre (el flag 'i' de Selectors-4 se acepta
 * en el parser por compatibilidad pero SIEMPRE cae a comparación case-sensitive — SelectorParser
 * emite el warning de ese fallback UNA VEZ en tiempo de parseo, no aquí).
 */
final readonly class AttributeSelector
{
    public function __construct(
        public string $name,
        public ?string $operator = null,
        public ?string $value = null,
    ) {}

    public function matches(\Dom\Element $element): bool
    {
        if (!$element->hasAttribute($this->name)) {
            return false;
        }
        if ($this->operator === null) {
            return true; // [attr]: solo presencia
        }

        $actual = (string) $element->getAttribute($this->name);
        $value = (string) $this->value;

        return match ($this->operator) {
            '=' => $actual === $value,
            // §6.3: uno de los valores separados por espacios es EXACTAMENTE $value.
            '~=' => $value !== '' && in_array($value, preg_split('/\s+/', trim($actual)) ?: [], true),
            // Edge de la spec: patrón vacío nunca matchea nada (ni siquiera un valor vacío).
            '^=' => $value !== '' && str_starts_with($actual, $value),
            '$=' => $value !== '' && str_ends_with($actual, $value),
            '*=' => $value !== '' && str_contains($actual, $value),
            // §6.3 (atributos de idioma tipo hreflang): igual a $value o empieza por "$value-".
            '|=' => $actual === $value || str_starts_with($actual, $value . '-'),
            default => false, // inalcanzable: SelectorParser solo produce los operadores de arriba
        };
    }
}
