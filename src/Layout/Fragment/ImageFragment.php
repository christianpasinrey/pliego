<?php

declare(strict_types=1);

namespace Pliego\Layout\Fragment;

use Pliego\Layout\Geometry\Rect;

/**
 * M3-T3: hoja de imagen (css-images-3 replaced content), análoga a TextFragment pero sin texto —
 * el contenido pintable es la propia imagen decodificada, referenciada por $imageKey (la misma
 * ruta ya resuelta y verificada que trae ImageBox::$src, reutilizada tal cual como clave de dedup
 * en Pdf\ImageRegistry, M3-T5). $rect es la CONTENT box del replaced element (dentro de cualquier
 * padding/border de la propia <img>, ver BlockFlowContext) — Paginator la trata como una hoja de
 * más (push-down como TextFragment, M3-T3 brief: una imagen más alta que la página se queda,
 * documentado, no partida).
 */
final readonly class ImageFragment implements Fragment
{
    public function __construct(
        public Rect $rect,
        public string $imageKey,
    ) {}

    public function rect(): Rect
    {
        return $this->rect;
    }
}
