<?php

declare(strict_types=1);

namespace Pliego\Layout;

use Pliego\Box\BlockBox;
use Pliego\Box\LineBreakRun;
use Pliego\Box\TextRun;
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
        $x = $containingBlock->x + $style->marginLeft->value;
        $y = $containingBlock->y + $style->marginTop->value;
        // Falso positivo verificado (ver task-8-report.md): PHPStan resuelve `?LengthPercentage`
        // como no-nulo solo cuando el nullsafe y el `??` conviven en la misma expresión; separar
        // en dos sentencias hace desaparecer el aviso sin cambiar tipo ni comportamiento.
        // TODO(M2-T4): los ->value de aquí abajo leen LengthPercentage CRUDO — un % llega como
        // su número (50% => 50, no px). T4 debe sustituir estas lecturas por ->resolve($containingBlock->width).
        // @phpstan-ignore nullsafe.neverNull
        $borderBoxWidth = $style->width?->value
            ?? $containingBlock->width - $style->marginLeft->value - $style->marginRight->value;
        $contentX = $x + $style->paddingLeft->value;
        $contentWidth = $borderBoxWidth - $style->paddingLeft->value - $style->paddingRight->value;
        $cursorY = $y + $style->paddingTop->value;
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
            $childFragment = $this->layout($child, new Rect($contentX, $cursorY, $contentWidth, INF));
            $children[] = $childFragment;
            // CSS 2.2 §10.6.3: la altura de contenido llega hasta el border-box de la
            // última caja en flujo; el margin-bottom avanza el cursor para el siguiente
            // hermano pero no forma parte de la altura del padre.
            $contentBottom = $childFragment->rect->bottom();
            $cursorY = $contentBottom + $child->style->marginBottom->value;
        }
        $flushInline();

        $height = ($contentBottom - $y) + $style->paddingBottom->value;
        return new BoxFragment(
            new Rect($x, $y, $borderBoxWidth, $height),
            $style->backgroundColor,
            $children,
        );
    }
}
