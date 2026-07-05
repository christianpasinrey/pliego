<?php

declare(strict_types=1);

namespace Pliego\Layout;

use Pliego\Box\BlockBox;
use Pliego\Box\LineBreakRun;
use Pliego\Box\TextRun;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;

/**
 * CSS 2.2 §9.4.1 (block formatting) + §10.3.3 (anchos) simplificado para M0:
 * sin margin collapsing, sin floats, line breaking greedy por espacios.
 */
final readonly class BlockFlowContext implements FormattingContext
{
    public function __construct(private TextMeasurer $measurer) {}

    public function layout(BlockBox $box, Rect $containingBlock): BoxFragment
    {
        $style = $box->style;
        $x = $containingBlock->x + $style->marginLeft->px;
        $y = $containingBlock->y + $style->marginTop->px;
        // Falso positivo verificado (ver task-8-report.md): PHPStan resuelve `?Length` como
        // no-nulo solo cuando el nullsafe y el `??` conviven en la misma expresión; separar
        // en dos sentencias hace desaparecer el aviso sin cambiar tipo ni comportamiento.
        // @phpstan-ignore nullsafe.neverNull
        $borderBoxWidth = $style->width?->px
            ?? $containingBlock->width - $style->marginLeft->px - $style->marginRight->px;
        $contentX = $x + $style->paddingLeft->px;
        $contentWidth = $borderBoxWidth - $style->paddingLeft->px - $style->paddingRight->px;
        $cursorY = $y + $style->paddingTop->px;
        $contentBottom = $cursorY;

        $children = [];
        foreach ($box->children as $child) {
            if ($child instanceof TextRun) {
                foreach ($this->wrapText($child, $contentX, $cursorY, $contentWidth) as $line) {
                    $children[] = $line;
                    $cursorY = $line->rect->bottom();
                }
                $contentBottom = $cursorY;
                continue;
            }
            if ($child instanceof LineBreakRun) {
                continue; // T6 lo consume
            }
            $childFragment = $this->layout($child, new Rect($contentX, $cursorY, $contentWidth, INF));
            $children[] = $childFragment;
            // CSS 2.2 §10.6.3: la altura de contenido llega hasta el border-box de la
            // última caja en flujo; el margin-bottom avanza el cursor para el siguiente
            // hermano pero no forma parte de la altura del padre.
            $contentBottom = $childFragment->rect->bottom();
            $cursorY = $contentBottom + $child->style->marginBottom->px;
        }

        $height = ($contentBottom - $y) + $style->paddingBottom->px;
        return new BoxFragment(
            new Rect($x, $y, $borderBoxWidth, $height),
            $style->backgroundColor,
            $children,
        );
    }

    /** @return list<TextFragment> */
    private function wrapText(TextRun $run, float $x, float $y, float $availableWidth): array
    {
        $fontSize = $run->style->fontSizePx;
        $lineHeight = $this->measurer->lineHeight($fontSize);
        $ascent = $this->measurer->ascent($fontSize);
        $spaceWidth = $this->measurer->widthOf(' ', $fontSize);
        $lines = [];
        $currentWords = [];
        $currentWidth = 0.0;
        $flush = function () use (&$lines, &$currentWords, &$currentWidth, $x, &$y, $lineHeight, $ascent, $fontSize, $run): void {
            $text = implode(' ', $currentWords);
            $lines[] = new TextFragment(
                new Rect($x, $y, $currentWidth, $lineHeight),
                $text,
                $y + ($lineHeight - $fontSize) / 2 + $ascent,
                $fontSize,
                $run->style->color,
            );
            $y += $lineHeight;
            $currentWords = [];
            $currentWidth = 0.0;
        };
        foreach (explode(' ', $run->text) as $word) {
            $wordWidth = $this->measurer->widthOf($word, $fontSize);
            $projected = $currentWords === [] ? $wordWidth : $currentWidth + $spaceWidth + $wordWidth;
            if ($projected > $availableWidth && $currentWords !== []) {
                $flush();
                $projected = $wordWidth;
            }
            $currentWords[] = $word;
            $currentWidth = $projected;
        }
        $flush();
        return $lines;
    }
}
