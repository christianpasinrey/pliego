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
 * M5-T4 (TableFormattingContext) es quien layoutea esta caja (algoritmo de anchos §17.5.2). Hasta
 * entonces, BlockFlowContext/FlexFormattingContext/IntrinsicSizer la SALTAN explícitamente
 * ("M5-T4 lo consume", mismo patrón documentado para Display::Table en Style\Display) para que una
 * tabla como hijo de un bloque o item flex no crashee el render — simplemente no produce ningún
 * fragmento todavía.
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
