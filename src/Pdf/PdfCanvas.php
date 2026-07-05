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
    /** @var array<string, int> resource name (e.g. "XO1") => object id, used by THIS page only */
    private array $xobjectRefs = [];

    public function __construct(
        private readonly PdfWriter $writer,
        private readonly FontRegistry $fonts,
        private readonly PaperSize $paper,
        private readonly float $offsetX,
        private readonly float $offsetY,
    ) {}

    public function beginPage(): void
    {
        $this->ops = '';
        $this->xobjectRefs = [];
    }

    public function endPage(): void
    {
        $this->writer->addPage(
            $this->paper->widthPx() * self::PX_TO_PT,
            $this->paper->heightPx() * self::PX_TO_PT,
            $this->ops,
            $this->fonts->pageResources(),
            $this->xobjectRefs,
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
        $x = ($text->rect->x + $this->offsetX) * self::PX_TO_PT;
        $baseline = ($this->paper->heightPx() - ($text->baselineY + $this->offsetY)) * self::PX_TO_PT;
        $resourceName = $this->fonts->resourceNameFor($text->faceKey);
        $hex = $this->fonts->embedderFor($text->faceKey)->encode($text->text);
        $this->ops .= sprintf(
            "BT /%s %.2F Tf %s %.2F %.2F Td <%s> Tj ET\n",
            $resourceName,
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

    /**
     * M2-T7: draws text at page-absolute px coordinates (top-left origin), bypassing offsetX/
     * offsetY — @page margin boxes are positioned relative to the whole page, not the content
     * area those offsets represent. Used for margin-box labels painted directly (no
     * counter(pages), so no need to defer — see MarginBoxPainter).
     */
    public function fillTextAtPage(float $xPx, float $baselinePx, string $text, float $fontSizePx, Color $color, string $faceKey): void
    {
        $x = $xPx * self::PX_TO_PT;
        $baseline = ($this->paper->heightPx() - $baselinePx) * self::PX_TO_PT;
        $resourceName = $this->fonts->resourceNameFor($faceKey);
        $hex = $this->fonts->embedderFor($faceKey)->encode($text);
        $this->ops .= sprintf(
            "BT /%s %.2F Tf %s %.2F %.2F Td <%s> Tj ET\n",
            $resourceName,
            $fontSizePx * self::PX_TO_PT,
            $this->rg($color),
            $x,
            $baseline,
            $hex,
        );
    }

    /**
     * M2-T7: places a deferred Form XObject (PdfWriter::defer()) at page-absolute px coordinates
     * — `q 1 0 0 1 x y cm /XOn Do Q` (ISO 32000-1 §8.10.2) — and registers it as this page's
     * resource. ($xPx, $bottomYPx) is the BOTTOM-LEFT corner of the XObject's own BBox in page px
     * space (top-left origin, Y grows down — so "bottom" means the LARGER px y); PDF space has Y
     * grow up from that same corner, matching the XObject's own BBox starting at (0,0).
     */
    public function placeXObject(DeferredXObject $xobject, float $xPx, float $bottomYPx): void
    {
        $x = $xPx * self::PX_TO_PT;
        $y = ($this->paper->heightPx() - $bottomYPx) * self::PX_TO_PT;
        $this->ops .= sprintf("q 1 0 0 1 %.2F %.2F cm /%s Do Q\n", $x, $y, $xobject->name);
        $this->xobjectRefs[$xobject->name] = $xobject->objectId;
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
