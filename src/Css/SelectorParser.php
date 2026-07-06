<?php

declare(strict_types=1);

namespace Pliego\Css;

/**
 * selectors-3 §4 (tokenización) + §8 (combinadores) + §6 (selectores simples/compuestos):
 * tokenizer propio (ya no regex de M0) para un selector complejo completo (StylesheetParser sigue
 * siendo quien separa la lista por comas — cada elemento de esa lista es un selector completo que
 * se le pasa a parse()).
 *
 * M6-T1 (staging, ver brief): el parseo es completo (combinadores, [atributos], :pseudo-clases,
 * :not()) y la specificity resultante es exacta (selectors-3 §17). El MATCHING de lo que no sea un
 * único compuesto simple queda diferido a M6-T2 (ver ComplexSelector::isStagedForMatching()) — el
 * warning de ese staging se emite aquí, una única vez por selector aceptado, no en tiempo de match.
 */
final readonly class SelectorParser
{
    private const string COMBINATOR_CHARS = '>+~';
    /** selectors-3 §6.3: operadores de comparación de [attr]. */
    private const string ATTRIBUTE_OPERATOR_RE = '/\G(~=|\|=|\^=|\$=|\*=|=)/';
    private const string IDENT_RE = '/\G-?[A-Za-z_][A-Za-z0-9_-]*/';

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

        $complex = new ComplexSelector($compounds);
        if ($complex->isStagedForMatching()) {
            $this->warnings?->addWarning('combinator/pseudo matching arrives in M6-T2');
        }
        return $complex;
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
        if ($pos >= $length || $input[$pos] !== ']') {
            return null;
        }
        $pos++;
        return new AttributeSelector($name, $operator, $value);
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
        if ($pos >= $length || $input[$pos] !== '(') {
            return new PseudoClass($name);
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

        $negatedSelector = null;
        if (strtolower($name) === 'not') {
            $negatedSelector = $this->parseNegationArgument($argument);
            if ($negatedSelector === null) {
                return null;
            }
        }
        return new PseudoClass($name, $argument, $negatedSelector);
    }

    /**
     * selectors-3 §6.6.7 (negation_arg): un único selector simple. Se reutiliza parseCompound()
     * (más permisivo que la gramática estricta — acepta también secuencias como ".a.b" — pero
     * suficiente para M6-T1, donde :not() nunca llega a matchear de verdad todavía).
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
        return $compound;
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
