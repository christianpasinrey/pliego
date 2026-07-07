<?php

declare(strict_types=1);

namespace Pliego\Layout;

use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\Color;
use Pliego\Layout\Fragment\BorderRadius;
use Pliego\Layout\Fragment\BorderSet;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\Fragment;
use Pliego\Layout\Fragment\ImageFragment;
use Pliego\Layout\Fragment\InlineBoxFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;

/**
 * Fragment tree -> plain nested arrays (JSON-friendly), for golden dump comparisons in tests
 * (M1-T10 brief). Keeps a fixed, explicit key order per fragment type so that json_encode()
 * output — and hence diffs against a golden file committed under tests/Unit/Layout/goldens/ —
 * stays deterministic and easy to read.
 *
 * Geometry (rect components, baselineY) is rounded to 2 decimals: layout math involves floats
 * (line-height fractions, glyph metric divisions) whose last bit can legitimately differ by an
 * epsilon across PHP builds/architectures without being an actual regression; 2 decimals of a
 * px value is well below anything visually or functionally meaningful.
 */
final class FragmentDumper
{
    /** @return array<string, mixed> */
    public function dump(Fragment $fragment): array
    {
        return match (true) {
            $fragment instanceof TextFragment => $this->dumpText($fragment),
            $fragment instanceof BoxFragment => $this->dumpBox($fragment),
            $fragment instanceof ImageFragment => $this->dumpImage($fragment),
            $fragment instanceof InlineBoxFragment => $this->dumpInlineBox($fragment),
            default => throw new \LogicException('Unknown fragment type: ' . $fragment::class),
        };
    }

    /**
     * M7-T4: mismas claves 'background'/'borders' que dumpBox() (mismo helper hex()/borders()),
     * más 'isFirstSlice'/'isLastSlice' (box-decoration-break:slice, ver InlineBoxFragment) — sin
     * 'children'/'atomic' (esta clase no tiene ninguno de los dos: no es un contenedor, ver su
     * docblock).
     * @return array<string, mixed>
     */
    private function dumpInlineBox(InlineBoxFragment $fragment): array
    {
        $dump = [
            'type' => 'inline-box',
            'rect' => $this->rect($fragment->rect),
            'background' => $fragment->background === null ? null : $this->hex($fragment->background),
            'borders' => $fragment->borders->isVisible() ? $this->borders($fragment->borders) : null,
            'isFirstSlice' => $fragment->isFirstSlice,
            'isLastSlice' => $fragment->isLastSlice,
        ];
        if (!$fragment->borderRadius->isZero()) {
            $dump['borderRadius'] = $this->borderRadius($fragment->borderRadius);
        }
        return $dump;
    }

    /**
     * M4-T6: 'atomic' añadido de forma ADITIVA (nueva clave, mismo lugar en todos los boxes,
     * ninguna clave existente se mueve ni cambia de valor) — refleja BoxFragment::$atomic (M4-T5),
     * hasta ahora invisible en los goldens. Sin él, un golden de un contenedor flex no podría
     * distinguir "este BoxFragment es la unidad de paginación indivisible que Paginator trata en
     * bloque" de un box normal aplanado hijo a hijo — información de layout real, no solo de
     * pintado, que pertenece al dump igual que rect/background/borders.
     *
     * M8-T1 housekeeping (M7 final-review finding): 'clipsChildren' se añade con el MISMO criterio
     * aditivo que 'atomic' arriba — refleja BoxFragment::$clipsChildren (M7-T5, overflow:hidden),
     * hasta ahora presente en el fragment tree real pero invisible en cualquier golden dump (ningún
     * golden existente podía distinguir "esta caja recorta a sus descendientes en pintado" de una
     * caja normal). Puramente aditivo: ninguna clave existente cambia de posición ni de valor, así
     * que TODOS los goldens preexistentes solo ganan esta clave (con valor `false` en cada uno,
     * ninguno de sus fixtures usa overflow:hidden) al regenerarlos — ver el report de esta tarea
     * para la lista exacta de goldens regenerados.
     * @return array<string, mixed>
     */
    private function dumpBox(BoxFragment $fragment): array
    {
        $dump = [
            'type' => 'box',
            'rect' => $this->rect($fragment->rect),
            'background' => $fragment->background === null ? null : $this->hex($fragment->background),
            'borders' => $fragment->borders->isVisible() ? $this->borders($fragment->borders) : null,
            'atomic' => $fragment->atomic,
            'clipsChildren' => $fragment->clipsChildren,
        ];
        // M8-T2: 'borderRadius' es ADITIVA solo cuando no es cero (a diferencia de 'atomic'/
        // 'clipsChildren', que SIEMPRE aparecen con su valor real, incl. false) -- omitirla del
        // todo en el caso común (radio cero, la inmensa mayoría de las cajas) mantiene los 14
        // goldens M1-M7 preexistentes byte-idénticos SIN regenerarlos (ninguno usa border-radius
        // todavía, ver el brief de esta tarea).
        if (!$fragment->borderRadius->isZero()) {
            $dump['borderRadius'] = $this->borderRadius($fragment->borderRadius);
        }
        $dump['children'] = array_map($this->dump(...), $fragment->children);
        return $dump;
    }

    /** @return array{tl: float, tr: float, br: float, bl: float} */
    private function borderRadius(BorderRadius $radius): array
    {
        return [
            'tl' => round($radius->tl, 2),
            'tr' => round($radius->tr, 2),
            'br' => round($radius->br, 2),
            'bl' => round($radius->bl, 2),
        ];
    }

    /** @return array<string, array{widthPx: float, color: string}|null> */
    private function borders(BorderSet $borders): array
    {
        return [
            'top' => $this->side($borders->top),
            'right' => $this->side($borders->right),
            'bottom' => $this->side($borders->bottom),
            'left' => $this->side($borders->left),
        ];
    }

    /** @return array{widthPx: float, color: string}|null */
    private function side(BorderSide $side): ?array
    {
        if ($side->style !== BorderStyle::Solid || $side->widthPx <= 0.0) {
            return null;
        }
        return [
            'widthPx' => round($side->widthPx, 2),
            'color' => $side->color === null ? '#000000' : $this->hex($side->color),
        ];
    }

    /** @return array<string, mixed> */
    private function dumpText(TextFragment $fragment): array
    {
        return [
            'type' => 'text',
            'rect' => $this->rect($fragment->rect),
            'text' => $fragment->text,
            'faceKey' => $fragment->faceKey,
            'underline' => $fragment->underline,
            'baselineY' => round($fragment->baselineY, 2),
        ];
    }

    /**
     * M3-T3: imageKey vuelca solo el basename() de la ruta, NUNCA la ruta completa — el imageKey
     * real es un path absoluto (basePath del Engine + src), que varía por máquina/checkout y
     * rompería el golden en cualquier otro entorno (mandato explícito del brief: "goldens
     * machine-independent").
     * @return array<string, mixed>
     */
    private function dumpImage(ImageFragment $fragment): array
    {
        return [
            'type' => 'image',
            'rect' => $this->rect($fragment->rect),
            'imageKey' => basename($fragment->imageKey),
        ];
    }

    /** @return list<float> */
    private function rect(Rect $rect): array
    {
        return [
            round($rect->x, 2),
            round($rect->y, 2),
            round($rect->width, 2),
            round($rect->height, 2),
        ];
    }

    private function hex(Color $color): string
    {
        return sprintf('#%02x%02x%02x', $color->r, $color->g, $color->b);
    }
}
