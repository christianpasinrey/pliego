<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/**
 * M8-T7 (css-fonts-4 §4 reducido): un @font-face YA resuelto por StylesheetParser --
 * $family (comillas despojadas), $srcPath es la ruta LOCAL tal cual apareció en el PRIMER
 * url() usable de la lista de fallback de `src` (ver StylesheetParser::parseFontFaceSrc()) --
 * TODAVÍA sin resolver contra basePath (eso es trabajo de Engine, igual que <img src> y
 * background-image, ver Image\ImagePathResolver -- M8-T6), $weight es el valor tal cual
 * (400/700/cualquier numérico entre 100-900; un rango "100 900" ya colapsó al PRIMER valor,
 * con warning, en el propio parser) -- el mapeo a los dos slots que Text\FontCatalog realmente
 * soporta por family/style (400/700) es de Engine, no de este VO -- y $italic es el mismo
 * vocabulario mínimo booleano que FontCatalog::register() ya usa (evita que Css dependa de
 * Style\FontStyle -- deptrac: `Css: [Vendor]`, sin Style).
 */
final readonly class FontFaceRule
{
    public function __construct(
        public string $family,
        public string $srcPath,
        public int $weight,
        public bool $italic,
    ) {}
}
