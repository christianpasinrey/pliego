<?php

declare(strict_types=1);

namespace Pliego\Box;

use Pliego\Style\ComputedStyle;

/**
 * css-tables-3 §2: una fila de tabla, siempre hija DIRECTA de TableBox::$rows. TableHeaderGroup/
 * TableRowGroup (<thead>/<tbody>) son transparentes en el árbol de caja (ver
 * BoxTreeBuilder::collectTableRows()), así que esta clase no guarda ninguna referencia a su grupo
 * de origen: $isHeader es la única señal que sobrevive de haber salido de un <thead> (true) frente
 * a cualquier otro origen — <tbody>, una tabla plana sin grupos, o una fila ANÓNIMA generada por la
 * variante mínima de §17.2.1 (ver TableCellBox) — que vale false salvo que la fila anónima misma
 * se haya generado dentro de un <thead> (entonces también hereda isHeader=true, ver
 * BoxTreeBuilder::wrapLooseContentInAnonymousRow()/wrapElementInAnonymousRow()).
 */
final readonly class TableRowBox
{
    /** @param list<TableCellBox> $cells */
    public function __construct(
        public ComputedStyle $style,
        public array $cells,
        public bool $isHeader,
    ) {}
}
