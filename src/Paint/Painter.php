<?php

declare(strict_types=1);

namespace Pliego\Paint;

use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\Fragment;
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
            $this->paintFragment($fragment, $canvas);
        }
    }

    /**
     * M4-T5: extraído de paint() para poder RECURSAR — una hoja compuesta atómica (Paginator,
     * ver su docblock de flatten()) llega aquí como un BoxFragment con $children NO vacío (antes
     * de T5, todo BoxFragment que llegaba a un Page ya tenía children === [], aplanado de
     * antemano; esa invariante ya no es universal). Orden de pintado sin cambios: fondo, luego
     * bordes, luego — solo para el caso atómico — los hijos, en el mismo orden que
     * Paginator::relocate() los deja (documento order, ver brief T5).
     */
    private function paintFragment(Fragment $fragment, Canvas $canvas): void
    {
        if ($fragment instanceof BoxFragment) {
            // M6-T5: opacity PROPIA de este BoxFragment multiplica el alpha de su fondo (Color::
            // withOpacity() — no-op si opacity es 1.0, ver su docblock) — los HIJOS (pintados más
            // abajo, vía recursión) NO reciben esta opacity (divergencia M6 documentada, ver
            // ComputedStyle::$opacity): cada uno trae la SUYA propia.
            if ($fragment->background !== null) {
                $canvas->fillRect($fragment->rect, $fragment->background->withOpacity($fragment->opacity));
            }
            $this->paintBorders($fragment, $canvas);
            foreach ($fragment->children as $child) {
                $this->paintFragment($child, $canvas);
            }
        } elseif ($fragment instanceof TextFragment) {
            // InlineFlowContext::closeLine() emite un TextFragment con text === '' y
            // rect->width === 0.0 para la línea vacía que deja un <br> forzado — nada que
            // pintar (ni fillText, ni underline, ni por tanto registro de cara/glifos vía
            // Canvas::fillText()).
            if ($fragment->text === '' && $fragment->rect->width === 0.0) {
                return;
            }
            // M6-T5: fillText() recibe el TextFragment ENTERO (a diferencia de fillRect/
            // strokeLine, que reciben un Color suelto) — combina $fragment->color con
            // $fragment->opacity POR DENTRO (PdfCanvas::fillText()), así que aquí no hace falta
            // (ni se puede, sin clonar el fragmento) tocar el color de antemano.
            $canvas->fillText($fragment);
            if ($fragment->underline) {
                $this->paintUnderline($fragment, $canvas);
            }
        } elseif ($fragment instanceof ImageFragment) {
            $canvas->drawImage($fragment->rect, $fragment->imageKey, $fragment->opacity);
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
        // M6-T5: strokeLine() recibe un Color suelto (a diferencia de fillText) — a diferencia de
        // fillText, aquí SÍ hace falta combinar $fragment->opacity a mano (mismo Color::
        // withOpacity() que fillRect/paintBorderSide).
        $canvas->strokeLine(
            $fragment->rect->x,
            $y,
            $fragment->rect->x + $fragment->rect->width,
            $y,
            $thicknessPx,
            $fragment->color->withOpacity($fragment->opacity),
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

        $this->paintBorderSide($borders->top, $canvas, new Rect($rect->x, $rect->y, $rect->width, $topW), $fragment->opacity);
        $this->paintBorderSide(
            $borders->right,
            $canvas,
            new Rect($rect->right() - $borders->right->widthPx, $rect->y + $topW, $borders->right->widthPx, $middleHeight),
            $fragment->opacity,
        );
        $this->paintBorderSide(
            $borders->bottom,
            $canvas,
            new Rect($rect->x, $rect->bottom() - $bottomW, $rect->width, $bottomW),
            $fragment->opacity,
        );
        $this->paintBorderSide(
            $borders->left,
            $canvas,
            new Rect($rect->x, $rect->y + $topW, $borders->left->widthPx, $middleHeight),
            $fragment->opacity,
        );
    }

    /**
     * BorderSide::$color es ?Color por tipo, aunque ComputedStyle nunca produce null (T3:
     * currentColor eager) — guardia defensiva, nunca debería activarse desde el pipeline real.
     */
    private function paintBorderSide(BorderSide $side, Canvas $canvas, Rect $rect, float $opacity): void
    {
        if ($side->style !== BorderStyle::Solid || $side->widthPx <= 0.0 || $side->color === null) {
            return;
        }
        $canvas->fillRect($rect, $side->color->withOpacity($opacity));
    }

    private function effectiveWidth(BorderSide $side): float
    {
        return $side->style === BorderStyle::Solid ? $side->widthPx : 0.0;
    }
}
