<?php

declare(strict_types=1);

namespace Pliego\Image;

/**
 * Datos ya preparados para escribir el XObject de imagen (ISO 32000-1 §8.9.5): el stream
 * principal más, opcionalmente, un SMask de 8 bits en escala de grises (§11.6.5.3) cuando la
 * imagen original tenía canal alfa.
 */
final readonly class ImagePdfData
{
    public function __construct(
        public string $filter,          // 'DCTDecode' | 'FlateDecode'
        public string $colorSpace,      // 'DeviceRGB' | 'DeviceGray'
        public int $bitsPerComponent,   // 8 (M3)
        public string $bytes,           // stream principal
        public ?string $smaskBytes,     // alpha 8-bit FlateDecode o null
    ) {}
}
