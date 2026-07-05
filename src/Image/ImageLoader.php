<?php

declare(strict_types=1);

namespace Pliego\Image;

/** Punto de entrada: detecta el formato por sus magic bytes y delega en el decodificador correspondiente. */
final class ImageLoader
{
    public function load(string $path): DecodedImage
    {
        if (!is_readable($path)) {
            throw new ImageException("Cannot read image file: $path");
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new ImageException("Cannot read image file: $path");
        }

        if (str_starts_with($bytes, "\xFF\xD8")) {
            return JpegImage::fromBytes($bytes);
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return PngImage::fromBytes($bytes);
        }
        throw new ImageException("Unsupported image format: $path");
    }
}
