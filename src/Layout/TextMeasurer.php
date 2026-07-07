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

    /**
     * M8-T5 (css-text-3 §8 reducido): += $letterSpacingPx/$wordSpacingPx, AMBOS opcionales con
     * default 0.0 (el caso PREEXISTENTE, "normal" en ambas propiedades) -- CRÍTICO para la
     * estabilidad de goldens: cuando ambos son 0.0, el cálculo de abajo (glifo por glifo, suma de
     * advances / unitsPerEm * sizePx) es BYTE-A-BYTE idéntico al de antes de esta tarea, sin ni
     * siquiera recorrer $text una segunda vez (el `if` de guarda evita el conteo de
     * caracteres/espacios por completo) -- ningún golden existente declara letter-spacing/
     * word-spacing, así que esta guarda hace que M1-M8-T4 permanezcan intactos.
     *
     * Con spacing != 0.0: letter-spacing se añade DESPUÉS DE CADA carácter, INCLUIDO el último
     * (adjudicación M8-T5, siguiendo el comportamiento observado en navegadores reales en vez del
     * texto literal ambiguo del spec -- documentado); word-spacing se añade una vez por cada
     * carácter ESPACIO (U+0020) presente en $text, ADEMÁS del letter-spacing de ese mismo carácter
     * (ambos son aditivos, nunca uno sustituye al otro). Pdf\PdfCanvas::fillText() replica
     * EXACTAMENTE esta misma regla por-glifo al construir el array TJ (ver su docblock), para que
     * el ancho medido aquí y el avance realmente pintado coincidan.
     */
    public function widthOf(string $text, FontFace $face, float $sizePx, float $letterSpacingPx = 0.0, float $wordSpacingPx = 0.0): float
    {
        $font = $face->font;
        $units = 0;
        foreach (mb_str_split($text) as $char) {
            $units += $font->advanceOf($font->glyphId(mb_ord($char)));
        }
        $width = $units / $font->unitsPerEm() * $sizePx;
        if ($letterSpacingPx === 0.0 && $wordSpacingPx === 0.0) {
            return $width;
        }
        $charCount = mb_strlen($text);
        $spaceCount = substr_count($text, ' ');
        return $width + $letterSpacingPx * $charCount + $wordSpacingPx * $spaceCount;
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
