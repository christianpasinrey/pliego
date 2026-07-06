<?php

declare(strict_types=1);

namespace Pliego\Box;

use Pliego\Style\ComputedStyle;

/**
 * css-tables-3 §2: una celda de tabla — un <td>/<th> real ($tag conserva el nombre), o una celda
 * ANÓNIMA generada por la variante MÍNIMA de §17.2.1 que BoxTreeBuilder implementa ($tag =
 * 'anonymous', misma convención que BlockBox::$tag para los items anónimos de flex, ver
 * BoxTreeBuilder::wrapAnonymousFlexItems()). $children usa la MISMA unión que BlockBox::$children
 * (incluido TableBox: una tabla puede anidarse dentro de una celda) — se construye reutilizando el
 * pipeline NORMAL de BoxTreeBuilder (bloques/inline/imágenes/tabla anidada, ver
 * BoxTreeBuilder::collectChildren()), sin ninguna regla especial de contenido para el hecho de
 * estar dentro de una celda.
 *
 * $colspan es SIEMPRE ≥1: un atributo colspan inválido, ausente, "0" o negativo cae al default 1
 * (ver BoxTreeBuilder::parseColspan()). rowspan NO está soportado (diferido a M6): su sola
 * PRESENCIA como atributo dispara un warning ("rowspan not supported yet: treated as 1") y la
 * celda se construye igual que si no existiera — no hay ningún campo aquí que lo represente.
 */
final readonly class TableCellBox
{
    /**
     * M7-T4: += InlineBoxStart/InlineBoxEnd -- misma unión que BlockBox::$children (una celda
     * reutiliza collectChildren() ENTERO, ver el docblock de clase; una caja inline real dentro
     * de una celda no es distinta de una dentro de cualquier otro bloque).
     * @param list<BlockBox|TextRun|LineBreakRun|ImageBox|TableBox|InlineBoxStart|InlineBoxEnd> $children
     */
    public function __construct(
        public ComputedStyle $style,
        public array $children,
        public int $colspan,
        public string $tag,
    ) {}
}
