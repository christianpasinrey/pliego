<?php

declare(strict_types=1);

namespace Pliego\Css;

/**
 * selectors-3 §4 (tokenización) + §8 (combinadores) + §6 (selectores simples/compuestos):
 * tokenizer propio (ya no regex de M0) para un selector complejo completo (StylesheetParser sigue
 * siendo quien separa la lista por comas — cada elemento de esa lista es un selector completo que
 * se le pasa a parse()).
 *
 * M6-T2: parseo Y matching completos (ver ComplexSelector/CompoundSelector). Los warnings de
 * "esto parsea pero nunca hará match" se emiten aquí, UNA vez por selector/ocurrencia en tiempo de
 * parseo — nunca en matches(), que puede invocarse miles de veces (una vez por elemento del
 * documento por regla) y no debe inundar de warnings repetidos.
 */
final readonly class SelectorParser
{
    private const string COMBINATOR_CHARS = '>+~';
    /** selectors-3 §6.3: operadores de comparación de [attr]. */
    private const string ATTRIBUTE_OPERATOR_RE = '/\G(~=|\|=|\^=|\$=|\*=|=)/';
    private const string IDENT_RE = '/\G-?[A-Za-z_][A-Za-z0-9_-]*/';
    /** selectors-3 §6.6.1: pseudo-clases sin efecto en medios paginados (M6 exclusión permanente). */
    private const array DYNAMIC_PSEUDO_CLASSES = ['hover', 'focus', 'active', 'visited', 'link'];
    /** Estructurales reconocidas por la gramática pero aún no implementadas (M6 exclusión, no M7). */
    private const array UNSUPPORTED_PSEUDO_CLASSES = [
        'nth-of-type', 'nth-last-of-type', 'nth-last-child',
        'first-of-type', 'last-of-type', 'only-of-type', 'only-child', 'empty',
    ];
    /** Pseudo-clases funcionales (`:foo(...)`) que EXIGEN argumento. */
    private const array FUNCTIONAL_PSEUDO_CLASSES = ['not', 'nth-child'];

    public function __construct(private ?WarningCollector $warnings = null) {}

    public function parse(string $selector): ?ComplexSelector
    {
        $input = trim($selector);
        if ($input === '') {
            $this->warnings?->addWarning('Invalid selector syntax: empty selector');
            return null;
        }

        $length = strlen($input);
        $pos = 0;
        /** @var list<array{Combinator, CompoundSelector}> $compounds */
        $compounds = [];
        // Primer par: el combinador no tiene "compuesto anterior" al que referirse (ver docblock
        // de ComplexSelector) — Descendant es solo el relleno convencional, nunca se lee.
        $combinator = Combinator::Descendant;

        while (true) {
            $compound = $this->parseCompound($input, $pos, $length);
            if ($compound === null) {
                $this->warnings?->addWarning("Invalid selector syntax: \"$selector\"");
                return null;
            }
            $compounds[] = [$combinator, $compound];

            $hadWhitespace = $this->skipWhitespace($input, $pos, $length);
            if ($pos >= $length) {
                break;
            }

            $char = $input[$pos];
            if (str_contains(self::COMBINATOR_CHARS, $char)) {
                $combinator = match ($char) {
                    '>' => Combinator::Child,
                    '+' => Combinator::NextSibling,
                    '~' => Combinator::SubsequentSibling,
                    default => Combinator::Descendant, // inalcanzable: $char ya está en COMBINATOR_CHARS
                };
                $pos++;
                $this->skipWhitespace($input, $pos, $length);
                if ($pos >= $length) {
                    $this->warnings?->addWarning("Invalid selector syntax: \"$selector\" (trailing combinator)");
                    return null;
                }
                continue;
            }

            if ($hadWhitespace) {
                $combinator = Combinator::Descendant;
                continue;
            }

            // Ni espacio ni combinador entre dos compuestos: sintaxis inválida (p.ej. "p*", "a:hover:hover" está
            // permitido por el bucle de parseCompound, pero "a b" mal separado como "ab " no lo está).
            $this->warnings?->addWarning("Invalid selector syntax: \"$selector\"");
            return null;
        }

        return new ComplexSelector($compounds);
    }

    private function parseCompound(string $input, int &$pos, int $length): ?CompoundSelector
    {
        $universal = false;
        $type = null;
        $classes = [];
        $id = null;
        $attributes = [];
        $pseudoClasses = [];
        $consumedAny = false;

        if ($pos < $length && $input[$pos] === '*') {
            $universal = true;
            $pos++;
            $consumedAny = true;
        } else {
            $ident = $this->matchIdent($input, $pos, $length);
            if ($ident !== null) {
                $type = strtolower($ident);
                $consumedAny = true;
            }
        }

        while ($pos < $length) {
            $char = $input[$pos];
            if ($char === '.') {
                $pos++;
                $ident = $this->matchIdent($input, $pos, $length);
                if ($ident === null) {
                    return null;
                }
                $classes[] = $ident;
                $consumedAny = true;
                continue;
            }
            if ($char === '#') {
                $pos++;
                $ident = $this->matchIdent($input, $pos, $length);
                if ($ident === null) {
                    return null;
                }
                $id = $ident;
                $consumedAny = true;
                continue;
            }
            if ($char === '[') {
                $attribute = $this->parseAttribute($input, $pos, $length);
                if ($attribute === null) {
                    return null;
                }
                $attributes[] = $attribute;
                $consumedAny = true;
                continue;
            }
            if ($char === ':') {
                if ($pos + 1 < $length && $input[$pos + 1] === ':') {
                    // Pseudo-elemento (::before/::after): M7 (necesitan generar cajas) — error de
                    // parseo aquí, no un caso silencioso.
                    return null;
                }
                $pseudoClass = $this->parsePseudoClass($input, $pos, $length);
                if ($pseudoClass === null) {
                    return null;
                }
                $pseudoClasses[] = $pseudoClass;
                $consumedAny = true;
                continue;
            }
            break; // whitespace, combinador, o fin de cadena: el compuesto termina aquí
        }

        if (!$consumedAny) {
            return null;
        }
        return new CompoundSelector($universal, $type, $classes, $id, $attributes, $pseudoClasses);
    }

    private function parseAttribute(string $input, int &$pos, int $length): ?AttributeSelector
    {
        $pos++; // consume '['
        $this->skipWhitespace($input, $pos, $length);
        $name = $this->matchIdent($input, $pos, $length);
        if ($name === null) {
            return null;
        }
        $this->skipWhitespace($input, $pos, $length);
        if ($pos >= $length) {
            return null;
        }
        if ($input[$pos] === ']') {
            $pos++;
            return new AttributeSelector($name);
        }
        if (preg_match(self::ATTRIBUTE_OPERATOR_RE, $input, $m, 0, $pos) !== 1) {
            return null;
        }
        $operator = $m[0];
        $pos += strlen($operator);
        $this->skipWhitespace($input, $pos, $length);
        $value = $this->parseAttributeValue($input, $pos, $length);
        if ($value === null) {
            return null;
        }
        $this->skipWhitespace($input, $pos, $length);

        // Selectors-4 §6.3.1 (aceptado aquí por compatibilidad, fuera de selectors-3): flag 'i'/'I'
        // de case-insensitive matching. M6 nunca lo honra de verdad — se acepta la sintaxis pero el
        // matching sigue siendo case-sensitive, con un warning UNA vez en tiempo de parseo.
        $caseFlag = null;
        if ($pos < $length && ($input[$pos] === 'i' || $input[$pos] === 'I')) {
            $peek = $pos + 1;
            if ($peek >= $length || !$this->isIdentChar($input[$peek])) {
                $caseFlag = $input[$pos];
                $pos++;
                $this->skipWhitespace($input, $pos, $length);
            }
        }

        if ($pos >= $length || $input[$pos] !== ']') {
            return null;
        }
        $pos++;
        if ($caseFlag !== null) {
            $this->warnings?->addWarning(
                "Case-insensitive attribute matching (\"$caseFlag\" flag) is not supported; "
                . "falling back to case-sensitive matching: [$name$operator\"$value\" $caseFlag]",
            );
        }
        return new AttributeSelector($name, $operator, $value);
    }

    private function isIdentChar(string $char): bool
    {
        return preg_match('/[A-Za-z0-9_-]/', $char) === 1;
    }

    private function parseAttributeValue(string $input, int &$pos, int $length): ?string
    {
        if ($pos >= $length) {
            return null;
        }
        $quote = $input[$pos];
        if ($quote === '"' || $quote === "'") {
            $end = strpos($input, $quote, $pos + 1);
            if ($end === false) {
                return null;
            }
            $value = substr($input, $pos + 1, $end - $pos - 1);
            $pos = $end + 1;
            return $value;
        }
        return $this->matchIdent($input, $pos, $length);
    }

    private function parsePseudoClass(string $input, int &$pos, int $length): ?PseudoClass
    {
        $pos++; // consume ':'
        $name = $this->matchIdent($input, $pos, $length);
        if ($name === null) {
            return null;
        }
        $lowerName = strtolower($name);

        $hasParens = $pos < $length && $input[$pos] === '(';
        if (in_array($lowerName, self::FUNCTIONAL_PSEUDO_CLASSES, true) && !$hasParens) {
            return null; // :not y :nth-child EXIGEN argumento
        }
        if (!$hasParens) {
            return $this->buildSimplePseudoClass($name, $lowerName);
        }

        $pos++; // consume '('
        $argStart = $pos;
        $depth = 1;
        while ($pos < $length) {
            if ($input[$pos] === '(') {
                $depth++;
            } elseif ($input[$pos] === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $pos++;
        }
        if ($depth !== 0) {
            return null; // paréntesis sin cerrar
        }
        $argument = trim(substr($input, $argStart, $pos - $argStart));
        $pos++; // consume ')'

        if ($lowerName === 'not') {
            $negatedSelector = $this->parseNegationArgument($argument);
            if ($negatedSelector === null) {
                return null;
            }
            return new PseudoClass($name, $argument, $negatedSelector);
        }
        if ($lowerName === 'nth-child') {
            $anB = $this->parseAnB($argument);
            if ($anB === null) {
                $this->warnings?->addWarning("Invalid :nth-child() argument: \"$argument\"");
                return null;
            }
            [$a, $b] = $anB;
            return new PseudoClass($name, $argument, nthA: $a, nthB: $b);
        }
        // Cualquier otra pseudo-clase funcional (p.ej. :nth-of-type(2), :lang(en)) cae en la misma
        // clasificación (dinámica/no-soportada/desconocida) que las que no llevan argumento — el
        // argumento se conserva en el objeto por si sirve de contexto en el warning, pero no se
        // interpreta.
        return $this->buildSimplePseudoClass($name, $lowerName, $argument);
    }

    /** Clasifica una pseudo-clase que no es :not()/:nth-child() (con o sin argumento). */
    private function buildSimplePseudoClass(string $name, string $lowerName, ?string $argument = null): PseudoClass
    {
        if ($lowerName === 'root' || $lowerName === 'first-child' || $lowerName === 'last-child') {
            return new PseudoClass($name, $argument);
        }
        if (in_array($lowerName, self::DYNAMIC_PSEUDO_CLASSES, true)) {
            $this->warnings?->addWarning("Dynamic pseudo-class has no effect in paged media: :$lowerName");
            return new PseudoClass($name, $argument, neverMatches: true);
        }
        if (in_array($lowerName, self::UNSUPPORTED_PSEUDO_CLASSES, true)) {
            $this->warnings?->addWarning("Pseudo-class not supported yet: :$lowerName");
            return new PseudoClass($name, $argument, neverMatches: true);
        }
        $this->warnings?->addWarning("Unknown pseudo-class: :$lowerName");
        return new PseudoClass($name, $argument, neverMatches: true);
    }

    /**
     * selectors-3 §6.6.7 (negation_arg): un único selector simple — SIN combinar (nada de ".a.b" ni
     * "p.a"), SIN anidar :not() dentro de :not(). Más estricto que parseCompound() en general
     * (reutilizado aquí y luego validado), per directiva de review de M6-T1.
     */
    private function parseNegationArgument(string $argument): ?CompoundSelector
    {
        $pos = 0;
        $length = strlen($argument);
        $compound = $this->parseCompound($argument, $pos, $length);
        if ($compound === null) {
            return null;
        }
        $this->skipWhitespace($argument, $pos, $length);
        if ($pos !== $length) {
            return null; // sobra contenido tras el selector simple
        }
        if (!$this->isSingleSimpleSelector($compound)) {
            $this->warnings?->addWarning(
                ":not() argument must be a single simple selector (no compounds, no nesting): \"$argument\"",
            );
            return null;
        }
        return $compound;
    }

    /** true si $compound tiene EXACTAMENTE un selector simple y no anida :not(). */
    private function isSingleSimpleSelector(CompoundSelector $compound): bool
    {
        $count = ($compound->type !== null ? 1 : 0)
            + ($compound->universal ? 1 : 0)
            + count($compound->classes)
            + ($compound->id !== null ? 1 : 0)
            + count($compound->attributes)
            + count($compound->pseudoClasses);
        if ($count !== 1) {
            return false;
        }
        foreach ($compound->pseudoClasses as $pseudoClass) {
            if ($pseudoClass->isNegation()) {
                return false; // no anidar :not() dentro de :not()
            }
        }
        return true;
    }

    /**
     * selectors-3 §6.5.2 (nth-child grammar), simplificada: "odd", "even", "<entero>",
     * "[±]<entero>?n[ ±<entero>]" (espacios internos ya normalizados fuera).
     *
     * @return ?array{int, int} [A, B]
     */
    private function parseAnB(string $argument): ?array
    {
        $normalized = strtolower(str_replace(' ', '', $argument));
        if ($normalized === '') {
            return null;
        }
        if ($normalized === 'odd') {
            return [2, 1];
        }
        if ($normalized === 'even') {
            return [2, 0];
        }
        if (preg_match('/^([+-]?\d+)$/', $normalized, $m) === 1) {
            return [0, (int) $m[1]];
        }
        if (preg_match('/^([+-]?\d*)n([+-]\d+)?$/', $normalized, $m) === 1) {
            $aPart = $m[1];
            $a = match ($aPart) {
                '', '+' => 1,
                '-' => -1,
                default => (int) $aPart,
            };
            $b = isset($m[2]) ? (int) $m[2] : 0;
            return [$a, $b];
        }
        return null;
    }

    private function matchIdent(string $input, int &$pos, int $length): ?string
    {
        if ($pos >= $length || preg_match(self::IDENT_RE, $input, $m, 0, $pos) !== 1) {
            return null;
        }
        $pos += strlen($m[0]);
        return $m[0];
    }

    private function skipWhitespace(string $input, int &$pos, int $length): bool
    {
        $start = $pos;
        while ($pos < $length && ctype_space($input[$pos])) {
            $pos++;
        }
        return $pos > $start;
    }
}
