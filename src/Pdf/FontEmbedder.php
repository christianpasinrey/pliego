<?php

declare(strict_types=1);

namespace Pliego\Pdf;

use Pliego\Text\FontSubsetter;
use Pliego\Text\TtfFont;

/** Fuente compuesta Type0/CIDFontType2, Identity-H (ISO 32000-1 §9.7.4). */
final class FontEmbedder
{
    /**
     * Tablas que un rasterizador de PDF necesita para hintear/escalar glifos (M1-T9 controller
     * addition). name/post/GSUB/GPOS/kern/cmap se descartan: el shaping lo hace el engine y
     * CIDToGIDMap=Identity no necesita cmap. glyf/loca/head siempre se reconstruyen aparte
     * (ver FontSubsetter::subset()).
     *
     * @var list<string>
     */
    private const array RASTERIZER_TABLES = ['head', 'hhea', 'maxp', 'hmtx', 'cvt ', 'fpgm', 'prep'];

    private readonly int $fontObjectId;
    /** @var array<int, true> glyph ids usados */
    private array $usedGlyphs = [];
    /** @var array<int, int> glyph id => primer codepoint Unicode que lo produjo (para ToUnicode) */
    private array $codepointOf = [];

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

    /** Codifica texto como CIDs hex (Identity-H: CID = glyph id) y registra uso + ToUnicode. */
    public function encode(string $text): string
    {
        $hex = '';
        foreach (mb_str_split($text) as $char) {
            $codepoint = mb_ord($char);
            $glyphId = $this->font->glyphId($codepoint);
            $this->usedGlyphs[$glyphId] = true;
            $this->codepointOf[$glyphId] ??= $codepoint;
            $hex .= sprintf('%04X', $glyphId);
        }
        return $hex;
    }

    /** Escribe los objetos de fuente. Llamar una única vez, tras la última página. */
    public function flush(): void
    {
        $scale = 1000 / $this->font->unitsPerEm();

        ksort($this->usedGlyphs);
        $glyphIds = array_keys($this->usedGlyphs);
        $subsetBytes = (new FontSubsetter())->subset($this->font, $glyphIds, self::RASTERIZER_TABLES);
        $baseFontName = $this->subsetTag() . '+' . $this->baseFontName;

        $fileId = $this->writer->allocateObjectId();
        $length = strlen($subsetBytes);
        $this->writer->writeObject($fileId, "<< /Length $length /Length1 $length >>\nstream\n$subsetBytes\nendstream");

        [$xMin, $yMin, $xMax, $yMax] = array_map(
            static fn(int $v): int => (int) round($v * $scale),
            $this->font->boundingBox(),
        );
        $ascent = (int) round($this->font->ascender() * $scale);
        $descent = (int) round($this->font->descender() * $scale);
        $descriptorId = $this->writer->allocateObjectId();
        $this->writer->writeObject($descriptorId, "<< /Type /FontDescriptor /FontName /$baseFontName /Flags 32 /FontBBox [$xMin $yMin $xMax $yMax] /ItalicAngle 0 /Ascent $ascent /Descent $descent /CapHeight $ascent /StemV 80 /FontFile2 $fileId 0 R >>");

        $w = '';
        foreach ($glyphIds as $glyphId) {
            $width = (int) round($this->font->advanceOf($glyphId) * $scale);
            $w .= "$glyphId [$width] ";
        }
        $cidFontId = $this->writer->allocateObjectId();
        $this->writer->writeObject($cidFontId, "<< /Type /Font /Subtype /CIDFontType2 /BaseFont /$baseFontName /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor $descriptorId 0 R /CIDToGIDMap /Identity /DW 1000 /W [ $w] >>");

        $toUnicodeId = $this->writer->allocateObjectId();
        $cmap = $this->toUnicodeCMap();
        $cmapLength = strlen($cmap);
        $this->writer->writeObject($toUnicodeId, "<< /Length $cmapLength >>\nstream\n$cmap\nendstream");

        $this->writer->writeObject($this->fontObjectId, "<< /Type /Font /Subtype /Type0 /BaseFont /$baseFontName /Encoding /Identity-H /DescendantFonts [$cidFontId 0 R] /ToUnicode $toUnicodeId 0 R >>");
    }

    /**
     * Prefijo de subset (PDF spec §9.6.4.3, "Font Subsets"): 6 letras mayúsculas derivadas
     * determinísticamente del conjunto de glyph ids usados (base26 de un crc32), para que
     * lectores que cachean por BaseFont no confundan subsets distintos con el mismo nombre.
     */
    private function subsetTag(): string
    {
        $hash = crc32(implode(',', array_keys($this->usedGlyphs)));
        $tag = '';
        for ($i = 0; $i < 6; $i++) {
            $tag = chr(65 + ($hash % 26)) . $tag;
            $hash = intdiv($hash, 26);
        }
        return $tag;
    }

    /**
     * Stream CMap ToUnicode (ISO 32000-1 §9.10.3): una entrada bfchar por glyph id usado,
     * <CID hex4> <UTF-16BE del codepoint>. Referenciado desde el objeto Type0 vía /ToUnicode.
     */
    private function toUnicodeCMap(): string
    {
        ksort($this->codepointOf);
        $entries = '';
        foreach ($this->codepointOf as $glyphId => $codepoint) {
            $entries .= sprintf("<%04X> <%s>\n", $glyphId, $this->utf16beHex($codepoint));
        }
        $count = count($this->codepointOf);

        return "/CIDInit /ProcSet findresource begin\n"
            . "12 dict begin\n"
            . "begincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n"
            . "/CMapType 2 def\n"
            . "1 begincodespacerange\n"
            . "<0000> <FFFF>\n"
            . "endcodespacerange\n"
            . "$count beginbfchar\n"
            . $entries
            . "endbfchar\n"
            . "endcmap\n"
            . "CMapName currentdict /CMap defineresource pop\n"
            . "end\n"
            . "end";
    }

    /** UTF-16BE hex (4 dígitos, o 8 con par subrogado fuera del BMP — ISO 32000-1 §9.7.5.2). */
    private function utf16beHex(int $codepoint): string
    {
        if ($codepoint <= 0xFFFF) {
            return sprintf('%04X', $codepoint);
        }
        $reduced = $codepoint - 0x10000;
        $high = 0xD800 + ($reduced >> 10);
        $low = 0xDC00 + ($reduced & 0x3FF);
        return sprintf('%04X%04X', $high, $low);
    }
}
