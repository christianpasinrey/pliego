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
        // M10-T1 (css-values-4 §5.1.1): vw/vh — trailing/optional (default 0.0) for the same
        // backward-compatibility reason as CalcNode's own vwFactor/vhFactor (every pre-existing
        // 4-arg CalcExpr::of() call site, including test fixtures, stays valid unchanged). Unlike
        // %, vw/vh do NOT need to defer to Layout: the page's CSS-px size is known statically for
        // the whole render (Page\PaperSize), so fold() resolves them EAGERLY, in the same pass as
        // em/rem — never producing a deferred CalcValue for a vw/vh-only expression.
        public float $vwFactor = 0.0,
        public float $vhFactor = 0.0,
    ) {}

    public static function of(
        float $percentFactor,
        float $emFactor,
        float $remFactor,
        float $pxOffset,
        float $vwFactor = 0.0,
        float $vhFactor = 0.0,
    ): self {
        return new self($percentFactor, $emFactor, $remFactor, $pxOffset, $vwFactor, $vhFactor);
    }

    /**
     * M6-T4 fix (Finding 2): true when this calc() has no em/rem/%/vw/vh component — i.e. it is
     * already a definite px value, knowable WITHOUT any compute-time context (font-size,
     * containing block, page size). DeclarationParser::rawValueOf() uses this to fold `calc(-5px)`
     * to -5.0 at PARSE time so the existing non-negative check applies exactly as it would to the
     * literal `-5px` — a calc() with em/rem/%/vw/vh is NOT definite yet (needs
     * ComputedStyle::compute()/Layout), so it must NOT be treated as a known value here.
     *
     * M10-T1: vw/vh join the check for the same reason em/rem do — even though they resolve
     * eagerly in ComputedStyle::compute() (see fold()'s own docblock), that resolution still needs
     * the page size, unavailable at DeclarationParser's parse-time-only vantage point.
     */
    public function isDefinite(): bool
    {
        return $this->percentFactor === 0.0 && $this->emFactor === 0.0 && $this->remFactor === 0.0
            && $this->vwFactor === 0.0 && $this->vhFactor === 0.0;
    }

    /**
     * Pliega em/rem/vw/vh (siempre resolubles aquí, igual que CssLength en ComputedStyle::compute
     * — ver el docblock de $vwFactor arriba para por qué vw/vh se pliegan EAGER, no diferidos como
     * %). $percentBase es null en propiedades donde % se difiere a Layout (margin/padding/width/
     * flex-basis, igual que un LengthPercentage::percent() normal): en ese caso, si el árbol
     * contiene %, el resultado es un CalcValue diferido. $percentBase NO es null en font-size/
     * line-height (% se resuelve YA, contra el font-size propio/del padre, nunca contra un
     * containing block) — ahí el resultado SIEMPRE es un float puro.
     *
     * M10-T1: $pageWidthPx/$pageHeightPx llevan el default 0.0 (nunca alcanzado en la práctica
     * cuando vwFactor/vhFactor son cero, el caso de CUALQUIER calc() sin vw/vh) — todo llamador
     * real (ComputedStyle::compute()) los hila desde el mismo pageWidthPx/pageHeightPx que ya
     * recibe, igual patrón que $emBase/$remBase.
     */
    public function fold(float $emBase, float $remBase, ?float $percentBase, float $pageWidthPx = 0.0, float $pageHeightPx = 0.0): CalcValue|float
    {
        $px = $this->pxOffset + $this->emFactor * $emBase + $this->remFactor * $remBase
            + ($this->vwFactor / 100.0) * $pageWidthPx + ($this->vhFactor / 100.0) * $pageHeightPx;
        if ($this->percentFactor === 0.0) {
            return $px;
        }
        if ($percentBase !== null) {
            return $px + ($this->percentFactor / 100.0) * $percentBase;
        }
        return CalcValue::of($this->percentFactor, $px);
    }
}
