<?php

declare(strict_types=1);

namespace Pliego\Layout\Fragment;

use Pliego\Layout\Geometry\Rect;

/**
 * M5-T5 (extraído VERBATIM de FlexFormattingContext — antes de esta tarea, translateY()/
 * translateChildrenY() vivían ahí como métodos privados estáticos, ver su docblock de clase
 * pre-extracción para el M5-T1 que los introdujo): "geometry shift" — desplaza un subárbol de
 * fragmentos YA calculado $deltaY píxeles en el eje vertical SIN volver a layoutear nada; seguro
 * porque Y solo entra de forma ADITIVA en BlockFlowContext::layout()/layoutImage() (ningún cálculo
 * depende de su valor absoluto), así que el resultado es idéntico bit a bit al que produciría un
 * layout real en la nueva posición.
 *
 * Dos operaciones distintas, ambas necesarias:
 *   - translateY(): mueve el fragmento ENTERO (su propio rect + hijos) — el caso de
 *     FlexFormattingContext (align-items/justify-content: center/flex-end, M4-T5/M5-T1): el ITEM
 *     completo cambia de posición.
 *   - translateChildrenY(): mueve SOLO la lista de hijos, dejando el rect del CONTENEDOR que los
 *     envuelve intacto — el caso de TableFormattingContext (vertical-align: middle/bottom en
 *     celdas, M5-T5): la celda ya fue estirada geometry-only a la altura de fila (T4,
 *     withHeight()) y su propio fondo/borde se pintan a ese rect completo; solo el CONTENIDO
 *     (texto/bloques hijos) se desplaza dentro de esa caja ya fijada, nunca la caja en sí.
 *
 * Extraída a esta clase (en vez de quedarse duplicada en TableFormattingContext) porque ambas
 * clases la necesitan con exactamente el mismo comportamiento y sin estado propio — un colaborador
 * puro, sin ciclo de constructores que evitar (a diferencia de BlockFlowContext<->Flex/Table).
 */
final class GeometryShift
{
    private function __construct()
    {
        // Solo métodos estáticos — sin estado, sin instancias.
    }

    public static function translateY(BoxFragment $fragment, float $deltaY): BoxFragment
    {
        return new BoxFragment(
            new Rect($fragment->rect->x, $fragment->rect->y + $deltaY, $fragment->rect->width, $fragment->rect->height),
            $fragment->background,
            self::translateChildrenY($fragment->children, $deltaY),
            $fragment->borders,
            $fragment->atomic,
            $fragment->opacity,
            $fragment->clipsChildren,
        );
    }

    /**
     * @param list<Fragment> $children
     * @return list<Fragment>
     */
    public static function translateChildrenY(array $children, float $deltaY): array
    {
        $result = [];
        foreach ($children as $child) {
            $result[] = match (true) {
                $child instanceof BoxFragment => self::translateY($child, $deltaY),
                $child instanceof TextFragment => new TextFragment(
                    new Rect($child->rect->x, $child->rect->y + $deltaY, $child->rect->width, $child->rect->height),
                    $child->text,
                    $child->baselineY + $deltaY,
                    $child->fontSizePx,
                    $child->color,
                    $child->faceKey,
                    $child->underline,
                    $child->opacity,
                ),
                $child instanceof ImageFragment => new ImageFragment(
                    new Rect($child->rect->x, $child->rect->y + $deltaY, $child->rect->width, $child->rect->height),
                    $child->imageKey,
                    $child->opacity,
                ),
                default => throw new \LogicException('Unknown fragment leaf: ' . $child::class),
            };
        }
        return $result;
    }
}
