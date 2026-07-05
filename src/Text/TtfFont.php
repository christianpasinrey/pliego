<?php

declare(strict_types=1);

namespace Pliego\Text;

/**
 * Minimal TrueType parser: sfnt table directory plus the head, hhea, maxp,
 * hmtx and cmap (format 4) tables. Reference: OpenType spec §5 (sfnt) and
 * Apple TrueType Reference Manual. Whole-file embedding; subsetting is M1.
 */
final class TtfFont
{
    /** @var array<string, array{offset: int, length: int}> */
    private array $tables = [];
    private int $unitsPerEm;
    private int $ascender;
    private int $descender;
    private int $numGlyphs;
    /** @var array{int, int, int, int} xMin, yMin, xMax, yMax */
    private array $bbox;
    /** @var list<int> advance width per hmtx entry */
    private array $advances = [];
    /** @var array<int, int> codepoint => glyph id (lazy, per codepoint) */
    private array $cmapCache = [];
    private int $cmapOffset;

    private function __construct(private readonly string $data, private readonly ?string $sourcePath = null)
    {
        $numTables = $this->uint16(4);
        for ($i = 0; $i < $numTables; $i++) {
            $record = 12 + $i * 16;
            $tag = substr($this->data, $record, 4);
            $this->tables[$tag] = [
                'offset' => $this->uint32($record + 8),
                'length' => $this->uint32($record + 12),
            ];
        }
        $head = $this->requireTable('head');
        $this->unitsPerEm = $this->uint16($head + 18);
        $this->bbox = [
            $this->int16($head + 36), $this->int16($head + 38),
            $this->int16($head + 40), $this->int16($head + 42),
        ];
        $hhea = $this->requireTable('hhea');
        $this->ascender = $this->int16($hhea + 4);
        $this->descender = $this->int16($hhea + 6);
        $numberOfHMetrics = $this->uint16($hhea + 34);
        $this->numGlyphs = $this->uint16($this->requireTable('maxp') + 4);
        $hmtx = $this->requireTable('hmtx');
        for ($i = 0; $i < $numberOfHMetrics; $i++) {
            $this->advances[] = $this->uint16($hmtx + $i * 4);
        }
        $this->cmapOffset = $this->findCmapFormat4();
    }

    public static function fromFile(string $path): self
    {
        $data = file_get_contents($path);
        if ($data === false) {
            throw new FontException("Cannot read font file: $path");
        }
        return new self($data, $path);
    }

    /** Parses an in-memory sfnt (e.g. the output of FontSubsetter::subset()). */
    public static function fromString(string $data): self
    {
        return new self($data);
    }

    /** Path passed to fromFile(), or null when loaded via fromString() (e.g. a subset). */
    public function sourcePath(): ?string
    {
        return $this->sourcePath;
    }

    public function unitsPerEm(): int
    {
        return $this->unitsPerEm;
    }

    public function ascender(): int
    {
        return $this->ascender;
    }

    public function descender(): int
    {
        return $this->descender;
    }

    public function glyphCount(): int
    {
        return $this->numGlyphs;
    }

    /** @return array{int, int, int, int} */
    public function boundingBox(): array
    {
        return $this->bbox;
    }

    public function bytes(): string
    {
        return $this->data;
    }

    /**
     * post table (OpenType spec §5.6 "post"): underlinePosition/underlineThickness, int16
     * @+8/@+10 respectively, common to every post table version (0.5/1.0/2.0/3.0). Returns
     * null when the font has no post table at all — callers fall back to a documented
     * em-relative default (see Paint\Painter).
     *
     * @return array{int, int}|null [underlinePosition, underlineThickness] in font units
     */
    public function underlineMetrics(): ?array
    {
        $post = $this->tables['post']['offset'] ?? null;
        if ($post === null) {
            return null;
        }
        return [$this->int16($post + 8), $this->int16($post + 10)];
    }

    public function advanceOf(int $glyphId): int
    {
        // hmtx: glyphs beyond numberOfHMetrics reuse the last advance width.
        return $this->advances[min($glyphId, count($this->advances) - 1)];
    }

    /**
     * Full sfnt table directory (tag => {offset,length} into bytes()), for FontSubsetter.
     *
     * @return array<string, array{offset: int, length: int}>
     */
    public function tableDirectory(): array
    {
        return $this->tables;
    }

    /** Raw bytes of one table as stored in the sfnt, or null if the font has no such table. */
    public function tableBytes(string $tag): ?string
    {
        $table = $this->tables[$tag] ?? null;
        if ($table === null) {
            return null;
        }
        return substr($this->data, $table['offset'], $table['length']);
    }

    /** head @+50 (OpenType spec §5.2 "head"): 0 = short (loca values × 2), 1 = long offsets. */
    public function indexToLocFormat(): int
    {
        return $this->int16($this->requireTable('head') + 50);
    }

    /**
     * loca table (OpenType spec §5.3 "loca"): byte offsets into glyf, one per glyph plus a
     * trailing sentinel (numGlyphs + 1 entries total).
     *
     * @return list<int>
     */
    public function locaOffsets(): array
    {
        $loca = $this->requireTable('loca');
        $long = $this->indexToLocFormat() === 1;
        $offsets = [];
        for ($i = 0; $i <= $this->numGlyphs; $i++) {
            $offsets[] = $long ? $this->uint32($loca + $i * 4) : $this->uint16($loca + $i * 2) * 2;
        }
        return $offsets;
    }

    /** Raw glyf bytes for one glyph id (empty string for glyphs with no outline data). */
    public function glyphDataFor(int $glyphId): string
    {
        $offsets = $this->locaOffsets();
        $glyf = $this->requireTable('glyf');
        $start = $offsets[$glyphId] ?? throw new FontException("Glyph id out of range: $glyphId");
        $end = $offsets[$glyphId + 1] ?? throw new FontException("Glyph id out of range: $glyphId");
        return substr($this->data, $glyf + $start, $end - $start);
    }

    /**
     * Direct (non-recursive) component glyph ids of a composite glyph (glyf table,
     * numberOfContours < 0 — OpenType spec §5.3.3 "Glyph Headers" / composite glyph
     * description). Returns [] for simple or empty glyphs. Callers that need the transitive
     * closure (a component that is itself composite) must recurse.
     *
     * @return list<int>
     */
    public function compositeComponents(int $glyphId): array
    {
        $offsets = $this->locaOffsets();
        $start = $offsets[$glyphId] ?? throw new FontException("Glyph id out of range: $glyphId");
        $end = $offsets[$glyphId + 1] ?? throw new FontException("Glyph id out of range: $glyphId");
        if ($end <= $start) {
            return [];
        }
        $glyf = $this->requireTable('glyf');
        $base = $glyf + $start;
        if ($this->int16($base) >= 0) {
            return []; // simple glyph
        }
        $components = [];
        $pos = $base + 10; // past numberOfContours (2) + bbox (4 × int16)
        while (true) {
            $flags = $this->uint16($pos);
            $components[] = $this->uint16($pos + 2);
            $pos += 4;
            $pos += ($flags & 0x0001) !== 0 ? 4 : 2; // ARG_1_AND_2_ARE_WORDS (bit 0)
            if (($flags & 0x0008) !== 0) {
                $pos += 2; // WE_HAVE_A_SCALE (bit 3)
            } elseif (($flags & 0x0040) !== 0) {
                $pos += 4; // WE_HAVE_AN_X_AND_Y_SCALE (bit 6)
            } elseif (($flags & 0x0080) !== 0) {
                $pos += 8; // WE_HAVE_A_TWO_BY_TWO (bit 7)
            }
            if (($flags & 0x0020) === 0) {
                break; // no MORE_COMPONENTS (bit 5)
            }
        }
        return $components;
    }

    /** cmap format 4 lookup (OpenType spec, "Segment mapping to delta values"). */
    public function glyphId(int $codepoint): int
    {
        if ($codepoint > 0xFFFF) {
            return 0;
        }
        if (isset($this->cmapCache[$codepoint])) {
            return $this->cmapCache[$codepoint];
        }
        $t = $this->cmapOffset;
        $segCount = intdiv($this->uint16($t + 6), 2);
        $endCodes = $t + 14;
        $startCodes = $endCodes + $segCount * 2 + 2;
        $idDeltas = $startCodes + $segCount * 2;
        $idRangeOffsets = $idDeltas + $segCount * 2;
        for ($seg = 0; $seg < $segCount; $seg++) {
            if ($this->uint16($endCodes + $seg * 2) < $codepoint) {
                continue;
            }
            $start = $this->uint16($startCodes + $seg * 2);
            if ($start > $codepoint) {
                return $this->cmapCache[$codepoint] = 0;
            }
            $rangeOffset = $this->uint16($idRangeOffsets + $seg * 2);
            if ($rangeOffset === 0) {
                $glyph = ($codepoint + $this->int16($idDeltas + $seg * 2)) & 0xFFFF;
            } else {
                $addr = $idRangeOffsets + $seg * 2 + $rangeOffset + ($codepoint - $start) * 2;
                $glyph = $this->uint16($addr);
                if ($glyph !== 0) {
                    $glyph = ($glyph + $this->int16($idDeltas + $seg * 2)) & 0xFFFF;
                }
            }
            return $this->cmapCache[$codepoint] = $glyph;
        }
        return $this->cmapCache[$codepoint] = 0;
    }

    private function findCmapFormat4(): int
    {
        $cmap = $this->requireTable('cmap');
        $numSubtables = $this->uint16($cmap + 2);
        for ($i = 0; $i < $numSubtables; $i++) {
            $rec = $cmap + 4 + $i * 8;
            $platform = $this->uint16($rec);
            $encoding = $this->uint16($rec + 2);
            $offset = $cmap + $this->uint32($rec + 4);
            if ($platform === 3 && $encoding === 1 && $this->uint16($offset) === 4) {
                return $offset;
            }
        }
        throw new FontException('No Windows Unicode BMP (format 4) cmap subtable found');
    }

    private function requireTable(string $tag): int
    {
        return $this->tables[$tag]['offset'] ?? throw new FontException("Missing required table: $tag");
    }

    private function uint16(int $offset): int
    {
        /** @var array{1: int} $v */
        $v = unpack('n', substr($this->data, $offset, 2));
        return $v[1];
    }

    private function int16(int $offset): int
    {
        $v = $this->uint16($offset);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function uint32(int $offset): int
    {
        /** @var array{1: int} $v */
        $v = unpack('N', substr($this->data, $offset, 4));
        return $v[1];
    }
}
