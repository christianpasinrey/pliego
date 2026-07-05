<?php

declare(strict_types=1);

namespace Pliego\Box;

use Pliego\Style\ComputedStyle;

/**
 * M3-T2: replaced block-level box para <img> (css-images-3: el contenido reemplazado no participa
 * del flujo inline en M3, ver brief). $src ya llega RESUELTA contra el basePath del Engine y
 * VERIFICADA — BoxTreeBuilder nunca construye un ImageBox para una imagen remota (http/https),
 * ausente o con formato no soportado por Image\ImageLoader; esos casos producen un warning y la
 * caja simplemente se omite (nunca una excepción hasta aquí).
 */
final readonly class ImageBox
{
    public function __construct(
        public ComputedStyle $style,
        public string $src,
        public int $intrinsicWidth,
        public int $intrinsicHeight,
        public ?float $attrWidth,
        public ?float $attrHeight,
    ) {}
}
