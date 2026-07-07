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
        $length = strlen($text);
        while (($varPos = $this->findNextUnquotedVarCall($text, $pos, $length)) !== null) {
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

    /**
     * M7-T1 housekeeping (css-syntax-3 §4.3.5: a <string-token> is opaque — its contents are never
     * re-tokenized): finds the next "var(" (case-insensitive) OUTSIDE of a quoted string, starting
     * at $pos. Before this fix, substituteText() used a plain stripos() that would happily "find"
     * var( even when it was sitting inside a literal string value, e.g. a custom property declared
     * as `--a: "var(--b)"` — the review probe wants that string to survive untouched (font-family
     * gets the literal text "var(--b)", never the substituted --b) instead of being silently
     * rewritten as if it were a real function call. Escaped quotes (backslash) inside the string
     * are honored so a stray `\"` doesn't end the string early.
     */
    private function findNextUnquotedVarCall(string $text, int $pos, int $length): ?int
    {
        $quote = null;
        while ($pos < $length) {
            $char = $text[$pos];
            if ($quote !== null) {
                if ($char === '\\' && $pos + 1 < $length) {
                    $pos += 2;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                $pos++;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $pos++;
                continue;
            }
            if (($char === 'v' || $char === 'V') && $pos + 4 <= $length && strncasecmp(substr($text, $pos, 4), 'var(', 4) === 0) {
                return $pos;
            }
            $pos++;
        }
        return null;
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
        // M10-T1 finding fix (css-variables-1 §7.3, css-cascade-4 §7.3): `--x: initial` is the
        // CSS-wide keyword `initial`, which sets a custom property to the GUARANTEED-INVALID
        // value — NOT the literal three-letter string "initial". A var() reference to it must be
        // treated exactly like a reference to an unknown/cyclic custom property: engage the
        // fallback if present, else IACVT. Before this check, the literal text "initial" won
        // substitution and flowed into whatever property consumed it (e.g. Bootstrap's own
        // `.table` reset, `--bs-table-bg-state: initial` consumed via `var(--bs-table-bg-state,
        // var(--bs-table-bg-type, var(--bs-table-accent-bg)))`), producing bogus values like
        // `box-shadow: inset 0 0 0 9999px initial` instead of engaging the real fallback chain.
        //
        // Adjudicated scope for the OTHER three CSS-wide keywords on a custom property (not
        // exercised by any real sheet in this milestone, so left undriven/untested rather than
        // guessed at): `inherit` is already the DEFAULT behavior of custom properties (they always
        // inherit unless re-declared — StyleResolver merges parentCustomProperties before
        // ownCustomProperties, see matchedDeclarationsAndCustomProperties()), so `--x: inherit`
        // only diverges from today's behavior in the edge case of an element re-asserting it after
        // a MORE specific same-element rule already overrode --x with something else — a real but
        // rare pattern, deferred. `unset` is defined as `inherit` for custom properties specifically
        // (they are always inherited-type per spec), same deferral. `revert` would need cascade-
        // origin tracking this engine doesn't have; treating it as `initial` (this same GIV path)
        // is the pragmatic approximation, deferred until a real sheet drives it.
        if ($this->isCssWideInitialKeyword($this->customProperties[$name])) {
            if ($fallback !== null) {
                return $this->substituteText($fallback, $stack);
            }
            $this->warnings[] = "Custom property $name is the guaranteed-invalid value (set to the CSS-wide keyword 'initial')";
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

    /** css-cascade-4 §7.3: the CSS-wide keyword `initial`, matched case-insensitively with
     * surrounding whitespace trimmed (same tolerance css-syntax-3 tokenizing already affords any
     * other bare keyword token, e.g. `transparent`/`inherit` elsewhere in this codebase). */
    private function isCssWideInitialKeyword(string $rawValue): bool
    {
        return strtolower(trim($rawValue)) === 'initial';
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
