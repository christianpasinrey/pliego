<?php

declare(strict_types=1);

use Pliego\Text\TtfFont;

beforeEach(function (): void {
    $this->font = TtfFont::fromFile(__DIR__ . '/../../../resources/fonts/DejaVuSans.ttf');
});

it('reads unitsPerEm from the head table', fn() => expect($this->font->unitsPerEm())->toBe(2048));
it('reads vertical metrics from hhea', function () {
    expect($this->font->ascender())->toBeGreaterThan(0);
    expect($this->font->descender())->toBeLessThan(0);
});
it('maps codepoints to glyphs via cmap format 4', function () {
    expect($this->font->glyphId(0x41))->toBeGreaterThan(0);   // 'A'
    expect($this->font->glyphId(0xF1))->toBeGreaterThan(0);   // 'ñ'
    expect($this->font->glyphId(0x10FFFD))->toBe(0);          // fuera de BMP => .notdef
});

// M7-T3 (css-lists-3 §3, "marker glyph availability probe" del brief): verifica QUE DejaVuSans
// (la cara 'default' registrada por FontCatalog::withDefaults(), usada para el marcador de un
// <li> con estilo por defecto) tiene glifos reales para los 3 símbolos de bullet que
// Layout\BlockFlowContext::listMarkerFragment() emite -- disc U+2022, circle U+25E6, square
// U+25AA -- confirmando que la adjudicación del brief ("si falta, fallback documentado a U+00B7")
// nunca se activa en la práctica con esta fuente: los 3 glyphId son > 0 (nunca .notdef/0).
it('has real glyphs (not .notdef) for the 3 list-item marker bullets used by BlockFlowContext', function () {
    expect($this->font->glyphId(0x2022))->toBeGreaterThan(0); // disc •
    expect($this->font->glyphId(0x25E6))->toBeGreaterThan(0); // circle ◦
    expect($this->font->glyphId(0x25AA))->toBeGreaterThan(0); // square ▪
});
it('exposes wider advances for wider glyphs', function () {
    $narrow = $this->font->advanceOf($this->font->glyphId(0x69)); // 'i'
    $wide = $this->font->advanceOf($this->font->glyphId(0x57));   // 'W'
    expect($narrow)->toBeGreaterThan(0);
    expect($wide)->toBeGreaterThan($narrow);
});
it('returns the raw file bytes for embedding', function () {
    expect(strlen($this->font->bytes()))->toBe(filesize(__DIR__ . '/../../../resources/fonts/DejaVuSans.ttf'));
});
it('reads underline metrics from the post table (real DejaVuSans values)', function () {
    // Verificado a mano contra los bytes reales de DejaVuSans.ttf (post table @ offset
    // 693640): version 0x00020000, underlinePosition int16 @+8 = -130 (bajo la baseline,
    // signo negativo por convención OpenType), underlineThickness int16 @+10 = 90.
    [$position, $thickness] = $this->font->underlineMetrics();
    expect($position)->toBe(-130);
    expect($position)->toBeLessThan(0); // bajo la baseline
    expect($thickness)->toBe(90);
});
it('returns null underline metrics when the font has no post table', function () {
    // buildMinimalTtfWithoutPostTable() está en tests/Pest.php (bootstrap), compartida con
    // PainterTest para el mismo caso de fallback en el punto de consumo.
    $path = buildMinimalTtfWithoutPostTable();
    try {
        $font = TtfFont::fromFile($path);
        expect($font->underlineMetrics())->toBeNull();
    } finally {
        unlink($path);
    }
});
