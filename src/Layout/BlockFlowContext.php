<?php

declare(strict_types=1);

namespace Pliego\Layout;

use Pliego\Box\BlockBox;
use Pliego\Box\ImageBox;
use Pliego\Box\LineBreakRun;
use Pliego\Box\TextRun;
use Pliego\Layout\Fragment\BorderSet;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\ImageFragment;
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
                $childFragment = $this->layoutImage($child, new Rect($contentX, $cursorY, $contentWidth, INF));
                $children[] = $childFragment;
                $contentBottom = $childFragment->rect->bottom();
                $cursorY = $contentBottom + $child->style->marginBottom->resolve($contentWidth);
                continue;
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

    /**
     * M3-T3: <img> es un replaced block-level box — mismo box model que un BlockBox normal
     * (margin/border/padding se resuelven exactamente igual, incluido box-sizing's ausencia:
     * un replaced element no lo necesita porque su tamaño "usado" YA ES el del content box, ver
     * resolveReplacedSize()), pero SIN flujo interno: el content box tiene el tamaño que decide
     * el algoritmo de sizing CSS 2.2 §10.3.4/§10.6.2 en vez de "lo que quede" del containing
     * block. Se emite como un BoxFragment (border-box, background/borders pintables igual que
     * cualquier otra caja) cuyo único hijo es el ImageFragment (la content box real, lo que
     * pinta la imagen).
     */
    private function layoutImage(ImageBox $box, Rect $containingBlock): BoxFragment
    {
        $style = $box->style;
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

        [$contentWidth, $contentHeight] = $this->resolveReplacedSize($box, $cbWidth);

        $contentX = $x + $borderLeft + $paddingLeft;
        $contentY = $y + $borderTop + $paddingTop;

        $borderBoxWidth = $contentWidth + $paddingLeft + $paddingRight + $borderLeft + $borderRight;
        $borderBoxHeight = $contentHeight + $paddingTop + $paddingBottom + $borderTop + $borderBottom;

        $imageFragment = new ImageFragment(new Rect($contentX, $contentY, $contentWidth, $contentHeight), $box->src);

        return new BoxFragment(
            new Rect($x, $y, $borderBoxWidth, $borderBoxHeight),
            $style->backgroundColor,
            [$imageFragment],
            new BorderSet($style->borderTop, $style->borderRight, $style->borderBottom, $style->borderLeft),
        );
    }

    /**
     * CSS 2.2 §10.3.4 (ancho de replaced elements) + §10.6.2 (alto), simplificado para M3:
     * cada eje se resuelve INDEPENDIENTEMENTE por prioridad CSS width/height (resuelto contra
     * $cbWidth para width; height nunca admite % en M3, ver ComputedStyle::$height) > atributo
     * HTML width/height > intrínseco. Solo cuando UN eje queda sin resolver por ninguna de las
     * 3 fuentes se deriva del otro eje ya resuelto vía el aspect ratio intrínseco; si NINGÚN eje
     * se resuelve, se usan ambas dimensiones intrínsecas, recortadas (preservando el ratio) al
     * ancho del containing block si lo exceden — regla práctica de los navegadores, no está en
     * el texto de la spec CSS 2.2 pero es el comportamiento observable universal.
     *
     * box-sizing (CSS 2.2 §8.3 + css-sizing-3): reinterpreta SOLO el width/height DECLARADO EN
     * CSS — los atributos HTML width/height y las dimensiones intrínsecas son SIEMPRE medidas de
     * content-box (nunca pasan por box-sizing, igual que en HTML puro sin CSS). Por eso la resta
     * de padding+border se aplica aquí mismo, ANTES de mezclar con attr/intrínseco, y solo al
     * valor declarado en CSS. El ratio de aspecto, cuando hace falta derivar el eje que falta, se
     * aplica siempre sobre el content box ya resuelto (css-images-3 §4: el "used value" que
     * produce el ratio es una dimensión de content box), así que da igual si $width/$height ya
     * traen border-box restado o no: en el momento en que se usan para derivar el otro eje, ya
     * son valores de content box.
     *
     * @return array{0: float, 1: float} content width/height en px
     */
    private function resolveReplacedSize(ImageBox $box, float $cbWidth): array
    {
        $style = $box->style;
        $intrinsicWidth = (float) $box->intrinsicWidth;
        $intrinsicHeight = (float) $box->intrinsicHeight;
        $ratio = $intrinsicWidth > 0.0 ? $intrinsicHeight / $intrinsicWidth : 0.0;

        // Nullsafe + ?? en la misma expresión dispara el mismo falso positivo de PHPStan que en
        // BlockFlowContext::layout() (ver comentario de $declaredWidthPx más arriba); se separa en
        // dos sentencias por eje, igual que allí.
        $declaredWidth = $style->width;
        $declaredWidthPx = $declaredWidth?->resolve($cbWidth);
        $declaredHeight = $style->height;
        $declaredHeightPx = $declaredHeight?->px;

        if ($style->boxSizing === 'border-box') {
            $paddingBorderX = $style->paddingLeft->resolve($cbWidth) + $style->paddingRight->resolve($cbWidth)
                + $style->borderLeft->widthPx + $style->borderRight->widthPx;
            $paddingBorderY = $style->paddingTop->resolve($cbWidth) + $style->paddingBottom->resolve($cbWidth)
                + $style->borderTop->widthPx + $style->borderBottom->widthPx;
            if ($declaredWidthPx !== null) {
                $declaredWidthPx = max(0.0, $declaredWidthPx - $paddingBorderX);
            }
            if ($declaredHeightPx !== null) {
                $declaredHeightPx = max(0.0, $declaredHeightPx - $paddingBorderY);
            }
        }

        $width = $declaredWidthPx ?? $box->attrWidth;
        $height = $declaredHeightPx ?? $box->attrHeight;

        if ($width === null && $height === null) {
            $width = $intrinsicWidth;
            $height = $intrinsicHeight;
            if ($width > $cbWidth && $width > 0.0) {
                $scale = $cbWidth / $width;
                $width = $cbWidth;
                $height *= $scale;
            }
        } elseif ($width === null) {
            $width = $ratio > 0.0 ? $height / $ratio : $intrinsicWidth;
        } elseif ($height === null) {
            $height = $width * $ratio;
        }

        return [$width, $height];
    }
}
