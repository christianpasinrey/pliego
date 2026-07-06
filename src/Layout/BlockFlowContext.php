<?php

declare(strict_types=1);

namespace Pliego\Layout;

use Pliego\Box\BlockBox;
use Pliego\Box\ImageBox;
use Pliego\Box\LineBreakRun;
use Pliego\Box\TableBox;
use Pliego\Box\TextRun;
use Pliego\Css\WarningCollector;
use Pliego\Layout\Fragment\BorderSet;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\ImageFragment;
use Pliego\Layout\Geometry\Rect;
use Pliego\Style\Display;
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
 *
 * M4-T4: BlockFlowContext y FlexFormattingContext se necesitan mutuamente (un hijo bloque puede
 * ser un contenedor flex; un item flex se layoutea con la misma maquinaria de bloque) — ciclo de
 * constructores. Se rompe con INYECCIÓN PEREZOSA: esta clase deja de ser `readonly` a nivel de
 * clase (las propiedades promovidas del constructor se marcan `readonly` individualmente, igual
 * que antes) para poder alojar $flexContext, un colaborador MUTABLE que arranca en null y se
 * autoconstruye la PRIMERA vez que hace falta (ver flexContext()) — así ningún caller (Engine,
 * tests, el propio FlexFormattingContext) necesita wiring explícito: basta con
 * `new BlockFlowContext($measurer, $catalog)` para que un hijo `display:flex` funcione. El setter
 * público sigue existiendo por si un caller quiere inyectar una instancia propia (p.ej. un test
 * con un doble, o FlexFormattingContext conectando SU BlockFlowContext interno consigo mismo,
 * ver el docblock de esa clase) en vez de la autocreada.
 *
 * M5-T1 (housekeeping): $warnings (último parámetro, opcional, null = silencioso) es el mismo
 * WarningCollector que Engine::render() comparte con BoxTreeBuilder/Paginator — esta clase no
 * emite ningún warning propio todavía, pero DEBE reenviarlo al FlexFormattingContext que crea
 * perezosamente en flexContext() (ver más abajo), para que un `display:flex` anidado a
 * cualquier profundidad siga viendo el MISMO colector que el resto del pipeline, en vez de uno
 * silencioso por accidente de wiring.
 *
 * M5-T4: mismo mecanismo de inyección perezosa que $flexContext, ahora también para
 * TableFormattingContext (ver el docblock de esa clase, sección "RUPTURA DE CICLO") — un hijo
 * TableBox se delega a $this->tableContext(), autocreada la primera vez que hace falta si nadie
 * la wireó explícitamente (el caso normal: Engine construye `new BlockFlowContext(...)` a secas y
 * el primer <table> que encuentra dispara la autocreación).
 */
final class BlockFlowContext implements FormattingContext
{
    private readonly InlineFlowContext $inline;
    private ?FlexFormattingContext $flexContext = null;
    private ?TableFormattingContext $tableContext = null;

    public function __construct(
        private readonly TextMeasurer $measurer,
        private readonly FontCatalog $catalog,
        private readonly ?WarningCollector $warnings = null,
    ) {
        $this->inline = new InlineFlowContext($measurer, $catalog);
    }

    /** Ver docblock de clase: wiring explícito opcional del delegado flex (por defecto, perezoso). */
    public function setFlexContext(FlexFormattingContext $flexContext): void
    {
        $this->flexContext = $flexContext;
    }

    /**
     * M5-T4: análogo a setFlexContext() — TableFormattingContext lo llama en SU propio
     * constructor sobre el BlockFlowContext interno que crea para layoutear contenido de celda
     * (ver el docblock "RUPTURA DE CICLO" de esa clase), así que esta instancia NUNCA pasa por la
     * autocreación de tableContext() de más abajo.
     */
    public function setTableContext(TableFormattingContext $tableContext): void
    {
        $this->tableContext = $tableContext;
    }

    private function flexContext(): FlexFormattingContext
    {
        if ($this->flexContext === null) {
            $this->flexContext = new FlexFormattingContext(
                $this->measurer,
                $this->catalog,
                new IntrinsicSizer($this->measurer, $this->catalog),
                $this->warnings,
            );
        }
        return $this->flexContext;
    }

    private function tableContext(): TableFormattingContext
    {
        if ($this->tableContext === null) {
            $this->tableContext = new TableFormattingContext(
                $this->measurer,
                $this->catalog,
                new IntrinsicSizer($this->measurer, $this->catalog),
                $this->warnings,
            );
        }
        return $this->tableContext;
    }

    /**
     * M4-T5 (carry-over fix from T4's review): $usedWidthOverride, cuando no es null, es el
     * ancho BORDER-BOX que un FormattingContext exterior (FlexFormattingContext) ya resolvió
     * para esta caja (§9.7) y que DEBE ganar sobre cualquier width propio declarado en CSS —
     * antes de este parámetro, un item flex con su propio `width` ignoraba por completo el
     * tamaño resuelto por flex-grow/shrink (BlockFlowContext solo miraba $style->width, nunca
     * el containingBlock), dejando huecos o solapes visibles frente a lo que hace un navegador
     * real. Cuando se pasa, el override sustituye ENTERAMENTE la rama de "width propio" de
     * abajo (auto o declarado, box-sizing incluido: el valor ya llega en convención border-box
     * uniforme, ver el docblock de adjudicación en FlexFormattingContext) — el resto del método
     * (posición, hijos, altura de contenido) no cambia en absoluto.
     */
    public function layout(BlockBox $box, Rect $containingBlock, ?float $usedWidthOverride = null): BoxFragment
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

        if ($usedWidthOverride !== null) {
            $borderBoxWidth = $usedWidthOverride;
            $contentWidth = max(0.0, $borderBoxWidth - $paddingLeft - $paddingRight - $borderLeft - $borderRight);
        } else {
            // Falso positivo verificado (ver task-8-report.md): PHPStan resuelve
            // `?LengthPercentage` como no-nulo solo cuando el nullsafe y el `??` conviven en la
            // misma expresión; separar en dos sentencias hace desaparecer el aviso sin cambiar
            // tipo ni comportamiento.
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
            // M5-T4: una TableBox hija se delega ENTERA a TableFormattingContext (ver
            // tableContext()/su docblock de clase) — reemplaza el skip de T3: el cursor SÍ avanza
            // ahora (mismo patrón que ImageBox/display:flex justo arriba: fragmento + avance de
            // cursor con el margin-bottom propio de la tabla, resuelto contra este mismo
            // $contentWidth).
            if ($child instanceof TableBox) {
                $childFragment = $this->tableContext()->layout($child, new Rect($contentX, $cursorY, $contentWidth, INF));
                $children[] = $childFragment;
                $contentBottom = $childFragment->rect->bottom();
                $cursorY = $contentBottom + $child->style->marginBottom->resolve($contentWidth);
                continue;
            }
            // M4-T4: un hijo bloque con display:flex se layoutea ENTERO con FlexFormattingContext
            // (resuelve su propia caja — márgenes/width/box-sizing — con el mismo cálculo que esta
            // clase, ver el docblock de esa clase) en vez de recursar aquí; el resto del bucle
            // (avance del cursor con el margin-bottom del hijo) es idéntico a un bloque normal.
            // $child ya está acotado a BlockBox aquí (los otros dos casos posibles, TextRun|
            // LineBreakRun e ImageBox, ya hicieron `continue` arriba), así que solo hace falta
            // mirar su display.
            if ($child->style->display === Display::Flex) {
                $childFragment = $this->flexContext()->layout($child, new Rect($contentX, $cursorY, $contentWidth, INF));
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
            opacity: $style->opacity,
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
     *
     * M4-T4: PÚBLICO (era privado) — FlexFormattingContext reutiliza este mismo método para sus
     * items ImageBox, sin duplicar el sizing de replaced elements (resolveReplacedSize no cambia:
     * es agnóstico a flex/bloque, el sizing de un <img> es el mismo eje a eje en ambos contextos).
     *
     * M4-T5: $usedWidthOverride, análogo al de layout() (ver su docblock) — un ImageBox item con
     * su propio width/attr/intrínseco sufre el mismo problema de carry-over que un BlockBox, ver
     * resolveReplacedSize().
     */
    public function layoutImage(ImageBox $box, Rect $containingBlock, ?float $usedWidthOverride = null): BoxFragment
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

        [$contentWidth, $contentHeight] = $this->resolveReplacedSize($box, $cbWidth, $usedWidthOverride);

        $contentX = $x + $borderLeft + $paddingLeft;
        $contentY = $y + $borderTop + $paddingTop;

        $borderBoxWidth = $contentWidth + $paddingLeft + $paddingRight + $borderLeft + $borderRight;
        $borderBoxHeight = $contentHeight + $paddingTop + $paddingBottom + $borderTop + $borderBottom;

        // M6-T5: opacity se pasa a AMBOS, la caja (su propio fondo/borde) y el ImageFragment (los
        // píxeles de la imagen, vía ExtGState en PdfCanvas::drawImage) — mismo ComputedStyle, un
        // único <img>, así que ambos comparten el mismo valor.
        $imageFragment = new ImageFragment(new Rect($contentX, $contentY, $contentWidth, $contentHeight), $box->src, $style->opacity);

        return new BoxFragment(
            new Rect($x, $y, $borderBoxWidth, $borderBoxHeight),
            $style->backgroundColor,
            [$imageFragment],
            new BorderSet($style->borderTop, $style->borderRight, $style->borderBottom, $style->borderLeft),
            opacity: $style->opacity,
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
     * M4-T5: $usedWidthOverride, cuando no es null, es el ancho BORDER-BOX ya resuelto por
     * FlexFormattingContext (§9.7) — gana sobre CSS width, atributo HTML width e intrínseco por
     * igual (misma prioridad absoluta que en BlockFlowContext::layout(), ver su docblock);
     * SIEMPRE se interpreta como border-box, sea cual sea el box-sizing propio de la imagen
     * (adjudicación "border-box main size" del brief, ya documentada en FlexFormattingContext).
     * El alto NO se toca por el override (el eje principal de un contenedor row es el ancho): se
     * resuelve igual que sin override (CSS > attr > derivado del ratio con el ancho YA
     * sobrescrito, si ningún alto propio existe).
     *
     * @return array{0: float, 1: float} content width/height en px
     */
    private function resolveReplacedSize(ImageBox $box, float $cbWidth, ?float $usedWidthOverride = null): array
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

        $paddingBorderX = $style->paddingLeft->resolve($cbWidth) + $style->paddingRight->resolve($cbWidth)
            + $style->borderLeft->widthPx + $style->borderRight->widthPx;
        $paddingBorderY = $style->paddingTop->resolve($cbWidth) + $style->paddingBottom->resolve($cbWidth)
            + $style->borderTop->widthPx + $style->borderBottom->widthPx;

        if ($style->boxSizing === 'border-box') {
            if ($declaredWidthPx !== null) {
                $declaredWidthPx = max(0.0, $declaredWidthPx - $paddingBorderX);
            }
            if ($declaredHeightPx !== null) {
                $declaredHeightPx = max(0.0, $declaredHeightPx - $paddingBorderY);
            }
        }

        if ($usedWidthOverride !== null) {
            $width = max(0.0, $usedWidthOverride - $paddingBorderX);
            $height = $declaredHeightPx ?? $box->attrHeight ?? ($ratio > 0.0 ? $width * $ratio : $intrinsicHeight);
            return [$width, $height];
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
