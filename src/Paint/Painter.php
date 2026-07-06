<?php

declare(strict_types=1);

namespace Pliego\Paint;

use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
use Pliego\Layout\Fragment\BorderSet;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\Fragment;
use Pliego\Layout\Fragment\ImageFragment;
use Pliego\Layout\Fragment\InlineBoxFragment;
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
            $this->paintBorders($fragment->rect, $fragment->borders, $fragment->opacity, $canvas);
            // M7-T5 (css-overflow-3): $clipsChildren envuelve SOLO a los descendientes en un
            // scope de recorte PDF (Canvas::clipRect()/restoreClip()) al rect BORDER-BOX de ESTA
            // caja — el fondo/borde propios, ya pintados arriba, no lo necesitan (coinciden
            // exactamente con ese rect). Paginator::flatten() garantiza que una caja clipsChildren
            // NUNCA llega aquí descompuesta (mismo camino que $atomic, ver su docblock), así que
            // el subárbol completo bajo el clip siempre está intacto.
            if ($fragment->clipsChildren) {
                $canvas->clipRect($fragment->rect);
                foreach ($fragment->children as $child) {
                    $this->paintFragment($child, $canvas);
                }
                $canvas->restoreClip();
            } else {
                foreach ($fragment->children as $child) {
                    $this->paintFragment($child, $canvas);
                }
            }
        } elseif ($fragment instanceof InlineBoxFragment) {
            // M7-T4: misma orden de pintado que un BoxFragment (fondo, luego bordes) — sin hijos
            // propios que recorrer (el "contenido" de la caja son los TextFragment/BoxFragment
            // VECINOS de esta misma línea, ya emitidos ANTES por InlineFlowContext::closeLine(),
            // ver su docblock de "orden de emisión"). Los lados de borde suprimidos por
            // box-decoration-break:slice (lateral en un slice no-extremo) ya llegan como
            // BorderStyle::None desde InlineFlowContext -- paintBorders() no necesita ninguna
            // lógica de slice-awareness propia, solo pinta lo que trae el BorderSet.
            if ($fragment->background !== null) {
                $canvas->fillRect($fragment->rect, $fragment->background->withOpacity($fragment->opacity));
            }
            $this->paintBorders($fragment->rect, $fragment->borders, $fragment->opacity, $canvas);
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
    /**
     * M7-T4: generalizado de `(BoxFragment $fragment)` a params sueltos (rect/borders/opacity) —
     * InlineBoxFragment necesita EXACTAMENTE la misma lógica de pintado (sin lados
     * slice-suprimidos, ya resueltos por InlineFlowContext antes de construir su BorderSet, ver su
     * docblock) pero no es un BoxFragment, así que ambos llamadores (paintFragment() para cada
     * uno) pasan sus propios campos homónimos en vez de compartir un tipo común.
     */
    private function paintBorders(Rect $rect, BorderSet $borders, float $opacity, Canvas $canvas): void
    {
        // Solo el ancho de los lados VISIBLES reserva espacio para el rect vertical entre ellos
        // (un lado con style None no ocupa hueco, igual que en el modelo de caja CSS 2.2 §8.5.3:
        // "if border-style is none... the computed value of the border width is 0").
        $topW = $this->effectiveWidth($borders->top);
        $bottomW = $this->effectiveWidth($borders->bottom);
        $middleHeight = $rect->height - $topW - $bottomW;

        $this->paintBorderSide($borders->top, $canvas, new Rect($rect->x, $rect->y, $rect->width, $topW), $opacity);
        $this->paintBorderSide(
            $borders->right,
            $canvas,
            new Rect($rect->right() - $borders->right->widthPx, $rect->y + $topW, $borders->right->widthPx, $middleHeight),
            $opacity,
        );
        $this->paintBorderSide(
            $borders->bottom,
            $canvas,
            new Rect($rect->x, $rect->bottom() - $bottomW, $rect->width, $bottomW),
            $opacity,
        );
        $this->paintBorderSide(
            $borders->left,
            $canvas,
            new Rect($rect->x, $rect->y + $topW, $borders->left->widthPx, $middleHeight),
            $opacity,
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
