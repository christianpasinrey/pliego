<?php

declare(strict_types=1);

namespace Pliego\Layout;

use Pliego\Box\BlockBox;
use Pliego\Box\ImageBox;
use Pliego\Box\InlineBoxEnd;
use Pliego\Box\InlineBoxStart;
use Pliego\Box\LineBreakRun;
use Pliego\Box\TableBox;
use Pliego\Box\TextRun;
use Pliego\Css\WarningCollector;
use Pliego\Layout\Fragment\BorderSet;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\Fragment;
use Pliego\Layout\Fragment\ImageFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;
use Pliego\Layout\Text\FontFamilyResolver;
use Pliego\Style\ComputedStyle;
use Pliego\Style\Display;
use Pliego\Style\FontStyle;
use Pliego\Style\ListStyleType;
use Pliego\Text\FontCatalog;
use Pliego\Text\FontFace;

/**
 * CSS 2.2 §9.4.1 (block formatting) + §10.3.3 (anchos) simplificado para M0:
 * sin margin collapsing, sin floats.
 *
 * M1-T6: el line breaking multi-run/multi-cara se delega ENTERAMENTE a InlineFlowContext
 * (M0 tenía aquí un wrapText() de una sola cara/estilo, ya eliminado). Este contexto solo se
 * encarga de agrupar tramos consecutivos de TextRun|LineBreakRun (pueden aparecer intercalados
 * con hijos BlockBox, ver BoxTreeBuilder::buildBlock) y pasarle cada grupo íntegro al inline
 * context de una vez.
 *
 * M4-T4: BlockFlowContext y FlexFormattingContext se necesitan mutuamente (un hijo bloque puede
 * ser un contenedor flex; un item flex se layoutea con la misma maquinaria de bloque) — ciclo de
 * constructores. Se rompe con INYECCIÓN PEREZOSA: esta clase deja de ser `readonly` a nivel de
 * clase (las propiedades promovidas del constructor se marcan `readonly` individualmente, igual
 * que antes) para poder alojar $flexContext, un colaborador MUTABLE que arranca en null y se
 * autoconstruye la PRIMERA vez que hace falta (ver flexContext()) — así ningún caller (Engine,
 * tests, el propio FlexFormattingContext) necesita wiring explícito: basta con
 * `new BlockFlowContext($measurer, $catalog)` para que un hijo `display:flex` funcione. El setter
 * público sigue existiendo por si un caller quiere inyectar una instancia propia (p.ej. un test
 * con un doble, o FlexFormattingContext conectando SU BlockFlowContext interno consigo mismo,
 * ver el docblock de esa clase) en vez de la autocreada.
 *
 * M5-T1 (housekeeping): $warnings (último parámetro, opcional, null = silencioso) es el mismo
 * WarningCollector que Engine::render() comparte con BoxTreeBuilder/Paginator — esta clase no
 * emite ningún warning propio todavía, pero DEBE reenviarlo al FlexFormattingContext que crea
 * perezosamente en flexContext() (ver más abajo), para que un `display:flex` anidado a
 * cualquier profundidad siga viendo el MISMO colector que el resto del pipeline, en vez de uno
 * silencioso por accidente de wiring.
 *
 * M5-T4: mismo mecanismo de inyección perezosa que $flexContext, ahora también para
 * TableFormattingContext (ver el docblock de esa clase, sección "RUPTURA DE CICLO") — un hijo
 * TableBox se delega a $this->tableContext(), autocreada la primera vez que hace falta si nadie
 * la wireó explícitamente (el caso normal: Engine construye `new BlockFlowContext(...)` a secas y
 * el primer <table> que encuentra dispara la autocreación).
 *
 * M7-T3 (css-lists-3 §3, reducido): un hijo Display::ListItem (típicamente <li>, ver
 * Style\Display::ListItem) se layoutea como un BLOQUE NORMAL (misma rama de código que cualquier
 * otro hijo bloque, sin FormattingContext dedicado) MÁS un marcador sintético — ver
 * listMarkerFragment(), llamado desde el final de layout() cuando $style->display es ListItem. El
 * marcador NUNCA vive en Box\BlockBox (no hay ::marker real, ni una "MarkerBox" en el árbol de
 * caja — Box: [Dom, Style, Css, Vendor, Image] en deptrac.yaml tampoco lo permitiría sin más
 * fricción de la necesaria): es puramente un TextFragment generado en Layout, hijo del propio
 * BoxFragment del li. El contador decimal ("1.", "2."...) es "por lista" — $nextListItemNumber en
 * el bucle de layout() se reinicia a $box->listStart (o 1) en CADA llamada a layout(), así que una
 * lista anidada dentro de un <li> reinicia su propio contador automáticamente (es una caja/
 * llamada distinta) — ver el docblock de $nextListItemNumber en layout(). disc/circle/square por
 * nivel de anidamiento (ul ul/ul ul ul) NO necesita ningún código aquí: ya lo resuelve el cascade
 * normal vía UserAgentStylesheet (combinators descendientes, funcionan desde M6) sobre
 * ComputedStyle::$listStyleType (heredado, ver esa clase) — este contexto solo LEE el valor ya
 * resuelto.
 */
final class BlockFlowContext implements FormattingContext
{
    /** M7-T3 (css-lists-3 §3, marcador de list-item): 0.5em de separación entre el borde derecho
     * del marcador y el borde izquierdo del content box del li — ver listMarkerFragment(). */
    private const float MARKER_GAP_EM = 0.5;

    private readonly InlineFlowContext $inline;
    private ?FlexFormattingContext $flexContext = null;
    private ?TableFormattingContext $tableContext = null;
    /** M7-T3: mismo colaborador que InlineFlowContext usa para resolver font-family -> cara
     * concreta del catálogo — el marcador de list-item usa la MISMA cara/tamaño que el propio li
     * (ver docblock de listMarkerFragment()), así que esta clase necesita el mismo mecanismo de
     * resolución, sin duplicar GENERIC_FAMILIES aquí (ver Layout\Text\FontFamilyResolver). */
    private readonly FontFamilyResolver $fontFamilyResolver;

    public function __construct(
        private readonly TextMeasurer $measurer,
        private readonly FontCatalog $catalog,
        private readonly ?WarningCollector $warnings = null,
    ) {
        $this->inline = new InlineFlowContext($measurer, $catalog, $warnings);
        // M7-T4: ruptura del ciclo constructor BlockFlowContext<->InlineFlowContext (necesario
        // para que un display:inline-block pueda medirse/layoutearse con la maquinaria de bloque
        // completa, ver InlineFlowContext::layoutInlineBlockAtomic()) — a diferencia de
        // flexContext()/tableContext() (autocreación PEREZOSA, porque ESTA clase no puede
        // pasarse a sí misma dentro de su propio constructor a esos colaboradores, que ella NO
        // crea), aquí no hace falta pereza: $this->inline ya existe (se acaba de construir en la
        // línea de arriba), así que el auto-wiring es inmediato y SIEMPRE ocurre (nunca queda sin
        // wirear en el pipeline real).
        $this->inline->setBlockContext($this);
        $this->fontFamilyResolver = new FontFamilyResolver($catalog, $warnings);
    }

    /** Ver docblock de clase: wiring explícito opcional del delegado flex (por defecto, perezoso). */
    public function setFlexContext(FlexFormattingContext $flexContext): void
    {
        $this->flexContext = $flexContext;
    }

    /**
     * M5-T4: análogo a setFlexContext() — TableFormattingContext lo llama en SU propio
     * constructor sobre el BlockFlowContext interno que crea para layoutear contenido de celda
     * (ver el docblock "RUPTURA DE CICLO" de esa clase), así que esta instancia NUNCA pasa por la
     * autocreación de tableContext() de más abajo.
     */
    public function setTableContext(TableFormattingContext $tableContext): void
    {
        $this->tableContext = $tableContext;
    }

    private function flexContext(): FlexFormattingContext
    {
        if ($this->flexContext === null) {
            $this->flexContext = new FlexFormattingContext(
                $this->measurer,
                $this->catalog,
                new IntrinsicSizer($this->measurer, $this->catalog, $this->warnings),
                $this->warnings,
            );
        }
        return $this->flexContext;
    }

    private function tableContext(): TableFormattingContext
    {
        if ($this->tableContext === null) {
            $this->tableContext = new TableFormattingContext(
                $this->measurer,
                $this->catalog,
                new IntrinsicSizer($this->measurer, $this->catalog, $this->warnings),
                $this->warnings,
            );
        }
        return $this->tableContext;
    }

    /**
     * M4-T5 (carry-over fix from T4's review): $usedWidthOverride, cuando no es null, es el
     * ancho BORDER-BOX que un FormattingContext exterior (FlexFormattingContext) ya resolvió
     * para esta caja (§9.7) y que DEBE ganar sobre cualquier width propio declarado en CSS —
     * antes de este parámetro, un item flex con su propio `width` ignoraba por completo el
     * tamaño resuelto por flex-grow/shrink (BlockFlowContext solo miraba $style->width, nunca
     * el containingBlock), dejando huecos o solapes visibles frente a lo que hace un navegador
     * real. Cuando se pasa, el override sustituye ENTERAMENTE la rama de "width propio" de
     * abajo (auto o declarado, box-sizing incluido: el valor ya llega en convención border-box
     * uniforme, ver el docblock de adjudicación en FlexFormattingContext) — el resto del método
     * (posición, hijos, altura de contenido) no cambia en absoluto.
     *
     * M7-T3 (css-lists-3 §3): $listItemOrdinal, cuando no es null, es el número 1-based de ESTE
     * <li> dentro de la secuencia de hijos Display::ListItem de SU padre (ol/ul) — el ÚNICO
     * llamador que lo pasa es este mismo método, en la rama genérica del bucle de más abajo,
     * donde SÍ conoce esa posición (cuenta sus propios hijos ListItem según los recorre, ver
     * $nextListItemNumber). Ausente (null, el caso normal para CUALQUIER caja que no sea un <li>,
     * y también un <li> alcanzado por cualquier otro camino: FlexFormattingContext/
     * TableFormattingContext NUNCA lo pasan) cae a 1 dentro de listMarkerFragment() — mismo
     * comportamiento que un navegador real para un <li> "huérfano" (sin ol/ul ancestro): se
     * numera como si fuera el primero. Ignorado por completo si $style->display no es
     * Display::ListItem (ver el final de este método).
     */
    public function layout(BlockBox $box, Rect $containingBlock, ?float $usedWidthOverride = null, ?int $listItemOrdinal = null): BoxFragment
    {
        $style = $box->style;
        // CSS 2.2 §10.2/§10.3.3/§8.3: todo porcentaje de width/margin-*/padding-* se resuelve
        // contra el ANCHO del containing block — incluso los verticales (margin-top/bottom,
        // padding-top/bottom), que NO se resuelven contra ninguna altura.
        $cbWidth = $containingBlock->width;

        $marginLeft = $style->marginLeft->resolve($cbWidth);
        $marginRight = $style->marginRight->resolve($cbWidth);
        $marginTop = $style->marginTop->resolve($cbWidth);

        $x = $containingBlock->x + $marginLeft;
        $y = $containingBlock->y + $marginTop;

        $paddingLeft = $style->paddingLeft->resolve($cbWidth);
        $paddingRight = $style->paddingRight->resolve($cbWidth);
        $paddingTop = $style->paddingTop->resolve($cbWidth);
        $paddingBottom = $style->paddingBottom->resolve($cbWidth);

        $borderLeft = $style->borderLeft->widthPx;
        $borderRight = $style->borderRight->widthPx;
        $borderTop = $style->borderTop->widthPx;
        $borderBottom = $style->borderBottom->widthPx;

        if ($usedWidthOverride !== null) {
            $borderBoxWidth = $usedWidthOverride;
            $contentWidth = max(0.0, $borderBoxWidth - $paddingLeft - $paddingRight - $borderLeft - $borderRight);
        } else {
            // Falso positivo verificado (ver task-8-report.md): PHPStan resuelve
            // `?LengthPercentage` como no-nulo solo cuando el nullsafe y el `??` conviven en la
            // misma expresión; separar en dos sentencias hace desaparecer el aviso sin cambiar
            // tipo ni comportamiento.
            $declaredWidth = $style->width;
            $declaredWidthPx = $declaredWidth?->resolve($cbWidth);
            if ($declaredWidthPx === null) {
                // width: auto — el border-box ocupa lo que quede del containing block tras los
                // márgenes; box-sizing solo importa cuando hay un ancho declarado explícitamente.
                $borderBoxWidth = $cbWidth - $marginLeft - $marginRight;
                $contentWidth = max(0.0, $borderBoxWidth - $paddingLeft - $paddingRight - $borderLeft - $borderRight);
            } elseif ($style->boxSizing === 'border-box') {
                $borderBoxWidth = $declaredWidthPx;
                $contentWidth = max(0.0, $borderBoxWidth - $paddingLeft - $paddingRight - $borderLeft - $borderRight);
            } else {
                $contentWidth = $declaredWidthPx;
                $borderBoxWidth = $contentWidth + $paddingLeft + $paddingRight + $borderLeft + $borderRight;
            }
        }

        $contentX = $x + $borderLeft + $paddingLeft;
        $cursorY = $y + $borderTop + $paddingTop;
        $contentBottom = $cursorY;
        // M7-T3: instantánea INMUTABLE del content-top de ESTA caja — $cursorY se muta por el
        // resto del método a medida que se layoutean los hijos; listMarkerFragment() necesita el
        // valor ORIGINAL (top del content box) para el caso "li sin texto" (ver su docblock),
        // mucho después de que $cursorY ya haya avanzado.
        $contentTop = $cursorY;

        $children = [];
        /**
         * M7-T4: += InlineBoxStart/InlineBoxEnd (caja inline real) y BlockBox (SOLO cuando su
         * propio display es InlineBlock -- ver el dispatch del bucle de más abajo) — los tres
         * deben permanecer en la MISMA secuencia continua que TextRun/LineBreakRun, sin disparar
         * flushInline(), para que InlineFlowContext vea el flujo de texto completo de este bloque
         * de una sola vez (una caja inline puede abrir antes de un límite y cerrar varias líneas
         * después; si flushInline() cortara por medio, box-decoration-break:slice se rompería a
         * través de una frontera que no debería existir).
         *
         * @var list<TextRun|LineBreakRun|InlineBoxStart|InlineBoxEnd|BlockBox> $pendingRuns
         *     secuencia inline contigua pendiente de layout
         */
        $pendingRuns = [];
        $flushInline = function () use (&$pendingRuns, &$children, &$cursorY, &$contentBottom, $contentX, $contentWidth, $style): void {
            if ($pendingRuns === []) {
                return;
            }
            foreach ($this->inline->layout($pendingRuns, $contentX, $cursorY, $contentWidth, $style) as $line) {
                $children[] = $line;
                $cursorY = $line->rect()->bottom();
            }
            $contentBottom = $cursorY;
            $pendingRuns = [];
        };

        // M7-T3 (css-lists-3 §3, contador decimal "por lista"): 1-based, incrementado SOLO al
        // encontrar un hijo directo Display::ListItem (nunca por cualquier otro tipo de hijo —
        // texto/imagen/tabla/flex/bloque normal intercalado NO cuenta) — así que un <li> anidado
        // dentro de OTRO <li> (una lista anidada) nunca ve el contador de su abuelo: ese <ol>/<ul>
        // hijo es él mismo una caja distinta, con su PROPIA llamada a layout() y, por tanto, su
        // propio $nextListItemNumber reiniciado a 1 (o a $box->listStart si trae `start`, ver
        // BoxTreeBuilder::parseListStart()) — "nested lists restart" del brief queda satisfecho
        // sin ningún caso especial. Inerte (nunca se lee) para cualquier caja que no tenga hijos
        // Display::ListItem.
        $nextListItemNumber = $box->listStart ?? 1;

        foreach ($box->children as $child) {
            if ($child instanceof TextRun || $child instanceof LineBreakRun
                || $child instanceof InlineBoxStart || $child instanceof InlineBoxEnd
            ) {
                $pendingRuns[] = $child;
                continue;
            }
            // M7-T4: un BlockBox con display:inline-block es un token ATÓMICO de la secuencia de
            // runs (BoxTreeBuilder ya lo colocó AQUÍ, mezclado con TextRun/LineBreakRun, en vez de
            // como hijo de bloque "puro" -- ver su docblock) -- se comprueba ANTES de flushInline()
            // para que no corte el flujo de texto continuo; cualquier OTRO BlockBox (el caso
            // normal, un hijo de bloque real) sigue cayendo en las ramas de más abajo sin cambios.
            if ($child instanceof BlockBox && $child->style->display === Display::InlineBlock) {
                $pendingRuns[] = $child;
                continue;
            }
            $flushInline();
            if ($child instanceof ImageBox) {
                $childFragment = $this->layoutImage($child, new Rect($contentX, $cursorY, $contentWidth, INF));
                $children[] = $childFragment;
                $contentBottom = $childFragment->rect->bottom();
                $cursorY = $contentBottom + $child->style->marginBottom->resolve($contentWidth);
                continue;
            }
            // M5-T4: una TableBox hija se delega ENTERA a TableFormattingContext (ver
            // tableContext()/su docblock de clase) — reemplaza el skip de T3: el cursor SÍ avanza
            // ahora (mismo patrón que ImageBox/display:flex justo arriba: fragmento + avance de
            // cursor con el margin-bottom propio de la tabla, resuelto contra este mismo
            // $contentWidth).
            if ($child instanceof TableBox) {
                $childFragment = $this->tableContext()->layout($child, new Rect($contentX, $cursorY, $contentWidth, INF));
                $children[] = $childFragment;
                $contentBottom = $childFragment->rect->bottom();
                $cursorY = $contentBottom + $child->style->marginBottom->resolve($contentWidth);
                continue;
            }
            // M4-T4: un hijo bloque con display:flex se layoutea ENTERO con FlexFormattingContext
            // (resuelve su propia caja — márgenes/width/box-sizing — con el mismo cálculo que esta
            // clase, ver el docblock de esa clase) en vez de recursar aquí; el resto del bucle
            // (avance del cursor con el margin-bottom del hijo) es idéntico a un bloque normal.
            // $child ya está acotado a BlockBox aquí (los otros dos casos posibles, TextRun|
            // LineBreakRun e ImageBox, ya hicieron `continue` arriba), así que solo hace falta
            // mirar su display.
            if ($child->style->display === Display::Flex) {
                $childFragment = $this->flexContext()->layout($child, new Rect($contentX, $cursorY, $contentWidth, INF));
                $children[] = $childFragment;
                $contentBottom = $childFragment->rect->bottom();
                $cursorY = $contentBottom + $child->style->marginBottom->resolve($contentWidth);
                continue;
            }
            // M7-T3: un hijo Display::ListItem (típicamente <li>, UA default -- ver Display::
            // ListItem) recibe su ordinal 1-based ANTES de recursar -- listMarkerFragment(),
            // llamado desde DENTRO de esa recursión (ver el final de este método), es quien
            // traduce el ordinal a texto de marcador ("3." para decimal, ignorado para disc/
            // circle/square/none). El resto de este bloque (avance de cursor con el margin-bottom
            // del hijo) es IDÉNTICO al caso genérico de más abajo -- ListItem sigue siendo, por lo
            // demás, un bloque normal en el flujo (ver docblock de Display::ListItem).
            if ($child->style->display === Display::ListItem) {
                $childFragment = $this->layout(
                    $child,
                    new Rect($contentX, $cursorY, $contentWidth, INF),
                    listItemOrdinal: $nextListItemNumber,
                );
                $nextListItemNumber++;
                $children[] = $childFragment;
                $contentBottom = $childFragment->rect->bottom();
                $cursorY = $contentBottom + $child->style->marginBottom->resolve($contentWidth);
                continue;
            }
            $childFragment = $this->layout($child, new Rect($contentX, $cursorY, $contentWidth, INF));
            $children[] = $childFragment;
            // CSS 2.2 §10.6.3: la altura de contenido llega hasta el border-box de la
            // última caja en flujo; el margin-bottom avanza el cursor para el siguiente
            // hermano pero no forma parte de la altura del padre.
            $contentBottom = $childFragment->rect->bottom();
            // margin-bottom del hijo se resuelve contra el ancho de SU containing block, que es
            // el content width de este padre (el mismo que se le pasó arriba como containingBlock->width).
            $cursorY = $contentBottom + $child->style->marginBottom->resolve($contentWidth);
        }
        $flushInline();

        // M7-T3 (css-lists-3 §3): el marcador se emite AQUÍ, sobre el $children YA COMPLETO de
        // ESTA caja (después de flushInline(), así que cualquier línea de texto propia del <li>
        // ya está presente) — se AÑADE como último hijo del BoxFragment que construye este mismo
        // método, nunca como hijo de su padre (ver listMarkerFragment(), "por qué vive aquí y no
        // en el bucle del padre"). Un <li> con display:none nunca llega a esta rama (BoxTreeBuilder
        // lo poda antes de construir su BlockBox), así que no hace falta guardarlo aparte.
        if ($style->display === Display::ListItem) {
            $marker = $this->listMarkerFragment($style, $listItemOrdinal ?? 1, $contentX, $contentTop, $children);
            if ($marker !== null) {
                $children[] = $marker;
            }
        }

        $height = ($contentBottom - $y) + $paddingBottom + $borderBottom;
        return new BoxFragment(
            new Rect($x, $y, $borderBoxWidth, $height),
            $style->backgroundColor,
            $children,
            new BorderSet($style->borderTop, $style->borderRight, $style->borderBottom, $style->borderLeft),
            opacity: $style->opacity,
        );
    }

    /**
     * M7-T3 (css-lists-3 §3, marcador de list-item): genera el TextFragment sintético del
     * marcador de ESTE <li> (o null si list-style-type:none) — glifo fijo para disc/circle/square
     * (•/◦/▪, verificados con glyphId > 0 en DejaVuSans.ttf, ver report de esta tarea: "marker
     * glyph availability probe") o "{ordinal}." para decimal. Face/tamaño = el ComputedStyle
     * PROPIO del li (ninguna propiedad de marcador dedicada existe en este motor — M7 no
     * introduce ::marker ni sus propiedades heredables específicas, css-lists-3 §7).
     *
     * POR QUÉ VIVE AQUÍ (en el propio layout() del li) Y NO EN EL BUCLE DE SU PADRE: el marcador
     * debe terminar como HIJO del BoxFragment del li (para que Paginator lo desplace junto al
     * resto del li al reubicarse entre páginas, ver relocateChildren()) — el único sitio donde
     * $children (los hijos YA layouteados del li) está disponible ANTES de construir ese
     * BoxFragment es al final de la llamada recursiva que layoutea al li, nunca en el padre (que
     * solo ve el BoxFragment ya cerrado). Matiz de paginación (verificado contra Paginator::
     * flatten()): un BoxFragment NO atómico (el caso normal de un <li>, a diferencia del
     * contenedor de un FlexFormattingContext) se APLANA recursivamente — el marcador acaba como
     * hoja HERMANA de la primera línea de texto en la lista plana de Page::$fragments, no como
     * descendiente anidado. Esto sigue siendo observacionalmente "se mueve con la página": este
     * método le da al marcador el MISMO rect->y/height que la primera línea de texto (ver más
     * abajo), así que Paginator::paginate() toma la MISMA decisión de push-down para ambos.
     *
     * POSICIÓN (CSS 2.2 §12.5.1, list-style-position:outside — el único soportado en M7): el
     * marcador se right-aligns en la banda de padding a la IZQUIERDA del content box del li (con
     * MARKER_GAP_EM de separación respecto al borde de contenido) — normalmente la banda que deja
     * el `padding-left: 40px` del ol/ul contenedor (ver UserAgentStylesheet), nunca la del propio
     * li (que no tiene padding propio por defecto). Puede desbordar hacia la izquierda de esa
     * banda con un ordinal decimal ancho (p.ej. "10.") — comportamiento observable normal, mismo
     * que un navegador real, sin clipping especial en este motor (M7 no introduce overflow para
     * este caso).
     *
     * BASELINE: comparte la línea base de la PRIMERA línea de texto del li (recursivo: puede
     * venir de un descendiente anidado, p.ej. un <li><p>texto</p></li>, ver firstTextFragment()).
     * Un <li> SIN texto en absoluto (vacío, o solo con una imagen/tabla) cae al fallback
     * documentado en el brief: "alineado a li top + ascent" — mismo cálculo de línea forzada
     * vacía que InlineFlowContext::closeLine() usa para un <br> sin contenido (mismo lineHeight/
     * ascent/centrado vertical), para que el resultado sea indistinguible de una primera línea
     * real si el li tuviera texto invisible.
     *
     * @param list<Fragment> $children hijos YA layouteados del li (líneas de texto/bloques
     *     anidados/imágenes/tablas) — se busca ahí, NUNCA se muta.
     */
    private function listMarkerFragment(
        ComputedStyle $style,
        int $ordinal,
        float $contentX,
        float $contentTop,
        array $children,
    ): ?TextFragment {
        $markerText = match ($style->listStyleType) {
            ListStyleType::Disc => "\u{2022}",   // •
            ListStyleType::Circle => "\u{25E6}", // ◦
            ListStyleType::Square => "\u{25AA}", // ▪
            ListStyleType::Decimal => $ordinal . '.',
            ListStyleType::None => null,
        };
        if ($markerText === null) {
            return null;
        }

        $face = $this->faceFor($style);
        $fontSize = $style->fontSizePx;
        $markerWidth = $this->measurer->widthOf($markerText, $face, $fontSize);
        $markerRight = $contentX - self::MARKER_GAP_EM * $fontSize;
        $markerLeft = $markerRight - $markerWidth;

        $firstLine = self::firstTextFragment($children);
        if ($firstLine !== null) {
            $rect = new Rect($markerLeft, $firstLine->rect->y, $markerWidth, $firstLine->rect->height);
            $baselineY = $firstLine->baselineY;
        } else {
            // Mismo cálculo que InlineFlowContext::closeLine() para una línea forzada vacía
            // (ver su docblock) -- centrado vertical del glifo/número dentro del lineHeight,
            // partiendo de $contentTop (el top del content box de ESTE li, no de $cursorY ya
            // avanzado).
            $lineHeight = max($style->lineHeightPx ?? 0.0, $this->measurer->lineHeight($fontSize));
            $ascent = $this->measurer->ascent($face, $fontSize);
            $rect = new Rect($markerLeft, $contentTop, $markerWidth, $lineHeight);
            $baselineY = $contentTop + ($lineHeight - $fontSize) / 2 + $ascent;
        }

        // underline: false siempre -- un marcador de lista nunca hereda text-decoration (no hay
        // ::marker real en este motor, ver docblock de clase; un underline en el li se aplicaría
        // a su TEXTO, nunca al glifo/número sintético del marcador).
        return new TextFragment($rect, $markerText, $baselineY, $fontSize, $style->color, $face->key, false, $style->opacity);
    }

    /**
     * Búsqueda recursiva, en ORDEN DE DOCUMENTO, del primer TextFragment no vacío dentro de
     * $fragments (hijos YA layouteados de un li) — desciende a través de BoxFragment (bloques
     * anidados, p.ej. <li><p>texto</p></li>) pero nunca "entra" en un ImageFragment/otro
     * TextFragment (hojas sin hijos). Un TextFragment con text==='' (la línea vacía forzada que
     * InlineFlowContext::closeLine() emite para un <br> sin contenido, ver ahí) se salta -- no
     * cuenta como "primera línea real" a efectos de compartir baseline.
     *
     * @param list<Fragment> $fragments
     */
    private static function firstTextFragment(array $fragments): ?TextFragment
    {
        foreach ($fragments as $fragment) {
            if ($fragment instanceof TextFragment) {
                if ($fragment->text !== '') {
                    return $fragment;
                }
                continue;
            }
            if ($fragment instanceof BoxFragment) {
                $found = self::firstTextFragment($fragment->children);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /** M7-T3: mismo patrón que InlineFlowContext::faceFor() (ComputedStyle::$fontFamily es una
     * lista de fallback -- ver su docblock -- resuelta a una familia concreta antes de pedirle la
     * cara a FontCatalog); duplicado aquí (en vez de exponer el privado de InlineFlowContext)
     * porque ambas clases ya comparten el colaborador real (FontFamilyResolver), que es donde vive
     * la lógica no trivial -- esto es solo la llamada de una línea a $catalog->select(). */
    private function faceFor(ComputedStyle $style): FontFace
    {
        $family = $this->fontFamilyResolver->resolve($style->fontFamily);
        return $this->catalog->select($family, $style->fontWeight, $style->fontStyle === FontStyle::Italic);
    }

    /**
     * M3-T3: <img> es un replaced block-level box — mismo box model que un BlockBox normal
     * (margin/border/padding se resuelven exactamente igual, incluido box-sizing's ausencia:
     * un replaced element no lo necesita porque su tamaño "usado" YA ES el del content box, ver
     * resolveReplacedSize()), pero SIN flujo interno: el content box tiene el tamaño que decide
     * el algoritmo de sizing CSS 2.2 §10.3.4/§10.6.2 en vez de "lo que quede" del containing
     * block. Se emite como un BoxFragment (border-box, background/borders pintables igual que
     * cualquier otra caja) cuyo único hijo es el ImageFragment (la content box real, lo que
     * pinta la imagen).
     *
     * M4-T4: PÚBLICO (era privado) — FlexFormattingContext reutiliza este mismo método para sus
     * items ImageBox, sin duplicar el sizing de replaced elements (resolveReplacedSize no cambia:
     * es agnóstico a flex/bloque, el sizing de un <img> es el mismo eje a eje en ambos contextos).
     *
     * M4-T5: $usedWidthOverride, análogo al de layout() (ver su docblock) — un ImageBox item con
     * su propio width/attr/intrínseco sufre el mismo problema de carry-over que un BlockBox, ver
     * resolveReplacedSize().
     */
    public function layoutImage(ImageBox $box, Rect $containingBlock, ?float $usedWidthOverride = null): BoxFragment
    {
        $style = $box->style;
        $cbWidth = $containingBlock->width;

        $marginLeft = $style->marginLeft->resolve($cbWidth);
        $marginRight = $style->marginRight->resolve($cbWidth);
        $marginTop = $style->marginTop->resolve($cbWidth);

        $x = $containingBlock->x + $marginLeft;
        $y = $containingBlock->y + $marginTop;

        $paddingLeft = $style->paddingLeft->resolve($cbWidth);
        $paddingRight = $style->paddingRight->resolve($cbWidth);
        $paddingTop = $style->paddingTop->resolve($cbWidth);
        $paddingBottom = $style->paddingBottom->resolve($cbWidth);

        $borderLeft = $style->borderLeft->widthPx;
        $borderRight = $style->borderRight->widthPx;
        $borderTop = $style->borderTop->widthPx;
        $borderBottom = $style->borderBottom->widthPx;

        [$contentWidth, $contentHeight] = $this->resolveReplacedSize($box, $cbWidth, $usedWidthOverride);

        $contentX = $x + $borderLeft + $paddingLeft;
        $contentY = $y + $borderTop + $paddingTop;

        $borderBoxWidth = $contentWidth + $paddingLeft + $paddingRight + $borderLeft + $borderRight;
        $borderBoxHeight = $contentHeight + $paddingTop + $paddingBottom + $borderTop + $borderBottom;

        // M6-T5: opacity se pasa a AMBOS, la caja (su propio fondo/borde) y el ImageFragment (los
        // píxeles de la imagen, vía ExtGState en PdfCanvas::drawImage) — mismo ComputedStyle, un
        // único <img>, así que ambos comparten el mismo valor.
        $imageFragment = new ImageFragment(new Rect($contentX, $contentY, $contentWidth, $contentHeight), $box->src, $style->opacity);

        return new BoxFragment(
            new Rect($x, $y, $borderBoxWidth, $borderBoxHeight),
            $style->backgroundColor,
            [$imageFragment],
            new BorderSet($style->borderTop, $style->borderRight, $style->borderBottom, $style->borderLeft),
            opacity: $style->opacity,
        );
    }

    /**
     * CSS 2.2 §10.3.4 (ancho de replaced elements) + §10.6.2 (alto), simplificado para M3:
     * cada eje se resuelve INDEPENDIENTEMENTE por prioridad CSS width/height (resuelto contra
     * $cbWidth para width; height nunca admite % en M3, ver ComputedStyle::$height) > atributo
     * HTML width/height > intrínseco. Solo cuando UN eje queda sin resolver por ninguna de las
     * 3 fuentes se deriva del otro eje ya resuelto vía el aspect ratio intrínseco; si NINGÚN eje
     * se resuelve, se usan ambas dimensiones intrínsecas, recortadas (preservando el ratio) al
     * ancho del containing block si lo exceden — regla práctica de los navegadores, no está en
     * el texto de la spec CSS 2.2 pero es el comportamiento observable universal.
     *
     * box-sizing (CSS 2.2 §8.3 + css-sizing-3): reinterpreta SOLO el width/height DECLARADO EN
     * CSS — los atributos HTML width/height y las dimensiones intrínsecas son SIEMPRE medidas de
     * content-box (nunca pasan por box-sizing, igual que en HTML puro sin CSS). Por eso la resta
     * de padding+border se aplica aquí mismo, ANTES de mezclar con attr/intrínseco, y solo al
     * valor declarado en CSS. El ratio de aspecto, cuando hace falta derivar el eje que falta, se
     * aplica siempre sobre el content box ya resuelto (css-images-3 §4: el "used value" que
     * produce el ratio es una dimensión de content box), así que da igual si $width/$height ya
     * traen border-box restado o no: en el momento en que se usan para derivar el otro eje, ya
     * son valores de content box.
     *
     * M4-T5: $usedWidthOverride, cuando no es null, es el ancho BORDER-BOX ya resuelto por
     * FlexFormattingContext (§9.7) — gana sobre CSS width, atributo HTML width e intrínseco por
     * igual (misma prioridad absoluta que en BlockFlowContext::layout(), ver su docblock);
     * SIEMPRE se interpreta como border-box, sea cual sea el box-sizing propio de la imagen
     * (adjudicación "border-box main size" del brief, ya documentada en FlexFormattingContext).
     * El alto NO se toca por el override (el eje principal de un contenedor row es el ancho): se
     * resuelve igual que sin override (CSS > attr > derivado del ratio con el ancho YA
     * sobrescrito, si ningún alto propio existe).
     *
     * @return array{0: float, 1: float} content width/height en px
     */
    private function resolveReplacedSize(ImageBox $box, float $cbWidth, ?float $usedWidthOverride = null): array
    {
        $style = $box->style;
        $intrinsicWidth = (float) $box->intrinsicWidth;
        $intrinsicHeight = (float) $box->intrinsicHeight;
        $ratio = $intrinsicWidth > 0.0 ? $intrinsicHeight / $intrinsicWidth : 0.0;

        // Nullsafe + ?? en la misma expresión dispara el mismo falso positivo de PHPStan que en
        // BlockFlowContext::layout() (ver comentario de $declaredWidthPx más arriba); se separa en
        // dos sentencias por eje, igual que allí.
        $declaredWidth = $style->width;
        $declaredWidthPx = $declaredWidth?->resolve($cbWidth);
        $declaredHeight = $style->height;
        $declaredHeightPx = $declaredHeight?->px;

        $paddingBorderX = $style->paddingLeft->resolve($cbWidth) + $style->paddingRight->resolve($cbWidth)
            + $style->borderLeft->widthPx + $style->borderRight->widthPx;
        $paddingBorderY = $style->paddingTop->resolve($cbWidth) + $style->paddingBottom->resolve($cbWidth)
            + $style->borderTop->widthPx + $style->borderBottom->widthPx;

        if ($style->boxSizing === 'border-box') {
            if ($declaredWidthPx !== null) {
                $declaredWidthPx = max(0.0, $declaredWidthPx - $paddingBorderX);
            }
            if ($declaredHeightPx !== null) {
                $declaredHeightPx = max(0.0, $declaredHeightPx - $paddingBorderY);
            }
        }

        if ($usedWidthOverride !== null) {
            $width = max(0.0, $usedWidthOverride - $paddingBorderX);
            $height = $declaredHeightPx ?? $box->attrHeight ?? ($ratio > 0.0 ? $width * $ratio : $intrinsicHeight);
            return [$width, $height];
        }

        $width = $declaredWidthPx ?? $box->attrWidth;
        $height = $declaredHeightPx ?? $box->attrHeight;

        if ($width === null && $height === null) {
            $width = $intrinsicWidth;
            $height = $intrinsicHeight;
            if ($width > $cbWidth && $width > 0.0) {
                $scale = $cbWidth / $width;
                $width = $cbWidth;
                $height *= $scale;
            }
        } elseif ($width === null) {
            $width = $ratio > 0.0 ? $height / $ratio : $intrinsicWidth;
        } elseif ($height === null) {
            $height = $width * $ratio;
        }

        return [$width, $height];
    }
}
