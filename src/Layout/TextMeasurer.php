<?php

declare(strict_types=1);

namespace Pliego\Layout;

use Pliego\Text\FontFace;

/**
 * M1-T6: measurer se vuelve stateless y por-cara (ya no ata una única TtfFont vía
 * constructor, como en M0) — cada llamada recibe la FontFace resuelta por FontCatalog
 * para el TextRun en cuestión, permitiendo mezclar caras (normal/bold/italic) en el
 * mismo bloque/línea. `lineHeight()` se mantiene basada en fórmula (no depende de
 * ninguna cara concreta).
 */
final class TextMeasurer
{
    private const float NORMAL_LINE_HEIGHT = 1.2; // CSS 2.2 §10.8: 'normal' recomendado 1.0-1.2

    public function widthOf(string $text, FontFace $face, float $sizePx): float
    {
        $font = $face->font;
        $units = 0;
        foreach (mb_str_split($text) as $char) {
            $units += $font->advanceOf($font->glyphId(mb_ord($char)));
        }
        return $units / $font->unitsPerEm() * $sizePx;
    }

    public function lineHeight(float $fontSizePx): float
    {
        return $fontSizePx * self::NORMAL_LINE_HEIGHT;
    }

    public function ascent(FontFace $face, float $sizePx): float
    {
        return $face->font->ascender() / $face->font->unitsPerEm() * $sizePx;
    }

    public function descent(FontFace $face, float $sizePx): float
    {
        return abs($face->font->descender()) / $face->font->unitsPerEm() * $sizePx;
    }
}
