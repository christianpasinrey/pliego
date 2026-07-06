<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/**
 * css-values-3 §8: forma SIMBÓLICA de un calc() ya estructuralmente válido (tipos compatibles en
 * cada operación del árbol — suma, resta, multiplicación, división —, sin división por cero) pero
 * todavía sin resolver — em/rem necesitan el font-size propio/raíz (solo disponibles en
 * ComputedStyle::compute, igual que CssLength) y % puede necesitar el containing block (solo
 * disponible en Layout). CalcParser reduce cualquier árbol de calc() a esta forma canónica de 4
 * componentes ANTES de que exista ese contexto, porque suma y resta son combinaciones lineales, y
 * multiplicación/división (que exigen que un lado sea un número sin unidad) escalan los 4 por
 * igual — nunca hace falta guardar el árbol completo, un vector (percentFactor, emFactor,
 * remFactor, pxOffset) representa CUALQUIER expresión calc() válida en un contexto longitud/
 * porcentaje. Físicos (pt/cm/mm/in) ya llegan plegados a pxOffset desde CalcParser (mismos
 * factores que CssLength::fromCss, ver ahí).
 */
final readonly class CalcExpr
{
    private function __construct(
        public float $percentFactor,
        public float $emFactor,
        public float $remFactor,
        public float $pxOffset,
    ) {}

    public static function of(float $percentFactor, float $emFactor, float $remFactor, float $pxOffset): self
    {
        return new self($percentFactor, $emFactor, $remFactor, $pxOffset);
    }

    /**
     * M6-T4 fix (Finding 2): true when this calc() has no em/rem/% component — i.e. it is already
     * a definite px value, knowable WITHOUT any compute-time context (font-size, containing
     * block). DeclarationParser::rawValueOf() uses this to fold `calc(-5px)` to -5.0 at PARSE time
     * so the existing non-negative check applies exactly as it would to the literal `-5px` — a
     * calc() with em/rem/% is NOT definite yet (needs ComputedStyle::compute()/Layout), so it must
     * NOT be treated as a known value here.
     */
    public function isDefinite(): bool
    {
        return $this->percentFactor === 0.0 && $this->emFactor === 0.0 && $this->remFactor === 0.0;
    }

    /**
     * Pliega em/rem (siempre resolubles aquí, igual que CssLength en ComputedStyle::compute).
     * $percentBase es null en propiedades donde % se difiere a Layout (margin/padding/width/
     * flex-basis, igual que un LengthPercentage::percent() normal): en ese caso, si el árbol
     * contiene %, el resultado es un CalcValue diferido. $percentBase NO es null en font-size/
     * line-height (% se resuelve YA, contra el font-size propio/del padre, nunca contra un
     * containing block) — ahí el resultado SIEMPRE es un float puro.
     */
    public function fold(float $emBase, float $remBase, ?float $percentBase): CalcValue|float
    {
        $px = $this->pxOffset + $this->emFactor * $emBase + $this->remFactor * $remBase;
        if ($this->percentFactor === 0.0) {
            return $px;
        }
        if ($percentBase !== null) {
            return $px + ($this->percentFactor / 100.0) * $percentBase;
        }
        return CalcValue::of($this->percentFactor, $px);
    }
}
