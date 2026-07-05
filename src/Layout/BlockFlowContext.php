<?php

declare(strict_types=1);

namespace Pliego\Layout;

use Pliego\Box\BlockBox;
use Pliego\Box\ImageBox;
use Pliego\Box\LineBreakRun;
use Pliego\Box\TextRun;
use Pliego\Layout\Fragment\BorderSet;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Geometry\Rect;
use Pliego\Text\FontCatalog;

/**
 * CSS 2.2 §9.4.1 (block formatting) + §10.3.3 (anchos) simplificado para M0:
 * sin margin collapsing, sin floats.
 *
 * M1-T6: el line breaking multi-run/multi-cara se delega ENTERAMENTE a InlineFlowContext
 * (M0 tenía aquí un wrapText() de una sola cara/estilo, ya eliminado). Este contexto solo se
 * encarga de agrupar tramos consecutivos de TextRun|LineBreakRun (pueden aparecer intercalados
 * con hijos BlockBox, ver BoxTreeBuilder::buildBlock) y pasarle cada grupo íntegro al inline
 * context de una vez.
 */
final readonly class BlockFlowContext implements FormattingContext
{
    private InlineFlowContext $inline;

    public function __construct(
        private TextMeasurer $measurer,
        private FontCatalog $catalog,
    ) {
        $this->inline = new InlineFlowContext($measurer, $catalog);
    }

    public function layout(BlockBox $box, Rect $containingBlock): BoxFragment
    {
        $style = $box->style;
        // CSS 2.2 §10.2/§10.3.3/§8.3: todo porcentaje de width/margin-*/padding-* se resuelve
        // contra el ANCHO del containing block — incluso los verticales (margin-top/bottom,
        // padding-top/bottom), que NO se resuelven contra ninguna altura.
        $cbWidth = $containingBlock->width;

        $marginLeft = $style->marginLeft->resolve($cbWidth);
        $marginRight = $style->marginRight->resolve($cbWidth);
        $marginTop = $style->marginTop->resolve($cbWidth);

        $x = $containingBlock->x + $marginLeft;
        $y = $containingBlock->y + $marginTop;

        $paddingLeft = $style->paddingLeft->resolve($cbWidth);
        $paddingRight = $style->paddingRight->resolve($cbWidth);
        $paddingTop = $style->paddingTop->resolve($cbWidth);
        $paddingBottom = $style->paddingBottom->resolve($cbWidth);

        $borderLeft = $style->borderLeft->widthPx;
        $borderRight = $style->borderRight->widthPx;
        $borderTop = $style->borderTop->widthPx;
        $borderBottom = $style->borderBottom->widthPx;

        // Falso positivo verificado (ver task-8-report.md): PHPStan resuelve `?LengthPercentage`
        // como no-nulo solo cuando el nullsafe y el `??` conviven en la misma expresión; separar
        // en dos sentencias hace desaparecer el aviso sin cambiar tipo ni comportamiento.
        $declaredWidth = $style->width;
        $declaredWidthPx = $declaredWidth?->resolve($cbWidth);
        if ($declaredWidthPx === null) {
            // width: auto — el border-box ocupa lo que quede del containing block tras los
            // márgenes; box-sizing solo importa cuando hay un ancho declarado explícitamente.
            $borderBoxWidth = $cbWidth - $marginLeft - $marginRight;
            $contentWidth = max(0.0, $borderBoxWidth - $paddingLeft - $paddingRight - $borderLeft - $borderRight);
        } elseif ($style->boxSizing === 'border-box') {
            $borderBoxWidth = $declaredWidthPx;
            $contentWidth = max(0.0, $borderBoxWidth - $paddingLeft - $paddingRight - $borderLeft - $borderRight);
        } else {
            $contentWidth = $declaredWidthPx;
            $borderBoxWidth = $contentWidth + $paddingLeft + $paddingRight + $borderLeft + $borderRight;
        }

        $contentX = $x + $borderLeft + $paddingLeft;
        $cursorY = $y + $borderTop + $paddingTop;
        $contentBottom = $cursorY;

        $children = [];
        /** @var list<TextRun|LineBreakRun> $pendingRuns secuencia inline contigua pendiente de layout */
        $pendingRuns = [];
        $flushInline = function () use (&$pendingRuns, &$children, &$cursorY, &$contentBottom, $contentX, $contentWidth, $style): void {
            if ($pendingRuns === []) {
                return;
            }
            foreach ($this->inline->layout($pendingRuns, $contentX, $cursorY, $contentWidth, $style) as $line) {
                $children[] = $line;
                $cursorY = $line->rect->bottom();
            }
            $contentBottom = $cursorY;
            $pendingRuns = [];
        };

        foreach ($box->children as $child) {
            if ($child instanceof TextRun || $child instanceof LineBreakRun) {
                $pendingRuns[] = $child;
                continue;
            }
            $flushInline();
            if ($child instanceof ImageBox) {
                continue; // M3-T3 lo consume
            }
            $childFragment = $this->layout($child, new Rect($contentX, $cursorY, $contentWidth, INF));
            $children[] = $childFragment;
            // CSS 2.2 §10.6.3: la altura de contenido llega hasta el border-box de la
            // última caja en flujo; el margin-bottom avanza el cursor para el siguiente
            // hermano pero no forma parte de la altura del padre.
            $contentBottom = $childFragment->rect->bottom();
            // margin-bottom del hijo se resuelve contra el ancho de SU containing block, que es
            // el content width de este padre (el mismo que se le pasó arriba como containingBlock->width).
            $cursorY = $contentBottom + $child->style->marginBottom->resolve($contentWidth);
        }
        $flushInline();

        $height = ($contentBottom - $y) + $paddingBottom + $borderBottom;
        return new BoxFragment(
            new Rect($x, $y, $borderBoxWidth, $height),
            $style->backgroundColor,
            $children,
            new BorderSet($style->borderTop, $style->borderRight, $style->borderBottom, $style->borderLeft),
        );
    }
}
