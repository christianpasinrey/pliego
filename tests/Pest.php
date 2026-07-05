<?php

declare(strict_types=1);

/**
 * Construye un sfnt mínimo (head, hhea, maxp, hmtx, cmap format 4) SIN tabla `post`, para
 * ejercitar la ruta de fallback de underlineMetrics() (TtfFont) y del Painter que la consume.
 * Suficiente para que el constructor de TtfFont (que exige head/hhea/maxp/hmtx/cmap vía
 * requireTable) no lance FontException; los valores numéricos son arbitrarios salvo donde el
 * formato exige un terminador concreto (segmento final 0xFFFF/0xFFFF/1/0 del cmap format 4,
 * OpenType spec §5 "cmap"). Definida aquí (bootstrap de Pest, siempre cargado) en vez de en un
 * test concreto para que esté disponible sin importar qué fichero de test se ejecute.
 */
function buildMinimalTtfWithoutPostTable(): string
{
    $u16 = static fn(int $v): string => pack('n', $v & 0xFFFF);
    $u32 = static fn(int $v): string => pack('N', $v);

    // head (54 bytes): unitsPerEm @+18, bbox (xMin,yMin,xMax,yMax) @+36.
    $head = str_repeat("\x00", 54);
    $head = substr_replace($head, $u16(1000), 18, 2);
    $head = substr_replace($head, $u16(0) . $u16(0) . $u16(0) . $u16(0), 36, 8);

    // hhea (36 bytes): ascender @+4, descender @+6, numberOfHMetrics @+34.
    $hhea = str_repeat("\x00", 36);
    $hhea = substr_replace($hhea, $u16(800), 4, 2);
    $hhea = substr_replace($hhea, $u16(-200), 6, 2);
    $hhea = substr_replace($hhea, $u16(1), 34, 2);

    // maxp (6 bytes, version 0.5): numGlyphs @+4.
    $maxp = $u32(0x00005000) . $u16(1);

    // hmtx (4 bytes, 1 hMetric): advanceWidth @+0, lsb @+2.
    $hmtx = $u16(500) . $u16(0);

    // cmap: header (4) + 1 encoding record (8, platform 3 / encoding 1) + format-4 subtable (24).
    $subtable = $u16(4) . $u16(24) . $u16(0) // format, length, language
        . $u16(2) . $u16(2) . $u16(0) . $u16(0) // segCountX2, searchRange, entrySelector, rangeShift
        . $u16(0xFFFF) // endCode[0]
        . $u16(0) // reservedPad
        . $u16(0xFFFF) // startCode[0]
        . $u16(1) // idDelta[0]
        . $u16(0); // idRangeOffset[0]
    $cmap = $u16(0) . $u16(1) . $u16(3) . $u16(1) . $u32(12) . $subtable;

    $tables = ['head' => $head, 'hhea' => $hhea, 'maxp' => $maxp, 'hmtx' => $hmtx, 'cmap' => $cmap];

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
    $bytes = $sfntHeader . $directory . $data;

    $path = tempnam(sys_get_temp_dir(), 'ttf');
    if ($path === false) {
        throw new RuntimeException('Cannot create temp file for synthetic TTF fixture');
    }
    file_put_contents($path, $bytes);
    return $path;
}
