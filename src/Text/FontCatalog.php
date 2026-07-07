<?php

declare(strict_types=1);

namespace Pliego\Text;

/**
 * Registry of (family, weight, style) → TTF file, with weight/style fallback
 * so callers can always resolve a usable face even when a family only ships
 * a subset of its faces.
 *
 * NOTE (M1-T3 controller decision): the milestone interface contract listed
 * `select(string $family, int $weight, FontStyle $style)`, but `Pliego\Style\FontStyle`
 * lives in the Style layer and deptrac forbids Text -> Style (`Text: []`).
 * Text therefore uses its own minimal vocabulary — `bool $italic` — and the
 * Layout/Engine layers translate FontStyle -> bool at the call boundary.
 * Face keys use "family:weight:regular|italic" -> here "normal"/"italic".
 *
 * TtfFont instances are cached per file path (one parse per file); FontFace
 * instances are cached per resolved key so repeated select() calls for the
 * same face return the identical instance, which also makes faces() a
 * simple dedup list for embedding.
 */
final class FontCatalog
{
    /**
     * @var array<string, array<int, array<string, string>>> lowercased family => weight =>
     *     'normal'|'italic' => ttfPath. Keys are normalized via normalizeFamily() at register()
     *     time (the ONE canonical point, see that method's docblock) so a family is matched the
     *     same way no matter which casing the CSS author used to declare it (@font-face) or
     *     reference it (font-family) -- CSS font family names are case-insensitive end to end.
     */
    private array $registrations = [];

    /** @var array<string, TtfFont> ttfPath => loaded font */
    private array $fontCache = [];

    /** @var array<string, FontFace> faceKey => face */
    private array $usedFaces = [];

    /**
     * M7-T2: además de 'default' (DejaVu Sans, ya existente desde M1), registra las familias
     * genéricas 'monospace' (DejaVu Sans Mono) y 'serif' (DejaVu Serif) — mismo font family (misma
     * licencia, LICENSE-DejaVu.txt, ya cubre toda la familia DejaVu, no solo Sans) que dompdf
     * empaqueta en su propio vendor/ y que se localizó y copió a resources/fonts/ para esta tarea
     * (ver el report de M7-T2: "provenance" — checksum idéntico al TTF que dompdf distribuye,
     * confirmando que es la misma fuente, no una reconstrucción). No hay "búsqueda en runtime": a
     * diferencia de font() (que el caller de Engine puede usar para registrar SU PROPIA fuente en
     * una ruta arbitraria), las caras genéricas del motor se embeben como recurso propio, igual
     * que 'default' ya hacía — así que hasFamily('monospace')/hasFamily('serif') son SIEMPRE true
     * en este repo; el "fallback: falta mono -> default + warning" de
     * Layout\Text\FontFamilyResolver es una defensa para el caso hipotético de que un futuro
     * refactor retire estos ficheros de resources/fonts/, no una rama viva hoy.
     */
    public static function withDefaults(): self
    {
        $catalog = new self();
        $dir = __DIR__ . '/../../resources/fonts';
        $catalog->register('default', 400, false, $dir . '/DejaVuSans.ttf');
        $catalog->register('default', 700, false, $dir . '/DejaVuSans-Bold.ttf');
        $catalog->register('default', 400, true, $dir . '/DejaVuSans-Oblique.ttf');
        $catalog->register('default', 700, true, $dir . '/DejaVuSans-BoldOblique.ttf');
        $catalog->register('monospace', 400, false, $dir . '/DejaVuSansMono.ttf');
        $catalog->register('monospace', 700, false, $dir . '/DejaVuSansMono-Bold.ttf');
        $catalog->register('monospace', 400, true, $dir . '/DejaVuSansMono-Oblique.ttf');
        $catalog->register('monospace', 700, true, $dir . '/DejaVuSansMono-BoldOblique.ttf');
        $catalog->register('serif', 400, false, $dir . '/DejaVuSerif.ttf');
        $catalog->register('serif', 700, false, $dir . '/DejaVuSerif-Bold.ttf');
        $catalog->register('serif', 400, true, $dir . '/DejaVuSerif-Italic.ttf');
        $catalog->register('serif', 700, true, $dir . '/DejaVuSerif-BoldItalic.ttf');
        return $catalog;
    }

    public function register(string $family, int $weight, bool $italic, string $ttfPath): void
    {
        $this->registrations[$this->normalizeFamily($family)][$weight][$this->styleKey($italic)] = $ttfPath;
    }

    /**
     * M7-T2: existencia CASE-INSENSITIVE de una familia registrada — consumido por
     * Layout\Text\FontFamilyResolver para decidir, ANTES de llamar a select(), si un nombre de la
     * lista de fallback de font-family (o el destino de un genérico sans-serif/serif/monospace)
     * está realmente registrado, en vez de dejar que select() lo resuelva en silencio a 'default'
     * (select() no distingue "pediste una familia real que no existe" de "pediste 'default'
     * expresamente" — ambos caen al mismo fallback interno, útil para pintar, pero inútil para
     * decidir CUÁL de varios candidatos de la lista usar).
     */
    public function hasFamily(string $family): bool
    {
        return isset($this->registrations[$this->normalizeFamily($family)]);
    }

    /** Fallback: exact -> (weight,normal) -> (400,style) -> (400,normal) -> same chain in family 'default'. */
    public function select(string $family, int $weight, bool $italic): FontFace
    {
        $normalizedFamily = $this->normalizeFamily($family);
        $match = $this->resolve($normalizedFamily, $weight, $italic)
            ?? ($normalizedFamily !== 'default' ? $this->resolve('default', $weight, $italic) : null);

        if ($match === null) {
            throw new FontException(
                "No font face found for family '$family' (weight $weight) and no 'default' fallback registered",
            );
        }

        [$resolvedFamily, $resolvedWeight, $resolvedItalic, $ttfPath] = $match;
        $key = sprintf('%s:%d:%s', $resolvedFamily, $resolvedWeight, $this->styleKey($resolvedItalic));

        $font = $this->fontCache[$ttfPath] ??= TtfFont::fromFile($ttfPath);

        return $this->usedFaces[$key] ??= new FontFace($key, $font);
    }

    /** @return list<FontFace> caras usadas (para embedding) */
    public function faces(): array
    {
        return array_values($this->usedFaces);
    }

    /**
     * Resuelve una cara a partir de una key YA producida por select() (formato
     * "family:weight:normal|italic", ver constructor de $key en select()). Reutiliza el
     * mismo camino/caché de select(); pensado para consumidores que solo tienen el faceKey
     * de un TextFragment (p.ej. Painter al pintar el subrayado). Simplificación documentada:
     * si $family contuviera ':' el parseo se rompería — no ocurre con las familias usadas en
     * M1 (nombres CSS habituales sin ':').
     */
    public function faceByKey(string $key): FontFace
    {
        [$family, $weight, $style] = explode(':', $key, 3);
        return $this->select($family, (int) $weight, $style === 'italic');
    }

    /**
     * @param string $family ALREADY normalized (lowercased) by the sole caller, select() — this
     *     private method never touches $registrations with a raw, possibly author-cased key.
     * @return array{string, int, bool, string}|null
     */
    private function resolve(string $family, int $weight, bool $italic): ?array
    {
        $seen = [];
        foreach ([[$weight, $italic], [$weight, false], [400, $italic], [400, false]] as [$candidateWeight, $candidateItalic]) {
            $styleKey = $this->styleKey($candidateItalic);
            $signature = $candidateWeight . ':' . $styleKey;
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;

            $ttfPath = $this->registrations[$family][$candidateWeight][$styleKey] ?? null;
            if ($ttfPath !== null) {
                return [$family, $candidateWeight, $candidateItalic, $ttfPath];
            }
        }
        return null;
    }

    private function styleKey(bool $italic): string
    {
        return $italic ? 'italic' : 'normal';
    }

    /**
     * THE canonical normalization point (fixes the silent-fallback defect where an @font-face
     * family registered with author casing, e.g. 'MiSerif', would never match a font-family
     * usage with different casing, e.g. 'miserif' -- CSS font family names are case-insensitive,
     * per CSS Fonts §5.3.1, but register()/select() previously did an exact array-key lookup).
     * mb_strtolower (not strtolower) so non-ASCII family names fold correctly too.
     */
    private function normalizeFamily(string $family): string
    {
        return mb_strtolower($family);
    }
}
