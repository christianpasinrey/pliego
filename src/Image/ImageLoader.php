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
 * M5-T1 (housekeeping, diferido de M3): la memoización usa DOS claves por imagen decodificada:
 * el $path crudo tal cual lo pasó el caller, y su `realpath($path)` (cuando difiere del crudo).
 * La primera (raw) es la que preserva la garantía TOCTOU documentada arriba: es estable frente
 * al borrado del fichero, porque nunca depende de que realpath() vuelva a resolver nada. La
 * segunda (realpath) es la que preserva la propiedad de dedup entre grafías distintas del MISMO
 * fichero ('tiny.jpg' vs './tiny.jpg', o dos rutas relativas que atraviesan basePath de forma
 * diferente): comparten realpath() mientras el fichero exista, así que comparten DecodedImage
 * aunque lleguen con un $path crudo distinto cada vez.
 *
 * Lookup order: raw primero, realpath después. Esto es lo que cierra el defecto original (visto
 * por el revisor con una junction NTFS): una imagen cargada a través de un path que resuelve por
 * symlink/junction quedaba SOLO bajo su clave realpath; si el fichero destino se borraba antes de
 * la segunda llamada, realpath() fallaba y el fallback usaba el $path crudo — una clave que NUNCA
 * se había guardado — provocando un cache miss y relanzando ImageException en vez de servir la
 * instancia cacheada. Guardar bajo ambas claves en el momento del load elimina esa ventana: la
 * clave raw siempre está disponible para el mismo caller, sin importar qué le pase a realpath()
 * después.
 *
 * Fallback documentado: si realpath($path) falla (fichero inexistente en el momento de la
 * llamada, symlink roto, etc. — devuelve `false`) o coincide con el propio $path, simplemente no
 * se guarda una segunda entrada — nunca se lanza ni se trata como error aquí, la siguiente
 * comprobación (`is_readable`) sigue siendo la que decide si $path es utilizable.
 */
final class ImageLoader
{
    /** @var array<string, DecodedImage> clave de dedup: raw path Y/O realpath (ver docblock de clase) => imagen ya decodificada */
    private array $cache = [];

    public function load(string $path): DecodedImage
    {
        if (isset($this->cache[$path])) {
            return $this->cache[$path];
        }
        $real = realpath($path);
        if ($real !== false && $real !== $path && isset($this->cache[$real])) {
            return $this->cache[$real];
        }

        if (!is_readable($path)) {
            throw new ImageException("Cannot read image file: $path");
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new ImageException("Cannot read image file: $path");
        }

        if (str_starts_with($bytes, "\xFF\xD8")) {
            $image = JpegImage::fromBytes($bytes);
        } elseif (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            $image = PngImage::fromBytes($bytes);
        } else {
            throw new ImageException("Unsupported image format: $path");
        }

        $this->cache[$path] = $image;
        if ($real !== false && $real !== $path) {
            $this->cache[$real] = $image;
        }
        return $image;
    }
}
