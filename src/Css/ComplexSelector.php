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
 * M6-T2: matches() implementa el algoritmo estándar right-to-left con backtracking. El compuesto
 * MÁS A LA DERECHA matchea el elemento en sí; para cada compuesto a su izquierda, el combinador que
 * lo une al compuesto ya matcheado (a su derecha) determina dónde buscar el siguiente candidato:
 *   - Child (>): el parentElement, exactamente.
 *   - Descendant (' '): CUALQUIER ancestro — se prueba cada uno de abajo hacia arriba y, si el
 *     resto de la cadena no matchea desde ahí, se sigue subiendo (backtracking real: un ancestro
 *     que matchea el compuesto pero no el resto de la cadena NO aborta la búsqueda).
 *   - NextSibling (+): el hermano ELEMENT inmediatamente anterior, exactamente (sin backtracking:
 *     la spec exige adyacencia directa).
 *   - SubsequentSibling (~): CUALQUIER hermano anterior — mismo backtracking que Descendant.
 * DOM 8.4 no expone ->children (solo listas de nodos mixtos); se usa parentElement/
 * previousElementSibling/nextElementSibling, que sí filtran a solo Element.
 *
 * Implementación: en vez de indexar $compounds con enteros calculados (offsets que PHPStan no
 * puede verificar sobre un list<> genérico), matches() precomputa, de una pasada con foreach, la
 * lista de "steps" en orden DERECHA→IZQUIERDA (ver stepsRightToLeft()) y la consume con
 * array_shift() — solo desestructuración de listas, sin aritmética de índices.
 */
final readonly class ComplexSelector
{
    /** @param list<array{Combinator, CompoundSelector}> $compounds */
    public function __construct(public array $compounds) {}

    public function matches(\Dom\Element $element): bool
    {
        [$steps, $rightmost] = $this->stepsRightToLeft();
        if ($rightmost === null || !$rightmost->matchesElement($element)) {
            return false;
        }
        return $this->matchesSteps($steps, $element);
    }

    /**
     * Recorre $compounds UNA vez, de izquierda a derecha, y produce:
     *   - $rightmost: el último compuesto (matcheado directamente contra el elemento en matches()).
     *   - $steps: el resto de los pares, en orden INVERSO (derecha→izquierda), donde cada step es
     *     (combinador-que-une-este-compuesto-con-el-ya-matcheado-a-su-derecha, ESTE compuesto).
     *
     * @return array{list<array{Combinator, CompoundSelector}>, ?CompoundSelector}
     */
    private function stepsRightToLeft(): array
    {
        $steps = [];
        $previousCompound = null;
        foreach ($this->compounds as [$combinator, $compound]) {
            if ($previousCompound !== null) {
                $steps[] = [$combinator, $previousCompound];
            }
            $previousCompound = $compound;
        }
        return [array_reverse($steps), $previousCompound];
    }

    /** @param list<array{Combinator, CompoundSelector}> $steps */
    private function matchesSteps(array $steps, \Dom\Element $matchedElement): bool
    {
        $step = array_shift($steps);
        if ($step === null) {
            return true; // no quedan compuestos a la izquierda: toda la cadena matcheó
        }
        [$combinator, $compound] = $step;

        return match ($combinator) {
            Combinator::Child => $this->matchesParent($steps, $compound, $matchedElement),
            Combinator::Descendant => $this->matchesAnyAncestor($steps, $compound, $matchedElement),
            Combinator::NextSibling => $this->matchesImmediatePreviousSibling($steps, $compound, $matchedElement),
            Combinator::SubsequentSibling => $this->matchesAnyPreviousSibling($steps, $compound, $matchedElement),
        };
    }

    /** @param list<array{Combinator, CompoundSelector}> $steps */
    private function matchesParent(array $steps, CompoundSelector $compound, \Dom\Element $child): bool
    {
        $parent = $child->parentElement;
        if ($parent === null || !$compound->matchesElement($parent)) {
            return false;
        }
        return $this->matchesSteps($steps, $parent);
    }

    /** @param list<array{Combinator, CompoundSelector}> $steps */
    private function matchesAnyAncestor(array $steps, CompoundSelector $compound, \Dom\Element $descendant): bool
    {
        for ($ancestor = $descendant->parentElement; $ancestor !== null; $ancestor = $ancestor->parentElement) {
            // Backtracking: si este ancestro matchea el compuesto pero el resto de la cadena
            // falla desde él, el bucle sigue subiendo en vez de abortar.
            if ($compound->matchesElement($ancestor) && $this->matchesSteps($steps, $ancestor)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array{Combinator, CompoundSelector}> $steps */
    private function matchesImmediatePreviousSibling(array $steps, CompoundSelector $compound, \Dom\Element $element): bool
    {
        $sibling = $element->previousElementSibling;
        if ($sibling === null || !$compound->matchesElement($sibling)) {
            return false;
        }
        return $this->matchesSteps($steps, $sibling);
    }

    /** @param list<array{Combinator, CompoundSelector}> $steps */
    private function matchesAnyPreviousSibling(array $steps, CompoundSelector $compound, \Dom\Element $element): bool
    {
        for ($sibling = $element->previousElementSibling; $sibling !== null; $sibling = $sibling->previousElementSibling) {
            if ($compound->matchesElement($sibling) && $this->matchesSteps($steps, $sibling)) {
                return true;
            }
        }
        return false;
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
