<?php

declare(strict_types=1);

namespace Pliego\Image;

/**
 * Punto de entrada: detecta el formato por sus magic bytes y delega en el decodificador
 * correspondiente. Memoiza por path (DecodedImage es readonly/inmutable, por lo que compartir
 * la misma instancia entre llamadas es seguro): la misma instancia de ImageLoader se pasa tanto
 * a BoxTreeBuilder (layout) como a ImageRegistry (paint), así que cada imagen distinta se decodifica
 * UNA vez por render, sin importar cuántas veces aparezca un <img> con el mismo src. Efecto
 * colateral: también cierra el TOCTOU entre build y paint (si el fichero se borra entre medias,
 * la segunda llamada sirve la instancia cacheada en vez de relanzar la lectura).
 */
final class ImageLoader
{
    /** @var array<string, DecodedImage> path => imagen ya decodificada */
    private array $cache = [];

    public function load(string $path): DecodedImage
    {
        if (isset($this->cache[$path])) {
            return $this->cache[$path];
        }

        if (!is_readable($path)) {
            throw new ImageException("Cannot read image file: $path");
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new ImageException("Cannot read image file: $path");
        }

        if (str_starts_with($bytes, "\xFF\xD8")) {
            return $this->cache[$path] = JpegImage::fromBytes($bytes);
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return $this->cache[$path] = PngImage::fromBytes($bytes);
        }
        throw new ImageException("Unsupported image format: $path");
    }
}
