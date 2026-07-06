<?php

declare(strict_types=1);

namespace Pliego\Layout;

use Pliego\Box\BlockBox;
use Pliego\Box\TableBox;
use Pliego\Box\TableRowBox;
use Pliego\Css\WarningCollector;
use Pliego\Layout\Fragment\BorderSet;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\GeometryShift;
use Pliego\Layout\Geometry\Rect;
use Pliego\Style\VerticalAlign;
use Pliego\Text\FontCatalog;

/**
 * CSS 2.2 §17.5.2 (column width algorithm) + §17.6.1 (separated borders model, border-spacing).
 *
 * ADJUDICACIÓN (no implementa FormattingContext): la interfaz del milestone tipa
 * `layout(BlockBox $box, Rect): BoxFragment` — una TableBox NO es una BlockBox (son cajas
 * hermanas en el árbol, ver TableBox/BlockBox), así que esta clase no puede satisfacer esa firma
 * sin ensuciar el contrato del interface con una unión de tipos que el resto de sus
 * implementadores (BlockFlowContext, FlexFormattingContext) no necesitan. Se mantiene como
 * COLABORADOR INDEPENDIENTE con su propia firma `layout(TableBox, Rect): BoxFragment` — el mismo
 * criterio "standalone, not FormattingContext" que ya aplica de facto a InlineFlowContext (nunca
 * implementó la interfaz, layoutea listas de runs, no una BlockBox). El texto del plan de
 * milestone que dice "implements FormattingContext" es aspiracional/impreciso; esta nota documenta
 * la desviación deliberada.
 *
 * RUPTURA DE CICLO (BlockFlowContext <-> TableFormattingContext): idéntico patrón que M4-T4 usa
 * para Block<->Flex (ver el docblock de clase de FlexFormattingContext). El constructor —firma de
 * contrato del milestone, `(TextMeasurer, FontCatalog, IntrinsicSizer)` + `?WarningCollector`
 * opcional al final, igual que Flex— crea su PROPIO BlockFlowContext interno (mismos
 * measurer/catalog/warnings) y lo conecta consigo mismo vía `BlockFlowContext::setTableContext()`
 * (inyección perezosa: BlockFlowContext también expone una versión perezosa —autocreada la
 * primera vez que hace falta— para cualquier caller, Engine incluido, que solo construya
 * `new BlockFlowContext(...)` sin wiring explícito). Así, cuando el BlockFlowContext interno de
 * este contexto layoutea el contenido de una celda y encuentra una TableBox anidada (tabla dentro
 * de celda), vuelve a ESTA MISMA instancia sin necesitar recursión explícita aquí — análogo a como
 * un bloque anidado con un descendiente flex encuentra el FlexFormattingContext ya wireado de su
 * BlockFlowContext padre.
 *
 * Nº DE COLUMNAS (§17.2.1, sin rowspan — M6 lo difiere, ver TableCellBox): max de Σcolspan por
 * fila (ver ColumnExtentsCalculator::columnCount()); sin necesidad de rastrear una grid de celdas
 * ocupadas porque no hay rowspan que desplace columnas entre filas — el índice de columna de una
 * celda depende solo de las celdas QUE LA PRECEDEN EN SU PROPIA FILA.
 *
 * AUTO (table-layout:auto, el default, o table-layout:fixed sin width declarado — ver más abajo):
 * min/max-content POR CELDA vía IntrinsicSizer sobre una BlockBox SINTÉTICA que envuelve
 * `$cell->children` con el propio ComputedStyle de la celda (más barato que añadir un método
 * dedicado a IntrinsicSizer para `list<...>` — su sizeBlock() YA suma el padding/borde propios de
 * ESE style, exactamente "cell intrinsic = children intrinsic + cell paddings + cell borders" sin
 * código adicional). Por columna: max/min = el MAYOR entre todas las celdas de span=1 que caen en
 * esa columna (distintas filas pueden competir por la misma columna, gana el máximo, nunca se
 * suman). Una celda con colspan>1 reparte su EXCESO (lo que su propio min/max supera a la suma de
 * las columnas que abarca) entre esas columnas, proporcional al max de single-span YA acumulado en
 * cada una (partes iguales si todas están en 0) — la MISMA ponderación para el exceso de max Y el
 * de min (adjudicación del brief: no hay una ponderación separada "por min" independiente).
 *
 * BUGFIX post-review (M5-T4): este cálculo por columna vivía ENTERO en esta clase
 * (autoColumnExtents()/cellMaxContent()/cellMinContent()/columnCount()) — ahora extraído,
 * VERBATIM, a `ColumnExtentsCalculator` (ver su propio docblock para el porqué de la extracción:
 * IntrinsicSizer necesitaba la MISMA aritmética para medir una TableBox como caja, sin poder
 * depender de esta clase entera por el ciclo de constructores que crearía). Esta clase aloja una
 * instancia propia ($columnExtents, construida en el constructor con el mismo $sizer que ya
 * recibía) y la usa exactamente donde antes llamaba a sus métodos privados — sin cambio de
 * comportamiento, solo de dueño del código.
 *
 * Ancho de tabla (auto): width declarado (resuelto contra el containing block, box-sizing
 * reinterpretado igual que un bloque normal) si lo hay; si no, el MENOR entre el ancho que un
 * bloque normal auto ocuparía en este containing block (cbWidth − márgenes − padding − borde
 * propios) y Σmax de columnas + spacing total — un "shrink-to-fit" simplificado (no el algoritmo
 * completo de CAPMIN/GRIDMIN de la spec, adjudicación del brief). available = ese content width
 * menos el spacing total (borderSpacing×(cols+1), separated model §17.6.1). Reparto por columna
 * (§17.5.2.2, exactamente el orden de ramas del brief): Σmax ≤ available → cada col su max (+
 * sobrante, solo posible con width declarado mayor que el natural, repartido proporcional al max,
 * partes iguales si Σmax=0); Σmin ≥ available → cada col su min, overflow permitido, warning
 * ("table minimum content width exceeds available width"); intermedio → interpolación lineal
 * min+(available−Σmin)×(max−min)/(Σmax−Σmin). Ver distributeAutoWidths().
 *
 * FIXED (table-layout:fixed CON width declarado — sin él, warning + fallback a auto, ver más
 * abajo): NINGUNA llamada a IntrinsicSizer (rápido, la razón de ser de fixed). La PRIMERA fila
 * manda: cada celda de span=1 con width propio (px o %, % contra el content width de la tabla) fija
 * esa columna; las columnas sin declarar (incluidas las que colspan cubre, repartido a partes
 * iguales entre sus columnas) se reparten lo que quede del available entre sí, a partes iguales.
 * Filas siguientes NO influyen en absoluto en los anchos (documentado: si declaran menos/más
 * celdas que cols, sus celdas simplemente ocupan lo que su colspan cubra en la grid ya fijada).
 * Ver fixedColumnWidths().
 *
 * Celdas y filas: cada celda se layoutea con el BlockFlowContext interno vía el mecanismo de
 * $usedWidthOverride (patrón M4-T5) = ancho BORDER-BOX resuelto para ella (suma de los anchos de
 * columna que abarca + el borderSpacing INTERNO entre esas columnas, span−1 veces) — así un width
 * propio declarado en la celda NUNCA gana sobre el ancho de columna ya decidido por el algoritmo
 * (mismo criterio "el override siempre gana" que Flex/Block ya documentan). Altura de fila =
 * MÁXIMO de las alturas (border-box) de fragmento de sus celdas; las celdas más bajas se ESTIRAN
 * geometry-only a esa altura (mismo patrón que FlexFormattingContext::withHeight(): el rect crece,
 * el contenido NO se re-layoutea).
 *
 * vertical-align (M5-T5, css-tables-3 §3, solo top|middle|bottom soportados — ver VerticalAlign):
 * top (default) es el estirado de arriba sin más — el contenido queda anclado en la parte
 * superior de la caja YA estirada, comportamiento intacto desde T4. middle/bottom desplazan el
 * CONTENIDO (nunca la caja de la celda en sí, que ya ocupa la altura completa de fila — su fondo/
 * borde siguen pintándose sobre ESE rect completo) dentro de la caja estirada, vía
 * GeometryShift::translateChildrenY() (M5-T5: extraído de FlexFormattingContext, ver el docblock
 * de esa clase compartida). $contentHeight = la altura NATURAL del fragmento de la celda ANTES de
 * estirar (lo que withHeight() habría dejado sin tocar); el delta se calcula contra la altura de
 * fila ($rowHeight, que YA incluye el padding/borde propios de la celda más alta — ver
 * layoutRow()): middle → (rowHeight − contentHeight)/2 hacia abajo; bottom → el delta completo,
 * rowHeight − contentHeight. Una celda cuyo contentHeight YA es rowHeight (la más alta, o
 * cualquiera sin estirar) tiene delta=0 → alignCell() no la toca, sin importar su vertical-align
 * declarado (no-op observable, documentado).
 *
 * Fragmento de FILA: BoxFragment con atomic=true (M5-T5: antes T4 lo dejaba en false,
 * documentado como pendiente) — Paginator la trata como unidad de paginación indivisible (mismo
 * mecanismo M4-T5 que ya usa FlexFormattingContext para su contenedor, ver
 * Paginator::flatten()/relocate()): la TABLA en sí NO es atómica, así que Paginator desciende
 * dentro de ella libremente y encuentra sus filas —cada una, atómica— partiendo la tabla EXACTAMENTE
 * entre filas, sin código de paginación específico para tablas (la misma maquinaria M4 genérica).
 * Una fila más alta que la página se queda sin partir, con el warning ya existente desde M5-T1
 * ("atomic fragment taller than page, kept unsplit") — reutilizado tal cual, sin mensaje nuevo.
 * El espaciado vertical (border-spacing) entre la última fila de una página y la primera de la
 * siguiente desaparece de forma natural cuando una fila se empuja entera a la página siguiente
 * (el push-down de Paginator reposiciona su Y absoluta, el spacing ya "vivía" en esa Y original,
 * no en un cálculo aparte) — sin caso especial en esta clase, verificado por test de integración
 * (ver TableFormattingContextTest, "30-row table splits between rows exactly").
 * Filas SIN bordes propios (CSS 2.2 §17.6.1: en el modelo separado, filas/row-groups NUNCA pintan
 * borde propio, solo celdas y la tabla exterior lo hacen — sí pinta su propio background, detrás de
 * sus celdas). Orden de pintado gratis por anidamiento de fragmentos: tabla → fila → celda → contenido.
 *
 * border-spacing (un solo valor para ambos ejes, T2 ya documenta esa simplificación): horizontal
 * antes de la primera columna, entre columnas, y después de la última (spacing×(cols+1) total);
 * vertical idéntico entre/alrededor de filas, acumulado fila a fila en vez de precalculado (no
 * hace falta conocer el nº de filas de antemano: cada iteración añade el spacing tras la fila,
 * incluida la última, así el cursor final YA incluye el spacing de cierre sin caso especial).
 */
final readonly class TableFormattingContext
{
    private BlockFlowContext $blockFlow;
    private ColumnExtentsCalculator $columnExtents;

    public function __construct(
        private TextMeasurer $measurer,
        private FontCatalog $catalog,
        private IntrinsicSizer $sizer,
        private ?WarningCollector $warnings = null,
    ) {
        $this->blockFlow = new BlockFlowContext($measurer, $catalog, $warnings);
        $this->blockFlow->setTableContext($this);
        $this->columnExtents = new ColumnExtentsCalculator($sizer);
    }

    private function warn(string $message): void
    {
        $this->warnings?->addWarning($message);
    }

    public function layout(TableBox $table, Rect $containingBlock): BoxFragment
    {
        $style = $table->style;
        $cbWidth = $containingBlock->width;

        // Resolución de la propia caja de la tabla — EL MISMO cálculo que BlockFlowContext/
        // FlexFormattingContext hacen para cualquier bloque (márgenes/padding/borde), duplicado a
        // propósito (~15 líneas, patrón ya autorizado en M4-T5/FlexFormattingContext: extraerlo a
        // un trait compartido no aporta claridad aquí). El width, a diferencia de un bloque
        // normal, NO se resuelve aquí mismo — depende del algoritmo de columnas (auto/fixed) más
        // abajo, así que solo se calculan margen/padding/borde en esta sección.
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

        $cols = ColumnExtentsCalculator::columnCount($table->rows);
        $borderSpacing = $style->borderSpacingPx;
        $spacingTotal = $borderSpacing * ($cols + 1);

        $declaredWidth = $style->width;
        $declaredWidthPx = $declaredWidth?->resolve($cbWidth);

        if ($style->tableLayout === 'fixed' && $declaredWidthPx !== null) {
            $gridWidth = self::contentWidthFromDeclared($declaredWidthPx, $style->boxSizing, $paddingLeft, $paddingRight, $borderLeft, $borderRight);
            $available = max(0.0, $gridWidth - $spacingTotal);
            $colWidths = $this->fixedColumnWidths($table->rows, $cols, $gridWidth, $available);
        } else {
            // CSS 2.2 §17.5.2: "if 'table-layout' is 'fixed'... [but] 'width' is 'auto'... use
            // automatic table layout" — adjudicación del brief: en vez de silenciar el caso, se
            // avisa (una sola vez, aquí) de que fixed no se está aplicando.
            if ($style->tableLayout === 'fixed') {
                $this->warn('table-layout: fixed without a declared width falls back to auto');
            }
            [$colMax, $colMin] = $this->columnExtents->columnExtents($table->rows, $cols);
            $sumMax = array_sum($colMax);
            if ($declaredWidthPx !== null) {
                $gridWidth = self::contentWidthFromDeclared($declaredWidthPx, $style->boxSizing, $paddingLeft, $paddingRight, $borderLeft, $borderRight);
            } else {
                $autoContentWidth = max(0.0, $cbWidth - $marginLeft - $marginRight - $paddingLeft - $paddingRight - $borderLeft - $borderRight);
                $gridWidth = min($autoContentWidth, $sumMax + $spacingTotal);
            }
            $available = max(0.0, $gridWidth - $spacingTotal);
            $colWidths = $this->distributeAutoWidths($colMax, $colMin, $available);
        }

        $borderBoxWidth = $gridWidth + $paddingLeft + $paddingRight + $borderLeft + $borderRight;
        $contentX = $x + $borderLeft + $paddingLeft;
        $cursorY = $y + $borderTop + $paddingTop + $borderSpacing;

        $rowFragments = [];
        foreach ($table->rows as $row) {
            [$rowFragment, $rowBottom] = $this->layoutRow($row, $colWidths, $borderSpacing, $contentX, $gridWidth, $cursorY);
            $rowFragments[] = $rowFragment;
            // El spacing tras ESTA fila se añade siempre, incluida la última — así $cursorY al
            // salir del bucle YA es el content-bottom final (spacing de cierre incluido, §17.6.1),
            // sin necesidad de un caso especial "solo entre filas, no tras la última".
            $cursorY = $rowBottom + $borderSpacing;
        }

        $height = ($cursorY - $y) + $paddingBottom + $borderBottom;

        return new BoxFragment(
            new Rect($x, $y, $borderBoxWidth, $height),
            $style->backgroundColor,
            $rowFragments,
            new BorderSet($style->borderTop, $style->borderRight, $style->borderBottom, $style->borderLeft),
            opacity: $style->opacity,
        );
    }

    /**
     * CSS 2.2 §17.5.2.2, reparto final por columna a partir de $colMax/$colMin ya resueltos (ver
     * ColumnExtentsCalculator::columnExtents()) y el $available (content width de la tabla menos
     * el spacing total).
     * Orden de ramas EXACTO del brief (mutuamente excluyentes, primera que aplique gana):
     *   1. Σmax ≤ available: cada columna a su max; el sobrante (solo posible si el width
     *      declarado de la tabla es mayor que su contenido natural, ver layout()) se reparte
     *      proporcional al max de cada columna, partes iguales si Σmax=0.
     *   2. Σmin ≥ available: cada columna a su min (overflow permitido, sin clipping en este
     *      motor — mismo criterio "soft" que el resto del motor), con un warning explícito.
     *   3. Intermedio: interpolación lineal min+(available−Σmin)×(max−min)/(Σmax−Σmin) — sin
     *      división por cero posible aquí: si Σmax=Σmin la rama 1 ya habría capturado el caso
     *      (available ≥ Σmin = Σmax), así que llegar aquí garantiza Σmax > Σmin.
     *
     * BUGFIX post-review (M5-T4): toda salida de este método pasa por
     * warnIfColumnCollapsed() antes de devolver — una defensa, no el fix en sí (el fix real es que
     * IntrinsicSizer/ColumnExtentsCalculator ya no le dan 0 de max-content a una celda cuyo único
     * contenido es una TableBox anidada, ver el docblock de clase de ColumnExtentsCalculator, así
     * que el caso que reportó el reviewer YA NO llega aquí con un colMax en 0). Queda para
     * cualquier OTRA fuente futura de "0 de max-content genuino" que compita en la misma tabla
     * contra columnas con contenido real — sin este aviso, ese 0 se propaga en silencio a un ancho
     * de columna final también 0 (el mismo síntoma visual: contenido solapado con la columna
     * vecina), exactamente como pasaba con las tablas anidadas antes de este fix.
     *
     * @param array<int, float> $colMax
     * @param array<int, float> $colMin
     * @return array<int, float>
     */
    private function distributeAutoWidths(array $colMax, array $colMin, float $available): array
    {
        $cols = count($colMax);
        if ($cols === 0) {
            return [];
        }
        $sumMax = array_sum($colMax);
        $sumMin = array_sum($colMin);

        if ($sumMax <= $available) {
            $surplus = $available - $sumMax;
            if ($surplus <= 0.0) {
                $this->warnIfColumnCollapsed($colMax);
                return $colMax;
            }
            $equalShare = $surplus / $cols;
            $widths = [];
            foreach ($colMax as $i => $max) {
                $widths[$i] = $sumMax > 0.0 ? $max + $surplus * ($max / $sumMax) : $max + $equalShare;
            }
            $this->warnIfColumnCollapsed($widths);
            return $widths;
        }

        if ($sumMin >= $available) {
            $this->warn('table minimum content width exceeds available width');
            $this->warnIfColumnCollapsed($colMin);
            return $colMin;
        }

        $denom = $sumMax - $sumMin;
        $widths = [];
        foreach ($colMax as $i => $max) {
            $min = $colMin[$i];
            $widths[$i] = $min + ($available - $sumMin) * (($max - $min) / $denom);
        }
        $this->warnIfColumnCollapsed($widths);
        return $widths;
    }

    /**
     * Ver el docblock de distributeAutoWidths() para el porqué de esta defensa. Un único warning
     * por llamada (no uno por columna colapsada) — igual criterio "un aviso, no spam" que el resto
     * de esta clase (p.ej. el de table-layout:fixed sin width). Una tabla de una sola columna
     * nunca dispara esto (no hay "columna vecina no-cero" con la que contrastar: un ancho 0 ahí es
     * un caso degenerado distinto, no el síntoma de solapamiento que motiva este aviso).
     *
     * @param array<int, float> $widths
     */
    private function warnIfColumnCollapsed(array $widths): void
    {
        if (count($widths) < 2) {
            return;
        }
        $hasZero = false;
        $hasNonZero = false;
        foreach ($widths as $width) {
            if ($width <= 0.0) {
                $hasZero = true;
            } else {
                $hasNonZero = true;
            }
        }
        if ($hasZero && $hasNonZero) {
            $this->warn('table column collapsed to zero width');
        }
    }

    /**
     * table-layout:fixed (§17.5.2.1): la PRIMERA fila manda, sin ninguna llamada a IntrinsicSizer.
     * Cada celda de span=1 con width propio (px o %, % contra $gridWidth — "el ancho de la tabla",
     * adjudicación del brief) fija esa columna; una celda con colspan>1 y width propio reparte ESE
     * ancho a partes iguales entre las columnas que abarca (documentado: no hay señal por columna
     * individual dentro de un colspan declarado como un solo número, a diferencia de auto donde
     * IntrinsicSizer sí puede medir cada celda de span=1 por separado). Las columnas sin declarar
     * (de cualquier fila posterior a la primera no importa: solo se mira `$rows[0]`) se reparten a
     * PARTES IGUALES lo que quede de $available tras restar lo declarado.
     *
     * @param list<TableRowBox> $rows
     * @return array<int, float>
     */
    private function fixedColumnWidths(array $rows, int $cols, float $gridWidth, float $available): array
    {
        /** @var array<int, float|null> $widths null = sin declarar, comparte el resto */
        $widths = array_fill(0, $cols, null);

        if ($rows !== []) {
            $colIndex = 0;
            foreach ($rows[0]->cells as $cell) {
                $span = $cell->colspan;
                $declared = $cell->style->width;
                if ($declared !== null) {
                    $each = $declared->resolve($gridWidth) / $span;
                    for ($k = 0; $k < $span && $colIndex + $k < $cols; $k++) {
                        $widths[$colIndex + $k] = $each;
                    }
                }
                $colIndex += $span;
            }
        }

        $declaredSum = 0.0;
        $undeclared = 0;
        foreach ($widths as $w) {
            if ($w !== null) {
                $declaredSum += $w;
            } else {
                $undeclared++;
            }
        }
        $remainder = max(0.0, $available - $declaredSum);
        $share = $undeclared > 0 ? $remainder / $undeclared : 0.0;

        return array_map(static fn(?float $w): float => $w ?? $share, $widths);
    }

    /**
     * Layoutea una fila completa: posiciona sus celdas por columna (con el borderSpacing separado
     * antes/entre/después, §17.6.1), layoutea cada una vía el BlockFlowContext interno con
     * $usedWidthOverride (ver docblock de clase), estira geometry-only las más bajas a la altura
     * máxima de la fila y aplica vertical-align (top/middle/bottom, ver alignCell()) por celda. El
     * fragmento de fila resultante es atomic:true (M5-T5, ver docblock de clase) — Paginator la
     * trata como unidad de paginación indivisible.
     *
     * @param array<int, float> $colWidths
     * @return array{0: BoxFragment, 1: float} fragmento de fila + su borde inferior absoluto
     */
    private function layoutRow(TableRowBox $row, array $colWidths, float $borderSpacing, float $contentX, float $gridWidth, float $rowTop): array
    {
        // Posiciones de borde izquierdo de cada columna (índice 0..cols), acumulando ancho +
        // spacing; colX[cols] es el borde derecho de la última columna + su spacing de cierre (no
        // se usa directamente, pero cae de la misma fórmula sin caso especial).
        $colX = [$contentX + $borderSpacing];
        $cursor = $colX[0];
        foreach ($colWidths as $w) {
            $cursor += $w + $borderSpacing;
            $colX[] = $cursor;
        }

        $cellFragments = [];
        $cellStyles = [];
        $colIndex = 0;
        foreach ($row->cells as $cell) {
            $span = $cell->colspan;
            // Invariante: colIndex nunca excede $cols dentro de esta fila (cols = max Σcolspan por
            // fila, ver ColumnExtentsCalculator::columnCount()) — colX[colIndex] y
            // colX[colIndex+span] SIEMPRE existen.
            $cellX = $colX[$colIndex];
            $cellRight = $colX[$colIndex + $span] - $borderSpacing;
            $cellWidth = max(0.0, $cellRight - $cellX);

            $cellBox = new BlockBox($cell->style, $cell->children, $cell->tag);
            $cellFragments[] = $this->blockFlow->layout($cellBox, new Rect($cellX, $rowTop, $cellWidth, INF), $cellWidth);
            $cellStyles[] = $cell->style;
            $colIndex += $span;
        }

        $rowHeight = 0.0;
        foreach ($cellFragments as $fragment) {
            $rowHeight = max($rowHeight, $fragment->rect->height);
        }

        $aligned = [];
        foreach ($cellFragments as $i => $fragment) {
            $aligned[] = self::alignCell($fragment, $cellStyles[$i]->verticalAlign, $rowHeight);
        }

        // CSS 2.2 §17.6.1: en el modelo separado, una FILA nunca pinta su propio borde (solo las
        // celdas y la tabla exterior lo hacen) — BorderSet::none() a propósito, no un descuido; su
        // background SÍ se pinta, detrás de las celdas (orden de pintado gratis por anidamiento).
        // atomic:true (M5-T5): ver el docblock de clase para el porqué (Paginator parte la tabla
        // ENTRE filas gratis, sin código de paginación específico para tablas).
        $rowFragment = new BoxFragment(
            new Rect($contentX, $rowTop, $gridWidth, $rowHeight),
            $row->style->backgroundColor,
            $aligned,
            BorderSet::none(),
            atomic: true,
            opacity: $row->style->opacity,
        );

        return [$rowFragment, $rowTop + $rowHeight];
    }

    /**
     * M5-T5: estira geometry-only la celda a $rowHeight (mismo mecanismo que
     * FlexFormattingContext::withHeight(), ver docblock de esa clase — sin duplicar código entre
     * ambas porque no comparten ninguna otra cosa que justifique un trait) y, si $verticalAlign no
     * es Top, desplaza SU CONTENIDO (no la caja ya estirada, ver docblock de clase) hacia abajo vía
     * GeometryShift::translateChildrenY(). $contentHeight es la altura NATURAL del fragmento (antes
     * de estirar) — el mismo valor que withHeight() habría dejado como estaba si $rowHeight fuera
     * igual a ella; una celda cuyo contentHeight YA es $rowHeight (delta=0, la más alta de la fila,
     * o cualquiera sin estirar) no se toca pase lo que pase en $verticalAlign, sin caso especial.
     */
    private static function alignCell(BoxFragment $fragment, VerticalAlign $verticalAlign, float $rowHeight): BoxFragment
    {
        $contentHeight = $fragment->rect->height;
        $stretched = $contentHeight < $rowHeight ? self::withHeight($fragment, $rowHeight) : $fragment;

        $delta = match ($verticalAlign) {
            VerticalAlign::Top => 0.0,
            VerticalAlign::Middle => ($rowHeight - $contentHeight) / 2.0,
            VerticalAlign::Bottom => $rowHeight - $contentHeight,
        };
        if ($delta <= 0.0) {
            return $stretched;
        }

        return new BoxFragment(
            $stretched->rect,
            $stretched->background,
            GeometryShift::translateChildrenY($stretched->children, $delta),
            $stretched->borders,
            $stretched->atomic,
            $stretched->opacity,
            $stretched->clipsChildren,
        );
    }

    /**
     * Mismo mecanismo geometry-only que FlexFormattingContext::withHeight() (sin duplicar código
     * entre ambas clases porque no comparten ninguna otra cosa que justifique un trait): agranda
     * el rect de un fragmento de celda ya calculado sin re-layoutear su contenido (que queda
     * anclado arriba — alignCell() se encarga después de desplazarlo si vertical-align no es top).
     */
    private static function withHeight(BoxFragment $fragment, float $height): BoxFragment
    {
        return new BoxFragment(
            new Rect($fragment->rect->x, $fragment->rect->y, $fragment->rect->width, $height),
            $fragment->background,
            $fragment->children,
            $fragment->borders,
            $fragment->atomic,
            $fragment->opacity,
            $fragment->clipsChildren,
        );
    }

    private static function contentWidthFromDeclared(
        float $declaredWidthPx,
        string $boxSizing,
        float $paddingLeft,
        float $paddingRight,
        float $borderLeft,
        float $borderRight,
    ): float {
        return $boxSizing === 'border-box'
            ? max(0.0, $declaredWidthPx - $paddingLeft - $paddingRight - $borderLeft - $borderRight)
            : $declaredWidthPx;
    }
}
