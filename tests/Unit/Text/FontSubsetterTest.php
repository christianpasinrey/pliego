<?php

declare(strict_types=1);

use Pliego\Text\FontSubsetter;
use Pliego\Text\TtfFont;

function fontSubsetterFixturesDir(): string
{
    return __DIR__ . '/../../../resources/fonts';
}

/** Número de tablas del sfnt (uint16 @+4), sin depender del parser de TtfFont (que exige cmap). */
function sfntTableCount(string $bytes): int
{
    /** @var array{1: int} $v */
    $v = unpack('n', substr($bytes, 4, 2));
    return $v[1];
}

/**
 * Synthetic multi-glyph sfnt built to actually exercise the size-reduction guarantee of
 * keep-gid subsetting. A real production font (see DejaVuSans fixtures) has large GPOS/GSUB/
 * kern/post/name tables that the subsetter must copy verbatim (M1-T8 brief: "el resto de
 * tablas se copian tal cual"), plus hmtx/loca whose size depends only on numGlyphs, not on
 * which glyphs are kept — so a real font's *total file* size reduction is bounded well above
 * any tight percentage. This fixture has none of those fixed-size tables and many large
 * "filler" glyphs, so glyf dominates the file and dropping filler glyphs is clearly visible in
 * the resulting size — isolating the keep-gid glyf/loca rebuild algorithm from unrelated
 * table bloat.
 *
 * gid layout: 0 = .notdef (empty) · 1..fillerCount = filler simple glyphs (never requested,
 * must be dropped) · then A, B, componentN, componentTilde, and a composite "NTilde" (=
 * component N + component Tilde, mirroring real 'ñ') whose components are NOT requested
 * directly by callers — only reachable via the composite's own component records.
 *
 * @return array{bytes: string, aGid: int, bGid: int, componentN: int, componentTilde: int, ntildeGid: int, unusedFillerGid: int}
 */
function buildSubsettableTtf(): array
{
    $u16 = static fn(int $v): string => pack('n', $v & 0xFFFF);
    $u32 = static fn(int $v): string => pack('N', $v);

    $fillerCount = 80;
    $fillerSize = 300;

    $aGid = $fillerCount + 1;
    $bGid = $fillerCount + 2;
    $componentN = $fillerCount + 3;
    $componentTilde = $fillerCount + 4;
    $ntildeGid = $fillerCount + 5;
    $numGlyphs = $ntildeGid + 1;

    // Simple glyph: numberOfContours=1 + zero-filled payload. The subsetter treats glyf
    // entries as opaque byte blobs (only composite records need real structure to walk), so
    // geometric validity is irrelevant here — only byte length and "is this composite" (bit
    // sign of numberOfContours) matter.
    $simpleGlyph = static fn(int $size): string => $u16(1) . str_repeat("\x00", $size - 2);

    // Composite glyph (OpenType spec glyf table, component record: flags uint16 + glyphIndex
    // uint16, +2 bytes args since ARG_1_AND_2_ARE_WORDS is off): numberOfContours=-1, 8-byte
    // bbox, then two component records; only the first carries MORE_COMPONENTS (bit 5 /
    // 0x0020).
    $compositeGlyph = $u16(0xFFFF) // -1 as int16 = composite marker
        . str_repeat("\x00", 8) // bbox, unused by the subsetter
        . $u16(0x0020) . $u16($componentN) . $u16(0)
        . $u16(0x0000) . $u16($componentTilde) . $u16(0);

    $glyphs = [0 => ''];
    for ($i = 1; $i <= $fillerCount; $i++) {
        $glyphs[$i] = $simpleGlyph($fillerSize);
    }
    $glyphs[$aGid] = $simpleGlyph(80);
    $glyphs[$bGid] = $simpleGlyph(80);
    $glyphs[$componentN] = $simpleGlyph(60);
    $glyphs[$componentTilde] = $simpleGlyph(40);
    $glyphs[$ntildeGid] = $compositeGlyph;

    $glyf = '';
    $locaOffsets = [];
    for ($gid = 0; $gid < $numGlyphs; $gid++) {
        $locaOffsets[] = strlen($glyf);
        $glyf .= $glyphs[$gid];
    }
    $locaOffsets[] = strlen($glyf);

    $loca = '';
    foreach ($locaOffsets as $offset) {
        $loca .= $u32($offset);
    }

    // head (54 bytes): unitsPerEm @+18, indexToLocFormat @+50 (1 = long offsets).
    $head = str_repeat("\x00", 54);
    $head = substr_replace($head, $u16(1000), 18, 2);
    $head = substr_replace($head, $u16(1), 50, 2);

    // hhea (36 bytes): ascender @+4, descender @+6, numberOfHMetrics @+34.
    $hhea = str_repeat("\x00", 36);
    $hhea = substr_replace($hhea, $u16(800), 4, 2);
    $hhea = substr_replace($hhea, $u16(-200 & 0xFFFF), 6, 2);
    $hhea = substr_replace($hhea, $u16($numGlyphs), 34, 2);

    // maxp (6 bytes, version 0.5): numGlyphs @+4.
    $maxp = $u32(0x00005000) . $u16($numGlyphs);

    // hmtx: one full metric per glyph, distinct advances for A/B so metric preservation is
    // actually checking something.
    $hmtx = '';
    for ($gid = 0; $gid < $numGlyphs; $gid++) {
        $advance = match ($gid) {
            $aGid => 600,
            $bGid => 700,
            default => 500,
        };
        $hmtx .= $u16($advance) . $u16(0);
    }

    // cmap format 4: contiguous segment [0x41,0x42] -> [aGid,bGid] (same idDelta since both
    // codepoint and gid advance by 1), [0xF1,0xF1] -> ntildeGid, plus the mandatory 0xFFFF
    // terminator segment.
    $segments = [
        [0x41, 0x42, $aGid - 0x41],
        [0xF1, 0xF1, $ntildeGid - 0xF1],
        [0xFFFF, 0xFFFF, 1],
    ];
    $segCount = count($segments);
    $endCodes = '';
    $startCodes = '';
    $idDeltas = '';
    $idRangeOffsets = '';
    foreach ($segments as [$start, $end, $delta]) {
        $endCodes .= $u16($end);
        $startCodes .= $u16($start);
        $idDeltas .= $u16($delta);
        $idRangeOffsets .= $u16(0);
    }
    $subtableLength = 14 + $segCount * 8 + 2;
    $subtable = $u16(4) . $u16($subtableLength) . $u16(0)
        . $u16($segCount * 2) . $u16(0) . $u16(0) . $u16(0)
        . $endCodes . $u16(0) . $startCodes . $idDeltas . $idRangeOffsets;
    $cmap = $u16(0) . $u16(1) . $u16(3) . $u16(1) . $u32(12) . $subtable;

    $tables = [
        'cmap' => $cmap, 'glyf' => $glyf, 'head' => $head, 'hhea' => $hhea,
        'hmtx' => $hmtx, 'loca' => $loca, 'maxp' => $maxp,
    ];
    ksort($tables);

    $directoryStart = 12;
    $tableDataStart = $directoryStart + 16 * count($tables);
    $directory = '';
    $data = '';
    $offset = $tableDataStart;
    foreach ($tables as $tag => $bytes) {
        $directory .= $tag . $u32(0) . $u32($offset) . $u32(strlen($bytes));
        $data .= $bytes;
        $offset += strlen($bytes);
    }
    $sfntHeader = $u32(0x00010000) . $u16(count($tables)) . $u16(0) . $u16(0) . $u16(0);

    return [
        'bytes' => $sfntHeader . $directory . $data,
        'aGid' => $aGid,
        'bGid' => $bGid,
        'componentN' => $componentN,
        'componentTilde' => $componentTilde,
        'ntildeGid' => $ntildeGid,
        'unusedFillerGid' => 1,
    ];
}

it('produces a much smaller font for few glyphs', function (): void {
    $fixture = buildSubsettableTtf();
    $font = TtfFont::fromString($fixture['bytes']);

    $subset = (new FontSubsetter())->subset($font, [$fixture['aGid'], $fixture['bGid'], $fixture['ntildeGid']]);

    expect(strlen($subset))->toBeLessThan((int) (strlen($fixture['bytes']) * 0.15));
});

it('keeps glyph ids stable', function (): void {
    $original = TtfFont::fromFile(fontSubsetterFixturesDir() . '/DejaVuSans.ttf');
    $gidA = $original->glyphId(0x41);
    $gidNTilde = $original->glyphId(0xF1);

    $subsetBytes = (new FontSubsetter())->subset($original, [$gidA, $gidNTilde]);
    $subsetFont = TtfFont::fromString($subsetBytes);

    expect($subsetFont->glyphId(0x41))->toBe($gidA);
    expect($subsetFont->glyphId(0xF1))->toBe($gidNTilde);
});

it('preserves metrics for kept glyphs', function (): void {
    $original = TtfFont::fromFile(fontSubsetterFixturesDir() . '/DejaVuSans.ttf');
    $gidA = $original->glyphId(0x41);
    $gidW = $original->glyphId(0x57);

    $subsetBytes = (new FontSubsetter())->subset($original, [$gidA, $gidW]);
    $subsetFont = TtfFont::fromString($subsetBytes);

    expect($subsetFont->advanceOf($gidA))->toBe($original->advanceOf($gidA));
    expect($subsetFont->advanceOf($gidW))->toBe($original->advanceOf($gidW));
});

it('includes composite components even when only the composite gid is requested', function (): void {
    $original = TtfFont::fromFile(fontSubsetterFixturesDir() . '/DejaVuSans.ttf');
    $gidNTilde = $original->glyphId(0xF1); // 'ñ', composite of n + combining tilde in DejaVuSans
    $components = $original->compositeComponents($gidNTilde);
    expect($components)->not->toBeEmpty(); // sanity: this really is a composite glyph

    $gidZ = $original->glyphId(0x5A); // unrelated glyph, deliberately NOT requested
    $subsetBytes = (new FontSubsetter())->subset($original, [$gidNTilde]);
    $subsetFont = TtfFont::fromString($subsetBytes);

    foreach ($components as $componentGid) {
        expect($subsetFont->glyphDataFor($componentGid))->not->toBe('');
    }
    expect($subsetFont->glyphDataFor($gidZ))->toBe(''); // dropped: never requested, not a component
});

it('parses as a valid sfnt that TtfFont can fully re-parse', function (): void {
    $original = TtfFont::fromFile(fontSubsetterFixturesDir() . '/DejaVuSans.ttf');
    $gidA = $original->glyphId(0x41);
    $gidNTilde = $original->glyphId(0xF1);

    $subsetBytes = (new FontSubsetter())->subset($original, [$gidA, $gidNTilde]);
    $subsetFont = TtfFont::fromString($subsetBytes);

    expect($subsetFont->unitsPerEm())->toBe($original->unitsPerEm());
    expect($subsetFont->ascender())->toBe($original->ascender());
    expect($subsetFont->descender())->toBe($original->descender());
    expect($subsetFont->glyphCount())->toBe($original->glyphCount()); // keep-gid: no renumbering
    expect($subsetFont->indexToLocFormat())->toBe(1); // subsetter always emits long loca
});

it('shrinks a real 3-glyph subset well below 8% of the original using the PDF rasterizer table whitelist', function (): void {
    // M1-T9 controller addition: FontEmbedder passes this exact whitelist (the tables a PDF
    // rasterizer needs to hint/scale glyphs) — name/post/GSUB/GPOS/kern/cmap are dropped
    // because we shape text ourselves and CIDToGIDMap=Identity needs no cmap.
    $whitelist = ['head', 'hhea', 'maxp', 'hmtx', 'cvt ', 'fpgm', 'prep'];
    $originalPath = fontSubsetterFixturesDir() . '/DejaVuSans.ttf';
    $original = TtfFont::fromFile($originalPath);
    $gidA = $original->glyphId(0x41);
    $gidB = $original->glyphId(0x42);
    $gidNTilde = $original->glyphId(0xF1);

    $subsetBytes = (new FontSubsetter())->subset($original, [$gidA, $gidB, $gidNTilde], $whitelist);

    expect(strlen($subsetBytes))->toBeLessThan((int) (filesize($originalPath) * 0.08));

    // Solo el whitelist + glyf/loca/head (siempre reconstruidas) sobreviven; cmap/name/post/
    // GSUB/GPOS/kern (todas presentes en el DejaVuSans real) se descartan. TtfFont::fromString
    // no puede re-parsear este subset (exige cmap en el constructor), así que se inspecciona
    // el directorio de tablas del sfnt crudo.
    $numTables = sfntTableCount($subsetBytes);
    $tags = [];
    for ($i = 0; $i < $numTables; $i++) {
        $tags[] = substr($subsetBytes, 12 + $i * 16, 4);
    }
    sort($tags);
    expect($tags)->toBe(['cvt ', 'fpgm', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp', 'prep']);
});

it('skips a whitelisted table silently when the source font does not have it', function (): void {
    $whitelist = ['head', 'hhea', 'maxp', 'hmtx', 'GSUB']; // GSUB absent from the synthetic fixture
    $fixture = buildSubsettableTtf();
    $font = TtfFont::fromString($fixture['bytes']);

    $subsetBytes = (new FontSubsetter())->subset($font, [$fixture['aGid']], $whitelist);

    $numTables = sfntTableCount($subsetBytes);
    $tags = [];
    for ($i = 0; $i < $numTables; $i++) {
        $tags[] = substr($subsetBytes, 12 + $i * 16, 4);
    }
    sort($tags);
    expect($tags)->toBe(['glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp']); // no 'GSUB', no error
});
