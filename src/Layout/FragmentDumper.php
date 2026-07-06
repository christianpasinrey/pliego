<?php

declare(strict_types=1);

namespace Pliego\Layout;

use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\Color;
use Pliego\Layout\Fragment\BorderSet;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\Fragment;
use Pliego\Layout\Fragment\ImageFragment;
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
            default => throw new \LogicException('Unknown fragment type: ' . $fragment::class),
        };
    }

    /** @return array<string, mixed> */
    private function dumpBox(BoxFragment $fragment): array
    {
        return [
            'type' => 'box',
            'rect' => $this->rect($fragment->rect),
            'background' => $fragment->background === null ? null : $this->hex($fragment->background),
            'borders' => $fragment->borders->isVisible() ? $this->borders($fragment->borders) : null,
            'children' => array_map($this->dump(...), $fragment->children),
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
