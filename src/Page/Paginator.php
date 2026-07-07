<?php

declare(strict_types=1);

namespace Pliego\Page;

use Pliego\Css\WarningCollector;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\Fragment;
use Pliego\Layout\Fragment\ImageFragment;
use Pliego\Layout\Fragment\InlineBoxFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;

/**
 * Fragmentación M0 (css-break-3 mínimo): push-down de hojas que cruzan el
 * límite de página. Streaming real: cada página se emite en cuanto la
 * siguiente hoja pertenece a una página posterior.
 *
 * M5-T1 (housekeeping): $warnings es opcional (null = silencioso, no rompe ninguna firma de
 * test unitario existente) — Engine::render() inyecta AQUÍ el mismo WarningCollector compartido
 * que ya recibe BoxTreeBuilder/BlockFlowContext/FlexFormattingContext, para que RenderReport
 * también refleje limitaciones de PAGINACIÓN (antes solo cubría CSS/imagen/@page). Primer uso
 * real: ver flatten()/paginate() más abajo, "atomic fragment taller than page, kept unsplit".
 */
final readonly class Paginator
{
    public function __construct(
        private float $contentHeightPx,
        private ?WarningCollector $warnings = null,
    ) {}

    private function warn(string $message): void
    {
        $this->warnings?->addWarning($message);
    }

    /** @return \Generator<int, Page> */
    public function paginate(BoxFragment $root): \Generator
    {
        $h = $this->contentHeightPx;
        $offset = 0.0;
        $pageIndex = 0;
        $current = [];
        foreach ($this->flatten($root) as $leaf) {
            $top = $leaf->rect()->y + $offset;
            $leafPage = (int) floor($top / $h);
            $bottom = $top + $leaf->rect()->height;
            if ($leaf->rect()->height <= $h && $bottom > ($leafPage + 1) * $h) {
                $pushDown = ($leafPage + 1) * $h - $top;
                $offset += $pushDown;
                $top += $pushDown;
                $leafPage++;
            } elseif ($leaf instanceof BoxFragment && ($leaf->atomic || $leaf->clipsChildren) && $leaf->rect()->height > $h) {
                // M5-T1: la guarda de push-down de arriba nunca se activa para una hoja más alta
                // que la propia página (misma limitación documentada, sin partir, que ya tenían
                // texto/imágenes demasiado altos) — ahora, al menos para el caso atómico (M4-T5:
                // contenedor flex entero) o clipping (M7-T5: overflow:hidden, misma ruta de
                // flatten()), queda un aviso explícito en vez de quedar en silencio.
                $this->warn('atomic fragment taller than page, kept unsplit');
            }
            while ($leafPage > $pageIndex) {
                yield new Page($pageIndex + 1, $current);
                $current = [];
                $pageIndex++;
            }
            $current[] = $this->relocate($leaf, $top - $pageIndex * $h);
        }
        yield new Page($pageIndex + 1, $current);
    }

    /**
     * Hojas en orden de pintado: el fondo de un contenedor precede a sus hijos.
     *
     * M4-T5 (paginación atómica): un BoxFragment con $atomic === true (por ahora, solo el
     * contenedor de FlexFormattingContext, ver su docblock) se emite ENTERO, de un solo golpe,
     * como una única hoja compuesta CON sus hijos intactos (sin aplanar recursivamente) — el
     * bucle de paginate() de más abajo lo trata entonces como cualquier otra hoja indivisible: si
     * cruza un límite de página Y cabe entera en una sola página, se empuja completa (con TODO su
     * subárbol, ver relocate()); si es más alta que una página, se queda donde cae sin partirse
     * (misma limitación ya documentada para texto/imágenes demasiado altas, PERO desde M5-T1 con
     * un warning explícito para el caso atómico — ver paginate()).
     *
     * M7-T5 (css-overflow-3): un BoxFragment con $clipsChildren === true (overflow:hidden, ver
     * BlockFlowContext::layout()) se trata IGUAL que $atomic aquí — reutiliza la misma ruta
     * "composite preservado entero" en vez de introducir un tercer caso: si Paginator la
     * descompusiera hoja a hoja (el camino normal de más abajo), el clip path que Painter aplica
     * al pintar esta caja (ver su docblock) quedaría sin caja-contenedora a la que amarrarse — el
     * rect que delimita el clip ES el rect de esta caja, que solo sobrevive intacto por esta rama.
     * @return \Generator<int, Fragment>
     */
    private function flatten(BoxFragment $box): \Generator
    {
        if ($box->atomic || $box->clipsChildren) {
            yield $box;
            return;
        }
        // T5: una caja sin fondo pero con borde visible también necesita una hoja paintable
        // (antes de T5 solo se emitía por background !== null y el borde se perdía).
        // M8-T2: $borderRadius se preserva (no add nada a la condición de arriba -- un radio sin
        // background/borde visible no pinta nada por sí solo, exactamente igual que antes de esta
        // tarea; una caja clipsChildren con radio SIEMPRE sale por la rama de arriba, nunca llega
        // aquí, ver el docblock de esta clase).
        if ($box->background !== null || $box->borders->isVisible()) {
            yield new BoxFragment($box->rect, $box->background, [], $box->borders, opacity: $box->opacity, borderRadius: $box->borderRadius);
        }
        foreach ($box->children as $child) {
            if ($child instanceof BoxFragment) {
                yield from $this->flatten($child);
            } else {
                yield $child;
            }
        }
    }

    private function relocate(Fragment $leaf, float $localY): Fragment
    {
        $rect = new Rect($leaf->rect()->x, $localY, $leaf->rect()->width, $leaf->rect()->height);
        return match (true) {
            $leaf instanceof TextFragment => new TextFragment(
                $rect,
                $leaf->text,
                $localY + ($leaf->baselineY - $leaf->rect->y),
                $leaf->fontSizePx,
                $leaf->color,
                $leaf->faceKey,
                $leaf->underline,
                $leaf->opacity,
            ),
            // M4-T5: un BoxFragment con hijos NO vacíos solo puede llegar aquí desde dentro del
            // subárbol de una hoja compuesta atómica (flatten() nunca aplana esos hijos por
            // separado, ver su docblock) — se recorre recursivamente para desplazar CADA
            // descendiente por el MISMO delta vertical que la propia caja (un desplazamiento
            // uniforme de todo el subárbol, coordenadas x intactas). Un BoxFragment normal
            // post-flatten (no atómico) siempre trae children === [], igual que antes de T5.
            $leaf instanceof BoxFragment => new BoxFragment(
                $rect,
                $leaf->background,
                $leaf->children !== [] ? $this->relocateChildren($leaf->children, $localY - $leaf->rect->y) : [],
                $leaf->borders,
                $leaf->atomic,
                $leaf->opacity,
                $leaf->clipsChildren,
                $leaf->borderRadius,
            ),
            // M3-T3: hoja simple igual que TextFragment — el push-down genérico de arriba ya la
            // trata como cualquier otra hoja (una imagen más alta que la página no se parte, se
            // queda cruzando el límite sin pushear, documentado en el brief).
            $leaf instanceof ImageFragment => new ImageFragment($rect, $leaf->imageKey, $leaf->opacity),
            // M7-T4: InlineBoxFragment es una hoja simple más (sin hijos propios, ver su
            // docblock) — flatten() nunca la aplana especialmente (no es BoxFragment, cae al
            // `else { yield $child; }` genérico), así que solo hace falta desplazar su rect, igual
            // que ImageFragment/TextFragment.
            $leaf instanceof InlineBoxFragment => new InlineBoxFragment(
                $rect,
                $leaf->background,
                $leaf->borders,
                $leaf->opacity,
                $leaf->isFirstSlice,
                $leaf->isLastSlice,
                $leaf->borderRadius,
            ),
            default => throw new \LogicException('Unknown fragment leaf: ' . $leaf::class),
        };
    }

    /**
     * @param list<Fragment> $children
     * @return list<Fragment>
     */
    private function relocateChildren(array $children, float $deltaY): array
    {
        $result = [];
        foreach ($children as $child) {
            $result[] = $this->relocate($child, $child->rect()->y + $deltaY);
        }
        return $result;
    }
}
