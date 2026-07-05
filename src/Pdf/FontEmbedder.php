<?php

declare(strict_types=1);

namespace Pliego\Pdf;

use Pliego\Text\TtfFont;

/** Fuente compuesta Type0/CIDFontType2, Identity-H (ISO 32000-1 §9.7.4). */
final class FontEmbedder
{
    private readonly int $fontObjectId;
    /** @var array<int, true> glyph ids usados */
    private array $usedGlyphs = [];

    public function __construct(
        private readonly PdfWriter $writer,
        private readonly TtfFont $font,
        private readonly string $baseFontName,
    ) {
        $this->fontObjectId = $writer->allocateObjectId();
    }

    public function objectId(): int
    {
        return $this->fontObjectId;
    }

    /** Codifica texto como CIDs hex (Identity-H: CID = glyph id) y registra uso. */
    public function encode(string $text): string
    {
        $hex = '';
        foreach (mb_str_split($text) as $char) {
            $glyphId = $this->font->glyphId(mb_ord($char));
            $this->usedGlyphs[$glyphId] = true;
            $hex .= sprintf('%04X', $glyphId);
        }
        return $hex;
    }

    /** Escribe los objetos de fuente. Llamar una única vez, tras la última página. */
    public function flush(): void
    {
        $scale = 1000 / $this->font->unitsPerEm();
        $fileId = $this->writer->allocateObjectId();
        $bytes = $this->font->bytes();
        $length = strlen($bytes);
        $this->writer->writeObject($fileId, "<< /Length $length /Length1 $length >>\nstream\n$bytes\nendstream");

        [$xMin, $yMin, $xMax, $yMax] = array_map(
            static fn(int $v): int => (int) round($v * $scale),
            $this->font->boundingBox(),
        );
        $ascent = (int) round($this->font->ascender() * $scale);
        $descent = (int) round($this->font->descender() * $scale);
        $descriptorId = $this->writer->allocateObjectId();
        $this->writer->writeObject($descriptorId, "<< /Type /FontDescriptor /FontName /{$this->baseFontName} /Flags 32 /FontBBox [$xMin $yMin $xMax $yMax] /ItalicAngle 0 /Ascent $ascent /Descent $descent /CapHeight $ascent /StemV 80 /FontFile2 $fileId 0 R >>");

        ksort($this->usedGlyphs);
        $w = '';
        foreach (array_keys($this->usedGlyphs) as $glyphId) {
            $width = (int) round($this->font->advanceOf($glyphId) * $scale);
            $w .= "$glyphId [$width] ";
        }
        $cidFontId = $this->writer->allocateObjectId();
        $this->writer->writeObject($cidFontId, "<< /Type /Font /Subtype /CIDFontType2 /BaseFont /{$this->baseFontName} /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor $descriptorId 0 R /CIDToGIDMap /Identity /DW 1000 /W [ $w] >>");

        $this->writer->writeObject($this->fontObjectId, "<< /Type /Font /Subtype /Type0 /BaseFont /{$this->baseFontName} /Encoding /Identity-H /DescendantFonts [$cidFontId 0 R] >>");
    }
}
