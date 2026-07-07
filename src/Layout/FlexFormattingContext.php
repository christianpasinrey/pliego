<?php

declare(strict_types=1);

namespace Pliego\Layout;

use Pliego\Box\BlockBox;
use Pliego\Box\ImageBox;
use Pliego\Box\InlineBoxEnd;
use Pliego\Box\InlineBoxStart;
use Pliego\Box\LineBreakRun;
use Pliego\Box\TableBox;
use Pliego\Box\TextRun;
use Pliego\Css\WarningCollector;
use Pliego\Layout\Fragment\BorderRadius;
use Pliego\Layout\Fragment\BorderSet;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\Fragment;
use Pliego\Layout\Fragment\GeometryShift;
use Pliego\Layout\Geometry\Rect;
use Pliego\Style\AlignItems;
use Pliego\Style\ComputedStyle;
use Pliego\Style\Display;
use Pliego\Style\FlexDirection;
use Pliego\Style\FlexWrap;
use Pliego\Style\JustifyContent;
use Pliego\Text\FontCatalog;

/**
 * css-flexbox-1 §9. M4-T5 añade wrap (§9.3, solo en row: ver splitIntoLines()), flex-direction:
 * column (ver layoutColumnContainer()) y paginación atómica (ver $atomic más abajo); M4-T4 dejaba
 * la clase restringida a una sola línea en row. order, align-self, align-content, inline-flex,
 * flex-basis:content, *-reverse y writing modes siguen fuera de alcance (M4, ver brief) — caen a
 * warning y fallback en DeclarationParser/ComputedStyle, nunca llegan aquí; cualquier
 * simplificación adicional de esta tarea queda documentada en el método correspondiente en vez
 * de silenciosa. M5-T1 (housekeeping) añade un canal de warnings EN TIEMPO DE LAYOUT (el
 * constructor recibe un `?WarningCollector` opcional, ver warn()) — primer uso real:
 * layoutColumnContainer(), "justify-content ignorado sin altura declarada".
 *
 * PAGINACIÓN ATÓMICA (brief T5): el BoxFragment que este contexto devuelve para el CONTENEDOR
 * (nunca para un item individual) se marca `atomic: true` — Paginator lo trata como una unidad
 * indivisible frente al push-down de página (ver Paginator::flatten()/relocate()): cruza un
 * límite de página y cabe entera en una sola → se empuja ENTERA (con todo su subárbol); es más
 * alta que una página → se queda donde cae, sin partirse (misma limitación ya documentada para
 * texto/imágenes demasiado altos — desde M5-T1, Paginator emite un warning explícito para este
 * caso atómico, ver su docblock).
 *
 * RUPTURA DE CICLO (BlockFlowContext <-> FlexFormattingContext): un contenedor flex puede tener
 * items que a su vez son bloques normales (necesitan BlockFlowContext) o incluso OTROS
 * contenedores flex anidados (necesitan volver aquí); un bloque normal puede tener un hijo
 * `display:flex` (necesita este contexto). Ninguna de las dos clases puede recibir a la otra por
 * constructor sin ciclo. Solución: este constructor —cuya firma es la del contrato del milestone,
 * `(TextMeasurer, FontCatalog, IntrinsicSizer)` más el `?WarningCollector` opcional que M5-T1
 * añade al final (housekeeping, no rompe la ruptura de ciclo descrita aquí)— crea SU PROPIA
 * instancia interna de BlockFlowContext (mismo measurer/catalog/warnings, las tres clases son
 * puras respecto a esos colaboradores, sin más estado propio compartido) y la conecta consigo mismo vía
 * `BlockFlowContext::setFlexContext()` (inyección perezosa, ver el docblock de esa clase): así,
 * cuando este contexto delega un ITEM que es un bloque normal a su BlockFlowContext interno, y
 * ese bloque tiene DESCENDIENTES con `display:flex`, el propio BlockFlowContext interno sabe
 * volver a ESTA MISMA instancia sin wiring adicional. Un ITEM que es él mismo un contenedor flex
 * (no un descendiente, el item DIRECTO) nunca pasa por el BlockFlowContext interno: layoutItem()
 * lo detecta y se re-invoca a sí mismo directamente (ver más abajo) — evita el problema simétrico
 * de que BlockFlowContext::layout() nunca examina el display del BOX que se le pasa como raíz,
 * solo el de sus HIJOS (mismo gap documentado en ese docblock para el <body> raíz).
 *
 * ADJUDICACIÓN "border-box main size" (brief, contrato ACTUAL tras el M4 final-review — ver
 * Finding 1): el tamaño resuelto de un item en el eje principal (§9.2 base / §9.7 resuelto) se
 * trata UNIFORMEMENTE como su ancho BORDER-BOX, sea cual sea la fuente (flex-basis, width CSS, o
 * max-content) — ver hypotheticalMainSize(). Ese tamaño resuelto SIEMPRE GANA sobre cualquier
 * width propio que el item declare, para CUALQUIER tipo de item: BlockBox normal y ImageBox vía
 * el parámetro $usedWidthOverride de BlockFlowContext::layout()/layoutImage() (mecanismo desde
 * T5), y —desde el M4 final-review— también un item que es él mismo OTRO contenedor
 * `display:flex` anidado, vía el mismo parámetro en FlexFormattingContext::layout() (ver
 * layoutItem() y el docblock de ese método). Antes de esa corrección, un item flex anidado era el
 * ÚNICO tipo de item que NO recibía el override: su propio layout() volvía a resolver su width CSS
 * declarado desde cero, reproduciendo para ese caso concreto el mismo hueco/solape que T5 ya había
 * cerrado para BlockBox/ImageBox — ver FlexFormattingContextTest "a nested display:flex item
 * receives the resolved width override too". Un item SIN width propio (el caso normal:
 * flex-basis:auto tomó el max-content) coincide con este mecanismo trivialmente, porque
 * BlockFlowContext ya trata width:auto como "llena el containing width menos márgenes" — el
 * override simplemente confirma ese mismo número.
 */
final readonly class FlexFormattingContext implements FormattingContext
{
    private BlockFlowContext $blockFlow;

    public function __construct(
        private TextMeasurer $measurer,
        private FontCatalog $catalog,
        private IntrinsicSizer $sizer,
        private ?WarningCollector $warnings = null,
    ) {
        $this->blockFlow = new BlockFlowContext($measurer, $catalog, $warnings);
        $this->blockFlow->setFlexContext($this);
    }

    private function warn(string $message): void
    {
        $this->warnings?->addWarning($message);
    }

    public function layout(BlockBox $container, Rect $containingBlock, ?float $usedWidthOverride = null): BoxFragment
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

        // M4 final-review Finding 1: $usedWidthOverride, cuando no es null, es el ancho BORDER-BOX
        // que un FlexFormattingContext EXTERIOR (un contenedor flex del que este contenedor es,
        // él mismo, un item DIRECTO — ver layoutItem()) ya resolvió para esta caja vía §9.7. Mismo
        // parámetro y mismo criterio de "gana sobre cualquier width propio declarado" que
        // BlockFlowContext::layout() (ver su docblock de T5): antes de este parámetro, un item
        // que era OTRO contenedor flex anidado ignoraba por completo el ajuste de flex-grow/shrink
        // de su padre y volvía a resolver su propio width CSS desde cero, reproduciendo el mismo
        // hueco/solape que T5 ya arregló para items BlockBox/ImageBox normales — el bug era
        // exactamente el mismo, solo que layoutItem() nunca pasaba el override en este caso.
        if ($usedWidthOverride !== null) {
            $borderBoxWidth = $usedWidthOverride;
            $contentWidth = max(0.0, $borderBoxWidth - $paddingLeft - $paddingRight - $borderLeft - $borderRight);
        } else {
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
        $items = $this->flexItems($container->children);

        if ($items === []) {
            $lineCross = $declaredContentHeight ?? 0.0;
            $height = $lineCross + $paddingTop + $paddingBottom + $borderTop + $borderBottom;
            $radius = BorderRadius::fromCss($style->borderRadius, $borderBoxWidth, $height);
            return new BoxFragment(new Rect($x, $y, $borderBoxWidth, $height), $style->backgroundColor, [], $borders, atomic: true, opacity: $style->opacity, borderRadius: $radius, backgroundGradient: $style->backgroundGradient, boxShadow: $style->boxShadow);
        }

        if ($style->flexDirection === FlexDirection::Column) {
            return $this->layoutColumnContainer(
                $style,
                $items,
                $x,
                $y,
                $contentX,
                $contentTop,
                $contentWidth,
                $declaredContentHeight,
                $paddingBottom,
                $borderBottom,
                $borderBoxWidth,
                $borders,
            );
        }

        // M5-T1 (housekeeping): hypotheticalMainSize() se calcula UNA sola vez por item aquí,
        // memoizado por identidad de objeto — antes de esta tarea, splitIntoLines() (más abajo) y
        // resolveMainSizes() (dentro de layoutRowLine(), una vez POR LÍNEA) lo recalculaban cada
        // una por su cuenta para los MISMOS items, sin que el resultado pudiera cambiar entre
        // ambas llamadas (mismo item, mismo $contentWidth) — ver el docblock de
        // hypotheticalMainSizesById().
        $basesById = $this->hypotheticalMainSizesById($items, $contentWidth);

        // §9.3 (wrap): parte $items en líneas — ver splitIntoLines(). flex-wrap:nowrap (default)
        // siempre produce UNA sola línea con TODOS los items (misma llamada, mismo resultado
        // exacto que T4 antes de esta tarea: la condición de apertura de línea en
        // splitIntoLines() solo se evalúa cuando $style->flexWrap === Wrap).
        $lines = $this->splitIntoLines($items, $contentWidth, $style->columnGapPx, $style->flexWrap, $basesById);

        // Con una sola línea, la altura declarada del contenedor (si la hay) sigue forzando el
        // cross size de ESA línea (comportamiento T4 intacto, ver layoutRowLine()). Con 2+ líneas
        // no hay align-content (fuera de alcance, ver docblock de clase): cada línea se limita a
        // su propio máximo natural, documentado — la altura declarada solo actúa como SUELO del
        // total (ver más abajo), nunca reparte el sobrante entre líneas.
        $singleLineForcedCross = count($lines) === 1 ? $declaredContentHeight : null;

        $finalFragments = [];
        $cursorY = $contentTop;
        $lineCrossSizes = [];
        foreach ($lines as $lineItems) {
            [$lineFragments, $lineCross] = $this->layoutRowLine($lineItems, $contentWidth, $contentX, $cursorY, $style, $singleLineForcedCross, $basesById);
            foreach ($lineFragments as $fragment) {
                $finalFragments[] = $fragment;
            }
            $lineCrossSizes[] = $lineCross;
            $cursorY += $lineCross + $style->rowGapPx;
        }

        $linesCount = count($lineCrossSizes);
        $naturalTotalCross = array_sum($lineCrossSizes) + ($linesCount > 1 ? $style->rowGapPx * ($linesCount - 1) : 0.0);
        $totalCross = $linesCount > 1 && $declaredContentHeight !== null
            ? max($declaredContentHeight, $naturalTotalCross)
            : $naturalTotalCross;

        $contentBottom = $contentTop + $totalCross;
        $height = ($contentBottom - $y) + $paddingBottom + $borderBottom;

        $radius = BorderRadius::fromCss($style->borderRadius, $borderBoxWidth, $height);
        return new BoxFragment(new Rect($x, $y, $borderBoxWidth, $height), $style->backgroundColor, $finalFragments, $borders, atomic: true, opacity: $style->opacity, borderRadius: $radius, backgroundGradient: $style->backgroundGradient, boxShadow: $style->boxShadow);
    }

    /**
     * css-flexbox-1 §9.3: agrupa $items (en orden de documento) en líneas flex. flex-wrap:nowrap
     * (el default) nunca abre una línea nueva — todos los items caen en una sola, EXACTAMENTE el
     * comportamiento de M4-T4 (overflow permitido, ver resolveMainSizes()). Con wrap, se acumula
     * el tamaño hipotético EXTERIOR (hypotheticalMainSize + márgenes horizontales) de cada item +
     * el column-gap que le precede; en cuanto añadirlo desbordaría $contentWidth, se abre una
     * línea nueva — salvo que la línea actual esté vacía (el PRIMER item de una línea nunca se
     * rechaza: "un item solo más ancho que el contenedor se queda en su línea, sin partir", brief).
     *
     * M5-T1: $basesById es el resultado YA calculado de hypotheticalMainSizesById() para estos
     * MISMOS items (una sola vez, en layout()) — este método ya no vuelve a invocar
     * hypotheticalMainSize() por su cuenta, solo lee del mapa por identidad de objeto.
     *
     * @param non-empty-list<BlockBox|ImageBox> $items
     * @param array<int, float> $basesById spl_object_id($item) => hypotheticalMainSize
     * @return non-empty-list<non-empty-list<BlockBox|ImageBox>>
     */
    private function splitIntoLines(array $items, float $contentWidth, float $columnGapPx, FlexWrap $wrap, array $basesById): array
    {
        $lines = [];
        /** @var list<BlockBox|ImageBox> $currentLine */
        $currentLine = [];
        $currentSum = 0.0;
        foreach ($items as $item) {
            $outer = $basesById[spl_object_id($item)]
                + $item->style->marginLeft->resolve($contentWidth) + $item->style->marginRight->resolve($contentWidth);
            if ($currentLine === []) {
                $currentLine[] = $item;
                $currentSum = $outer;
                continue;
            }
            $withGap = $currentSum + $columnGapPx + $outer;
            if ($wrap === FlexWrap::Wrap && $withGap > $contentWidth) {
                $lines[] = $currentLine;
                $currentLine = [$item];
                $currentSum = $outer;
                continue;
            }
            $currentLine[] = $item;
            $currentSum = $withGap;
        }
        // $items es non-empty-list (garantizado por el único call site, ver layout(): el branch
        // de items===[] ya retornó antes) => el bucle de arriba corre al menos una vez y
        // $currentLine SIEMPRE tiene, como mínimo, el último item — nunca queda vacío aquí.
        $lines[] = $currentLine;
        return $lines;
    }

    /**
     * Cuerpo del algoritmo de UNA línea row (§9.2/9.7/9.5/9.4/9.8), extraído sin cambios de M4-T4
     * para que splitIntoLines()+wrap puedan invocarlo una vez por línea, apilando el resultado en
     * el eje cruzado (vertical) con row-gap entre líneas (ver layout()). $forcedCross —altura
     * declarada del contenedor— solo se pasa cuando hay UNA sola línea (ver layout()): con 2+, no
     * hay align-content, cada línea se limita a su máximo natural.
     *
     * M4 final-review Finding 2(a): §9.4 define el cross size de una línea como el máximo de los
     * OUTER cross size de sus items — border-box MÁS márgenes en el eje cruzado (margin-top +
     * margin-bottom aquí, en row) — no el border-box a secas. Antes de esta corrección,
     * $crossSizes solo miraba `$fragment->rect->height` (border-box): un item con margin-top>0 ya
     * pintaba su caja desplazada hacia abajo ese margen (BlockFlowContext/layoutImage lo aplican
     * incondicionalmente al posicionar, con o sin flex), pero la línea nunca reservaba ESE espacio
     * al calcular su propio cross size ni, por tanto, la altura del contenedor — el fondo de la
     * caja del item quedaba pintado por debajo del borde inferior del propio contenedor flex.
     * $crossMarginsY se reutiliza también para el stretch target de más abajo (el margen no se
     * estira, solo el border-box entre ambos). NOTA (finding 4 del review, solo documentación):
     * el % de estos márgenes verticales se resuelve contra $contentWidth — el mismo criterio css
     * 2.2 §10.3 ("todo margin en % usa el ancho del containing block, incluso los verticales") ya
     * aplicado a marginsX/marginsY en el resto de esta clase; NO es una base distinta por ser
     * cross-axis en vez de main-axis, es la MISMA base contentWidth en ambos casos — la única
     * base realmente "dual" en este método es otra (ver el comentario junto a $marginsX en
     * resolveMainSizes()).
     *
     * M5-T1: $basesById, ver el docblock de splitIntoLines()/hypotheticalMainSizesById() — se
     * reenvía tal cual a resolveMainSizes() en vez de dejar que recalcule.
     *
     * @param non-empty-list<BlockBox|ImageBox> $items items de ESTA línea
     * @param array<int, float> $basesById spl_object_id($item) => hypotheticalMainSize
     * @return array{0: list<Fragment>, 1: float} fragments (orden de documento) + cross size de la línea
     */
    private function layoutRowLine(array $items, float $contentWidth, float $contentX, float $lineTop, ComputedStyle $style, ?float $forcedCross, array $basesById): array
    {
        [$resolvedMain, $marginsX] = $this->resolveMainSizes($items, $contentWidth, $style->columnGapPx, $basesById);
        $finalX = self::mainAxisPositions($resolvedMain, $marginsX, $contentWidth, $contentX, $style->columnGapPx, $style->justifyContent);

        $natural = [];
        foreach ($items as $i => $item) {
            $natural[$i] = $this->layoutItem($item, new Rect($finalX[$i], $lineTop, $resolvedMain[$i] + $marginsX[$i], INF), $resolvedMain[$i]);
        }

        $crossMarginsY = [];
        $crossSizes = [];
        foreach ($natural as $i => $fragment) {
            $itemStyle = $items[$i]->style;
            $crossMarginsY[$i] = $itemStyle->marginTop->resolve($contentWidth) + $itemStyle->marginBottom->resolve($contentWidth);
            $crossSizes[$i] = $fragment->rect->height + $crossMarginsY[$i];
        }
        $lineCross = $forcedCross ?? max($crossSizes);

        $finalFragments = [];
        foreach ($items as $i => $item) {
            $itemCross = $crossSizes[$i];
            if ($style->alignItems === AlignItems::Stretch && !self::hasDefiniteCrossSize($item)) {
                $stretchTarget = max(0.0, $lineCross - $crossMarginsY[$i]);
                $finalFragments[] = self::withHeight($natural[$i], $stretchTarget);
                continue;
            }
            $offset = match ($style->alignItems) {
                AlignItems::Center => ($lineCross - $itemCross) / 2.0,
                AlignItems::FlexEnd => $lineCross - $itemCross,
                default => 0.0,
            };
            // M5-T1 (housekeeping): antes de esta tarea, un offset≠0 (center/flex-end) volvía a
            // invocar layoutItem() ENTERO con el ÚNICO cambio de sumarle $offset a la Y de
            // partida — mismo $resolvedMain[$i]/$marginsX[$i] (mismo tamaño) que el layout
            // "natural" ya calculado unas líneas arriba. GeometryShift::translateY() (M5-T5:
            // extraído de esta clase, ver su docblock) reutiliza ESE fragmento, desplazando su
            // subárbol completo en vez de repetir el layout — seguro porque Y solo entra de forma
            // ADITIVA en BlockFlowContext::layout()/layoutImage() (ningún cálculo depende de su
            // valor absoluto), así que el resultado es idéntico bit a bit.
            $finalFragments[] = $offset !== 0.0 ? GeometryShift::translateY($natural[$i], $offset) : $natural[$i];
        }

        return [$finalFragments, $lineCross];
    }

    /**
     * css-flexbox-1 §4: los hijos directos de un contenedor flex ya llegan aplanados a
     * BlockBox|ImageBox — BoxTreeBuilder::wrapAnonymousFlexItems() (M4-T2) envuelve cualquier
     * tramo de TextRun|LineBreakRun en un BlockBox anónimo ANTES de que el árbol llegue aquí. Se
     * filtra de todos modos (en vez de asumirlo ciegamente) por si algún caller construye el árbol
     * a mano sin pasar por el builder (p.ej. un test) — mismo espíritu "soft, documented" que el
     * resto del motor, no una excepción.
     *
     * M5-T3/T4: $children puede incluir TableBox (una tabla es, ella misma, un flex item DIRECTO —
     * ver BlockBox::$children y wrapAnonymousFlexItems()). El filtro whitelist de abajo (solo
     * BlockBox|ImageBox) la excluye: M5-T4 le da a TableBox su propio TableFormattingContext
     * (consumido desde BlockFlowContext, no desde aquí) pero DELIBERADAMENTE no la convierte en un
     * tipo de flex item válido — sigue excluida. M5-T6 (M5 final-review): una TableBox como item
     * flex DIRECTO simplemente desaparece de $items con un warning, no participa del layout de
     * línea ni del cálculo de tamaños, pero tampoco crashea (contrato de excluded-values-warn:
     * exclusión deliberada, valor conocido, warning a través del canal M5-T1). Support real via
     * flex-child layout delegating a TableFormattingContext (similar a BlockFlowContext pattern)
     * queda en M6+.
     *
     * M7-T4: += InlineBoxStart/InlineBoxEnd (misma unión que BlockBox::$children) — nunca
     * alcanzan este método en la práctica: BoxTreeBuilder::wrapAnonymousFlexItems() ya los
     * coalesció (junto con cualquier TextRun/LineBreakRun/inline-block suelto) en un BlockBox
     * anónimo ANTES de que este método vea $container->children, así que la rama `else` de abajo
     * (ninguna instanceof coincide) es la única alcanzable para ellos si de alguna forma llegaran
     * — se ignoran en silencio, igual que un TextRun/LineBreakRun suelto ya haría.
     *
     * @param list<BlockBox|TextRun|LineBreakRun|ImageBox|TableBox|InlineBoxStart|InlineBoxEnd> $children
     * @return list<BlockBox|ImageBox>
     */
    private function flexItems(array $children): array
    {
        $items = [];
        foreach ($children as $child) {
            if ($child instanceof BlockBox || $child instanceof ImageBox) {
                $items[] = $child;
            } elseif ($child instanceof TableBox) {
                $this->warn('table as direct flex item not supported yet: skipped');
            }
        }
        return $items;
    }

    /**
     * M4-T5 (carry-over fix): $usedWidthOverride es el ancho BORDER-BOX que este contexto ya
     * resolvió para el item (§9.7 en row; stretch de cross size en column, ver layoutColumn())
     * — se pasa SIEMPRE (nunca condicionalmente a si el item declara su propio width), porque
     * cuando el item NO tiene width propio el override coincide exactamente con lo que el
     * cálculo "auto llena el containing width" ya producía (ver docblock de clase), y cuando SÍ
     * lo tiene, es lo único que evita el hueco/solape documentado en el ledger de T4. M4
     * final-review Finding 1: un item que es él mismo OTRO contenedor flex anidado SÍ recibe el
     * override ahora — antes de esta corrección se descartaba para este caso concreto (ver el
     * docblock de FlexFormattingContext::layout()), dejando que el item anidado volviera a
     * resolver su propio width CSS desde cero, el mismo hueco/solape de T4 resurgiendo para este
     * tipo de item; el override sigue siendo un mecanismo de ITEM (se reaplica en cada nivel de
     * anidación vía la llamada recursiva de más abajo), nunca de CONTENEDOR de nivel superior (la
     * llamada pública en layout() para el contenedor raíz nunca lo recibe).
     */
    private function layoutItem(BlockBox|ImageBox $item, Rect $itemRect, ?float $usedWidthOverride = null): BoxFragment
    {
        if ($item instanceof ImageBox) {
            return $this->blockFlow->layoutImage($item, $itemRect, $usedWidthOverride);
        }
        if ($item->style->display === Display::Flex) {
            // Item que es él mismo otro contenedor flex (anidado) — ver docblock de clase y el
            // párrafo de Finding 1 arriba: el override viaja con él, igual que a BlockFlowContext.
            return $this->layout($item, $itemRect, $usedWidthOverride);
        }
        return $this->blockFlow->layout($item, $itemRect, $usedWidthOverride);
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

    /**
     * Aproximación de stretch/resolución de main size en column (ver §9.4/§9.8 en layout() y
     * layoutColumnContainer()): agranda o encoge el rect sin re-layout interno. $atomic del
     * fragmento original se PRESERVA (un item que es él mismo un contenedor flex anidado sigue
     * siendo atómico frente a Paginator tras este ajuste geométrico).
     */
    private static function withHeight(BoxFragment $fragment, float $height): BoxFragment
    {
        // M8-T2 review Finding 2 (css-backgrounds-3 §5.5): $borderRadius se RE-CLAMPA contra la
        // altura FINAL, no se preserva tal cual -- ya venía resuelto/clampeado contra la altura
        // NATURAL del fragmento original, pero un radio que cabía a ESA altura puede dejar de
        // caber tras este ajuste geometry-only (p.ej. flex-shrink en column: tl+bl > la nueva
        // altura), produciendo un path Bézier auto-intersecante (bowtie) en vez de una esquina
        // real. BorderRadius::reclampFor() reaplica el MISMO algoritmo proporcional que el clamp
        // inicial (fromCss()) -- nunca agranda (ver su docblock), así que el caso de CRECER
        // (align-items:stretch normal) deja los radios intactos, byte a byte.
        return new BoxFragment(
            new Rect($fragment->rect->x, $fragment->rect->y, $fragment->rect->width, $height),
            $fragment->background,
            $fragment->children,
            $fragment->borders,
            $fragment->atomic,
            $fragment->opacity,
            $fragment->clipsChildren,
            $fragment->borderRadius->reclampFor($fragment->rect->width, $height),
            $fragment->backgroundGradient,
            $fragment->boxShadow,
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
        $base = $this->hypotheticalMainSizeUnclamped($item, $contentWidth);
        // M7-T5 (css-flexbox-1 §9.7 / §4.5): min/max-width del ITEM son límites del base size,
        // igual criterio "min gana a max" que CSS 2.2 §10.4 -- aplicado aquí (una sola vez, antes
        // de wrap/grow/shrink, ver hypotheticalMainSizesById()) en vez de en resolveMainSizes()
        // porque el brief M7-T5 solo pide integrar el clamp en el BASE (§9.2), no reproducir la
        // tabla completa de interacción min/max con grow/shrink resuelto (§9.7 congelamiento
        // iterativo, ya simplificado a 2 pasadas por M4 -- ver el docblock de resolveMainSizes()).
        // border-box uniforme (mismo criterio "adjudicación border-box main size" del docblock de
        // clase): min/max-width se normalizan a border-box antes de comparar contra $base.
        $paddingH = $style->paddingLeft->resolve($contentWidth) + $style->paddingRight->resolve($contentWidth);
        $borderH = $style->borderLeft->widthPx + $style->borderRight->widthPx;
        $toBorderBox = static fn(float $px): float => $style->boxSizing === 'border-box' ? $px : $px + $paddingH + $borderH;
        $maxWidthPx = $style->maxWidth?->resolve($contentWidth);
        if ($maxWidthPx !== null) {
            $base = min($base, $toBorderBox($maxWidthPx));
        }
        $minWidthPx = $style->minWidth?->resolve($contentWidth);
        if ($minWidthPx !== null) {
            $base = max($base, $toBorderBox($minWidthPx));
        }
        return $base;
    }

    /** Cuerpo SIN clamp de hypotheticalMainSize() (§9.2, ver su docblock arriba) -- extraído para
     * que el clamp de min/max-width (M7-T5) envuelva el resultado sin duplicar las 3 ramas
     * flexBasis/width/max-content. */
    private function hypotheticalMainSizeUnclamped(BlockBox|ImageBox $item, float $contentWidth): float
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
     * M5-T1 (housekeeping): calcula hypotheticalMainSize() UNA sola vez por item de $items,
     * memoizado por identidad de objeto (spl_object_id) — antes de esta tarea, un layout() en
     * row invocaba hypotheticalMainSize() DOS veces por item: una en splitIntoLines() (decidir
     * dónde abrir línea) y otra en resolveMainSizes() (llamado UNA vez POR LÍNEA, para el §9.7
     * real), sin que el resultado pudiera cambiar entre ambas llamadas (mismo item, mismo
     * $contentWidth — ninguna de las dos varía dentro de un mismo layout()). El duplicado salía
     * más caro cuanto más contenido (texto/hijos) tuviera el item, vía
     * IntrinsicSizer::maxContentWidth() -> TextMeasurer::widthOf() por carácter. spl_object_id()
     * es seguro aquí porque $items nunca se copia ni reconstruye entre layout(), splitIntoLines()
     * y layoutRowLine(): son los MISMOS objetos BlockBox|ImageBox de principio a fin.
     *
     * @param non-empty-list<BlockBox|ImageBox> $items
     * @return array<int, float> spl_object_id($item) => hypotheticalMainSize
     */
    private function hypotheticalMainSizesById(array $items, float $contentWidth): array
    {
        $bases = [];
        foreach ($items as $item) {
            $bases[spl_object_id($item)] = $this->hypotheticalMainSize($item, $contentWidth);
        }
        return $bases;
    }

    /**
     * css-flexbox-1 §9.7 (resolver longitudes flexibles), una sola línea: libre = contentWidth −
     * Σ(base + márgenes horizontales del item) − columnGap×(n−1). libre>0 con Σgrow>0 reparte el
     * sobrante proporcional al grow de cada item (items con grow:0, el default, no se mueven).
     * libre<0 con Σ(shrink×base)>0 encoge proporcional a ese factor escalado. Sin items con
     * grow>0 (o shrink>0) el/los item(s) simplemente se quedan en su base — overflow permitido.
     *
     * M7 final-review Finding A (§9.7 "freeze on violation", ambos lados): cualquier item cuyo
     * candidato de ESTA distribución viole su propio min/max-width se CONGELA en ese límite
     * (clampado) y el espacio libre/déficit restante se reparte de nuevo SOLO entre los items aún
     * no congelados — bucle ACOTADO (ver freezeLoop()) en vez del "2 pasadas fijas" que esta clase
     * usaba antes de esta tarea (que dejaba sin cubrir una segunda violación en cascada cuando
     * congelar el primer item empujaba a un SEGUNDO por encima/debajo de SU propio límite — ver el
     * repro de 3 items del test). Lado grow: el techo es max-width (creciendo, un item nunca cae
     * por debajo de su min-width porque solo aumenta desde una base que hypotheticalMainSize() ya
     * clampó — ver su docblock, así que min-width no puede violarse creciendo). Lado shrink: el
     * suelo es max(min-content, min-width en border-box) — el min-content YA se aplicaba antes de
     * esta tarea (M4-T4/T5); min-width del item ahora TAMBIÉN participa como suelo alternativo,
     * "min gana" (coexisten, css-flexbox-1 §9.7 + CSS 2.2 §10.4) — un item nunca EXCEDE su
     * max-width encogiendo, por el mismo argumento de base ya clampada.
     *
     * NOTA (finding 4 del review final M4, solo documentación — no se toca el comportamiento): el
     * $marginsX que devuelve este método, resuelto contra $contentWidth, alimenta DOS usos con
     * bases distintas para el % — mainAxisPositions() lo usa TAL CUAL (misma base, contentWidth)
     * para calcular $finalX, pero layoutRowLine() vuelve a pasar por delante ESE MISMO margen
     * dentro de `resolvedMain[$i] + marginsX[$i]` como el WIDTH del Rect que se entrega a
     * layoutItem() — y layoutItem()/BlockFlowContext::layout() (o layoutImage(), o esta misma
     * clase para un item flex anidado, ver Finding 1) vuelven a resolver el margin-left/right
     * del item DESDE CERO, pero contra ESE Rect->width (contentWidth ya no es la base: ahora es
     * resolvedMain[i]+marginsX[i], un número distinto). Con márgenes en px es un no-op (mismo
     * valor con cualquier base); con márgenes en % puede producir dos valores de margen distintos
     * a partir de la MISMA declaración CSS — una sola vez para el bookkeeping de posición
     * (mainAxisPositions()) y otra, potencialmente distinta, para la caja realmente pintada. Sin
     * test en el alcance de M4 lo ejercita (ningún caso usa % en margin de un item flex), documentado
     * aquí para que una futura tarea con % en margin sepa dónde mirar antes de asumir que ambos
     * cálculos coinciden.
     *
     * M5-T1: $basesById trae ya calculado (una sola vez por item, en layout()) lo que este método
     * antes recomputaba aquí mismo para cada línea — ver hypotheticalMainSizesById().
     *
     * @param list<BlockBox|ImageBox> $items
     * @param array<int, float> $basesById spl_object_id($item) => hypotheticalMainSize
     * @return array{0: array<int, float>, 1: array<int, float>} resolvedMain (border-box, eje
     *     principal) y marginsX (margin-left+right, resuelto contra $contentWidth), mismo índice
     *     que $items (array<int,...> en vez de list<...>: PHPStan no puede probar por sí solo que
     *     las asignaciones `$arr[$i] = ...` dentro de un foreach sobre un list producen un list
     *     sin huecos, aunque en efecto lo sea).
     */
    private function resolveMainSizes(array $items, float $contentWidth, float $columnGapPx, array $basesById): array
    {
        $n = count($items);
        $base = [];
        $marginsX = [];
        $grow = [];
        $shrink = [];
        foreach ($items as $i => $item) {
            $base[$i] = $basesById[spl_object_id($item)];
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
                $maxBound = [];
                foreach ($items as $i => $item) {
                    $maxBound[$i] = $this->maxWidthBound($item, $contentWidth);
                }
                $resolvedMain = self::freezeLoop($base, $grow, $maxBound, $free, growing: true);
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

            $minBound = [];
            foreach ($items as $i => $item) {
                $minBound[$i] = $this->minWidthBound($item, $contentWidth);
            }
            $resolvedMain = self::freezeLoop($base, $scaledFactor, $minBound, $free, growing: false);
        }

        return [$resolvedMain, $marginsX];
    }

    /**
     * M7 final-review Finding A: techo de crecimiento de un item en el eje ancho (row) — su propio
     * max-width, convertido a border-box (mismo criterio "toBorderBox" que hypotheticalMainSize()),
     * o null si no hay max-width declarado (sin techo, el item puede crecer indefinidamente vía
     * flex-grow).
     */
    private function maxWidthBound(BlockBox|ImageBox $item, float $contentWidth): ?float
    {
        $style = $item->style;
        $maxWidthPx = $style->maxWidth?->resolve($contentWidth);
        if ($maxWidthPx === null) {
            return null;
        }
        $paddingH = $style->paddingLeft->resolve($contentWidth) + $style->paddingRight->resolve($contentWidth);
        $borderH = $style->borderLeft->widthPx + $style->borderRight->widthPx;
        return $style->boxSizing === 'border-box' ? $maxWidthPx : $maxWidthPx + $paddingH + $borderH;
    }

    /**
     * M7 final-review Finding A: suelo de encogimiento de un item en el eje ancho (row) —
     * max(min-content, min-width propio en border-box). min-content ya se aplicaba antes de esta
     * tarea (M4-T4/T5, IntrinsicSizer::minContentWidth()); min-width ahora TAMBIÉN participa como
     * suelo alternativo — "min gana" cuando ambos aplican (coexisten, nunca se excluyen entre sí).
     */
    private function minWidthBound(BlockBox|ImageBox $item, float $contentWidth): float
    {
        $minContent = $this->sizer->minContentWidth($item);
        $style = $item->style;
        $minWidthPx = $style->minWidth?->resolve($contentWidth);
        if ($minWidthPx === null) {
            return $minContent;
        }
        $paddingH = $style->paddingLeft->resolve($contentWidth) + $style->paddingRight->resolve($contentWidth);
        $borderH = $style->borderLeft->widthPx + $style->borderRight->widthPx;
        $minWidthBorderBox = $style->boxSizing === 'border-box' ? $minWidthPx : $minWidthPx + $paddingH + $borderH;
        return max($minContent, $minWidthBorderBox);
    }

    /**
     * css-flexbox-1 §9.7 paso 6 ("resolve the flexible lengths"), simplificado a un bucle
     * iterativo ACOTADO: como mucho count($base) rondas — cada ronda o bien congela AL MENOS un
     * item nuevo (sale del reparto, ver $frozen), o termina sin ninguna violación nueva (acepta los
     * candidatos de esa ronda y sale) — con n items, tras n rondas todos están congelados o el
     * bucle ya salió antes por falta de violaciones, así que NUNCA itera más de count($base) veces
     * (cota documentada, según pide el brief M7 final-review Finding A/B, en vez del "2 pasadas
     * fijas" de versiones anteriores de este método — insuficiente para una CASCADA de 2+
     * violaciones sucesivas, ver el repro de 3 items del test: congelar el primer item por su
     * propio límite puede empujar a un SEGUNDO por encima/debajo del SUYO, que solo una ronda
     * adicional detecta).
     *
     * Reutilizado IDÉNTICO por resolveMainSizes() (row, eje ancho) y layoutColumnContainer()
     * (column, eje alto) — el algoritmo es agnóstico al eje: solo necesita $base/$factor/$bound/
     * $freeOrDeficit en la unidad que sea (px de ancho o de alto) y la dirección ($growing).
     *
     * @param array<int, float> $base tamaño hipotético/base de cada item, mismo índice que $factor/$bound
     * @param array<int, float> $factor factor de reparto de ESTA ronda: grow[i] si $growing=true,
     *     o el "scaled flex shrink factor" shrink[i]×base[i] si $growing=false (css-flexbox-1 §9.7)
     * @param array<int, float|null> $bound techo (creciendo: max-width/height) o suelo (encogiendo:
     *     el suelo YA combinado por el caller, ver minWidthBound()) de cada item; null = sin límite
     * @param float $freeOrDeficit espacio libre (positivo, $growing=true) o negativo (déficit,
     *     $growing=false) — mismo $free que el caller ya calculó
     * @return array<int, float> tamaño final resuelto de cada item, mismo índice que $base
     */
    private static function freezeLoop(array $base, array $factor, array $bound, float $freeOrDeficit, bool $growing): array
    {
        $resolved = $base;
        $frozen = array_fill_keys(array_keys($base), false);
        $totalBudget = $growing ? $freeOrDeficit : abs($freeOrDeficit);
        $remaining = $totalBudget;
        $rounds = count($base);

        for ($round = 0; $round < $rounds; $round++) {
            $sumFactor = 0.0;
            foreach (array_keys($base) as $i) {
                if (!$frozen[$i]) {
                    $sumFactor += $factor[$i];
                }
            }
            if ($sumFactor <= 0.0) {
                break;
            }

            $newlyFrozen = false;
            $candidates = [];
            foreach ($base as $i => $b) {
                if ($frozen[$i]) {
                    continue;
                }
                $share = $remaining * ($factor[$i] / $sumFactor);
                $candidate = $growing ? $b + $share : $b - $share;
                $limit = $bound[$i];
                $violates = $limit !== null && ($growing ? $candidate > $limit : $candidate < $limit);
                if ($violates) {
                    $resolved[$i] = $limit;
                    $frozen[$i] = true;
                    $newlyFrozen = true;
                    continue;
                }
                $candidates[$i] = $candidate;
            }

            if (!$newlyFrozen) {
                foreach ($candidates as $i => $c) {
                    $resolved[$i] = $c;
                }
                break;
            }

            $consumed = 0.0;
            foreach ($base as $i => $b) {
                if ($frozen[$i]) {
                    $consumed += $growing ? ($resolved[$i] - $b) : ($b - $resolved[$i]);
                }
            }
            $remaining = $totalBudget - $consumed;
            if ($remaining <= 0.0) {
                break;
            }
        }

        return $resolved;
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

    /**
     * flex-direction: column — eje principal VERTICAL (brief M4-T5). No soporta wrap (fuera de
     * alcance de esta tarea: ningún test la ejercita en combinación con column, ver brief; una
     * columna es siempre una única "línea" vertical, columnGapPx del contenedor queda sin uso en
     * este eje).
     *
     * §9.2 (base, adaptado a altura): flex-basis en px manda (% se resuelve contra la altura
     * DECLARADA del contenedor si la hay, o 0 si es auto — mismo criterio "sin containing block
     * real, % contra 0" que IntrinsicSizer); auto → el height CSS propio del item si lo declara
     * (BlockFlowContext nunca lo aplicaría por sí solo, ver su docblock — de ahí que el ajuste
     * final de altura pase SIEMPRE por withHeight(), ver más abajo) o, si tampoco lo declara, su
     * altura NATURAL: layoutear con el content width del contenedor y medir (mismo patrón que la
     * medición de cross size en row, pero en el eje principal).
     *
     * §9.7 (grow/shrink) + M7 final-review Finding B: min/max-height del ITEM clampan el BASE
     * (mismo criterio "max primero, min después" que hypotheticalMainSize() en row, ver su
     * docblock) y, tras grow/shrink, cualquier candidato que viole su propio min/max-height se
     * CONGELA en ese límite y el libre/déficit restante se reparte de nuevo entre los items aún no
     * congelados — MISMO bucle acotado que row (freezeLoop(), reutilizado tal cual, ver su
     * docblock). A diferencia de row, no hay concepto de "min-content de bloque" en este motor
     * (css-flexbox-1 §4.5 lo definiría como el alto natural mismo — simplificación ya documentada,
     * fuera de alcance): el suelo de encogimiento es min-height si se declaró, o 0.0 en su defecto
     * (preserva el "nunca negativo" que esta rama ya garantizaba ANTES de esta tarea, ver el
     * `max(0.0, ...)` que este método tenía). Sin altura DECLARADA en el contenedor, el main size
     * sigue indefinido: ni grow/shrink ni justify-content tienen sentido (huecos libres = 0 por
     * construcción, la columna se limita a apilar las bases —YA clampadas por su propio min/
     * max-height— con el gap — "hugs content", brief); % en min-height/max-height NO existen en
     * este motor (ComputedStyle::$minHeight/$maxHeight son PX-ONLY, ver su docblock — cualquier %
     * ya fue rechazado con warning por DeclarationParser antes de llegar aquí, "warning+ignore" es
     * el comportamiento YA existente que esta tarea no toca).
     *
     * Gap: el eje principal en column usa row-gap (roles intercambiados respecto a row, ver
     * columnAxisPositions()). Cross axis (horizontal): stretch (default) estira los items sin
     * ancho propio al content width del contenedor vía el override del carry-over fix (ver
     * layoutColumnItem()); un item con su propio width cae a flex-start (mismo criterio que row).
     *
     * @param non-empty-list<BlockBox|ImageBox> $items
     */
    private function layoutColumnContainer(
        ComputedStyle $style,
        array $items,
        float $x,
        float $y,
        float $contentX,
        float $contentTop,
        float $contentWidth,
        ?float $declaredContentHeight,
        float $paddingBottom,
        float $borderBottom,
        float $borderBoxWidth,
        BorderSet $borders,
    ): BoxFragment {
        $n = count($items);
        $base = [];
        $marginsY = [];
        $grow = [];
        $shrink = [];
        $minBoundH = [];
        $maxBoundH = [];
        foreach ($items as $i => $item) {
            $itemStyle = $item->style;
            $marginsY[$i] = $itemStyle->marginTop->resolve($contentWidth) + $itemStyle->marginBottom->resolve($contentWidth);
            $grow[$i] = $itemStyle->flexGrow;
            $shrink[$i] = $itemStyle->flexShrink;

            if ($itemStyle->flexBasis !== null) {
                $base[$i] = $itemStyle->flexBasis->resolve($declaredContentHeight ?? 0.0);
            } elseif (($ownHeightPx = $itemStyle->height?->px) !== null) {
                $base[$i] = $ownHeightPx;
            } else {
                // Altura natural: layout con el content width del contenedor (el cross size fija
                // el ancho, el alto queda libre) — SOLO se usa para medir, se descarta (la
                // posición final de este item se resuelve en una segunda pasada, ver más abajo,
                // una vez conocido su finalY — el mismo patrón "dos pasadas" que row usa para su
                // cross size).
                $base[$i] = $this->layoutItem($item, new Rect($contentX, 0.0, $contentWidth, INF))->rect->height;
            }

            // M7 final-review Finding B (§9.2 análogo al de hypotheticalMainSize() en row): el
            // BASE de altura, sea cual sea su fuente (flex-basis/height propio/natural, las 3
            // ramas de arriba), se clampa a min/max-height -- max PRIMERO, min DESPUÉS (CSS 2.2
            // §10.4: si el min resultante excede el max, el min gana). Sin conversión de
            // box-sizing (simplificación documentada, consistente con el trato YA existente de
            // $ownHeightPx arriba, que tampoco distingue box-sizing para la altura declarada de un
            // item flex column).
            $maxBoundH[$i] = $itemStyle->maxHeight?->px;
            if ($maxBoundH[$i] !== null) {
                $base[$i] = min($base[$i], $maxBoundH[$i]);
            }
            $minBoundH[$i] = $itemStyle->minHeight?->px;
            if ($minBoundH[$i] !== null) {
                $base[$i] = max($base[$i], $minBoundH[$i]);
            }
        }

        $gapsTotal = $n > 1 ? $style->rowGapPx * ($n - 1) : 0.0;
        $resolvedMain = $base;

        if ($declaredContentHeight !== null) {
            $sumOuterBase = array_sum($base) + array_sum($marginsY);
            $free = $declaredContentHeight - $sumOuterBase - $gapsTotal;
            if ($free > 0.0) {
                $sumGrow = array_sum($grow);
                if ($sumGrow > 0.0) {
                    $resolvedMain = self::freezeLoop($base, $grow, $maxBoundH, $free, growing: true);
                }
            } elseif ($free < 0.0) {
                $scaledFactor = [];
                foreach ($items as $i => $item) {
                    $scaledFactor[$i] = $shrink[$i] * $base[$i];
                }
                $sumShrinkBase = array_sum($scaledFactor);
                if ($sumShrinkBase > 0.0) {
                    // M7 final-review Finding B: el suelo de encogimiento es min-height si se
                    // declaró, o 0.0 (nunca negativo -- preserva el `max(0.0, ...)` que este
                    // método ya aplicaba ANTES de esta tarea para el caso sin min-height propio).
                    $shrinkFloor = [];
                    foreach ($minBoundH as $i => $bound) {
                        $shrinkFloor[$i] = $bound ?? 0.0;
                    }
                    $resolvedMain = self::freezeLoop($base, $scaledFactor, $shrinkFloor, $free, growing: false);
                }
            }
        }

        // M5-T1: primer warning real de este contexto (ver docblock de columnAxisPositions()) —
        // sin altura declarada, justify-content distinto de flex-start (el default, sin efecto
        // observable por construcción) queda sin nada que repartir: la columna "hugs content" y
        // el valor declarado se ignora en silencio salvo por este aviso.
        if ($declaredContentHeight === null && $style->justifyContent !== JustifyContent::FlexStart) {
            $this->warn('flex column: justify-content has no effect without a declared container height (auto height hugs content)');
        }

        $finalY = self::columnAxisPositions($resolvedMain, $marginsY, $declaredContentHeight, $contentTop, $style->rowGapPx, $style->justifyContent);

        $finalFragments = [];
        foreach ($items as $i => $item) {
            $stretchCandidate = $style->alignItems === AlignItems::Stretch && !self::hasDefiniteCrossSizeColumn($item);
            // M4 final-review Finding 2(b): el override de stretch es el ancho CONTENT del
            // contenedor MENOS los márgenes horizontales propios del item (resueltos contra
            // $contentWidth, mismo criterio que marginsY más arriba), no $contentWidth a secas.
            // Antes de esta corrección, un item con margin-left/right propio se posicionaba en
            // contentX + su marginLeft (BlockFlowContext/layoutItem lo aplican igual que a
            // cualquier bloque) pero se le seguía forzando el ANCHO COMPLETO del contenedor,
            // dejando su borde derecho tantos px más allá del borde de contenido como sumaran sus
            // márgenes — el mismo hueco/solape de Finding 1/2(a), aquí en el eje cruzado de column.
            $widthOverride = null;
            if ($stretchCandidate) {
                $itemMarginLeft = $item->style->marginLeft->resolve($contentWidth);
                $itemMarginRight = $item->style->marginRight->resolve($contentWidth);
                $widthOverride = max(0.0, $contentWidth - $itemMarginLeft - $itemMarginRight);
            }

            $frag = $this->layoutColumnItem($item, $contentX, $finalY[$i], $contentWidth, $widthOverride, $resolvedMain[$i]);
            $itemCrossWidth = $frag->rect->width;
            $offset = match ($style->alignItems) {
                AlignItems::Center => ($contentWidth - $itemCrossWidth) / 2.0,
                AlignItems::FlexEnd => $contentWidth - $itemCrossWidth,
                default => 0.0, // FlexStart, o Stretch ya a ancho completo / con ancho propio
            };
            if ($offset !== 0.0) {
                $frag = $this->layoutColumnItem($item, $contentX + $offset, $finalY[$i], $contentWidth, $widthOverride, $resolvedMain[$i]);
            }
            $finalFragments[] = $frag;
        }

        $sumOuterResolved = array_sum($resolvedMain) + array_sum($marginsY);
        $naturalContentHeight = $sumOuterResolved + $gapsTotal;
        $lineCross = $declaredContentHeight ?? $naturalContentHeight;

        $contentBottom = $contentTop + $lineCross;
        $height = ($contentBottom - $y) + $paddingBottom + $borderBottom;

        $radius = BorderRadius::fromCss($style->borderRadius, $borderBoxWidth, $height);
        return new BoxFragment(new Rect($x, $y, $borderBoxWidth, $height), $style->backgroundColor, $finalFragments, $borders, atomic: true, opacity: $style->opacity, borderRadius: $radius, backgroundGradient: $style->backgroundGradient, boxShadow: $style->boxShadow);
    }

    /**
     * Layoutea un item de columna en ($x, $y) y fuerza su altura al main size YA resuelto
     * (§9.7) — BlockFlowContext nunca produciría esa altura por sí solo (no soporta height en
     * bloques, ver su docblock; y aunque la soportara, el valor final aquí puede venir de
     * flex-grow/shrink, no de su propio CSS), así que se ajusta vía withHeight() (mismo mecanismo
     * geométrico que align-items:stretch en row: sin re-layout interno, contenido anclado
     * arriba). El ancho, en cambio, SÍ es real (no geométrico): $widthOverride, cuando no es
     * null, reutiliza el mecanismo de carry-over fix (ver layoutItem()) para estirar el item al
     * content width del contenedor.
     */
    private function layoutColumnItem(BlockBox|ImageBox $item, float $x, float $y, float $contentWidth, ?float $widthOverride, float $resolvedHeight): BoxFragment
    {
        $frag = $this->layoutItem($item, new Rect($x, $y, $widthOverride ?? $contentWidth, INF), $widthOverride);
        return abs($frag->rect->height - $resolvedHeight) > 0.0001 ? self::withHeight($frag, $resolvedHeight) : $frag;
    }

    /**
     * §9.4 análogo para column: un item tiene cross size (ANCHO, aquí) definido cuando declara su
     * propio width CSS — un BlockBox sin width propio SIEMPRE lo llena vía BlockFlowContext (no
     * hay shrink-to-fit en este motor, documentado en su docblock), así que es candidato a
     * stretch; un ImageBox con width CSS o atributo HTML width propios tampoco se estira (mismo
     * criterio que hasDefiniteCrossSize() para row/altura), pero uno puramente intrínseco sí,
     * para poder llenar el contenedor cuando align-items:stretch lo pide.
     */
    private static function hasDefiniteCrossSizeColumn(BlockBox|ImageBox $item): bool
    {
        if ($item instanceof ImageBox) {
            return $item->style->width !== null || $item->attrWidth !== null;
        }
        return $item->style->width !== null;
    }

    /**
     * css-flexbox-1 §9.5 análogo para column: eje principal vertical, gap = row-gap (roles
     * intercambiados respecto a row, brief: "columnGap ↔ rowGap roles swap"). justify-content
     * solo distribuye leftover cuando el contenedor declara altura ($availableHeight !== null) —
     * con auto, no hay leftover que repartir (el contenido MANDA la altura), justify-content
     * queda sin efecto observable, documentado en el brief ("auto height → hugs content, justify
     * moot").
     *
     * @param array<int, float> $resolvedMain
     * @param array<int, float> $marginsY
     * @return array<int, float> y del borde de MARGEN (no border-box) de cada item, mismo índice
     */
    private static function columnAxisPositions(
        array $resolvedMain,
        array $marginsY,
        ?float $availableHeight,
        float $contentTop,
        float $rowGapPx,
        JustifyContent $justifyContent,
    ): array {
        $n = count($resolvedMain);
        $gapsTotal = $n > 1 ? $rowGapPx * ($n - 1) : 0.0;

        $startOffset = 0.0;
        $extraGap = 0.0;
        if ($availableHeight !== null) {
            $sumOuter = 0.0;
            foreach ($resolvedMain as $i => $main) {
                $sumOuter += $main + $marginsY[$i];
            }
            $leftover = $availableHeight - $sumOuter - $gapsTotal;
            $startOffset = match ($justifyContent) {
                JustifyContent::Center => $leftover / 2.0,
                JustifyContent::FlexEnd => $leftover,
                default => 0.0,
            };
            $extraGap = ($justifyContent === JustifyContent::SpaceBetween && $n > 1) ? $leftover / ($n - 1) : 0.0;
        }

        $positions = [];
        $cursor = $contentTop + $startOffset;
        foreach ($resolvedMain as $i => $main) {
            $positions[$i] = $cursor;
            $cursor += $marginsY[$i] + $main + $rowGapPx + $extraGap;
        }
        return $positions;
    }
}
