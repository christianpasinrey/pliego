<?php

declare(strict_types=1);

namespace Pliego\Css;

/**
 * css-variables-1 §2-3: sustitución TEXTUAL de var(--name[, fallback]) — ocurre en compute-value
 * time, antes de que DeclarationParser tipe el valor sustituido (ver StyleResolver). $customProperties
 * son los valores CRUDOS (pueden contener var() anidado: --a: var(--b)) del elemento, ya fusionados
 * (heredados del padre + propios del cascade, propios ganan — ver StyleResolver::matchedDeclarationsAndCustomProps()).
 *
 * Ciclos (--a: var(--b); --b: var(--a)): se detectan con una pila de nombres EN CURSO DE
 * RESOLUCIÓN ($stack) — si var(--x) aparece mientras --x YA se está resolviendo más arriba en la
 * misma cadena, es un ciclo: la referencia es "guaranteed-invalid" (spec), así que se trata como
 * "sin valor", cayendo al fallback si lo hay o produciendo null (declaración inválida) si no.
 */
final class VarResolver
{
    /** @var list<string> */
    private array $warnings = [];

    /** @param array<string, string> $customProperties */
    public function __construct(private readonly array $customProperties) {}

    /** null = "invalid at computed-value time" (sin fallback disponible) — el llamador debe
     * descartar la declaración entera y avisar. */
    public function substitute(string $text): ?string
    {
        return $this->substituteText($text, []);
    }

    /** @return list<string> */
    public function drainWarnings(): array
    {
        $warnings = $this->warnings;
        $this->warnings = [];
        return $warnings;
    }

    /** @param list<string> $stack */
    private function substituteText(string $text, array $stack): ?string
    {
        $result = '';
        $pos = 0;
        while (($varPos = stripos($text, 'var(', $pos)) !== false) {
            $result .= substr($text, $pos, $varPos - $pos);
            $parenStart = $varPos + 3;
            $parenEnd = $this->findMatchingParen($text, $parenStart);
            if ($parenEnd === null) {
                $this->warnings[] = "Malformed var() (unbalanced parentheses): $text";
                return null;
            }
            $inner = substr($text, $parenStart + 1, $parenEnd - $parenStart - 1);
            $replacement = $this->resolveVarCall($inner, $stack);
            if ($replacement === null) {
                return null;
            }
            $result .= $replacement;
            $pos = $parenEnd + 1;
        }
        $result .= substr($text, $pos);
        return $result;
    }

    /** @param list<string> $stack */
    private function resolveVarCall(string $inner, array $stack): ?string
    {
        [$name, $fallback] = $this->splitVarArgs($inner);
        $name = trim($name);
        if (!str_starts_with($name, '--') || $name === '--') {
            $this->warnings[] = "Invalid var() reference: $inner";
            return null;
        }
        if (in_array($name, $stack, true)) {
            $this->warnings[] = "Cyclic reference to custom property $name";
            return $fallback !== null ? $this->substituteText($fallback, $stack) : null;
        }
        if (!array_key_exists($name, $this->customProperties)) {
            if ($fallback !== null) {
                return $this->substituteText($fallback, $stack);
            }
            $this->warnings[] = "Unknown custom property: $name";
            return null;
        }
        $resolved = $this->substituteText($this->customProperties[$name], [...$stack, $name]);
        if ($resolved === null) {
            return $fallback !== null ? $this->substituteText($fallback, $stack) : null;
        }
        return $resolved;
    }

    /** Divide "name, fallback" en el primer coma de NIVEL SUPERIOR (el fallback puede contener
     * comas propias dentro de paréntesis anidados, p.ej. var(--a, var(--b, blue))).
     * @return array{0: string, 1: ?string}
     */
    private function splitVarArgs(string $inner): array
    {
        $depth = 0;
        $len = strlen($inner);
        for ($i = 0; $i < $len; $i++) {
            $ch = $inner[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                return [substr($inner, 0, $i), trim(substr($inner, $i + 1))];
            }
        }
        return [$inner, null];
    }

    private function findMatchingParen(string $text, int $openParenIndex): ?int
    {
        $depth = 0;
        $len = strlen($text);
        for ($i = $openParenIndex; $i < $len; $i++) {
            if ($text[$i] === '(') {
                $depth++;
            } elseif ($text[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }
}
