<?php

declare(strict_types=1);

namespace Pliego\Box;

use Pliego\Style\ComputedStyle;

/**
 * css-tables-3 §2 / CSS 2.2 §17: caja de tabla, construida por BoxTreeBuilder::buildTable() a
 * partir de un elemento cuyo ComputedStyle resuelve a Display::Table (normalmente <table>, $tag
 * conserva el nombre real igual que BlockBox/ImageBox). $rows llega ya APLANADA: los grupos de
 * fila (<thead>/<tbody>, Display::TableHeaderGroup/TableRowGroup) son TRANSPARENTES en el árbol de
 * caja — desaparecen como nivel propio, sus <tr> pasan a ser filas DIRECTAS de esta lista, en
 * orden de documento, marcadas TableRowBox::$isHeader según el grupo de origen (ver
 * BoxTreeBuilder::collectTableRows()).
 *
 * M5-T4 (TableFormattingContext) layoutea esta caja (algoritmo de anchos §17.5.2) — consumida por
 * BlockFlowContext::layout() cuando aparece como hijo de un bloque normal (delega vía
 * tableContext(), ver el docblock de esa clase). FlexFormattingContext SIGUE excluyéndola como
 * item flex directo (adjudicación deliberada, no un hueco temporal — ver su docblock).
 * IntrinsicSizer (bugfix post-review M5-T4) YA NO la salta cuando aparece como hijo de un
 * bloque/celda genérico: tiene su propio min/max-content real (ver
 * IntrinsicSizer::sizeTable()/ColumnExtentsCalculator) — una tabla anidada SÍ aporta ahora al
 * max/min-content de su contenedor, cerrando el gap que colapsaba a 0 el ancho de una columna cuyo
 * único contenido era una tabla anidada.
 */
final readonly class TableBox
{
    /** @param list<TableRowBox> $rows */
    public function __construct(
        public ComputedStyle $style,
        public array $rows,
        public string $tag,
    ) {}
}
