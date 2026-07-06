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
 *
 * M5-T1 (housekeeping, diferido de M3): la clave de memoización es `realpath($path) ?: $path`
 * (documentado, no el $path crudo) — dos strings DISTINTOS que resuelven al MISMO fichero
 * ('tiny.jpg' vs './tiny.jpg', o dos rutas relativas que atraviesan basePath de forma diferente)
 * ahora comparten la misma DecodedImage en vez de decodificar el mismo fichero dos veces.
 * Fallback documentado: si realpath() falla (fichero inexistente en el momento de la llamada,
 * symlink roto, etc. — devuelve `false`), se usa el $path crudo tal cual como clave, exactamente
 * el comportamiento PRE-M5-T1 para ese caso — nunca se lanza ni se trata como error aquí, la
 * siguiente comprobación (`is_readable`) sigue siendo la que decide si $path es utilizable.
 */
final class ImageLoader
{
    /** @var array<string, DecodedImage> clave de dedup (ver docblock de clase) => imagen ya decodificada */
    private array $cache = [];

    public function load(string $path): DecodedImage
    {
        $key = realpath($path) ?: $path;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        if (!is_readable($path)) {
            throw new ImageException("Cannot read image file: $path");
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new ImageException("Cannot read image file: $path");
        }

        if (str_starts_with($bytes, "\xFF\xD8")) {
            return $this->cache[$key] = JpegImage::fromBytes($bytes);
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return $this->cache[$key] = PngImage::fromBytes($bytes);
        }
        throw new ImageException("Unsupported image format: $path");
    }
}
