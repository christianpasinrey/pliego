<?php

declare(strict_types=1);

namespace Pliego\Pdf;

use Pliego\Css\Value\Color;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;
use Pliego\Page\PaperSize;
use Pliego\Paint\Canvas;

/** Canvas PDF: px CSS (origen arriba-izda) => pt PDF (origen abajo-izda, ×0.75). */
final class PdfCanvas implements Canvas
{
    private const float PX_TO_PT = 0.75;
    private string $ops = '';

    public function __construct(
        private readonly PdfWriter $writer,
        private readonly FontEmbedder $font,
        private readonly PaperSize $paper,
        private readonly float $offsetX,
        private readonly float $offsetY,
    ) {}

    public function beginPage(): void
    {
        $this->ops = '';
    }

    public function endPage(): void
    {
        $this->writer->addPage(
            $this->paper->widthPx() * self::PX_TO_PT,
            $this->paper->heightPx() * self::PX_TO_PT,
            $this->ops,
            ['F1' => $this->font->objectId()],
        );
    }

    public function fillRect(Rect $rect, Color $color): void
    {
        $x = ($rect->x + $this->offsetX) * self::PX_TO_PT;
        $y = ($this->paper->heightPx() - ($rect->y + $this->offsetY) - $rect->height) * self::PX_TO_PT;
        $this->ops .= sprintf(
            "%s %.2F %.2F %.2F %.2F re f\n",
            $this->rg($color),
            $x,
            $y,
            $rect->width * self::PX_TO_PT,
            $rect->height * self::PX_TO_PT,
        );
    }

    public function fillText(TextFragment $text): void
    {
        // T9: seleccionar /F por faceKey (FontRegistry sustituirá a $this->font único; por
        // ahora toda la cara se pinta con los glifos de la única cara embebida por el Engine).
        $x = ($text->rect->x + $this->offsetX) * self::PX_TO_PT;
        $baseline = ($this->paper->heightPx() - ($text->baselineY + $this->offsetY)) * self::PX_TO_PT;
        $hex = $this->font->encode($text->text);
        $this->ops .= sprintf(
            "BT /F1 %.2F Tf %s %.2F %.2F Td <%s> Tj ET\n",
            $text->fontSizePx * self::PX_TO_PT,
            $this->rg($text->color),
            $x,
            $baseline,
            $hex,
        );
    }

    public function strokeLine(float $x1, float $y1, float $x2, float $y2, float $widthPx, Color $color): void
    {
        $px1 = ($x1 + $this->offsetX) * self::PX_TO_PT;
        $py1 = ($this->paper->heightPx() - ($y1 + $this->offsetY)) * self::PX_TO_PT;
        $px2 = ($x2 + $this->offsetX) * self::PX_TO_PT;
        $py2 = ($this->paper->heightPx() - ($y2 + $this->offsetY)) * self::PX_TO_PT;
        $this->ops .= sprintf(
            "%s\n%.2F w\n%.2F %.2F m %.2F %.2F l S\n",
            $this->rgStroke($color),
            $widthPx * self::PX_TO_PT,
            $px1,
            $py1,
            $px2,
            $py2,
        );
    }

    private function rg(Color $color): string
    {
        return sprintf('%.3F %.3F %.3F rg', $color->r / 255, $color->g / 255, $color->b / 255);
    }

    private function rgStroke(Color $color): string
    {
        return sprintf('%.3F %.3F %.3F RG', $color->r / 255, $color->g / 255, $color->b / 255);
    }
}
