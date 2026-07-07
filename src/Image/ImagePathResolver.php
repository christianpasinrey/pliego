<?php

declare(strict_types=1);

namespace Pliego\Image;

/**
 * M8-T6: extraído VERBATIM de Box\BoxTreeBuilder::resolvePath() (private, vivía solo ahí desde
 * M3-T2) a un colaborador compartido -- background-image (parseado en Css\, pero resuelto y
 * cargado en tiempo de PINTADO, ver Paint\Painter::paintBackgroundImage(), arquitectura M8-T6)
 * necesita EXACTAMENTE la misma resolución de ruta que un `<img src="...">` para que ambos caminos
 * (Box\BoxTreeBuilder para <img>, Paint\Painter para background-image) produzcan el MISMO string de
 * ruta absoluta para el MISMO fichero -- condición necesaria para que Pdf\ImageRegistry (que dedup
 * por ruta resuelta, ver su docblock) comparta un ÚNICO XObject cuando la misma imagen aparece una
 * vez como <img> y otra como background-image de un elemento hermano. `Image` es una capa hoja que
 * tanto `Box` como `Paint` ya tienen permiso de depender (ver deptrac.yaml), así que este método
 * vive aquí en vez de en cualquiera de las dos capas que lo consumen -- evita que ambas rutas de
 * resolución puedan divergir con el tiempo (antes de esta tarea, cada una habría tenido que
 * reimplementar la misma lógica de forma independiente).
 */
final class ImagePathResolver
{
    private function __construct()
    {
        // Solo un método estático -- sin estado, sin instancias.
    }

    /** src relativo se resuelve contra $basePath (Engine::basePath(), default getcwd()); un src ya
     * absoluto (unix "/..." o Windows "C:\..."/"C:/...") se usa tal cual. */
    public static function resolve(string $basePath, string $src): string
    {
        $isAbsolute = str_starts_with($src, '/') || preg_match('#^[a-zA-Z]:[\\\\/]#', $src) === 1;
        return $isAbsolute ? $src : rtrim($basePath, '/\\') . '/' . $src;
    }
}
