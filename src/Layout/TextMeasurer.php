<?php

declare(strict_types=1);

namespace Pliego\Layout;

use Pliego\Text\TtfFont;

final readonly class TextMeasurer
{
    private const float NORMAL_LINE_HEIGHT = 1.2; // CSS 2.2 §10.8: 'normal' recomendado 1.0-1.2

    public function __construct(private TtfFont $font) {}

    public function widthOf(string $text, float $fontSizePx): float
    {
        $units = 0;
        foreach (mb_str_split($text) as $char) {
            $units += $this->font->advanceOf($this->font->glyphId(mb_ord($char)));
        }
        return $units / $this->font->unitsPerEm() * $fontSizePx;
    }

    public function lineHeight(float $fontSizePx): float
    {
        return $fontSizePx * self::NORMAL_LINE_HEIGHT;
    }

    public function ascent(float $fontSizePx): float
    {
        return $this->font->ascender() / $this->font->unitsPerEm() * $fontSizePx;
    }
}
