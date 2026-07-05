<?php

declare(strict_types=1);

namespace Pliego\Pdf;

use Pliego\Css\Value\Color;
use Pliego\Layout\TextMeasurer;
use Pliego\Page\CounterRef;
use Pliego\Page\MarginBoxContent;
use Pliego\Page\PageRule;
use Pliego\Page\PaperSize;
use Pliego\Text\FontCatalog;
use Pliego\Text\FontFace;

/**
 * Paints @page margin boxes (css-page-3 §6.5.3) — one page at a time, called between
 * PdfCanvas::beginPage() and endPage(). M2 defaults (font-size/color from the @page rule itself
 * is a later milestone): the default regular face, FONT_SIZE_PX (10px), TEXT_COLOR (#555555).
 *
 * Layout, per box position ("top-left".."bottom-right"): each margin strip (the top margin for
 * "top-*", the bottom margin for "bottom-*") is divided into COLUMN_COUNT (3) equal-width columns
 * of contentWidth/3 — left/center/right, in that order. A box only ever paints inside ITS OWN
 * column: the left box aligns its text to the start of column 0, the center box centers its text
 * within column 1, the right box aligns its text to the end of column 2. This is css-page-3's own
 * three-box-per-row division (§6.5.3), just without its §5.3 flex-fit sizing: real css-page-3
 * lets each box shrink, grow or wrap to fit whatever the OTHER boxes in the row actually need;
 * here the three columns are always an equal, fixed third of the content width regardless of how
 * much (or how little) text each box carries.
 *
 * HONEST LIMITATION: there is no shrink-to-fit, and clipping behaviour differs by path. A box's
 * text can be wider than its own column either way, but what happens to the overflow depends on
 * which path painted it (see "Ordering" below): a box painted directly into the page content
 * stream (PdfCanvas::fillTextAtPage()) has no clipping path applied to it and overflows past the
 * column boundary freely; a box built as a deferred Form XObject (PdfWriter::defer()) is clipped
 * to its own /BBox — set to exactly the column's width and box height — per ISO 32000-1 §8.10.2,
 * so its overflow is cut at the column edge instead of drawing past it. Either way, overflow only
 * becomes visible/lossy when the NEIGHBORING column also has a box painting text into the shared
 * boundary region; with a single box per row, or with realistically short label text (page
 * numbers, short running headers), the columns are wide enough in practice that this doesn't
 * matter — but nothing here enforces it. Implementing real fit (measure all boxes in the row,
 * shrink/reflow as needed) is out of scope for M2.
 *
 * Ordering (the actual point of M2-T7): a box whose content includes CounterRef::Pages can't be
 * painted while streaming (the total page count is only known once every page has been laid
 * out), so its label is built by a deferred Form XObject (PdfWriter::defer()) instead — one
 * XObject per (page, box), captured with the page number already known and only counter(pages)
 * left open, resolved later by PdfWriter::writeDeferred(). A box with no counter(pages) paints
 * straight into the page content stream (PdfCanvas::fillTextAtPage()) — cheaper, and keeps
 * writeDeferred() a no-op when no @page rule needs it at all.
 */
final class MarginBoxPainter
{
    private const string FACE_KEY = 'default:400:normal';
    private const float FONT_SIZE_PX = 10.0;
    /** left/center/right — css-page-3 §6.5.3's fixed 3-box-per-row division (see class docblock). */
    private const int COLUMN_COUNT = 3;
    /**
     * Duplicated from PdfCanvas::PX_TO_PT (same px->pt factor, ISO 32000-1 §7.9.5 doesn't apply
     * here): a deferred XObject's own content stream is built independently of any PdfCanvas
     * instance (its ops are produced later, inside PdfWriter::writeDeferred(), well after this
     * page's PdfCanvas may already be gone), so it can't reuse PdfCanvas's private conversion.
     */
    private const float PX_TO_PT = 0.75;

    public function __construct(
        private readonly PdfWriter $writer,
        private readonly FontRegistry $fonts,
        private readonly FontCatalog $catalog,
        private readonly TextMeasurer $measurer,
        private readonly PaperSize $paper,
        private readonly float $marginTopPx,
        private readonly float $marginRightPx,
        private readonly float $marginBottomPx,
        private readonly float $marginLeftPx,
    ) {}

    /** Paints (or defers) every margin box declared by $pageRule, for one page. */
    public function paintPage(PageRule $pageRule, PdfCanvas $canvas, int $pageNumber): void
    {
        foreach ($pageRule->marginBoxes as $position => $content) {
            $this->paintBox($position, $content, $canvas, $pageNumber);
        }
    }

    private function paintBox(string $position, MarginBoxContent $content, PdfCanvas $canvas, int $pageNumber): void
    {
        [$vertical, $horizontal] = explode('-', $position, 2);
        $contentWidthPx = $this->paper->widthPx() - $this->marginLeftPx - $this->marginRightPx;
        $columnWidthPx = $contentWidthPx / self::COLUMN_COUNT;
        $columnXPx = $this->marginLeftPx + $this->columnIndex($horizontal) * $columnWidthPx;
        $boxHeightPx = $vertical === 'top' ? $this->marginTopPx : $this->marginBottomPx;
        $boxTopPx = $vertical === 'top' ? 0.0 : $this->paper->heightPx() - $this->marginBottomPx;

        if ($this->hasPagesCounter($content)) {
            $this->deferBox($content, $canvas, $pageNumber, $horizontal, $columnXPx, $boxTopPx, $columnWidthPx, $boxHeightPx);
            return;
        }

        $face = $this->catalog->faceByKey(self::FACE_KEY);
        $text = $this->assembleText($content->parts, $pageNumber, 0);
        $textWidthPx = $this->measurer->widthOf($text, $face, self::FONT_SIZE_PX);
        $localXPx = $this->horizontalOffset($horizontal, $columnWidthPx, $textWidthPx);
        $baselinePx = $boxTopPx + $this->centeredBaselineFromTop($face, $boxHeightPx);

        $canvas->fillTextAtPage($columnXPx + $localXPx, $baselinePx, $text, self::FONT_SIZE_PX, $this->color(), self::FACE_KEY);
    }

    /** Column of the 3-column row this box's horizontal position belongs to: left=0, center=1, right=2. */
    private function columnIndex(string $horizontal): int
    {
        return match ($horizontal) {
            'left' => 0,
            'center' => 1,
            'right' => 2,
            default => 0, // unreachable: PageRuleFactory only lets left|center|right suffixes through
        };
    }

    private function deferBox(
        MarginBoxContent $content,
        PdfCanvas $canvas,
        int $pageNumber,
        string $horizontal,
        float $columnXPx,
        float $boxTopPx,
        float $columnWidthPx,
        float $boxHeightPx,
    ): void {
        $faceKey = self::FACE_KEY;
        $resourceName = $this->fonts->resourceNameFor($faceKey);
        $embedder = $this->fonts->embedderFor($faceKey);
        $fontRefs = [$resourceName => $embedder->objectId()];

        $face = $this->catalog->faceByKey($faceKey);
        $baselineFromBottomPx = $boxHeightPx - $this->centeredBaselineFromTop($face, $boxHeightPx);
        $parts = $content->parts;
        $measurer = $this->measurer;
        $fontSizePx = self::FONT_SIZE_PX;
        $color = $this->color();

        $opsBuilder = function (int $totalPages) use (
            $embedder,
            $resourceName,
            $parts,
            $pageNumber,
            $horizontal,
            $columnWidthPx,
            $face,
            $measurer,
            $fontSizePx,
            $color,
            $baselineFromBottomPx,
        ): string {
            $text = $this->assembleText($parts, $pageNumber, $totalPages);
            $textWidthPx = $measurer->widthOf($text, $face, $fontSizePx);
            $localXPx = $this->horizontalOffset($horizontal, $columnWidthPx, $textWidthPx);
            $hex = $embedder->encode($text);
            return sprintf(
                "BT /%s %.2F Tf %s %.2F %.2F Td <%s> Tj ET\n",
                $resourceName,
                $fontSizePx * self::PX_TO_PT,
                $this->rg($color),
                $localXPx * self::PX_TO_PT,
                $baselineFromBottomPx * self::PX_TO_PT,
                $hex,
            );
        };

        $xobject = $this->writer->defer($columnWidthPx * self::PX_TO_PT, $boxHeightPx * self::PX_TO_PT, $fontRefs, $opsBuilder);
        $canvas->placeXObject($xobject, $columnXPx, $boxTopPx + $boxHeightPx);
    }

    private function hasPagesCounter(MarginBoxContent $content): bool
    {
        foreach ($content->parts as $part) {
            if ($part === CounterRef::Pages) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string|CounterRef> $parts */
    private function assembleText(array $parts, int $pageNumber, int $totalPages): string
    {
        $text = '';
        foreach ($parts as $part) {
            $text .= $part instanceof CounterRef
                ? ($part === CounterRef::Page ? (string) $pageNumber : (string) $totalPages)
                : $part;
        }
        return $text;
    }

    /** Offset from the START of the box's OWN column (not the full content width — see class docblock). */
    private function horizontalOffset(string $horizontal, float $columnWidthPx, float $textWidthPx): float
    {
        return match ($horizontal) {
            'left' => 0.0,
            'center' => ($columnWidthPx - $textWidthPx) / 2,
            'right' => $columnWidthPx - $textWidthPx,
            default => 0.0, // unreachable: PageRuleFactory only lets left|center|right suffixes through
        };
    }

    /** Baseline offset from the TOP of a $boxHeightPx-tall strip that visually centers one line of text. */
    private function centeredBaselineFromTop(FontFace $face, float $boxHeightPx): float
    {
        $ascent = $this->measurer->ascent($face, self::FONT_SIZE_PX);
        $descent = $this->measurer->descent($face, self::FONT_SIZE_PX);
        return ($boxHeightPx + $ascent - $descent) / 2;
    }

    private function color(): Color
    {
        return new Color(0x55, 0x55, 0x55);
    }

    private function rg(Color $color): string
    {
        return sprintf('%.3F %.3F %.3F rg', $color->r / 255, $color->g / 255, $color->b / 255);
    }
}
