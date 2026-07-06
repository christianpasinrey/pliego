<?php

declare(strict_types=1);

namespace Pliego\Layout;

use Pliego\Box\BlockBox;
use Pliego\Box\TableBox;
use Pliego\Box\TableCellBox;
use Pliego\Box\TableRowBox;

/**
 * M5-T4 (bugfix, post-review): extraído de TableFormattingContext::autoColumnExtents() +
 * columnCount() (ver el docblock de esa clase, sección "AUTO", para el algoritmo completo —
 * NINGÚN cambio de comportamiento en la extracción, solo movimiento de código) para que
 * IntrinsicSizer pueda medir una TableBox (max/min-content REAL de sus columnas, en vez de
 * saltarla) SIN depender de TableFormattingContext entero.
 *
 * Por qué esta clase y no un método público en TableFormattingContext (opción (a) del brief,
 * descartada): TableFormattingContext arrastra un BlockFlowContext interno propio (ver su
 * docblock, "RUPTURA DE CICLO") que layoutea celdas de verdad — maquinaria pesada e irrelevante
 * para IntrinsicSizer, que solo necesita MEDIR, no layoutear. Esta clase depende ÚNICAMENTE de
 * IntrinsicSizer (para medir el contenido de cada celda, exactamente igual que
 * TableFormattingContext::cellMaxContent()/cellMinContent() hacían), así que IntrinsicSizer puede
 * alojar y autocrear UNA de estas perezosamente sin necesitar nunca un TableFormattingContext (ni
 * el BlockFlowContext que este arrastra) — mismo patrón de "inyección perezosa para romper un
 * ciclo de constructores" que BlockFlowContext<->TableFormattingContext ya usa (ver el docblock de
 * clase de TableFormattingContext), aplicado ahora a IntrinsicSizer<->ColumnExtentsCalculator (que
 * a su vez usa IntrinsicSizer para medir celdas — el ciclo real es
 * IntrinsicSizer -> ColumnExtentsCalculator -> IntrinsicSizer, roto porque IntrinsicSizer ya está
 * completamente construido en el momento en que autocrea su ColumnExtentsCalculator perezoso, ver
 * IntrinsicSizer::columnExtents()).
 *
 * TableFormattingContext usa esta MISMA clase (instancia propia, construida en su constructor con
 * el mismo $sizer que ya recibía) en vez de su antigua autoColumnExtents() privada — sin
 * duplicación entre ambos callers.
 */
final readonly class ColumnExtentsCalculator
{
    public function __construct(private IntrinsicSizer $sizer) {}

    /**
     * css-tables-3 §2 / CSS 2.2 §17.2.1: max de Σcolspan por fila (sin rowspan, ver el docblock de
     * clase de TableFormattingContext, sección "Nº DE COLUMNAS").
     * @param list<TableRowBox> $rows
     */
    public static function columnCount(array $rows): int
    {
        $max = 0;
        foreach ($rows as $row) {
            $sum = 0;
            foreach ($row->cells as $cell) {
                $sum += $cell->colspan;
            }
            $max = max($max, $sum);
        }
        return $max;
    }

    /** @return array{0: array<int, float>, 1: array<int, float>} colMax, colMin de $table */
    public function extentsFor(TableBox $table): array
    {
        return $this->columnExtents($table->rows, self::columnCount($table->rows));
    }

    /**
     * CSS 2.2 §17.5.2: min/max-content por columna a partir de celdas de span=1 (el MAYOR entre
     * todas las que caen en esa columna, en cualquier fila) + el reparto del exceso de las celdas
     * con colspan>1 (ver el docblock de clase de TableFormattingContext para la ponderación
     * completa). Movido VERBATIM desde TableFormattingContext::autoColumnExtents() — mismo
     * algoritmo, sin cambios de comportamiento.
     *
     * @param list<TableRowBox> $rows
     * @return array{0: array<int, float>, 1: array<int, float>} colMax, colMin (tamaño $cols;
     *     array<int,...> en vez de list<...> por el mismo motivo documentado en el método
     *     original: PHPStan no puede probar por sí solo que las asignaciones `$arr[$i] = ...`
     *     dentro de un bucle sobre índices acotados producen un list sin huecos)
     */
    public function columnExtents(array $rows, int $cols): array
    {
        $colMax = array_fill(0, $cols, 0.0);
        $colMin = array_fill(0, $cols, 0.0);
        /** @var list<array{0: int, 1: int, 2: float, 3: float}> $spans (colIndex, span, cellMax, cellMin) */
        $spans = [];

        foreach ($rows as $row) {
            $colIndex = 0;
            foreach ($row->cells as $cell) {
                $cellMax = $this->cellMaxContent($cell);
                $cellMin = $this->cellMinContent($cell);
                if ($cell->colspan === 1) {
                    $colMax[$colIndex] = max($colMax[$colIndex], $cellMax);
                    $colMin[$colIndex] = max($colMin[$colIndex], $cellMin);
                } else {
                    $spans[] = [$colIndex, $cell->colspan, $cellMax, $cellMin];
                }
                $colIndex += $cell->colspan;
            }
        }

        foreach ($spans as [$start, $span, $cellMax, $cellMin]) {
            $weights = array_slice($colMax, $start, $span);
            $weightSum = array_sum($weights);
            $sliceMax = $weightSum;
            $sliceMin = array_sum(array_slice($colMin, $start, $span));
            $excessMax = max(0.0, $cellMax - $sliceMax);
            $excessMin = max(0.0, $cellMin - $sliceMin);
            for ($k = 0; $k < $span; $k++) {
                $share = $weightSum > 0.0 ? ($weights[$k] / $weightSum) : (1.0 / $span);
                $colMax[$start + $k] += $excessMax * $share;
                $colMin[$start + $k] += $excessMin * $share;
            }
        }

        return [$colMax, $colMin];
    }

    /**
     * css-sizing-3 §4 vía IntrinsicSizer, sobre una BlockBox SINTÉTICA que envuelve
     * `$cell->children` con el ComputedStyle de la propia celda — ver el docblock original en
     * TableFormattingContext::cellMaxContent() (movido aquí verbatim). Ya NO tiene la limitación
     * heredada de saltar una TableBox anidada: IntrinsicSizer ahora la mide (ver su docblock de
     * clase), así que una celda cuyo ÚNICO contenido es una tabla anidada aporta el max/min-content
     * REAL de esa tabla, no 0.
     */
    private function cellMaxContent(TableCellBox $cell): float
    {
        return $this->sizer->maxContentWidth(new BlockBox($cell->style, $cell->children, $cell->tag));
    }

    private function cellMinContent(TableCellBox $cell): float
    {
        return $this->sizer->minContentWidth(new BlockBox($cell->style, $cell->children, $cell->tag));
    }
}
