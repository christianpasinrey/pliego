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
    /** @var array<string, array<int, array<string, string>>> family => weight => 'normal'|'italic' => ttfPath */
    private array $registrations = [];

    /** @var array<string, TtfFont> ttfPath => loaded font */
    private array $fontCache = [];

    /** @var array<string, FontFace> faceKey => face */
    private array $usedFaces = [];

    public static function withDefaults(): self
    {
        $catalog = new self();
        $dir = __DIR__ . '/../../resources/fonts';
        $catalog->register('default', 400, false, $dir . '/DejaVuSans.ttf');
        $catalog->register('default', 700, false, $dir . '/DejaVuSans-Bold.ttf');
        $catalog->register('default', 400, true, $dir . '/DejaVuSans-Oblique.ttf');
        $catalog->register('default', 700, true, $dir . '/DejaVuSans-BoldOblique.ttf');
        return $catalog;
    }

    public function register(string $family, int $weight, bool $italic, string $ttfPath): void
    {
        $this->registrations[$family][$weight][$this->styleKey($italic)] = $ttfPath;
    }

    /** Fallback: exact -> (weight,normal) -> (400,style) -> (400,normal) -> same chain in family 'default'. */
    public function select(string $family, int $weight, bool $italic): FontFace
    {
        $match = $this->resolve($family, $weight, $italic)
            ?? ($family !== 'default' ? $this->resolve('default', $weight, $italic) : null);

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

    /** @return array{string, int, bool, string}|null */
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
}
