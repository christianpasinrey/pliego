<?php

declare(strict_types=1);

namespace Pliego\Paint;

use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\BoxShadow;
use Pliego\Css\Value\Color;
use Pliego\Css\Value\Gradient;
use Pliego\Css\WarningCollector;
use Pliego\Image\ImageException;
use Pliego\Image\ImageLoader;
use Pliego\Image\ImagePathResolver;
use Pliego\Layout\Fragment\BorderRadius;
use Pliego\Layout\Fragment\BorderSet;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\Fragment;
use Pliego\Layout\Fragment\ImageFragment;
use Pliego\Layout\Fragment\InlineBoxFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;
use Pliego\Page\Page;
use Pliego\Style\BackgroundPosition;
use Pliego\Style\BackgroundSize;
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
     *
     * M8-T6 (css-backgrounds-3 §4 reducido): $images/$basePath, AMBOS obligatorios (sin default) --
     * background-image se carga EN TIEMPO DE PINTADO (arquitectura M8-T6, a diferencia de <img>,
     * que Box\BoxTreeBuilder carga en tiempo de LAYOUT) para mantener Layout puro (ver el brief de
     * esta tarea); $images es la MISMA instancia de Image\ImageLoader que Engine ya construye y
     * pasa a Box\BoxTreeBuilder (memoización compartida por path, ver el docblock de esa clase --
     * un <img> y un background-image que apunten al mismo fichero decodifican UNA sola vez).
     * $basePath es el MISMO Engine::basePath() contra el que Box\BoxTreeBuilder ya resuelve los
     * `src` relativos de <img> (ver Image\ImagePathResolver, extraído de ahí en esta misma tarea
     * para que ambos caminos de resolución nunca puedan divergir).
     */
    public function __construct(
        private FontCatalog $catalog,
        private ImageLoader $images,
        private string $basePath,
        private ?WarningCollector $warnings = null,
    ) {}

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
            // M8-T4 (css-backgrounds-3 §6 reducido): box-shadow se pinta ANTES que el fondo
            // propio del elemento (css-backgrounds-3 §painting order: box-shadow queda DEBAJO de
            // background/borde/contenido) -- InlineBoxFragment no tiene este campo (ver su
            // docblock: box-shadow declarado en una caja inline real avisa y se descarta, ver
            // InlineFlowContext::buildInlineBoxFragment()), así que esta llamada solo existe en
            // esta rama.
            $this->paintBoxShadow($fragment->rect, $fragment->boxShadow, $fragment->opacity, $fragment->borderRadius, $canvas);
            // M6-T5: opacity PROPIA de este BoxFragment multiplica el alpha de su fondo (Color::
            // withOpacity() — no-op si opacity es 1.0, ver su docblock) — los HIJOS (pintados más
            // abajo, vía recursión) NO reciben esta opacity (divergencia M6 documentada, ver
            // ComputedStyle::$opacity): cada uno trae la SUYA propia.
            $this->paintBackground(
                $fragment->rect,
                $fragment->background,
                $fragment->backgroundGradient,
                $fragment->backgroundImagePath,
                $fragment->backgroundSize,
                $fragment->backgroundRepeat,
                $fragment->backgroundPosition,
                $fragment->opacity,
                $fragment->borderRadius,
                $canvas,
            );
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
            // M8-T6: InlineBoxFragment nunca lleva background-image (ver su docblock/el de
            // BoxFragment::$backgroundImagePath) -- se pasan los defaults "sin imagen" explícitos.
            $this->paintBackground(
                $fragment->rect,
                $fragment->background,
                $fragment->backgroundGradient,
                null,
                BackgroundSize::Auto,
                false,
                BackgroundPosition::TopLeft,
                $fragment->opacity,
                $fragment->borderRadius,
                $canvas,
            );
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
     * M8-T4 (css-backgrounds-3 §6, reducido): box-shadow SIN inset (M8: rechazado ya en
     * DeclarationParser, ver su docblock) -- offsetX/offsetY desplazan el rect de sombra; blur=0
     * pinta UN rect/rect-redondeado (fillRect()/fillRoundedRect(), mismo criterio de radio que
     * paintBackground()) del color de la sombra; blur>0 aproxima el desenfoque con 4 capas
     * concéntricas.
     *
     * APROXIMACIÓN RUIDOSAMENTE DOCUMENTADA (NO es un blur Gaussiano real, ISO 32000-1 no define
     * ningún operador de desenfoque nativo y este motor no rasteriza off-screen): 4 rects/
     * rects-redondeados concéntricos, cada uno con 1/4 del alpha efectivo de la sombra, centrados
     * en el borde ORIGINAL de la sombra (blur "a caballo" del borde, mismo lenguaje que el spec
     * usa para describir el blur real) -- layer 0 (más pequeño) insetado blur/2, layer 3 (más
     * grande) expandido blur/2, los 2 intermedios repartidos EQUIDISTANTES entre ambos extremos
     * (paso = blur/3, NO blur/4 pese a que "cada capa se expande blur/4" pueda leerse en la
     * documentación de más alto nivel de este milestone -- blur/3 es la única división que hace
     * que layer 0/layer 3 caigan EXACTAMENTE en ±blur/2, los dos valores ancla que sí son
     * hand-verificables byte a byte). La superposición de las 4 capas (todas semitransparentes,
     * pintadas de la más pequeña a la más grande) acumula alpha visualmente hacia el centro del
     * blur SIN que este código calcule ningún alpha compuesto por-píxel -- es un efecto emergente
     * de apilar varios rects translúcidos, aceptable visualmente para los blurs pequeños (<=10px)
     * de los fixtures de este motor, pero NO intercambiable por un blur real si algún día se migra
     * a un renderer con soporte de máscaras blandas (M9+, si acaso).
     */
    private function paintBoxShadow(Rect $rect, ?BoxShadow $shadow, float $opacity, BorderRadius $radius, Canvas $canvas): void
    {
        if ($shadow === null) {
            return;
        }
        $baseRect = new Rect($rect->x + $shadow->offsetX, $rect->y + $shadow->offsetY, $rect->width, $rect->height);
        $color = $shadow->color->withOpacity($opacity);
        if ($shadow->blurRadius <= 0.0) {
            $this->fillShadowRect($baseRect, $radius, $color, $canvas);
            return;
        }
        $quarterAlpha = new Color($color->r, $color->g, $color->b, ($color->alpha ?? 1.0) / 4.0, $color->isCurrentColor);
        $step = $shadow->blurRadius / 3.0;
        for ($i = 0; $i < 4; $i++) {
            $delta = -$shadow->blurRadius / 2.0 + $i * $step;
            $layerRect = new Rect(
                $baseRect->x - $delta,
                $baseRect->y - $delta,
                max(0.0, $baseRect->width + 2.0 * $delta),
                max(0.0, $baseRect->height + 2.0 * $delta),
            );
            $layerRadius = new BorderRadius(
                max(0.0, $radius->tl + $delta),
                max(0.0, $radius->tr + $delta),
                max(0.0, $radius->br + $delta),
                max(0.0, $radius->bl + $delta),
            );
            $this->fillShadowRect($layerRect, $layerRadius, $quarterAlpha, $canvas);
        }
    }

    /** fillRect()/fillRoundedRect() según $radius -- mismo criterio que paintBackground(), usado
     *  tanto por paintBoxShadow() (blur=0 o cada capa de blur>0) para evitar duplicar el `if`. */
    private function fillShadowRect(Rect $rect, BorderRadius $radius, Color $color, Canvas $canvas): void
    {
        if ($radius->isZero()) {
            $canvas->fillRect($rect, $color);
        } else {
            $canvas->fillRoundedRect($rect, $radius, $color);
        }
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
    /**
     * M8-T6: orden de pintado extendido a 3 capas -- background-color (ya existía), LUEGO
     * background-image (esta tarea), LUEGO background-gradient (M8-T3, sin cambios de orden
     * respecto a antes). En la práctica imagen y gradiente nunca coexisten (DeclarationParser::
     * firstBackgroundLayer() solo conserva la PRIMERA capa CSS, y las ramas url()/gradient()/color
     * de background-image/background son mutuamente excluyentes -- ver DeclarationParser::
     * parseBackgroundImageValue()/parseBackgroundShorthand(), cada una resetea explícitamente a
     * null la otra), pero el orden se documenta de todas formas en vez de dar por sentado "solo uno
     * puede estar declarado".
     */
    private function paintBackground(
        Rect $rect,
        ?Color $background,
        ?Gradient $gradient,
        ?string $backgroundImagePath,
        BackgroundSize $backgroundSize,
        bool $backgroundRepeat,
        BackgroundPosition $backgroundPosition,
        float $opacity,
        BorderRadius $radius,
        Canvas $canvas,
    ): void {
        if ($background !== null) {
            $color = $background->withOpacity($opacity);
            if ($radius->isZero()) {
                $canvas->fillRect($rect, $color);
            } else {
                $canvas->fillRoundedRect($rect, $radius, $color);
            }
        }
        if ($backgroundImagePath !== null) {
            $this->paintBackgroundImage($rect, $backgroundImagePath, $backgroundSize, $backgroundRepeat, $backgroundPosition, $opacity, $radius, $canvas);
        }
        if ($gradient !== null) {
            $canvas->paintGradient($rect, $gradient, $radius);
        }
    }

    /**
     * M8-T6 (css-backgrounds-3 §4 reducido): carga (o falla suavemente) y pinta un
     * background-image dentro de un clip del border-box (radius-aware, mismo criterio que
     * $clipsChildren de Paint\Painter::paintFragment() -- pero un ámbito de clip INDEPENDIENTE,
     * abierto y cerrado aquí mismo, nunca compartido con el de overflow:hidden). $rawPath es el
     * valor CRUDO tal cual lo dejó ComputedStyle::$backgroundImagePath (sin resolver contra ningún
     * basePath todavía, ver su docblock) -- se resuelve aquí, en tiempo de pintado, contra
     * $this->basePath vía Image\ImagePathResolver (el MISMO helper que Box\BoxTreeBuilder usa para
     * `<img src="...">`, condición necesaria para que Pdf\ImageRegistry deduplique un <img> y un
     * background-image que compartan fichero bajo un único XObject).
     *
     * Fallo suave (fichero ausente, formato no soportado, etc.): un warning y `return` inmediato --
     * el background-color, si lo hay, YA se pintó (llamador: paintBackground()) y sigue visible;
     * nada más se pinta (ni siquiera se abre el clip).
     *
     * Geometría (adjudicaciones M8-T6, ver el brief de la tarea, hand-verificadas en PainterTest):
     *   - auto: tamaño = intrínseco de la imagen (px, 96dpi, igual que <img>), sin escalar.
     *     Posición: top-left (default) -> origen = origen de la caja; center -> origen = origen +
     *     (tamaño caja - tamaño imagen)/2 (puede ser negativo, el clip se encarga del overflow).
     *   - cover: scale = max(boxW/imgW, boxH/imgH); tamaño = imagen×scale; SIEMPRE centrado
     *     (adjudicación M8: cover ignora background-position en este modelo reducido) --
     *     hand-verificado: imagen 300×150 en caja 200×200 -> scale=max(200/300,200/150)=1.333...
     *     -> destino 400×200, centrado -> offset (-100, 0) relativo al origen de la caja.
     *   - contain: scale = min(boxW/imgW, boxH/imgH); tamaño = imagen×scale; posición según
     *     background-position (igual que auto) -- el letterboxing (el background-color, ya pintado
     *     debajo) se ve solo porque el área sobrante del clip queda intacta, sin nada extra que
     *     hacer aquí. Hand-verificado: misma imagen/caja -> scale=min(200/300,200/150)=0.667 ->
     *     destino 200×100.
     *   - repeat=true: SIEMPRE tilea la imagen a su tamaño INTRÍNSECO (auto), sin importar lo que
     *     $backgroundSize resolviera (adjudicación M8: "tile the AUTO-sized image n×m" del brief --
     *     repeat manda sobre size para las dimensiones del tile en este modelo reducido) -- grid
     *     n=ceil(boxW/imgW) × m=ceil(boxH/imgH) tiles desde el origen de posición, cada uno
     *     dibujado ENTERO (nunca recortado a un tamaño parcial) -- el clip se encarga de cortar los
     *     tiles de borde que sobresalen. `background-position: center` combinado con repeat es una
     *     combinación no soportada en este modelo reducido -- warning UNA vez, se sigue tileando
     *     desde top-left (repeat permanece true, no se degrada a no-repeat).
     */
    private function paintBackgroundImage(
        Rect $rect,
        string $rawPath,
        BackgroundSize $size,
        bool $repeat,
        BackgroundPosition $position,
        float $opacity,
        BorderRadius $radius,
        Canvas $canvas,
    ): void {
        $resolved = ImagePathResolver::resolve($this->basePath, $rawPath);
        try {
            $decoded = $this->images->load($resolved);
        } catch (ImageException $e) {
            $this->warnings?->addWarning("Could not load background image \"$rawPath\": " . $e->getMessage());
            return;
        }
        $imgW = (float) $decoded->widthPx();
        $imgH = (float) $decoded->heightPx();
        if ($imgW <= 0.0 || $imgH <= 0.0) {
            // Guardia defensiva -- un JPEG/PNG decodificado con éxito nunca reporta dimensión cero
            // en la práctica, pero evita una división por cero / bucle infinito de tiling si
            // alguna vez ocurriera.
            return;
        }
        if ($radius->isZero()) {
            $canvas->clipRect($rect);
        } else {
            $canvas->clipRoundedRect($rect, $radius);
        }
        if ($repeat) {
            if ($position === BackgroundPosition::Center) {
                $this->warnings?->addWarningOnce(
                    'background-repeat-center-origin',
                    'background-repeat with background-position:center is not supported (M8): tiling from top-left',
                );
            }
            $cols = (int) ceil($rect->width / $imgW);
            $rows = (int) ceil($rect->height / $imgH);
            for ($row = 0; $row < $rows; $row++) {
                for ($col = 0; $col < $cols; $col++) {
                    $tile = new Rect($rect->x + $col * $imgW, $rect->y + $row * $imgH, $imgW, $imgH);
                    $canvas->drawImage($tile, $resolved, $opacity);
                }
            }
            $canvas->restoreClip();
            return;
        }
        $dest = match ($size) {
            BackgroundSize::Cover => self::coverDestRect($rect, $imgW, $imgH),
            BackgroundSize::Contain => self::sizedDestRect($rect, $imgW, $imgH, min($rect->width / $imgW, $rect->height / $imgH), $position),
            BackgroundSize::Auto => self::sizedDestRect($rect, $imgW, $imgH, 1.0, $position),
        };
        $canvas->drawImage($dest, $resolved, $opacity);
        $canvas->restoreClip();
    }

    /** cover: escala = max(boxW/imgW, boxH/imgH), SIEMPRE centrado (ignora $position -- adjudicación
     *  M8, ver el docblock de paintBackgroundImage()). */
    private static function coverDestRect(Rect $rect, float $imgW, float $imgH): Rect
    {
        $scale = max($rect->width / $imgW, $rect->height / $imgH);
        $w = $imgW * $scale;
        $h = $imgH * $scale;
        return new Rect(
            $rect->x + ($rect->width - $w) / 2.0,
            $rect->y + ($rect->height - $h) / 2.0,
            $w,
            $h,
        );
    }

    /** auto ($scale=1.0) / contain (scale=min(boxW/imgW, boxH/imgH)): posicionado según
     *  $position -- top-left (default) ancla al origen de la caja; center centra en ambos ejes
     *  (puede dar un offset negativo para auto, si la imagen es más grande que la caja -- el clip
     *  de paintBackgroundImage() se encarga del overflow). */
    private static function sizedDestRect(Rect $rect, float $imgW, float $imgH, float $scale, BackgroundPosition $position): Rect
    {
        $w = $imgW * $scale;
        $h = $imgH * $scale;
        $x = $rect->x;
        $y = $rect->y;
        if ($position === BackgroundPosition::Center) {
            $x += ($rect->width - $w) / 2.0;
            $y += ($rect->height - $h) / 2.0;
        }
        return new Rect($x, $y, $w, $h);
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
    /**
     * M8-T4 (css-backgrounds-3 §4.3): la uniformidad (ver bordersUniform()) se comprueba SIEMPRE
     * ahora que hay ESTILOS que pueden querer un ÚNICO path continuo incluso con $radius cero
     * (dashed/dotted uniforme, ver paintUniformDashedBorder() -- el patrón de guiones debe
     * "envolver" continuamente las 4 esquinas, algo que 4 líneas independientes por lado no
     * consiguen, incluso en un rect recto). Antes de esta tarea, $radius->isZero() cortaba a
     * paintBordersFlat() ANTES de calcular $uniform en absoluto -- eso sigue siendo el resultado
     * final para Solid (ver la rama de abajo), así que ningún borde Solid preexistente cambia de
     * bytes: la única rama NUEVA de este método es la de Dashed/Dotted.
     */
    private function paintBorders(Rect $rect, BorderSet $borders, float $opacity, BorderRadius $radius, Canvas $canvas): void
    {
        if (!$borders->isVisible()) {
            return;
        }
        $uniform = $this->bordersUniform($borders, $radius);
        if ($uniform === null) {
            // El warning de "mixed... approximated" solo tiene sentido cuando HAY un radio que
            // aproximar (la heterogeneidad en sí siempre se pinta EXACTA vía paintBordersFlat(),
            // radio o no) -- mismo gating que antes de esta tarea (cuando $radius->isZero() ya
            // cortaba antes de llegar aquí, este warning nunca podía dispararse para radio cero).
            if (!$radius->isZero()) {
                $this->warnings?->addWarningOnce(
                    'mixed-border-widths-with-radius',
                    'mixed border widths with border-radius approximated',
                );
            }
            $this->paintBordersFlat($rect, $borders, $opacity, $canvas);
            return;
        }
        if ($uniform->color === null) {
            // BorderSide::$color es ?Color por tipo, aunque ComputedStyle nunca produce null
            // (T3: currentColor eager) -- guardia defensiva, mismo criterio que paintBorderSide().
            return;
        }
        if ($uniform->style !== BorderStyle::Solid) {
            // Dashed/Dotted uniforme (radio cero o no) -- SIEMPRE un único path trazado, ver
            // paintUniformDashedBorder().
            $this->paintUniformDashedBorder($rect, $uniform, $radius, $opacity, $canvas);
            return;
        }
        if ($radius->isZero()) {
            // Solid uniforme SIN radio: 4 rects rectos, byte-idéntico al comportamiento pre-M8-T4
            // (antes de esta tarea, este caso ni siquiera llegaba a calcular $uniform).
            $this->paintBordersFlat($rect, $borders, $opacity, $canvas);
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
     * M8-T4 (css-backgrounds-3 §4.3, ISO 32000-1 §8.4.3.6): borde UNIFORME dashed/dotted -- UN
     * único path trazado (`S`) a lo largo de la línea CENTRAL del borde (el border-box insetado
     * $bw/2 en cada lado -- css-backgrounds-3 no especifica el centrado exacto del trazo de un
     * borde dashed/dotted; "centrado sobre el border-box declarado" es la interpretación
     * adjudicada M8, igual convención "el trazo cubre el mismo ancho visual que el borde solid
     * equivalente" que motiva el `w`=$bw pasado a Canvas). $radius, si no es cero, se reduce por
     * la MISMA mitad de ancho (mismo criterio de reclamp que el resto de este motor, clamp a 0
     * como mínimo) antes de trazar el path Bézier -- sin border-radius, un simple `re` trazado
     * (strokeRect()) basta.
     */
    private function paintUniformDashedBorder(Rect $rect, BorderSide $uniform, BorderRadius $radius, float $opacity, Canvas $canvas): void
    {
        $bw = $uniform->widthPx;
        $color = $uniform->color;
        if ($color === null || $bw <= 0.0) {
            return;
        }
        $centerRect = new Rect($rect->x + $bw / 2.0, $rect->y + $bw / 2.0, max(0.0, $rect->width - $bw), max(0.0, $rect->height - $bw));
        $dash = $this->dashPatternFor($uniform->style, $bw);
        $roundCap = $uniform->style === BorderStyle::Dotted;
        $paintColor = $color->withOpacity($opacity);
        if ($radius->isZero()) {
            $canvas->strokeRect($centerRect, $bw, $paintColor, $dash, $roundCap);
            return;
        }
        $centerRadius = new BorderRadius(
            max(0.0, $radius->tl - $bw / 2.0),
            max(0.0, $radius->tr - $bw / 2.0),
            max(0.0, $radius->br - $bw / 2.0),
            max(0.0, $radius->bl - $bw / 2.0),
        );
        $canvas->strokeRoundedRect($centerRect, $centerRadius, $bw, $paintColor, $dash, $roundCap);
    }

    /**
     * ISO 32000-1 §8.4.3.6: patrón de guiones en PX (Canvas los convierte a pt, ver PdfCanvas::
     * dashOp()) para $style/$widthPx -- dashed: `[3w w]` (guion de 3× el ancho del borde, hueco de
     * 1×); dotted: `[0 2w]` (guion de longitud CERO -- necesita $roundCap para dibujarse como un
     * punto circular en vez de desaparecer, ver Paint\Canvas::strokeLine()) con hueco de 2×.
     * Solid/None devuelven [] (sin patrón -- este método nunca se invoca para ellos en la
     * práctica, guardia defensiva).
     *
     * @return list<float>
     */
    private function dashPatternFor(BorderStyle $style, float $widthPx): array
    {
        return match ($style) {
            BorderStyle::Dashed => [3.0 * $widthPx, $widthPx],
            BorderStyle::Dotted => [0.0, 2.0 * $widthPx],
            default => [],
        };
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

    /**
     * El pintado de 4 bandas de siempre (pre-M8-T2) -- usado tanto para $radius cero como para la
     * aproximación de anchos/colores/estilos mixtos con radio (ver paintBorders()).
     *
     * M8-T4: cada banda ahora lleva también su orientación (true = horizontal -- top/bottom, false
     * = vertical -- left/right) -- paintBorderSide() la necesita para trazar la línea CENTRAL de
     * un lado Dashed/Dotted heterogéneo (un lado Solid sigue ignorándola, fillRect() de la banda
     * entera sin cambios).
     */
    private function paintBordersFlat(Rect $rect, BorderSet $borders, float $opacity, Canvas $canvas): void
    {
        // Solo el ancho de los lados VISIBLES reserva espacio para el rect vertical entre ellos
        // (un lado con style None no ocupa hueco, igual que en el modelo de caja CSS 2.2 §8.5.3:
        // "if border-style is none... the computed value of the border width is 0" -- Dashed/
        // Dotted SÍ reservan, igual que Solid, ver effectiveWidth()).
        $topW = $this->effectiveWidth($borders->top);
        $bottomW = $this->effectiveWidth($borders->bottom);
        $middleHeight = $rect->height - $topW - $bottomW;

        $this->paintBorderSide($borders->top, $canvas, new Rect($rect->x, $rect->y, $rect->width, $topW), $opacity, true);
        $this->paintBorderSide(
            $borders->right,
            $canvas,
            new Rect($rect->right() - $borders->right->widthPx, $rect->y + $topW, $borders->right->widthPx, $middleHeight),
            $opacity,
            false,
        );
        $this->paintBorderSide(
            $borders->bottom,
            $canvas,
            new Rect($rect->x, $rect->bottom() - $bottomW, $rect->width, $bottomW),
            $opacity,
            true,
        );
        $this->paintBorderSide(
            $borders->left,
            $canvas,
            new Rect($rect->x, $rect->y + $topW, $borders->left->widthPx, $middleHeight),
            $opacity,
            false,
        );
    }

    /**
     * BorderSide::$color es ?Color por tipo, aunque ComputedStyle nunca produce null (T3:
     * currentColor eager) — guardia defensiva, nunca debería activarse desde el pipeline real.
     *
     * M8-T4: Solid sigue pintando la BANDA entera vía fillRect() (comportamiento byte-idéntico a
     * antes de esta tarea); Dashed/Dotted (heterogéneo -- si fuera uniforme, paintBorders() ya
     * habría desviado a paintUniformDashedBorder() antes de llegar aquí) traza la línea CENTRAL de
     * $band como un segmento independiente (Canvas::strokeLine(), con el dash pattern/cap propios
     * de este lado -- "esquinas entre lados de distinto color: segmentos rectos M8, sin
     * negociación de miter", ver el brief de esta tarea) -- $horizontal decide si esa línea corre
     * a lo largo del ancho de la banda (top/bottom) o de su alto (left/right).
     */
    private function paintBorderSide(BorderSide $side, Canvas $canvas, Rect $band, float $opacity, bool $horizontal): void
    {
        if ($side->style === BorderStyle::None || $side->widthPx <= 0.0 || $side->color === null) {
            return;
        }
        $color = $side->color->withOpacity($opacity);
        if ($side->style === BorderStyle::Solid) {
            $canvas->fillRect($band, $color);
            return;
        }
        $dash = $this->dashPatternFor($side->style, $side->widthPx);
        $roundCap = $side->style === BorderStyle::Dotted;
        if ($horizontal) {
            $y = $band->y + $band->height / 2.0;
            $canvas->strokeLine($band->x, $y, $band->x + $band->width, $y, $side->widthPx, $color, $dash, $roundCap);
        } else {
            $x = $band->x + $band->width / 2.0;
            $canvas->strokeLine($x, $band->y, $x, $band->y + $band->height, $side->widthPx, $color, $dash, $roundCap);
        }
    }

    /** M8-T4: `!== None` en vez de `=== Solid` -- Dashed/Dotted RESERVAN el mismo espacio que
     *  Solid en la geometría de banda de arriba (mismo criterio que ComputedStyle::compute(),
     *  ver su docblock); no-op observacional para M2-M7. */
    private function effectiveWidth(BorderSide $side): float
    {
        return $side->style !== BorderStyle::None ? $side->widthPx : 0.0;
    }
}
