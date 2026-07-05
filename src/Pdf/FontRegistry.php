<?php

declare(strict_types=1);

namespace Pliego\Pdf;

use Pliego\Text\FontCatalog;
use Pliego\Text\TtfFont;

/**
 * Sustituye al FontEmbedder único del Engine (M1-T6): crea un FontEmbedder bajo demanda por
 * cada cara (FontFace) realmente usada durante el pintado, con su propio recurso /Fn
 * (F1..Fn, en orden de creación) y su propio BaseFont/subset/ToUnicode. Solo las caras
 * efectivamente pedidas via embedderFor() acaban embebidas en flushAll() — un catálogo con
 * caras registradas pero nunca usadas no genera FontFile2 para ellas.
 */
final class FontRegistry
{
    /** @var array<string, FontEmbedder> faceKey => embedder */
    private array $embedders = [];
    /** @var array<string, string> faceKey => resource name ("F1", "F2", ...), en orden de creación */
    private array $resourceNames = [];
    private int $nextResourceIndex = 1;

    public function __construct(
        private readonly PdfWriter $writer,
        private readonly FontCatalog $catalog,
    ) {}

    /** Devuelve (creando si hace falta) el FontEmbedder de la cara dada. */
    public function embedderFor(string $faceKey): FontEmbedder
    {
        if (isset($this->embedders[$faceKey])) {
            return $this->embedders[$faceKey];
        }

        $face = $this->catalog->faceByKey($faceKey);
        $this->resourceNames[$faceKey] = 'F' . $this->nextResourceIndex++;

        return $this->embedders[$faceKey] = new FontEmbedder($this->writer, $face->font, $this->baseFontNameFor($face->font, $faceKey));
    }

    /** Nombre de recurso ("F1", "F2", ...) de la cara dada, creándola si hace falta. */
    public function resourceNameFor(string $faceKey): string
    {
        $this->embedderFor($faceKey);
        return $this->resourceNames[$faceKey];
    }

    /** @return array<string, int> resourceName => objectId de TODAS las caras usadas hasta el momento */
    public function pageResources(): array
    {
        $resources = [];
        foreach ($this->resourceNames as $faceKey => $name) {
            $resources[$name] = $this->embedders[$faceKey]->objectId();
        }
        return $resources;
    }

    /** Subset + ToUnicode de cada cara usada. Llamar una única vez, tras la última página. */
    public function flushAll(): void
    {
        foreach ($this->embedders as $embedder) {
            $embedder->flush();
        }
    }

    /**
     * Nombre base (pre-subset-tag) para /BaseFont: el nombre del fichero TTF sin extensión
     * cuando se conoce (TtfFont::fromFile(), el caso normal vía FontCatalog), o una forma
     * saneada del faceKey en el caso raro de una cara sin ruta de origen (TtfFont::fromString()).
     * En ambos casos se sanea el resultado (ver sanitizeName()): un /BaseFont es un nombre de
     * PDF (ISO 32000-1 §7.3.5) y no admite espacios ni delimitadores — un fichero de fuente
     * como "My Custom Font.ttf" produciría de otro modo un token de PDF inválido.
     */
    private function baseFontNameFor(TtfFont $font, string $faceKey): string
    {
        $path = $font->sourcePath();
        if ($path !== null) {
            return $this->sanitizeName(pathinfo($path, PATHINFO_FILENAME));
        }
        return $this->sanitizeName('Font' . str_replace([':', '.'], '_', $faceKey));
    }

    /**
     * Reemplaza cualquier carácter fuera de [A-Za-z0-9_-] (espacios y delimitadores de PDF
     * como ()<>[]/%# incluidos — ISO 32000-1 §7.3.5, tabla 42) por '-', para que el resultado
     * sea siempre un nombre de PDF válido de una sola pieza.
     */
    private function sanitizeName(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '-', $name);
    }
}
