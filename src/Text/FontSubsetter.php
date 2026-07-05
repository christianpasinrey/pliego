<?php

declare(strict_types=1);

namespace Pliego\Text;

/**
 * TTF subsetting via the keep-gid technique: glyph ids are NEVER renumbered, so a
 * Type0/CIDFontType2 font using Identity-H (CID = glyph id) stays valid against the subset
 * without touching any content stream that already encoded text against the original font.
 * Unused glyphs simply become zero-length entries in glyf/loca; every other table (cmap,
 * hmtx, hhea, maxp, name, post, OS/2, ...) is copied verbatim (M1-T8 brief).
 *
 * Rebuild steps: (1) expand the requested glyph id set with the transitive closure of
 * composite glyph components (glyf composite records — OpenType spec §5.3.3); (2) rebuild
 * glyf keeping only the resulting set (others become 0 bytes) and loca with new offsets,
 * always in LONG format (head indexToLocFormat forced to 1 — simplest and always safe,
 * per brief); (3) zero head's checksumAdjustment (recomputing the whole-file checksum is
 * optional per the OpenType spec and out of scope here); (4) reassemble the sfnt: table
 * directory sorted by tag with recomputed offsets/checksums, tables padded to a 4-byte
 * boundary (OpenType spec §5 "sfnt").
 */
final class FontSubsetter
{
    /** @param list<int> $glyphIds glyph ids to keep, in addition to .notdef (gid 0) */
    public function subset(TtfFont $font, array $glyphIds): string
    {
        $keep = $this->expandWithComponents($font, $glyphIds);
        [$glyf, $loca] = $this->rebuildGlyfAndLoca($font, $keep);

        $head = $font->tableBytes('head') ?? throw new FontException('Missing head table for subsetting');
        $head = substr_replace($head, "\x00\x00\x00\x00", 8, 4); // checksumAdjustment = 0
        $head = substr_replace($head, pack('n', 1), 50, 2); // indexToLocFormat = long

        $tables = [];
        foreach ($font->tableDirectory() as $tag => $_) {
            $tables[$tag] = match ($tag) {
                'glyf' => $glyf,
                'loca' => $loca,
                'head' => $head,
                default => $font->tableBytes($tag) ?? '',
            };
        }

        return $this->assembleSfnt($tables);
    }

    /**
     * @param list<int> $glyphIds
     * @return array<int, true>
     */
    private function expandWithComponents(TtfFont $font, array $glyphIds): array
    {
        $keep = [0 => true]; // .notdef is always present
        $stack = $glyphIds;
        while ($stack !== []) {
            $gid = array_pop($stack);
            if (isset($keep[$gid])) {
                continue;
            }
            $keep[$gid] = true;
            foreach ($font->compositeComponents($gid) as $component) {
                if (!isset($keep[$component])) {
                    $stack[] = $component;
                }
            }
        }
        return $keep;
    }

    /**
     * @param array<int, true> $keep
     * @return array{string, string} [glyf bytes, loca bytes (long format)]
     */
    private function rebuildGlyfAndLoca(TtfFont $font, array $keep): array
    {
        $glyphCount = $font->glyphCount();
        $glyf = '';
        $offsets = [];
        for ($gid = 0; $gid < $glyphCount; $gid++) {
            $offsets[] = strlen($glyf);
            if (isset($keep[$gid])) {
                $data = $font->glyphDataFor($gid);
                $glyf .= $data;
                if (strlen($data) % 2 !== 0) {
                    $glyf .= "\x00"; // keep glyph entries on an even boundary
                }
            }
        }
        $offsets[] = strlen($glyf);

        $loca = '';
        foreach ($offsets as $offset) {
            $loca .= pack('N', $offset);
        }
        return [$glyf, $loca];
    }

    /**
     * Reassembles a complete sfnt from final table contents (OpenType spec §5 "sfnt", table
     * directory search fields per the spec's binary-search field formulas).
     *
     * @param array<string, string> $tables tag => final raw bytes
     */
    private function assembleSfnt(array $tables): string
    {
        ksort($tables); // table directory entries must be sorted by tag, ascending

        $numTables = count($tables);
        $entrySelector = $numTables > 0 ? (int) floor(log($numTables, 2)) : 0;
        $searchRange = (2 ** $entrySelector) * 16;
        $rangeShift = $numTables * 16 - $searchRange;

        $tableDataStart = 12 + $numTables * 16;
        $directory = '';
        $data = '';
        $offset = $tableDataStart;
        foreach ($tables as $tag => $bytes) {
            $length = strlen($bytes);
            $padding = (4 - ($length % 4)) % 4;
            $padded = $bytes . str_repeat("\x00", $padding);
            $directory .= $tag . pack('N', $this->checksum($padded)) . pack('N', $offset) . pack('N', $length);
            $data .= $padded;
            $offset += strlen($padded);
        }

        $sfntHeader = pack('N', 0x00010000)
            . pack('n', $numTables) . pack('n', $searchRange) . pack('n', $entrySelector) . pack('n', $rangeShift);

        return $sfntHeader . $directory . $data;
    }

    /** Sum of the table's bytes as big-endian uint32 words, wrapped to 32 bits (OpenType spec §5). */
    private function checksum(string $paddedBytes): int
    {
        $sum = 0;
        for ($i = 0, $len = strlen($paddedBytes); $i < $len; $i += 4) {
            /** @var array{1: int} $word */
            $word = unpack('N', substr($paddedBytes, $i, 4));
            $sum = ($sum + $word[1]) & 0xFFFFFFFF;
        }
        return $sum;
    }
}
