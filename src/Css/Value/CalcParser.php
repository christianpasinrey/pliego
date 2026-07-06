<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/**
 * css-values-3 §8: parser de expresiones para el CUERPO de un calc() (sin el "calc(" ni el ")"
 * exteriores — eso lo despoja el llamador, ver DeclarationParser::tryParseCalc()). Recursive-
 * descent clásico (sum -> product -> unary -> primary) sobre un tokenizer propio; evalúa
 * inmediatamente en vez de construir un AST reusable porque el único consumidor (CalcExpr) es un
 * vector de 4 floats — no hace falta conservar la forma del árbol una vez comprobados los tipos.
 *
 * Reglas de tipo (css-values-3 §8.1/§8.2, aplicadas en combineAdd()/combineMul()):
 *   - +/- exige que AMBOS operandos sean el mismo "tipo" (número puro, o longitud/porcentaje);
 *     mezclar un número con una longitud no tiene interpretación.
 *   - * exige que AL MENOS UN lado sea un número puro (no se pueden multiplicar dos longitudes).
 *   - / exige que el DIVISOR sea un número puro y no cero.
 * Cualquier violación (incluida división por cero) produce un warning y aborta el parseo entero
 * (toda la declaración se descarta más arriba, en DeclarationParser).
 */
final class CalcParser
{
    /** @var list<CalcToken> */
    private array $tokens = [];
    private int $pos = 0;

    /** @var list<string> */
    private array $warnings = [];

    public function parse(string $expression): ?CalcExpr
    {
        $tokens = $this->tokenize($expression);
        if ($tokens === null) {
            $this->warn("Invalid calc() expression: $expression");
            return null;
        }
        if ($tokens === []) {
            $this->warn('Empty calc() expression');
            return null;
        }
        $this->tokens = $tokens;
        $this->pos = 0;
        $node = $this->parseSum();
        if ($node === null) {
            return null;
        }
        if ($this->pos !== count($this->tokens)) {
            $this->warn("Invalid calc() expression: $expression");
            return null;
        }
        if ($node->isNumber) {
            $this->warn("calc() must resolve to a length or percentage, got a bare number: $expression");
            return null;
        }
        return CalcExpr::of($node->percentFactor, $node->emFactor, $node->remFactor, $node->pxOffset);
    }

    /** @return list<string> */
    public function drainWarnings(): array
    {
        $warnings = $this->warnings;
        $this->warnings = [];
        return $warnings;
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    // --- Tokenizer ------------------------------------------------------------------------

    /** @return ?list<CalcToken> */
    private function tokenize(string $s): ?array
    {
        $tokens = [];
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $ch = $s[$i];
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            if (in_array($ch, ['+', '-', '*', '/'], true)) {
                $tokens[] = CalcToken::op($ch);
                $i++;
                continue;
            }
            if ($ch === '(') {
                $tokens[] = CalcToken::lparen();
                $i++;
                continue;
            }
            if ($ch === ')') {
                $tokens[] = CalcToken::rparen();
                $i++;
                continue;
            }
            // css-values-3 §4.3.6 <number-token>: a leading digit before the dot is optional
            // (".5"/".25" are valid numbers, not just "0.5") — the alternation tries the
            // digit-first form first (greedier on the common case), falling back to the bare-dot
            // form. M6-T4 fix (Finding 1): the original `\d+(?:\.\d+)?` rejected ".5" outright,
            // dropping calc(var(--bs-spacing) * .5) — Bootstrap's literal spacer pattern — with
            // "Invalid calc() expression". No sign here: unary minus is handled by parseUnary(),
            // never embedded in the number token itself (see the "-10px + 3px" test).
            if (preg_match('/\G(?:\d+(?:\.\d+)?|\.\d+)/', $s, $m, 0, $i) === 1) {
                $numStr = $m[0];
                $i += strlen($numStr);
                if (preg_match('/\G(px|rem|em|pt|cm|mm|in|%)/i', $s, $um, 0, $i) === 1) {
                    $unit = strtolower($um[0]);
                    $i += strlen($um[0]);
                    $tokens[] = CalcToken::dim((float) $numStr, $unit);
                } else {
                    $tokens[] = CalcToken::num((float) $numStr);
                }
                continue;
            }
            return null;
        }
        return $tokens;
    }

    private function peekOp(): ?string
    {
        $tok = $this->tokens[$this->pos] ?? null;
        return $tok !== null && $tok->type === 'op' ? $tok->text : null;
    }

    private function consumeOp(): string
    {
        $op = $this->tokens[$this->pos]->text;
        $this->pos++;
        return $op;
    }

    // --- Recursive descent ------------------------------------------------------------------

    /**
     * @phpstan-impure — muta $this->pos a través de la recursión (parseProduct/parseUnary/
     * parsePrimary/consumeOp); sin esta anotación PHPStan asume pureza y "recuerda" el valor de
     * $this->pos previo a la llamada al comprobar `$this->pos !== count($this->tokens)` justo
     * después en parse(), produciendo falsos positivos (notIdentical.alwaysTrue,
     * deadCode.unreachable) sobre código perfectamente alcanzable.
     */
    private function parseSum(): ?CalcNode
    {
        $left = $this->parseProduct();
        if ($left === null) {
            return null;
        }
        while (($op = $this->peekOp()) === '+' || $op === '-') {
            $this->consumeOp();
            $right = $this->parseProduct();
            if ($right === null) {
                return null;
            }
            $left = $this->combineAdd($left, $right, $op);
            if ($left === null) {
                return null;
            }
        }
        return $left;
    }

    private function parseProduct(): ?CalcNode
    {
        $left = $this->parseUnary();
        if ($left === null) {
            return null;
        }
        while (($op = $this->peekOp()) === '*' || $op === '/') {
            $this->consumeOp();
            $right = $this->parseUnary();
            if ($right === null) {
                return null;
            }
            $left = $this->combineMul($left, $right, $op);
            if ($left === null) {
                return null;
            }
        }
        return $left;
    }

    private function parseUnary(): ?CalcNode
    {
        $op = $this->peekOp();
        if ($op === '-') {
            $this->consumeOp();
            $inner = $this->parseUnary();
            if ($inner === null) {
                return null;
            }
            return $inner->isNumber
                ? CalcNode::number(-$inner->number)
                : CalcNode::dimension(-$inner->percentFactor, -$inner->emFactor, -$inner->remFactor, -$inner->pxOffset);
        }
        if ($op === '+') {
            $this->consumeOp();
            return $this->parseUnary();
        }
        return $this->parsePrimary();
    }

    private function parsePrimary(): ?CalcNode
    {
        $tok = $this->tokens[$this->pos] ?? null;
        if ($tok === null) {
            $this->warn('calc(): unexpected end of expression');
            return null;
        }
        if ($tok->type === 'lparen') {
            $this->pos++;
            $node = $this->parseSum();
            if ($node === null) {
                return null;
            }
            $close = $this->tokens[$this->pos] ?? null;
            if ($close === null || $close->type !== 'rparen') {
                $this->warn('calc(): missing closing parenthesis');
                return null;
            }
            $this->pos++;
            return $node;
        }
        if ($tok->type === 'num') {
            $this->pos++;
            return CalcNode::number($tok->number);
        }
        if ($tok->type === 'dim') {
            $this->pos++;
            $value = $tok->number;
            $unit = $tok->text;
            return match ($unit) {
                '%' => CalcNode::dimension($value, 0.0, 0.0, 0.0),
                'em' => CalcNode::dimension(0.0, $value, 0.0, 0.0),
                'rem' => CalcNode::dimension(0.0, 0.0, $value, 0.0),
                'px' => CalcNode::dimension(0.0, 0.0, 0.0, $value),
                'pt' => CalcNode::dimension(0.0, 0.0, 0.0, $value * CssLength::PX_PER_PT),
                'cm' => CalcNode::dimension(0.0, 0.0, 0.0, $value * CssLength::PX_PER_CM),
                'mm' => CalcNode::dimension(0.0, 0.0, 0.0, $value * CssLength::PX_PER_MM),
                'in' => CalcNode::dimension(0.0, 0.0, 0.0, $value * CssLength::PX_PER_IN),
                default => CalcNode::dimension(0.0, 0.0, 0.0, 0.0),
            };
        }
        $this->warn('calc(): unexpected token');
        return null;
    }

    private function combineAdd(CalcNode $a, CalcNode $b, string $op): ?CalcNode
    {
        $sign = $op === '-' ? -1.0 : 1.0;
        if ($a->isNumber !== $b->isNumber) {
            $this->warn('calc(): cannot add/subtract a number and a length/percentage');
            return null;
        }
        if ($a->isNumber) {
            return CalcNode::number($a->number + $sign * $b->number);
        }
        return CalcNode::dimension(
            $a->percentFactor + $sign * $b->percentFactor,
            $a->emFactor + $sign * $b->emFactor,
            $a->remFactor + $sign * $b->remFactor,
            $a->pxOffset + $sign * $b->pxOffset,
        );
    }

    private function combineMul(CalcNode $a, CalcNode $b, string $op): ?CalcNode
    {
        if ($op === '*') {
            if ($a->isNumber && $b->isNumber) {
                return CalcNode::number($a->number * $b->number);
            }
            // Ya se descartó "ambos número" arriba: a partir de aquí, como mucho UNO de los dos
            // es un número puro (el otro es la dimensión que se escala) — nunca los dos a la vez.
            if ($a->isNumber) {
                return CalcNode::dimension(
                    $b->percentFactor * $a->number,
                    $b->emFactor * $a->number,
                    $b->remFactor * $a->number,
                    $b->pxOffset * $a->number,
                );
            }
            if ($b->isNumber) {
                return CalcNode::dimension(
                    $a->percentFactor * $b->number,
                    $a->emFactor * $b->number,
                    $a->remFactor * $b->number,
                    $a->pxOffset * $b->number,
                );
            }
            $this->warn('calc(): cannot multiply two lengths/percentages');
            return null;
        }
        // division: el divisor SIEMPRE debe ser un número puro (css-values-3 §8.2).
        if (!$b->isNumber) {
            $this->warn('calc(): cannot divide by a length/percentage');
            return null;
        }
        if ($b->number === 0.0) {
            $this->warn('calc(): division by zero');
            return null;
        }
        if ($a->isNumber) {
            return CalcNode::number($a->number / $b->number);
        }
        return CalcNode::dimension(
            $a->percentFactor / $b->number,
            $a->emFactor / $b->number,
            $a->remFactor / $b->number,
            $a->pxOffset / $b->number,
        );
    }
}
