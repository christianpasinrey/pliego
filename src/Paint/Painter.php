<?php

declare(strict_types=1);

namespace Pliego\Paint;

use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\Color;
use Pliego\Css\Value\Gradient;
use Pliego\Css\WarningCollector;
use Pliego\Layout\Fragment\BorderRadius;
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

    /**
     * M8-T2: $warnings es opcional (mismo patrón que Layout\*FormattingContext) — sigue siendo
     * `null` en TODOS los tests preexistentes de esta clase (Painter nunca necesitó avisar de
     * nada hasta esta tarea); Engine lo conecta al MISMO WarningCollector compartido que Style/
     * Box/Layout (ver Engine::render()), así que el aviso de "mixed border widths with
     * border-radius approximated" (ver paintBorders()) sale por el mismo canal que cualquier otro
     * warning del render.
     */
    public function __construct(private FontCatalog $catalog, private ?WarningCollector $warnings = null) {}

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
            $this->paintBackground($fragment->rect, $fragment->background, $fragment->backgroundGradient, $fragment->opacity, $fragment->borderRadius, $canvas);
            $this->paintBorders($fragment->rect, $fragment->borders, $fragment->opacity, $fragment->borderRadius, $canvas);
            // M7-T5 (css-overflow-3): $clipsChildren envuelve SOLO a los descendientes en un
            // scope de recorte PDF (Canvas::clipRect()/restoreClip()) al rect BORDER-BOX de ESTA
            // caja — el fondo/borde propios, ya pintados arriba, no lo necesitan (coinciden
            // exactamente con ese rect). Paginator::flatten() garantiza que una caja clipsChildren
            // NUNCA llega aquí descompuesta (mismo camino que $atomic, ver su docblock), así que
            // el subárbol completo bajo el clip siempre está intacto.
            //
            // M8-T2: $fragment->borderRadius decide clipRect() (radio cero, byte-idéntico a antes
            // de esta tarea) vs. clipRoundedRect() (radio no-cero, recorta a la curva del
            // border-box en vez de a su bounding box) -- ver el breadcrumb M8-T1 que este `if`
            // resuelve.
            if ($fragment->clipsChildren) {
                if ($fragment->borderRadius->isZero()) {
                    $canvas->clipRect($fragment->rect);
                } else {
                    $canvas->clipRoundedRect($fragment->rect, $fragment->borderRadius);
                }
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
            // lógica de slice-awareness propia, solo pinta lo que trae el BorderSet. M8-T2:
            // $fragment->borderRadius llega YA con las esquinas no-extremas a cero (misma
            // convención de slice, ver InlineFlowContext::buildInlineBoxFragment()).
            $this->paintBackground($fragment->rect, $fragment->background, $fragment->backgroundGradient, $fragment->opacity, $fragment->borderRadius, $canvas);
            $this->paintBorders($fragment->rect, $fragment->borders, $fragment->opacity, $fragment->borderRadius, $canvas);
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
     * M8-T2: fondo de una caja (BoxFragment o InlineBoxFragment) — fillRect() cuando $radius es
     * cero (byte-idéntico a antes de esta tarea), fillRoundedRect() en caso contrario (path
     * Bézier, ver Pdf\PdfCanvas::roundedRectPathOps()).
     *
     * M8-T3 (css-images-3 §3.1 reducido): $gradient, si lo hay, se pinta DESPUÉS del color (ver
     * ComputedStyle::$backgroundGradient) — ambos pueden coexistir (el color, cuando lo hay, sirve
     * de fondo visible detrás del gradiente; M8 no soporta alpha en stops, así que el gradiente en
     * sí siempre es opaco y normalmente lo tapa por completo, pero pintar el color de todas formas
     * es la interpretación correcta del spec y barata de mantener). Canvas::paintGradient() recibe
     * el MISMO $radius (recorta a la curva del border-box, igual criterio que fillRoundedRect()).
     */
    private function paintBackground(Rect $rect, ?Color $background, ?Gradient $gradient, float $opacity, BorderRadius $radius, Canvas $canvas): void
    {
        if ($background !== null) {
            $color = $background->withOpacity($opacity);
            if ($radius->isZero()) {
                $canvas->fillRect($rect, $color);
            } else {
                $canvas->fillRoundedRect($rect, $radius, $color);
            }
        }
        if ($gradient !== null) {
            $canvas->paintGradient($rect, $gradient, $radius);
        }
    }

    /**
     * css-backgrounds-3 §painting order: background, LUEGO bordes visibles (style Solid &&
     * widthPx > 0), antes que los hijos (que llegan después en el orden de flatten() de
     * Paginator). Orden entre lados: top, right, bottom, left (orden clockwise del shorthand
     * CSS) — los rects horizontales (top/bottom) cubren toda la anchura de la caja; los
     * verticales (left/right) encajan ENTRE ellos (alto = h - topW - bottomW). Esto deja una
     * junta simple sin solape en las esquinas, no un miter real (eso es un milestone de bordes
     * completos posterior).
     *
     * M8-T2 (css-backgrounds-3 §5): con $radius cero, comportamiento IDÉNTICO a antes de esta
     * tarea (paintBordersFlat(), el mismo código de siempre). Con $radius no-cero:
     *   - los lados VISIBLES (style != None) son todos IDÉNTICOS entre sí (mismo ancho/estilo/
     *     color, ver bordersUniform()): un ÚNICO Canvas::fillRoundedRectRing() (path anular
     *     outer-menos-inner, f* even-odd). El offset hacia el rect interior es POR LADO
     *     (effectiveWidth(): un lado None aporta 0), no un único $bw simétrico -- así un lado
     *     suprimido por box-decoration-break:slice (InlineFlowContext::buildInlineBoxFragment(),
     *     BorderStyle::None en un lateral no-extremo) queda con el borde interior a ras del
     *     exterior en ESE lado (ancho de relleno cero ahí, ninguna curva "cortada" a mitad de
     *     esquina) en vez de descalificar el path anular entero -- M8-T2 review Finding 1: antes
     *     de este fix, bordersUniform() exigía los 4 lados byte-idénticos SIN excepción, así que
     *     un slice con un lateral suprimido caía siempre en la rama "mixed" de abajo (esquinas
     *     rectas + warning falso, aunque el borde declarado fuera uniforme). El radio interior
     *     por esquina sigue reduciéndose por el ancho COMÚN de los lados visibles (mismo $bw que
     *     antes) porque, para cuando se llega aquí, bordersUniform() YA garantizó que toda
     *     esquina con radio > 0 tiene AMBOS lados adyacentes visibles -- por construcción para un
     *     slice de InlineFlowContext (las esquinas tocadas por un lado suprimido ya llegan con
     *     radio 0 desde el slicing, ver su docblock), pero por una GUARDA EXPLÍCITA en
     *     bordersUniform() para cualquier otro BoxFragment/InlineBoxFragment (M8-T2 fix, Reviewer
     *     Important: un borde AUTOR-declarado parcial, p.ej. `border-bottom: 8px solid;
     *     border-radius: 15px`, no tiene ninguna garantía estructural de eso -- ver el docblock de
     *     bordersUniform()) -- nunca hace falta mezclar dos anchos distintos en la resta de un
     *     mismo corner.
     *   - los lados VISIBLES NO son idénticos entre sí (ancho/color/estilo heterogéneo, esto es
     *     heterogeneidad REAL declarada por el usuario, no una supresión de slice): la geometría
     *     anular de un solo color no representa un borde con lados distintos -- aproximación
     *     adjudicada M8: paintBordersFlat() (los mismos 4 rects rectos de siempre, radios
     *     ignorados en el pintado de bordes) + un warning UNA SOLA VEZ ("mixed border widths with
     *     border-radius approximated", ver WarningCollector::addWarningOnce()).
     */
    /**
     * M7-T4: generalizado de `(BoxFragment $fragment)` a params sueltos (rect/borders/opacity) —
     * InlineBoxFragment necesita EXACTAMENTE la misma lógica de pintado (sin lados
     * slice-suprimidos, ya resueltos por InlineFlowContext antes de construir su BorderSet, ver su
     * docblock) pero no es un BoxFragment, así que ambos llamadores (paintFragment() para cada
     * uno) pasan sus propios campos homónimos en vez de compartir un tipo común.
     */
    private function paintBorders(Rect $rect, BorderSet $borders, float $opacity, BorderRadius $radius, Canvas $canvas): void
    {
        if ($radius->isZero() || !$borders->isVisible()) {
            $this->paintBordersFlat($rect, $borders, $opacity, $canvas);
            return;
        }
        $uniform = $this->bordersUniform($borders, $radius);
        if ($uniform === null) {
            $this->warnings?->addWarningOnce(
                'mixed-border-widths-with-radius',
                'mixed border widths with border-radius approximated',
            );
            $this->paintBordersFlat($rect, $borders, $opacity, $canvas);
            return;
        }
        if ($uniform->color === null) {
            // BorderSide::$color es ?Color por tipo, aunque ComputedStyle nunca produce null
            // (T3: currentColor eager) -- guardia defensiva, mismo criterio que paintBorderSide().
            return;
        }
        // M8-T2 review Finding 1: offset POR LADO (un lado None -- suprimido por slice --
        // aporta 0, ver el docblock de paintBorders() de arriba), no un único $bw simétrico. Esto
        // deja el rect interior a ras del exterior en el lado suprimido (relleno de ancho cero
        // ahí -- ese lado, correctamente, no pinta nada).
        $bw = $uniform->widthPx;
        $topW = $this->effectiveWidth($borders->top);
        $rightW = $this->effectiveWidth($borders->right);
        $bottomW = $this->effectiveWidth($borders->bottom);
        $leftW = $this->effectiveWidth($borders->left);
        $inner = new Rect(
            $rect->x + $leftW,
            $rect->y + $topW,
            $rect->width - $leftW - $rightW,
            $rect->height - $topW - $bottomW,
        );
        $innerRadius = new BorderRadius(
            max(0.0, $radius->tl - $bw),
            max(0.0, $radius->tr - $bw),
            max(0.0, $radius->br - $bw),
            max(0.0, $radius->bl - $bw),
        );
        $canvas->fillRoundedRectRing($rect, $radius, $inner, $innerRadius, $uniform->color->withOpacity($opacity));
    }

    /**
     * M8-T2 review Finding 1: la uniformidad ya NO exige los 4 BorderSide byte-idénticos SIN
     * excepción -- exige que los lados VISIBLES (style != None) sean idénticos entre sí (mismo
     * ancho/estilo/color); un lado None (BorderStyle::None, el que InlineFlowContext deja en un
     * lateral no-extremo de un slice de box-decoration-break:slice, ver su docblock) participa
     * con ancho efectivo 0 en la geometría (paintBorders() de arriba) pero NO tiene que
     * "coincidir" con nada para que el path anular siga siendo válido -- geométricamente, un lado
     * suprimido simplemente no reserva relleno en ESE lado del anillo, que es exactamente la
     * semántica de slice (sin borde ahí). Antes de este fix, un slice con un lateral suprimido
     * (Solid en 3 lados + None en 1) caía siempre por esta comprobación -- BorderSide::None !=
     * BorderSide::Solid siempre -- perdiendo el path anular Y emitiendo el warning de "mixed" de
     * forma FALSA (el borde declarado era uniforme; solo el slicing suprimió un lado).
     *
     * Devuelve `null` (heterogeneidad REAL, declarada por el usuario) cuando dos lados visibles
     * difieren entre sí; si TODOS los lados fueran None, paintBorders() nunca llega aquí (ya
     * cortó antes vía `!$borders->isVisible()`), así que $styled siempre trae al menos un
     * elemento cuando este método se invoca desde el pipeline real.
     *
     * M8-T2 fix (Reviewer, Important): además, devuelve `null` cuando CUALQUIER esquina con
     * radio > 0 tiene un lado adyacente con estilo None -- guarda que el relajo de arriba
     * necesitaba y no tenía. Ese relajo asumía "toda esquina con radio>0 tiene ambos lados
     * adyacentes visibles", pero esa invariante SOLO la garantiza InlineFlowContext::
     * buildInlineBoxFragment() por construcción para sus slices (pone a 0 tl/bl en toda slice que
     * no sea la primera y tr/br en toda slice que no sea la última -- exactamente las esquinas que
     * tocan el lado lateral que ese mismo método acaba de suprimir a None, ver
     * InlineFlowContext.php:793-799); NADA fuerza esa misma correspondencia para un BoxFragment
     * "normal" armado por BlockFlowContext a partir de ComputedStyle -- un autor puede declarar
     * perfectamente `border-bottom: 8px solid; border-radius: 15px` (un solo lado styled, radio en
     * las 4 esquinas), que es heterogeneidad real (3 lados sin borde) aunque $styled solo tenga UN
     * elemento y por tanto pase trivialmente el bucle de arriba. Sin esta guarda, ese caso pintaba
     * un anillo completo de 4 esquinas curvas usando el ancho del único lado styled -- tinta
     * fantasma en las esquinas superiores, donde el autor no declaró borde alguno, y sin el aviso
     * de "mixed" que debería haber saltado. La esquina bottom-left/bottom-right de un radio
     * declarado SOLO en las esquinas inferiores (border-bottom-{left,right}-radius) también cae
     * aquí -- cada una toca left/right (None), así que el mismo defecto (medialuna en el lado
     * lateral de esa esquina) se produciría igual; ver el test "edge case" en PainterTest.
     */
    private function bordersUniform(BorderSet $borders, BorderRadius $radius): ?BorderSide
    {
        $styled = array_values(array_filter(
            [$borders->top, $borders->right, $borders->bottom, $borders->left],
            static fn(BorderSide $side): bool => $side->style !== BorderStyle::None,
        ));
        if ($styled === []) {
            return null;
        }
        $first = $styled[0];
        foreach ($styled as $side) {
            if ($side != $first) {
                return null;
            }
        }
        $corners = [
            [$radius->tl, $borders->top, $borders->left],
            [$radius->tr, $borders->top, $borders->right],
            [$radius->br, $borders->bottom, $borders->right],
            [$radius->bl, $borders->bottom, $borders->left],
        ];
        foreach ($corners as [$cornerRadius, $sideA, $sideB]) {
            if ($cornerRadius > 0.0 && ($sideA->style === BorderStyle::None || $sideB->style === BorderStyle::None)) {
                return null;
            }
        }
        return $first;
    }

    /** El pintado de 4 rects rectos de siempre (pre-M8-T2) -- usado tanto para $radius cero como
     * para la aproximación de anchos/colores mixtos con radio (ver paintBorders()). */
    private function paintBordersFlat(Rect $rect, BorderSet $borders, float $opacity, Canvas $canvas): void
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
