<?php

declare(strict_types=1);

namespace Pliego\Paint;

use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\ImageFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;
use Pliego\Page\Page;
use Pliego\Text\FontCatalog;

final readonly class Painter
{
    /** underlinePosition/underlineThickness fallback (em-relative) when a font has no post
     *  table (TtfFont::underlineMetrics() === null). Documented in the M1-T7 brief: -0.1em
     *  position (same sign convention as a real post table: negative = below the baseline),
     *  0.05em thickness. */
    private const float FALLBACK_UNDERLINE_POSITION_EM = -0.1;
    private const float FALLBACK_UNDERLINE_THICKNESS_EM = 0.05;

    public function __construct(private FontCatalog $catalog) {}

    public function paint(Page $page, Canvas $canvas): void
    {
        foreach ($page->fragments as $fragment) {
            if ($fragment instanceof BoxFragment) {
                if ($fragment->background !== null) {
                    $canvas->fillRect($fragment->rect, $fragment->background);
                }
                $this->paintBorders($fragment, $canvas);
            } elseif ($fragment instanceof TextFragment) {
                // InlineFlowContext::closeLine() emite un TextFragment con text === '' y
                // rect->width === 0.0 para la línea vacía que deja un <br> forzado — nada que
                // pintar (ni fillText, ni underline, ni por tanto registro de cara/glifos vía
                // Canvas::fillText()).
                if ($fragment->text === '' && $fragment->rect->width === 0.0) {
                    continue;
                }
                $canvas->fillText($fragment);
                if ($fragment->underline) {
                    $this->paintUnderline($fragment, $canvas);
                }
            } elseif ($fragment instanceof ImageFragment) {
                continue; // M3-T4 lo consume
            }
        }
    }

    /**
     * Subrayado bajo la baseline, con posición/grosor de la tabla `post` de la cara del
     * fragmento (o el fallback documentado si la fuente no tiene `post`). Por fragmento
     * (per-run-slice): subrayados continuos a través de varios fragmentos consecutivos con el
     * mismo estilo se fusionarán en un milestone posterior (M1 no lo hace).
     */
    private function paintUnderline(TextFragment $fragment, Canvas $canvas): void
    {
        $font = $this->catalog->faceByKey($fragment->faceKey)->font;
        $metrics = $font->underlineMetrics();
        if ($metrics !== null) {
            [$position, $thickness] = $metrics;
            $unitsPerEm = $font->unitsPerEm();
            $positionPx = $position / $unitsPerEm * $fragment->fontSizePx;
            $thicknessPx = $thickness / $unitsPerEm * $fragment->fontSizePx;
        } else {
            $positionPx = self::FALLBACK_UNDERLINE_POSITION_EM * $fragment->fontSizePx;
            $thicknessPx = self::FALLBACK_UNDERLINE_THICKNESS_EM * $fragment->fontSizePx;
        }

        // $positionPx es NEGATIVA (bajo la baseline); restarla desplaza la Y hacia abajo (px
        // CSS: origen arriba-izquierda, Y crece hacia abajo).
        $y = $fragment->baselineY - $positionPx;
        $canvas->strokeLine(
            $fragment->rect->x,
            $y,
            $fragment->rect->x + $fragment->rect->width,
            $y,
            $thicknessPx,
            $fragment->color,
        );
    }

    /**
     * css-backgrounds-3 §painting order: background, LUEGO bordes visibles (style Solid &&
     * widthPx > 0), antes que los hijos (que llegan después en el orden de flatten() de
     * Paginator). Orden entre lados: top, right, bottom, left (orden clockwise del shorthand
     * CSS) — los rects horizontales (top/bottom) cubren toda la anchura de la caja; los
     * verticales (left/right) encajan ENTRE ellos (alto = h - topW - bottomW). Esto deja una
     * junta simple sin solape en las esquinas, no un miter real (eso es un milestone de bordes
     * completos posterior).
     */
    private function paintBorders(BoxFragment $fragment, Canvas $canvas): void
    {
        $rect = $fragment->rect;
        $borders = $fragment->borders;
        // Solo el ancho de los lados VISIBLES reserva espacio para el rect vertical entre ellos
        // (un lado con style None no ocupa hueco, igual que en el modelo de caja CSS 2.2 §8.5.3:
        // "if border-style is none... the computed value of the border width is 0").
        $topW = $this->effectiveWidth($borders->top);
        $bottomW = $this->effectiveWidth($borders->bottom);
        $middleHeight = $rect->height - $topW - $bottomW;

        $this->paintBorderSide($borders->top, $canvas, new Rect($rect->x, $rect->y, $rect->width, $topW));
        $this->paintBorderSide(
            $borders->right,
            $canvas,
            new Rect($rect->right() - $borders->right->widthPx, $rect->y + $topW, $borders->right->widthPx, $middleHeight),
        );
        $this->paintBorderSide(
            $borders->bottom,
            $canvas,
            new Rect($rect->x, $rect->bottom() - $bottomW, $rect->width, $bottomW),
        );
        $this->paintBorderSide(
            $borders->left,
            $canvas,
            new Rect($rect->x, $rect->y + $topW, $borders->left->widthPx, $middleHeight),
        );
    }

    /**
     * BorderSide::$color es ?Color por tipo, aunque ComputedStyle nunca produce null (T3:
     * currentColor eager) — guardia defensiva, nunca debería activarse desde el pipeline real.
     */
    private function paintBorderSide(BorderSide $side, Canvas $canvas, Rect $rect): void
    {
        if ($side->style !== BorderStyle::Solid || $side->widthPx <= 0.0 || $side->color === null) {
            return;
        }
        $canvas->fillRect($rect, $side->color);
    }

    private function effectiveWidth(BorderSide $side): float
    {
        return $side->style === BorderStyle::Solid ? $side->widthPx : 0.0;
    }
}
