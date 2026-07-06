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
use Pliego\Style\AlignItems;
use Pliego\Style\Display;
use Pliego\Style\JustifyContent;
use Pliego\Text\FontCatalog;

/**
 * css-flexbox-1 §9, restringido a UNA sola línea en flex-direction:row (M4-T4; wrap y column
 * llegan en M4-T5 — ver brief). $flexDirection/$flexWrap del contenedor se LEEN pero esta clase
 * SIEMPRE aplica el algoritmo de fila única sea cual sea su valor: no hay todavía un canal de
 * warnings en tiempo de layout (el constructor de esta clase, fijado por el contrato del
 * milestone en T1/T3, no recibe un WarningCollector) para avisar del fallback, así que queda
 * documentado aquí en vez de silencioso — T5, que SÍ implementa wrap/column, es quien
 * probablemente tenga que abrir ese canal si decide seguir sin silencio.
 *
 * RUPTURA DE CICLO (BlockFlowContext <-> FlexFormattingContext): un contenedor flex puede tener
 * items que a su vez son bloques normales (necesitan BlockFlowContext) o incluso OTROS
 * contenedores flex anidados (necesitan volver aquí); un bloque normal puede tener un hijo
 * `display:flex` (necesita este contexto). Ninguna de las dos clases puede recibir a la otra por
 * constructor sin ciclo. Solución: este constructor —cuya firma es la del contrato del milestone,
 * `(TextMeasurer, FontCatalog, IntrinsicSizer)`, sin parámetro extra— crea SU PROPIA instancia
 * interna de BlockFlowContext (mismo measurer/catalog, ambas clases son puras respecto a esos dos
 * colaboradores, sin estado compartido) y la conecta consigo mismo vía
 * `BlockFlowContext::setFlexContext()` (inyección perezosa, ver el docblock de esa clase): así,
 * cuando este contexto delega un ITEM que es un bloque normal a su BlockFlowContext interno, y
 * ese bloque tiene DESCENDIENTES con `display:flex`, el propio BlockFlowContext interno sabe
 * volver a ESTA MISMA instancia sin wiring adicional. Un ITEM que es él mismo un contenedor flex
 * (no un descendiente, el item DIRECTO) nunca pasa por el BlockFlowContext interno: layoutItem()
 * lo detecta y se re-invoca a sí mismo directamente (ver más abajo) — evita el problema simétrico
 * de que BlockFlowContext::layout() nunca examina el display del BOX que se le pasa como raíz,
 * solo el de sus HIJOS (mismo gap documentado en ese docblock para el <body> raíz).
 *
 * ADJUDICACIÓN "border-box main size" (brief): el tamaño resuelto de un item en el eje principal
 * (§9.2 base / §9.7 resuelto) se trata UNIFORMEMENTE como su ancho BORDER-BOX, sea cual sea la
 * fuente (flex-basis, width CSS, o max-content) — ver hypotheticalMainSize(). Al layoutear el
 * item, esta clase pasa un Rect cuyo width = tamaño resuelto + márgenes del item, y dado que
 * BlockFlowContext ya trata width:auto como "llena el containing width menos márgenes", un item
 * SIN width propio (el caso normal: flex-basis:auto tomó el max-content) reproduce el tamaño
 * resuelto exactamente. DIVERGENCIA DOCUMENTADA: un item que SÍ declara su propio width CSS (o,
 * para ImageBox, su propio width/attr/intrínseco) hace que BlockFlowContext/layoutImage ignoren
 * el Rect recibido y usen ESE width en su lugar — si flex-grow/shrink ajustó el tamaño resuelto
 * por encima/debajo de ese width declarado, el resultado RENDERIZADO sigue el width propio del
 * item, no el ajuste de flex (BlockFlowContext no tiene mecanismo de "override" de width, ver
 * brief). Ninguno de los tests requeridos ejercita este conflicto (el item con width propio de
 * "LA TARJETA" tiene flex-grow:0, así que nunca diverge).
 */
final readonly class FlexFormattingContext implements FormattingContext
{
    private BlockFlowContext $blockFlow;

    public function __construct(
        private TextMeasurer $measurer,
        private FontCatalog $catalog,
        private IntrinsicSizer $sizer,
    ) {
        $this->blockFlow = new BlockFlowContext($measurer, $catalog);
        $this->blockFlow->setFlexContext($this);
    }

    public function layout(BlockBox $container, Rect $containingBlock): BoxFragment
    {
        $style = $container->style;
        // Resolución de la propia caja del contenedor: EL MISMO cálculo que BlockFlowContext hace
        // para cualquier bloque (márgenes/padding/borde/width con box-sizing) — duplicado a
        // propósito (brief: "duplication of ~15 lines with a comment is acceptable"), porque
        // extraerlo a un trait compartido no aporta claridad aquí y el contenedor flex además
        // necesita resolver su ALTURA (ver más abajo), algo que un bloque normal nunca hace.
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

        $declaredWidth = $style->width;
        $declaredWidthPx = $declaredWidth?->resolve($cbWidth);
        if ($declaredWidthPx === null) {
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
        $contentTop = $y + $borderTop + $paddingTop;

        // Altura del contenedor (nunca aplica a un bloque normal, ver brief): auto -> el cross
        // size de la línea decide; declarada en px -> esa, con overflow de contenido permitido
        // (no hay clipping en este motor) si algún item resulta más alto. box-sizing reinterpreta
        // la declarada exactamente igual que el ancho (mismo criterio que BlockFlowContext y
        // resolveReplacedSize para el resto de la base de código).
        $declaredHeight = $style->height;
        $declaredHeightPx = $declaredHeight?->px;
        $declaredContentHeight = null;
        if ($declaredHeightPx !== null) {
            $declaredContentHeight = $style->boxSizing === 'border-box'
                ? max(0.0, $declaredHeightPx - $paddingTop - $paddingBottom - $borderTop - $borderBottom)
                : $declaredHeightPx;
        }

        $borders = new BorderSet($style->borderTop, $style->borderRight, $style->borderBottom, $style->borderLeft);
        $items = self::flexItems($container->children);

        if ($items === []) {
            $lineCross = $declaredContentHeight ?? 0.0;
            $height = $lineCross + $paddingTop + $paddingBottom + $borderTop + $borderBottom;
            return new BoxFragment(new Rect($x, $y, $borderBoxWidth, $height), $style->backgroundColor, [], $borders);
        }

        // §9.2 (base size) + §9.7 (longitudes flexibles) — ver resolveMainSizes(). Puramente
        // aritmético, independiente de cualquier layout real todavía.
        [$resolvedMain, $marginsX] = $this->resolveMainSizes($items, $contentWidth, $style->columnGapPx);
        // §9.5 (posición en el eje principal) — también aritmético, independiente del eje cruzado.
        $finalX = self::mainAxisPositions($resolvedMain, $marginsX, $contentWidth, $contentX, $style->columnGapPx, $style->justifyContent);

        // §9.4 paso 1: cada item se layoutea con su main size resuelto para MEDIR su cross size
        // natural (el alto que produce su propio contenido) — necesario antes de conocer el cross
        // size de la línea (depende del máximo de todos los items, o de la altura declarada del
        // contenedor).
        $natural = [];
        foreach ($items as $i => $item) {
            $natural[$i] = $this->layoutItem($item, new Rect($finalX[$i], $contentTop, $resolvedMain[$i] + $marginsX[$i], INF));
        }

        $crossSizes = [];
        foreach ($natural as $i => $fragment) {
            $crossSizes[$i] = $fragment->rect->height;
        }
        // §9.4 paso 7 (line cross size): el máximo de los items, salvo que el contenedor declare
        // su propia altura (ver arriba) — entonces esa altura manda, con overflow permitido.
        $lineCross = $declaredContentHeight ?? max($crossSizes);

        // §9.4/§9.8 (align-items): stretch estira los items SIN cross size definido (ver
        // hasDefiniteCrossSize()) al cross size de la línea; el resto (incl. stretch sobre un
        // item con cross size definido, que cae a flex-start per spec) se posiciona según su
        // propio cross size. Solo Center/FlexEnd necesitan desplazar el item hacia abajo — como
        // BlockFlowContext/layoutImage posicionan siempre desde arriba (contentTop), ese
        // desplazamiento exige un SEGUNDO layout del item a la Y correcta (no un simple parche de
        // coordenadas: los hijos del fragment llevan coordenadas ABSOLUTAS, no relativas al
        // padre, así que mover solo el rect exterior dejaría el contenido interior desalineado).
        $finalFragments = [];
        foreach ($items as $i => $item) {
            $itemCross = $crossSizes[$i];
            if ($style->alignItems === AlignItems::Stretch && !self::hasDefiniteCrossSize($item)) {
                // Aproximación documentada por el brief: SIN re-layout interno del contenido —
                // solo se agranda el rect del BoxFragment (fondo/bordes pintan hasta el nuevo
                // alto); los hijos quedan en las coordenadas absolutas que ya tenían, ancladas
                // arriba ("contenido arriba").
                $finalFragments[] = self::withHeight($natural[$i], $lineCross);
                continue;
            }
            $offset = match ($style->alignItems) {
                AlignItems::Center => ($lineCross - $itemCross) / 2.0,
                AlignItems::FlexEnd => $lineCross - $itemCross,
                default => 0.0, // FlexStart, o Stretch con cross size definido (cae a flex-start)
            };
            $finalFragments[] = $offset !== 0.0
                ? $this->layoutItem($item, new Rect($finalX[$i], $contentTop + $offset, $resolvedMain[$i] + $marginsX[$i], INF))
                : $natural[$i];
        }

        $contentBottom = $contentTop + $lineCross;
        $height = ($contentBottom - $y) + $paddingBottom + $borderBottom;

        return new BoxFragment(new Rect($x, $y, $borderBoxWidth, $height), $style->backgroundColor, $finalFragments, $borders);
    }

    /**
     * css-flexbox-1 §4: los hijos directos de un contenedor flex ya llegan aplanados a
     * BlockBox|ImageBox — BoxTreeBuilder::wrapAnonymousFlexItems() (M4-T2) envuelve cualquier
     * tramo de TextRun|LineBreakRun en un BlockBox anónimo ANTES de que el árbol llegue aquí. Se
     * filtra de todos modos (en vez de asumirlo ciegamente) por si algún caller construye el árbol
     * a mano sin pasar por el builder (p.ej. un test) — mismo espíritu "soft, documented" que el
     * resto del motor, no una excepción.
     *
     * @param list<BlockBox|TextRun|LineBreakRun|ImageBox> $children
     * @return list<BlockBox|ImageBox>
     */
    private static function flexItems(array $children): array
    {
        $items = [];
        foreach ($children as $child) {
            if ($child instanceof BlockBox || $child instanceof ImageBox) {
                $items[] = $child;
            }
        }
        return $items;
    }

    private function layoutItem(BlockBox|ImageBox $item, Rect $itemRect): BoxFragment
    {
        if ($item instanceof ImageBox) {
            return $this->blockFlow->layoutImage($item, $itemRect);
        }
        if ($item->style->display === Display::Flex) {
            // Item que es él mismo otro contenedor flex (anidado) — ver docblock de clase.
            return $this->layout($item, $itemRect);
        }
        return $this->blockFlow->layout($item, $itemRect);
    }

    /**
     * §9.4: un item tiene "cross size definido" cuando algo distinto de su propio contenido fija
     * su alto. Ningún BlockBox lo tiene en este motor (BlockFlowContext no soporta height en
     * bloques en absoluto, con o sin flex — hueco preexistente, no de esta tarea), así que siempre
     * es candidato a stretch. Un ImageBox SÍ puede tenerlo: CSS height o el atributo HTML height
     * ya fijan su alto final vía resolveReplacedSize (M3-T3) — igual que en un navegador real,
     * `<img height="...">` no se estira aunque el contenedor sea align-items:stretch.
     */
    private static function hasDefiniteCrossSize(BlockBox|ImageBox $item): bool
    {
        return $item instanceof ImageBox && ($item->style->height !== null || $item->attrHeight !== null);
    }

    /** Aproximación de stretch (ver §9.4/§9.8 en layout()): agranda el rect sin re-layout interno. */
    private static function withHeight(BoxFragment $fragment, float $height): BoxFragment
    {
        return new BoxFragment(
            new Rect($fragment->rect->x, $fragment->rect->y, $fragment->rect->width, $height),
            $fragment->background,
            $fragment->children,
            $fragment->borders,
        );
    }

    /**
     * css-flexbox-1 §9.2 (base size) simplificado: flex-basis definido (px o %, resuelto contra
     * $contentWidth, el main size del contenedor) manda; si es auto, se usa el width CSS propio
     * del item (mismo % contra $contentWidth; se le suma el padding/borde propio cuando
     * content-box, igual que IntrinsicSizer::sizeBlock()) y, si tampoco hay width, el max-content
     * de IntrinsicSizer — que YA incluye el padding/borde propio del item, ver su docblock. Las
     * TRES fuentes se tratan uniformemente como un ancho BORDER-BOX (adjudicación del brief, ver
     * docblock de clase). El clamp a min-content NO se aplica aquí — solo en la fase de shrink
     * (§9.7, resolveMainSizes()): "el base queda como se computó", el suelo de min-content es
     * un límite de la REDISTRIBUCIÓN, no del tamaño hipotético en sí.
     */
    private function hypotheticalMainSize(BlockBox|ImageBox $item, float $contentWidth): float
    {
        $style = $item->style;
        if ($style->flexBasis !== null) {
            return $style->flexBasis->resolve($contentWidth);
        }
        if ($item instanceof BlockBox && $style->width !== null) {
            $widthPx = $style->width->resolve($contentWidth);
            if ($style->boxSizing === 'border-box') {
                return $widthPx;
            }
            return $widthPx + $style->paddingLeft->resolve($contentWidth) + $style->paddingRight->resolve($contentWidth)
                + $style->borderLeft->widthPx + $style->borderRight->widthPx;
        }
        // ImageBox con flex-basis:auto SIEMPRE cae aquí (nunca por su propio $style->width): el
        // criterio CSS>attr>intrínseco de IntrinsicSizer::maxContentWidth() ya decide por ella.
        return $this->sizer->maxContentWidth($item);
    }

    /**
     * css-flexbox-1 §9.7 (resolver longitudes flexibles), una sola línea: libre = contentWidth −
     * Σ(base + márgenes horizontales del item) − columnGap×(n−1). libre>0 con Σgrow>0 reparte el
     * sobrante proporcional al grow de cada item (items con grow:0, el default, no se mueven).
     * libre<0 con Σ(shrink×base)>0 encoge proporcional a ese factor escalado; cualquier item cuyo
     * candidato caiga por debajo de su min-content (IntrinsicSizer::minContentWidth) se CONGELA en
     * su min-content, y se hace UNA sola re-pasada extra repartiendo el déficit restante entre los
     * items NO congelados (documentado: el bucle completo del spec, que congelaría iterativamente
     * hasta estabilizar, se simplifica aquí a 2 iteraciones como máximo — brief M4-T4/T5). Sin
     * items con grow>0 (o shrink>0) el/los item(s) simplemente se quedan en su base — overflow
     * permitido, sin min/max-width que limite más allá del min-content.
     *
     * @param list<BlockBox|ImageBox> $items
     * @return array{0: array<int, float>, 1: array<int, float>} resolvedMain (border-box, eje
     *     principal) y marginsX (margin-left+right, resuelto contra $contentWidth), mismo índice
     *     que $items (array<int,...> en vez de list<...>: PHPStan no puede probar por sí solo que
     *     las asignaciones `$arr[$i] = ...` dentro de un foreach sobre un list producen un list
     *     sin huecos, aunque en efecto lo sea).
     */
    private function resolveMainSizes(array $items, float $contentWidth, float $columnGapPx): array
    {
        $n = count($items);
        $base = [];
        $marginsX = [];
        $grow = [];
        $shrink = [];
        foreach ($items as $i => $item) {
            $base[$i] = $this->hypotheticalMainSize($item, $contentWidth);
            $marginsX[$i] = $item->style->marginLeft->resolve($contentWidth) + $item->style->marginRight->resolve($contentWidth);
            $grow[$i] = $item->style->flexGrow;
            $shrink[$i] = $item->style->flexShrink;
        }

        $gapsTotal = $n > 1 ? $columnGapPx * ($n - 1) : 0.0;
        $sumOuterBase = array_sum($base) + array_sum($marginsX);
        $free = $contentWidth - $sumOuterBase - $gapsTotal;

        $resolvedMain = $base;

        if ($free > 0.0) {
            $sumGrow = array_sum($grow);
            if ($sumGrow > 0.0) {
                foreach ($items as $i => $item) {
                    $resolvedMain[$i] = $base[$i] + $free * ($grow[$i] / $sumGrow);
                }
            }
            return [$resolvedMain, $marginsX];
        }

        if ($free < 0.0) {
            $scaledFactor = [];
            foreach ($items as $i => $item) {
                $scaledFactor[$i] = $shrink[$i] * $base[$i];
            }
            $sumScaled = array_sum($scaledFactor);
            if ($sumScaled <= 0.0) {
                // Nadie encoge (todos shrink:0 o base:0): overflow permitido, documentado.
                return [$resolvedMain, $marginsX];
            }

            $deficit = abs($free);
            $frozen = [];
            foreach ($items as $i => $item) {
                $candidate = $base[$i] - $deficit * ($scaledFactor[$i] / $sumScaled);
                $minContent = $this->sizer->minContentWidth($item);
                if ($candidate < $minContent) {
                    $resolvedMain[$i] = $minContent;
                    $frozen[$i] = true;
                } else {
                    $resolvedMain[$i] = $candidate;
                    $frozen[$i] = false;
                }
            }

            if (in_array(true, $frozen, true)) {
                $consumedByFrozen = 0.0;
                $sumUnfrozenScaled = 0.0;
                foreach ($items as $i => $item) {
                    if ($frozen[$i]) {
                        $consumedByFrozen += $base[$i] - $resolvedMain[$i];
                    } else {
                        $sumUnfrozenScaled += $scaledFactor[$i];
                    }
                }
                $remainingDeficit = $deficit - $consumedByFrozen;
                if ($remainingDeficit > 0.0 && $sumUnfrozenScaled > 0.0) {
                    foreach ($items as $i => $item) {
                        if (!$frozen[$i]) {
                            $resolvedMain[$i] = $base[$i] - $remainingDeficit * ($scaledFactor[$i] / $sumUnfrozenScaled);
                        }
                    }
                }
            }
        }

        return [$resolvedMain, $marginsX];
    }

    /**
     * css-flexbox-1 §9.5 (posición en el eje principal): $leftover es el espacio que NI grow ni
     * shrink absorbieron (si grow>0 absorbió todo el libre>0, o el shrink —incluido el clamp de
     * resolveMainSizes()— consumió todo el libre<0, $leftover sale ~0 y justify-content no mueve
     * nada; la fórmula genérica ya lo resuelve sin casos especiales). column-gap se aplica SIEMPRE
     * entre items consecutivos, independiente de justify-content; space-between reparte además
     * $leftover en los n−1 huecos; con 1 solo item no hay huecos que repartir, así que
     * space-between cae a "inicio" (igual que flex-start), tal y como pide el brief.
     *
     * @param array<int, float> $resolvedMain
     * @param array<int, float> $marginsX
     * @return array<int, float> x del borde de MARGEN (no border-box) de cada item, mismo índice
     */
    private static function mainAxisPositions(
        array $resolvedMain,
        array $marginsX,
        float $contentWidth,
        float $contentX,
        float $columnGapPx,
        JustifyContent $justifyContent,
    ): array {
        $n = count($resolvedMain);
        $gapsTotal = $n > 1 ? $columnGapPx * ($n - 1) : 0.0;
        $sumOuter = 0.0;
        foreach ($resolvedMain as $i => $main) {
            $sumOuter += $main + $marginsX[$i];
        }
        $leftover = $contentWidth - $sumOuter - $gapsTotal;

        $startOffset = match ($justifyContent) {
            JustifyContent::Center => $leftover / 2.0,
            JustifyContent::FlexEnd => $leftover,
            default => 0.0, // FlexStart, SpaceBetween (su extra va en los huecos, no al inicio)
        };
        $extraGap = ($justifyContent === JustifyContent::SpaceBetween && $n > 1) ? $leftover / ($n - 1) : 0.0;

        $positions = [];
        $cursor = $contentX + $startOffset;
        foreach ($resolvedMain as $i => $main) {
            $positions[$i] = $cursor;
            $cursor += $marginsX[$i] + $main + $columnGapPx + $extraGap;
        }
        return $positions;
    }
}
