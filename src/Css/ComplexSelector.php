<?php

declare(strict_types=1);

namespace Pliego\Css;

/**
 * selectors-3 §8: cadena de compuestos unidos por combinadores (p.ej. `ul ol+li` es
 * [ul, (Descendant, ol), (NextSibling, li)]).
 *
 * Representación: lista de pares (Combinator, CompoundSelector) en orden IZQUIERDA→DERECHA (orden
 * de lectura del selector, no de matching). El combinador de cada par describe la relación entre
 * el compuesto ANTERIOR de la lista y ESE compuesto (p.ej. el par en índice 1 con combinador
 * NextSibling significa "el compuesto en índice 0 es el hermano-anterior inmediato del compuesto
 * en índice 1"). El primer par no tiene "anterior": su combinador no tiene significado semántico
 * (no hay nada a su izquierda) y se rellena por convención con Combinator::Descendant — nunca se
 * lee para ese índice; existe solo para que list<array{Combinator, CompoundSelector}> tenga una
 * forma uniforme (sin Optional/nullable especial para el primer elemento).
 *
 * M6-T1 (staging, ver brief): matches() solo resuelve selectores de un único compuesto SIN
 * atributos ni pseudo-clases (delega en CompoundSelector::matchesElement(), igual que el M0
 * Selector::matches(), extendido a múltiples clases). Cualquier otro caso (combinadores reales,
 * atributos, pseudo-clases) parsea correctamente y tiene specificity() exacta, pero matches()
 * devuelve false — el matching real (right-to-left walk) llega en M6-T2. El warning de este
 * staging se emite UNA VEZ por selector en tiempo de parseo (SelectorParser), no aquí.
 */
final readonly class ComplexSelector
{
    /** @param list<array{Combinator, CompoundSelector}> $compounds */
    public function __construct(public array $compounds) {}

    /** true si este selector necesita el matching real de M6-T2 (aún no implementado). */
    public function isStagedForMatching(): bool
    {
        if (count($this->compounds) !== 1) {
            return true;
        }
        [, $only] = $this->compounds[0];
        return $only->hasAttributesOrPseudos();
    }

    public function matches(\Dom\Element $element): bool
    {
        if ($this->isStagedForMatching()) {
            return false;
        }
        [, $compound] = $this->compounds[0];
        return $compound->matchesElement($element);
    }

    public function specificity(): Specificity
    {
        $specificity = new Specificity(0, 0, 0);
        foreach ($this->compounds as [, $compound]) {
            $specificity = $specificity->plus($compound->specificity());
        }
        return $specificity;
    }
}
