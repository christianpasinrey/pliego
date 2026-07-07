<?php

declare(strict_types=1);

namespace Pliego\Layout\Fragment;

use Pliego\Css\Value\BorderRadius as CssBorderRadius;

/**
 * M8-T2 (css-backgrounds-3 §5/§5.5): contraparte RESUELTA (px, ya clampeada) de
 * Css\Value\BorderRadius — mismo patrón CSS-vs-Layout que Length/LengthPercentage frente a un
 * Rect ya en px, salvo que aquí SÍ hace falta un tipo propio (a diferencia de border-width, un
 * BorderSide::$widthPx siempre conocido en tiempo de estilo) porque el % de border-radius solo
 * puede resolverse cuando se conoce el border-box FINAL de la caja (tiempo de Layout), y porque
 * el clamp de solapes (§5.5) necesita los 4 radios y las 2 dimensiones de la caja a la vez.
 *
 * Construcción por defecto ($tl=$tr=$br=$bl=0.0, sin argumentos): usada como default de
 * BoxFragment::$borderRadius/InlineBoxFragment::$borderRadius vía "new in initializers" (PHP 8.1+)
 * — así los ~70 construction sites preexistentes de ambas clases (tests + FormattingContext*, ver
 * grep) no necesitan tocarse: sin radio declarado, el fragmento sigue trayendo exactamente el
 * mismo BorderRadius::zero() implícito que antes de esta tarea (comportamiento observable
 * idéntico, ver Paint\Painter: $radius->isZero() hace que el pintado caiga en el camino
 * fillRect/paintBordersFlat pre-M8-T2, byte a byte).
 */
final readonly class BorderRadius
{
    public function __construct(
        public float $tl = 0.0,
        public float $tr = 0.0,
        public float $br = 0.0,
        public float $bl = 0.0,
    ) {}

    public function isZero(): bool
    {
        return $this->tl === 0.0 && $this->tr === 0.0 && $this->br === 0.0 && $this->bl === 0.0;
    }

    /**
     * Resuelve los 4 LengthPercentage simbólicos de $css contra $borderBoxWidth (adjudicación
     * M8-T2: el % de border-radius SIEMPRE se resuelve contra el ANCHO del border box, incluso
     * para los radios que geométricamente "viven" en el eje vertical — divergencia documentada
     * frente a css-backgrounds-3 §5, que pediría el LADO correspondiente para cada eje; radios
     * circulares por esquina, sin componente elíptico, hace que esta simplificación sea
     * observacionalmente razonable y "hand-computable" para los tests del brief), y aplica el
     * clamp de solapes proporcional de §5.5: si la suma de dos radios adyacentes excede el lado
     * que comparten (tl+tr vs. $borderBoxWidth, bl+br vs. $borderBoxWidth, tl+bl vs.
     * $borderBoxHeight, tr+br vs. $borderBoxHeight), TODOS los radios se escalan por el MISMO
     * factor mínimo (nunca > 1 — nunca agranda un radio que ya cabía).
     */
    public static function fromCss(CssBorderRadius $css, float $borderBoxWidth, float $borderBoxHeight): self
    {
        $tl = $css->tl->resolve($borderBoxWidth);
        $tr = $css->tr->resolve($borderBoxWidth);
        $br = $css->br->resolve($borderBoxWidth);
        $bl = $css->bl->resolve($borderBoxWidth);

        $ratios = [1.0];
        if ($tl + $tr > 0.0) {
            $ratios[] = $borderBoxWidth / ($tl + $tr);
        }
        if ($bl + $br > 0.0) {
            $ratios[] = $borderBoxWidth / ($bl + $br);
        }
        if ($tl + $bl > 0.0) {
            $ratios[] = $borderBoxHeight / ($tl + $bl);
        }
        if ($tr + $br > 0.0) {
            $ratios[] = $borderBoxHeight / ($tr + $br);
        }
        $factor = min($ratios);

        return new self($tl * $factor, $tr * $factor, $br * $factor, $bl * $factor);
    }
}
