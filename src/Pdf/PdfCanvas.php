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
        private readonly ImageRegistry $images,
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
        // M2-T7's deferred-form XObjects (XO*, from placeXObject()) and M3-T4's image XObjects
        // (Im*, from drawImage()) share this page's /Resources /XObject dict — distinct name
        // prefixes, so the merge can never collide.
        //
        // M6-T5: extGStatePageResources() follows the SAME "accumulates globally, every page
        // over-includes" convention as fonts/images pageResources() (see PdfWriter) — a page
        // painted before a later /GSn was ever registered simply lists fewer entries; harmless
        // (a PDF Resources dict is allowed to list resources this page's content stream never
        // references, ISO 32000-1 §7.8.3).
        $this->writer->addPage(
            $this->paper->widthPx() * self::PX_TO_PT,
            $this->paper->heightPx() * self::PX_TO_PT,
            $this->ops,
            $this->fonts->pageResources(),
            [...$this->xobjectRefs, ...$this->images->pageResources()],
            $this->writer->extGStatePageResources(),
        );
    }

    public function fillRect(Rect $rect, Color $color): void
    {
        $x = ($rect->x + $this->offsetX) * self::PX_TO_PT;
        $y = ($this->paper->heightPx() - ($rect->y + $this->offsetY) - $rect->height) * self::PX_TO_PT;
        $body = sprintf(
            "%s %.2F %.2F %.2F %.2F re f\n",
            $this->rg($color),
            $x,
            $y,
            $rect->width * self::PX_TO_PT,
            $rect->height * self::PX_TO_PT,
        );
        $this->emitWithAlpha($color->alpha, $body);
    }

    /**
     * M7-T5 (ISO 32000-1 §8.5.4, "Clipping Path Operators"): `q` abre un scope propio (recortes
     * PDF son parte del graphics state, revertidos por el `Q` de restoreClip() -- nunca deben
     * "escaparse" al resto de la página), luego el rect en pt (misma transformación px CSS -> pt
     * PDF que fillRect: flip de Y + offsetX/offsetY + escala 0.75) se añade al path actual con
     * `re`, y `W n` lo fija como clipping path SIN pintarlo (`n` = "no-op paint", el operador de
     * pintado nulo -- el propio rect nunca se ve, solo recorta lo que venga después dentro de este
     * mismo scope).
     *
     * M8-T1 breadcrumb (preparando M8-T2, css-backgrounds-3 §5, BorderRadius): este `re` es un
     * rectángulo puro -- CORRECTO hoy porque overflow:hidden (el único caller, ver
     * Paint\Painter::paintFragment()) nunca se combina con border-radius (M8-T2 los introduce
     * juntos por primera vez). Un box con `border-radius` NO-CERO y `overflow: hidden` recorta a un
     * rectángulo de ESQUINAS REDONDEADAS (spec: el clip sigue la curva del border-box, no su bounding
     * box), así que esta llamada tendrá que sustituirse por un path Bézier (4 arcos, mismo criterio
     * que el `fillRoundedRect`/paths Bézier que M8-T2 añade a esta clase) cuando ESE box tenga un
     * BorderRadius no-cero -- `clipRect()` seguirá siendo válida tal cual para el caso común
     * (border-radius: 0, la inmensa mayoría de los overflow:hidden existentes), así que lo más
     * probable es que M8-T2 añada un `clipRoundedRect(Rect, BorderRadius)` NUEVO en vez de mutar
     * este método, y Painter decida cuál invocar según si el fragment trae radios no-cero.
     */
    public function clipRect(Rect $rect): void
    {
        $x = ($rect->x + $this->offsetX) * self::PX_TO_PT;
        $y = ($this->paper->heightPx() - ($rect->y + $this->offsetY) - $rect->height) * self::PX_TO_PT;
        $this->ops .= sprintf(
            "q\n%.2F %.2F %.2F %.2F re W n\n",
            $x,
            $y,
            $rect->width * self::PX_TO_PT,
            $rect->height * self::PX_TO_PT,
        );
    }

    /** Cierra el scope abierto por clipRect() -- ver su docblock. */
    public function restoreClip(): void
    {
        $this->ops .= "Q\n";
    }

    public function fillText(TextFragment $text): void
    {
        // M6-T5: fillText() recibe el TextFragment ENTERO (Painter no lo intercepta, ver su
        // docblock) — combina $text->color con $text->opacity AQUÍ, con el mismo Color::
        // withOpacity() que Painter usa para fillRect/strokeLine.
        $color = $text->color->withOpacity($text->opacity);
        if ($color->alpha !== null && $color->alpha <= 0.0) {
            return; // completamente transparente: no pinta nada (ni registra glifos).
        }
        $x = ($text->rect->x + $this->offsetX) * self::PX_TO_PT;
        $baseline = ($this->paper->heightPx() - ($text->baselineY + $this->offsetY)) * self::PX_TO_PT;
        $resourceName = $this->fonts->resourceNameFor($text->faceKey);
        $hex = $this->fonts->embedderFor($text->faceKey)->encode($text->text);
        $body = sprintf(
            "BT /%s %.2F Tf %s %.2F %.2F Td <%s> Tj ET\n",
            $resourceName,
            $text->fontSizePx * self::PX_TO_PT,
            $this->rg($color),
            $x,
            $baseline,
            $hex,
        );
        $this->emitWithAlpha($color->alpha, $body);
    }

    public function strokeLine(float $x1, float $y1, float $x2, float $y2, float $widthPx, Color $color): void
    {
        $px1 = ($x1 + $this->offsetX) * self::PX_TO_PT;
        $py1 = ($this->paper->heightPx() - ($y1 + $this->offsetY)) * self::PX_TO_PT;
        $px2 = ($x2 + $this->offsetX) * self::PX_TO_PT;
        $py2 = ($this->paper->heightPx() - ($y2 + $this->offsetY)) * self::PX_TO_PT;
        $body = sprintf(
            "%s\n%.2F w\n%.2F %.2F m %.2F %.2F l S\n",
            $this->rgStroke($color),
            $widthPx * self::PX_TO_PT,
            $px1,
            $py1,
            $px2,
            $py2,
        );
        $this->emitWithAlpha($color->alpha, $body);
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

    /**
     * M3-T4: draws the image XObject for $imageKey (ImageRegistry::xobjectFor(), lazy + memoized
     * — the same imageKey drawn twice reuses the same XObject) scaled to fill $rectPx (content
     * box px, same coordinate space as fillRect/fillText: offsetX/offsetY-relative, top-left
     * origin) — `q wPt 0 0 hPt xPt yPt cm /ImN Do Q` (ISO 32000-1 §8.10.2: the image's own
     * (0,0)-(1,1) unit square is remapped to the destination rect by the `cm` matrix; no
     * translation/rotation needed beyond the width/height scale + origin offset). $xPt/$yPt are
     * the DESTINATION rect's bottom-left corner in PDF space (Y grows up): the same flip used by
     * fillRect (y = pageHeightPx - rectPx.y - rectPx.height, offset-adjusted, then ×0.75).
     *
     * M6-T5: $opacity < 1.0 inserts `/GSn gs` right after the opening `q` — same q/Q scope the
     * image draw already used for its `cm` matrix, so no extra wrapping is needed; opacity<=0
     * (fully transparent) paints NOTHING — not even the XObject registration/`Do` call, same
     * "transparent skips the op entirely" rule as fillRect/fillText/strokeLine (see
     * emitWithAlpha()).
     */
    public function drawImage(Rect $rectPx, string $imageKey, float $opacity = 1.0): void
    {
        if ($opacity <= 0.0) {
            return;
        }
        $ref = $this->images->xobjectFor($imageKey);
        $xPt = ($rectPx->x + $this->offsetX) * self::PX_TO_PT;
        $yPt = ($this->paper->heightPx() - ($rectPx->y + $this->offsetY) - $rectPx->height) * self::PX_TO_PT;
        $wPt = $rectPx->width * self::PX_TO_PT;
        $hPt = $rectPx->height * self::PX_TO_PT;
        // El caso opaco (la inmensa mayoría) conserva el formato BYTE-A-BYTE anterior a esta tarea
        // ("q %.2F..." con espacio, sin salto de línea) — solo opacity<1.0 inserta el `gs` tras un
        // salto de línea propio, en un formato distinto.
        $this->ops .= $opacity < 1.0
            ? sprintf("q\n/%s gs\n%.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $this->writer->extGStateResourceName($opacity), $wPt, $hPt, $xPt, $yPt, $ref->name)
            : sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $wPt, $hPt, $xPt, $yPt, $ref->name);
        $this->xobjectRefs[$ref->name] = $ref->objectId;
    }

    /**
     * ISO 32000-1 §8.4.5 (ExtGState /ca /CA): wraps $body in its OWN q/Q scope with a `/GSn gs`
     * right before it, whenever $alpha is set and less than fully opaque — `gs` sets PERSISTENT
     * graphics state (ISO 32000-1 §8.4: unlike `rg`/`RG`, which only affect the NEXT painting
     * operation, an alpha set by `gs` stays in effect for every subsequent op until changed or
     * until the enclosing q/Q scope ends) — wrapping every alpha'd op in its own q/Q, rather than
     * emitting a single `gs` and trusting every op after it to also want that alpha (or resetting
     * to an opaque GS1 afterwards), keeps each op's alpha local and impossible to leak into an
     * unrelated later op. A fully-opaque $alpha (null or >=1.0) is the common case (the vast
     * majority of colors) and emits $body completely unchanged — zero bytes of overhead,
     * byte-for-byte identical to the pre-M6-T5 output. $alpha<=0.0 (fully transparent — the
     * 'transparent' keyword, or opacity multiplying a color down to 0) paints NOTHING: not even
     * a q/Q pair, since a fully transparent op has no visible effect regardless of scoping.
     */
    private function emitWithAlpha(?float $alpha, string $body): void
    {
        if ($alpha === null || $alpha >= 1.0) {
            $this->ops .= $body;
            return;
        }
        if ($alpha <= 0.0) {
            return;
        }
        $name = $this->writer->extGStateResourceName($alpha);
        $this->ops .= "q\n/$name gs\n" . $body . "Q\n";
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
