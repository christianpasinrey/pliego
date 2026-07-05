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
it('exposes wider advances for wider glyphs', function () {
    $narrow = $this->font->advanceOf($this->font->glyphId(0x69)); // 'i'
    $wide = $this->font->advanceOf($this->font->glyphId(0x57));   // 'W'
    expect($narrow)->toBeGreaterThan(0);
    expect($wide)->toBeGreaterThan($narrow);
});
it('returns the raw file bytes for embedding', function () {
    expect(strlen($this->font->bytes()))->toBe(filesize(__DIR__ . '/../../../resources/fonts/DejaVuSans.ttf'));
});
