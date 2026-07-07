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
use Pliego\Layout\Fragment\BorderRadius;
use Pliego\Layout\Fragment\BorderSet;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\Fragment;
use Pliego\Layout\Fragment\GeometryShift;
use Pliego\Layout\Fragment\ImageFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;
use Pliego\Layout\Text\FontFamilyResolver;
use Pliego\Style\ComputedStyle;
use Pliego\Style\Display;
use Pliego\Style\FloatSide;
use Pliego\Style\FontStyle;
use Pliego\Style\ListStyleType;
use Pliego\Style\Position;
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
 * M7-T5 breadcrumb para T6 (floats, CSS 2.2 §9.5/§9.4.3): overflow:hidden crea un nuevo block
 * formatting context — relevante para floats porque un BFC nuevo NO deja que floats de fuera
 * intersecten su content box (y, al revés, sus propios floats internos no se propagan al padre) —
 * el clásico "clearfix vía overflow:hidden". Esta tarea (M7-T5) NO implementa floats todavía
 * (T6), así que $clipsChildren (ver BoxFragment) hoy SOLO controla el clip de pintado — cuando T6
 * añada FloatContext, revisar si un BlockBox con overflow:hidden debe arrancar su propio
 * FloatContext aislado (bandas del padre invisibles dentro, bandas propias invisibles fuera) en
 * vez de heredar/propagar el del padre.
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
    /** M7-T6: shrink-to-fit width de un float/absolute (shrinkToFitWidth()) necesita el mismo
     * cálculo de max-content que un item flex/inline-block -- mismo patrón de inyección perezosa
     * que $flexContext/$tableContext (autocreada la primera vez que hace falta, ver
     * intrinsicSizer()). */
    private ?IntrinsicSizer $intrinsicSizer = null;

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
    public function layout(
        BlockBox $box,
        Rect $containingBlock,
        ?float $usedWidthOverride = null,
        ?int $listItemOrdinal = null,
        // M7-T6 (CSS 2.2 §9.5, floats): el FloatContext del BFC al que pertenecen los HIJOS de
        // ESTA caja (nunca el de la propia caja -- ver el docblock de FloatContext para dónde se
        // crea uno nuevo frente a dónde se reutiliza). null significa "nadie me dio uno" -- la
        // llamada raíz de Engine, o cualquier llamador que YA establece su propio contexto de
        // layout (FlexFormattingContext/TableFormattingContext/InlineFlowContext::
        // layoutInlineBlockAtomic(), ninguno de los cuales pasa este parámetro) -- tratado
        // exactamente igual que "esta caja establece BFC", ver $bfc más abajo.
        ?FloatContext $floatContext = null,
        // M7-T6 (CSS 2.2 §9.4.3/§10.3.7, position:absolute): el containing block POSITIONED más
        // cercano (Rect del content box de un ancestro position != Static), o null cuando nadie lo
        // pasó -- en ese caso se usa el propio $containingBlock recibido como fallback (el
        // "initial containing block" de esta sub-jerarquía; Engine::render() pasa explícitamente
        // el content box de LA PÁGINA en la llamada raíz, ver su docblock).
        ?Rect $positionedCB = null,
    ): BoxFragment {
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

            // M7-T5 (CSS 2.2 §10.4): clamp del ancho USADO -- max PRIMERO, min DESPUÉS (el propio
            // texto del algoritmo del spec: si el min resultante excede el max, el min GANA). Se
            // aplica solo en la rama SIN override (un item flex ya resuelve sus propios min/max
            // en FlexFormattingContext::hypotheticalMainSize(), ver su docblock -- re-clampar aquí
            // encima de un tamaño ya negociado por grow/shrink podría deshacer esa negociación).
            // El clamp trabaja en CONTENT-space (self::toContentSpace() normaliza min/max-width
            // igual que width: box-sizing:border-box les resta su propio padding+borde) para
            // poder recomponer $borderBoxWidth con la MISMA fórmula que las 3 ramas de arriba --
            // ANTES de layoutear a los hijos, porque el ancho clampeado es SU containing width.
            $paddingH = $paddingLeft + $paddingRight;
            $borderH = $borderLeft + $borderRight;
            $maxWidthPx = $style->maxWidth?->resolve($cbWidth);
            if ($maxWidthPx !== null) {
                $contentWidth = min($contentWidth, self::toContentSpace($maxWidthPx, $style->boxSizing, $paddingH, $borderH));
            }
            $minWidthPx = $style->minWidth?->resolve($cbWidth);
            if ($minWidthPx !== null) {
                $contentWidth = max($contentWidth, self::toContentSpace($minWidthPx, $style->boxSizing, $paddingH, $borderH));
            }
            $borderBoxWidth = $contentWidth + $paddingH + $borderH;
        }

        $contentX = $x + $borderLeft + $paddingLeft;
        $cursorY = $y + $borderTop + $paddingTop;
        $contentBottom = $cursorY;
        // M7-T3: instantánea INMUTABLE del content-top de ESTA caja — $cursorY se muta por el
        // resto del método a medida que se layoutean los hijos; listMarkerFragment() necesita el
        // valor ORIGINAL (top del content box) para el caso "li sin texto" (ver su docblock),
        // mucho después de que $cursorY ya haya avanzado.
        $contentTop = $cursorY;

        // M7-T6 (CSS 2.2 §9.4.1/§10.6.7, floats/BFC): esta caja establece su PROPIO block
        // formatting context nuevo -- aislando los floats de sus hijos de los del padre -- cuando
        // nadie le pasó uno ($floatContext === null: la raíz del documento, o cualquier llamador
        // que YA establece su propio contexto de layout, ver el docblock del parámetro) O cuando
        // ELLA MISMA tiene overflow:hidden (el "clearfix" clásico, ya wireado desde M7-T5 para el
        // clip de pintado -- ver $clipsChildren más abajo). En cualquier otro caso (caja normal,
        // position:static, overflow:visible, anidada dentro de un BFC ya existente) se REUTILIZA
        // el MISMO FloatContext que el padre -- los floats de un hijo "escapan" hacia arriba hasta
        // el BFC real que los contiene, comportamiento CSS correcto.
        $establishesFreshBfc = $floatContext === null || $style->overflow === 'hidden';
        $bfc = $establishesFreshBfc ? new FloatContext($contentX, $contentX + $contentWidth) : $floatContext;

        // M7-T6 (CSS 2.2 §9.4.3/§10.3.7, position:absolute): containing block para descendientes
        // -- cualquier caja con position != Static SE CONVIERTE en el CB de SUS PROPIOS hijos (su
        // content box; altura INF porque, en este punto del método, la altura de ESTA caja
        // todavía no se conoce -- content-driven, ver el final de este método -- documentado como
        // gap conocido: un descendiente `position:absolute` que use `bottom` contra ESTE ancestro,
        // sin `top`, no puede resolverse con precisión, ver layoutAbsoluteChild()). Una caja
        // position:static normal simplemente REENVÍA el CB que recibió de su propio padre sin
        // tocarlo (nunca se convierte ella misma en CB).
        $positionedCB ??= new Rect($containingBlock->x, $containingBlock->y, $containingBlock->width, $containingBlock->height);
        $childPositionedCB = $style->position !== Position::Static
            ? new Rect($contentX, $contentTop, $contentWidth, INF)
            : $positionedCB;

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
        $flushInline = function () use (&$pendingRuns, &$children, &$cursorY, &$contentBottom, $contentX, $contentWidth, $style, $bfc): void {
            if ($pendingRuns === []) {
                return;
            }
            // M7-T6: $bfc SIEMPRE se pasa (nunca null) -- con un FloatContext SIN floats activos
            // el resultado es bit-a-bit idéntico a no pasar ninguno (ver InlineFlowContext::
            // lineExtentsForY()), así que ningún bloque sin floats de por medio cambia de
            // comportamiento.
            foreach ($this->inline->layout($pendingRuns, $contentX, $cursorY, $contentWidth, $style, $bfc) as $line) {
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

            // M7-T6 (CSS 2.2 §9.5.2): clear -- aplica a CUALQUIER caja de bloque (incluida una
            // que ADEMÁS sea float, si combina float+clear -- soportado por el algoritmo sin rama
            // extra, aunque no hay ningún test explícito de esa combinación) -- se resuelve ANTES
            // de decidir la posición Y de este hijo, ya acabe en flujo normal o sea él mismo un
            // float.
            if ($child->style->clear !== 'none') {
                $cursorY = max($cursorY, $bfc->clearBottom($child->style->clear));
            }

            // M7-T6 (CSS 2.2 §9.5): float -- colocado DENTRO del BFC de esta caja ($bfc), el
            // cursor de flujo normal NO avanza (el float se "saca" del flujo, ver
            // layoutFloatChild()). Restringido a BlockBox|ImageBox (los únicos tipos con
            // ComputedStyle propio en esta posición del árbol) -- un elemento display:inline con
            // float NO pasa por aquí (BoxTreeBuilder no lo saca de la secuencia de runs, ver el
            // docblock del parámetro $floatContext -- gap documentado, fuera del alcance reducido
            // de esta tarea).
            if ($child->style->float !== null && ($child instanceof BlockBox || $child instanceof ImageBox)) {
                $children[] = $this->layoutFloatChild($child, $contentX, $contentWidth, $cursorY, $bfc, $childPositionedCB);
                continue;
            }

            // M7-T6 (CSS 2.2 §9.4.3/§10.3.7): position:absolute -- fuera de flujo, el cursor NO
            // avanza. Se coloca contra $childPositionedCB (el CB positioned más cercano, o ESTA
            // misma caja si acaba de convertirse en CB, ver arriba) y se añade como hijo más de
            // $children (adjudicación del brief: NO se burbujea hasta el ancestro CB real -- ver
            // el docblock de layoutAbsoluteChild()).
            if ($child->style->position === Position::Absolute && ($child instanceof BlockBox || $child instanceof ImageBox)) {
                $children[] = $this->layoutAbsoluteChild($child, $childPositionedCB, $cursorY);
                continue;
            }

            if ($child instanceof ImageBox) {
                $childFragment = $this->layoutImage($child, new Rect($contentX, $cursorY, $contentWidth, INF));
                $children[] = $childFragment;
                $contentBottom = self::flowBottom($childFragment, $cursorY, $child->style, $contentWidth);
                $cursorY = $contentBottom + $child->style->marginBottom->resolve($contentWidth);
                continue;
            }
            // M5-T4: una TableBox hija se delega ENTERA a TableFormattingContext (ver
            // tableContext()/su docblock de clase) — reemplaza el skip de T3: el cursor SÍ avanza
            // ahora (mismo patrón que ImageBox/display:flex justo arriba: fragmento + avance de
            // cursor con el margin-bottom propio de la tabla, resuelto contra este mismo
            // $contentWidth).
            if ($child instanceof TableBox) {
                // M7 final-review Finding D: el chequeo de float de más arriba está restringido a
                // BlockBox|ImageBox (ver su docblock) -- una TableBox con `float` propio NUNCA
                // pasa por layoutFloatChild(), cae aquí, y se layoutea en flujo normal como si
                // float no existiera. Antes de esta tarea ese "no-op" era silencioso; se avisa UNA
                // SOLA VEZ (WarningCollector::addWarningOnce(), no una vez por tabla) sin cambiar
                // el comportamiento (la tabla sigue en flujo normal).
                if ($child->style->float !== null) {
                    $this->warnings?->addWarningOnce(
                        'float-on-table',
                        'float on a <table> has no effect (not supported yet): the table stays in normal flow',
                    );
                }
                // M8-T1 housekeeping (M7 final-review Finding D, remaining gap): `position:
                // relative|absolute` on a <table> is ALSO a silent no-op today, same root cause as
                // float just above -- TableFormattingContext::layout() (called right below,
                // unconditionally) never reads $style->position at all (grep-verified: zero
                // matches), so neither the position:relative self-shift that BlockFlowContext::
                // layout() applies to a BlockBox (see resolveRelativeOffset()) nor the
                // position:absolute out-of-flow branch just above (restricted to BlockBox|ImageBox,
                // same restriction as the float branch) ever reaches a TableBox. One warning covers
                // BOTH values (mirrors warnIfFloatOrPositionOnInline()'s single "!== Static" check),
                // once per cause via addWarningOnce(), no behavioral change (the table stays in
                // normal flow either way).
                if ($child->style->position !== Position::Static) {
                    $this->warnings?->addWarningOnce(
                        'position-on-table',
                        'position:relative/absolute on a <table> has no effect (not supported yet): the table stays in normal flow',
                    );
                }
                $childFragment = $this->tableContext()->layout($child, new Rect($contentX, $cursorY, $contentWidth, INF));
                $children[] = $childFragment;
                $contentBottom = self::flowBottom($childFragment, $cursorY, $child->style, $contentWidth);
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
                $contentBottom = self::flowBottom($childFragment, $cursorY, $child->style, $contentWidth);
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
                    floatContext: $bfc,
                    positionedCB: $childPositionedCB,
                );
                $nextListItemNumber++;
                $children[] = $childFragment;
                $contentBottom = self::flowBottom($childFragment, $cursorY, $child->style, $contentWidth);
                $cursorY = $contentBottom + $child->style->marginBottom->resolve($contentWidth);
                continue;
            }
            // M7-T6: $bfc/$childPositionedCB se threadean SIN CONDICIÓN a cualquier hijo bloque
            // normal (no establece su propio BFC/CB salvo que SU PROPIO overflow/position lo
            // decida internamente, ver el arranque de este método) -- así los floats/absolutes de
            // un descendiente anidado varios niveles ven el MISMO FloatContext/CB que esta caja,
            // sin necesitar ninguna traducción de coordenadas (este motor usa coordenadas
            // ABSOLUTAS de página en TODO el árbol de fragments, nunca locales al padre -- ver
            // Layout\Geometry\Rect / Layout\Fragment\GeometryShift -- así que pasar la MISMA
            // instancia hacia abajo es, por construcción, correcto a cualquier profundidad).
            $childFragment = $this->layout($child, new Rect($contentX, $cursorY, $contentWidth, INF), floatContext: $bfc, positionedCB: $childPositionedCB);
            $children[] = $childFragment;
            // CSS 2.2 §10.6.3: la altura de contenido llega hasta el border-box de la
            // última caja en flujo; el margin-bottom avanza el cursor para el siguiente
            // hermano pero no forma parte de la altura del padre.
            //
            // Bugfix (critical review, M7-T6): $contentBottom NO puede leerse de
            // $childFragment->rect->bottom() a secas -- ver flowBottom() de más abajo para el
            // porqué (position:relative del hijo ya viene aplicada DENTRO de $childFragment en
            // este punto, y su offset NUNCA debe alcanzar el cursor de flujo, CSS 2.2 §9.4.3).
            $contentBottom = self::flowBottom($childFragment, $cursorY, $child->style, $contentWidth);
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

        // M7-T5 (CSS 2.2 §10.7): la altura de un bloque normal es SIEMPRE content-driven en este
        // motor (no hay `height` declarado en bloques, ver docblock de $declaredHeightPx en
        // resolveReplacedSize()/FlexFormattingContext -- una limitación preexistente, ajena a esta
        // tarea) -- min-height/max-height son la ÚNICA forma de clamp de altura para un bloque.
        // Mismo algoritmo max-primero-min-después que el ancho arriba, en CONTENT-space
        // ($contentTop..$contentBottom, sin padding/borde). min-height > contentHeight natural =>
        // el box CRECE (el contenido queda anclado arriba, sin recolocarse — el fondo/borde
        // cubren el hueco extra por debajo, "floor" documentado en el brief); max-height <
        // contentHeight natural encoge el BOX (no el contenido: los hijos ya layouteados NO se
        // recolocan/recortan aquí) -- overflow:visible deja that contenido pintar más allá del
        // borde inferior (documentado, sin clipping); overflow:hidden activa $clipsChildren más
        // abajo, que es quien realmente oculta ese exceso en el momento de pintar (Paint\Painter).
        $contentHeight = $contentBottom - $contentTop;

        // M7-T6 (CSS 2.2 §10.6.7): un BFC root (overflow:hidden, o la raíz del documento -- ver
        // $establishesFreshBfc arriba) CONTIENE la altura de sus PROPIOS floats en su propio
        // cálculo de altura de contenido -- a diferencia de una caja normal (que NO establece su
        // propio BFC), cuyos floats "escapan" hacia el BFC real y NUNCA cuentan para SU altura
        // (default CSS: floats no contribuyen a la altura del contenedor que los contiene, ver el
        // brief de esta tarea). $bfc->maxBottom() solo ve los floats registrados EN ESTE MISMO
        // FloatContext -- cuando $establishesFreshBfc es true, ese FloatContext es uno NUEVO,
        // aislado, así que nunca "cuenta" floats de un ancestro/descendiente ajeno.
        if ($establishesFreshBfc) {
            $floatsBottom = $bfc->maxBottom();
            if ($floatsBottom !== null) {
                $contentHeight = max($contentHeight, $floatsBottom - $contentTop);
            }
        }

        $paddingV = $paddingTop + $paddingBottom;
        $borderV = $borderTop + $borderBottom;
        // M8 final-review Finding A (CSS 2.2 §10.5/§10.6.3): a DEFINITE declared height (px only --
        // % is rejected at PARSE time, DeclarationParser::LENGTH_PROPERTIES, same gap documented on
        // ComputedStyle::$height -- this engine never tracks a containing block's height) REPLACES
        // the natural content-driven height computed above, exactly like the width algorithm above
        // replaces $contentWidth when a declared width exists (same toContentSpace() helper, same
        // box-sizing handling). Verified no-op since M2 until this fix: nothing below this line
        // ever read $style->height at all. Content SHORTER than the declared height simply grows
        // the box (content stays anchored at its natural top, background/border cover the extra
        // space below -- the exact "floor" behavior min-height already had, see the test just
        // above this one); content TALLER overflows past the box's own bottom edge -- painted
        // as-is when overflow:visible (the default, already handled by max-height the same way,
        // see the "max-height with overflow:visible" test above), or clipped by the PRE-EXISTING
        // overflow:hidden mechanism (Paint\Painter, $clipsChildren below -- completely unchanged by
        // this fix, it already clipped to whatever $height this method computed).
        //
        // Order per CSS 2.2 §10.7 ("used height" algorithm): height (this block) -> max-height
        // clamps DOWN -> min-height clamps UP, min wins on conflict -- identical order/formula to
        // the pre-existing max/min-height clamp just below, which is untouched.
        $declaredHeightPx = $style->height?->px;
        if ($declaredHeightPx !== null) {
            $contentHeight = self::toContentSpace($declaredHeightPx, $style->boxSizing, $paddingV, $borderV);
        }
        $maxHeightPx = $style->maxHeight?->px;
        if ($maxHeightPx !== null) {
            $contentHeight = min($contentHeight, self::toContentSpace($maxHeightPx, $style->boxSizing, $paddingV, $borderV));
        }
        $minHeightPx = $style->minHeight?->px;
        if ($minHeightPx !== null) {
            $contentHeight = max($contentHeight, self::toContentSpace($minHeightPx, $style->boxSizing, $paddingV, $borderV));
        }
        $height = $borderTop + $paddingTop + $contentHeight + $paddingBottom + $borderBottom;

        $fragment = new BoxFragment(
            new Rect($x, $y, $borderBoxWidth, $height),
            $style->backgroundColor,
            $children,
            new BorderSet($style->borderTop, $style->borderRight, $style->borderBottom, $style->borderLeft),
            opacity: $style->opacity,
            // M7-T5 (css-overflow-3, reducido a visible|hidden): overflow:hidden en CUALQUIER
            // bloque (con o sin max-height activo) marca esta caja para que Paint\Painter recorte
            // TODOS sus descendientes al rect border-box final (YA clampeado arriba) -- ver
            // BoxFragment::$clipsChildren.
            clipsChildren: $style->overflow === 'hidden',
            // M8-T2: % resuelto contra $borderBoxWidth (adjudicación M8, ver BorderRadius::
            // fromCss()), clamp de solapes §5.5 contra el $height final YA calculado arriba.
            borderRadius: BorderRadius::fromCss($style->borderRadius, $borderBoxWidth, $height),
            // M8-T3: VO crudo, sin resolver -- ver el docblock de BoxFragment::$backgroundGradient.
            backgroundGradient: $style->backgroundGradient,
            // M8-T4: VO YA resuelto a px -- ver el docblock de BoxFragment::$boxShadow.
            boxShadow: $style->boxShadow,
            // M8-T6: raw path sin resolver -- ver el docblock de BoxFragment::$backgroundImagePath.
            backgroundImagePath: $style->backgroundImagePath,
            backgroundSize: $style->backgroundSize,
            backgroundRepeat: $style->backgroundRepeat,
            backgroundPosition: $style->backgroundPosition,
        );

        // M7-T6 (CSS 2.2 §9.4.3): position:relative -- shift visual PURO, aplicado DESPUÉS de que
        // el layout normal-flow de esta caja (y de todo su subárbol) ya terminó -- los hermanos y
        // la geometría de flujo normal de TODOS los demás no se ven afectados (ver GeometryShift,
        // "seguro porque Y/X solo entran de forma ADITIVA"). Sin ningún top/right/bottom/left
        // declarado, $dx===$dy===0.0 y se evita el recorrido completo del subárbol.
        if ($style->position === Position::Relative) {
            [$dx, $dy] = self::resolveRelativeOffset($style, $cbWidth);
            if ($dx !== 0.0 || $dy !== 0.0) {
                return GeometryShift::translateXY($fragment, $dx, $dy);
            }
        }
        return $fragment;
    }

    /**
     * M7-T6 (CSS 2.2 §9.4.3): resuelve el offset visual de position:relative -- left GANA sobre
     * right (usado negado, desplaza hacia la izquierda) cuando AMBOS están declarados; igual
     * criterio top sobre bottom en el eje vertical. $cbWidth es el ancho del containing block de
     * ESTA caja (para resolver el % posible de left/right -- top/bottom son SIEMPRE px, ver
     * ComputedStyle::$top/$bottom, así que $cbWidth es irrelevante para ellos).
     *
     * @return array{0: float, 1: float} [dx, dy]
     */
    private static function resolveRelativeOffset(ComputedStyle $style, float $cbWidth): array
    {
        $dx = match (true) {
            $style->left !== null => $style->left->resolve($cbWidth),
            $style->right !== null => -$style->right->resolve($cbWidth),
            default => 0.0,
        };
        $dy = match (true) {
            $style->top !== null => $style->top->resolve($cbWidth),
            $style->bottom !== null => -$style->bottom->resolve($cbWidth),
            default => 0.0,
        };
        return [$dx, $dy];
    }

    /**
     * Bugfix (critical review, M7-T6, CSS 2.2 §9.4.3): "relative positioning [...] does not
     * affect the position of any other box" — top/left/bottom/right on a position:relative box is
     * a PAINT-ONLY shift; the box's contribution to normal flow (where the NEXT sibling starts,
     * and the parent's own content-driven auto-height) must be computed as if the shift never
     * happened. Before this fix, EVERY sibling-advance site in the loop above (block/image/table/
     * flex/list-item — all five child kinds this context can produce) read
     * `$childFragment->rect->bottom()` directly — but $childFragment, by the time it's returned
     * from layout()/layoutImage()/tableContext()->layout()/flexContext()->layout(), may ALREADY
     * be the POST-shift fragment (see the position:relative branch at the end of layout()/
     * layoutImage(): GeometryShift::translateXY() is applied there, before returning) — so a
     * `top`/`left` offset leaked straight into the flow math (reviewer repro: `.rel{position:
     * relative;top:50px;min-height:20px}` followed by a sibling put the sibling at y=70 instead
     * of y=20, and grew the container's auto-height by the same 50px).
     *
     * This computes the PRE-shift border-box bottom WITHOUT needing to know dx/dy at all, by
     * exploiting the one invariant every producer of $childFragment shares (verified against
     * layout()/layoutImage()/TableFormattingContext::layout()/FlexFormattingContext::layout(), all
     * four resolve their own box the same way, see the top of each): the box's border-box top is
     * ALWAYS placed at exactly `$containingBlock->y + marginTop` BEFORE any relative shift, and
     * GeometryShift::translateXY() only ever adds dx/dy to x/y — it NEVER touches width/height
     * (see its docblock: "seguro porque X/Y solo entran de forma ADITIVA"). So:
     *
     *   pre-shift bottom = (containingBlock->y BEFORE this child's layout call) + own margin-top
     *                       + $childFragment->rect->height
     *
     * $precedingCursorY is that containingBlock->y — the flow cursor exactly as it stood right
     * before the layout()/layoutImage()/tableContext()->layout()/flexContext()->layout() call that
     * produced $childFragment (every call site above passes `new Rect($contentX, $cursorY, ...)`
     * as that containing block, and reads $childFragment before mutating $cursorY, so the caller
     * always has this value on hand for free — no extra state to thread through).
     *
     * For a child WITHOUT position:relative (the overwhelming common case, and the ONLY case for
     * TableBox/display:flex containers today — see the docblock note below) this is bit-for-bit
     * identical to the old `$childFragment->rect->bottom()`, since dy=0 means nothing shifted in
     * the first place.
     *
     * NOTE on TableBox/display:flex: TableFormattingContext/FlexFormattingContext do NOT implement
     * a position:relative shift for their OWN container box today (only BlockFlowContext::layout()
     * and layoutImage() do) — so those two branches can never actually exhibit this bug yet. They
     * are routed through this SAME helper anyway (rather than left on the old direct
     * `->rect->bottom()` read) so all five sibling-advance sites share one invariant and one
     * implementation, and so this fix keeps holding automatically if either context ever grows its
     * own position:relative handling.
     */
    private static function flowBottom(BoxFragment $childFragment, float $precedingCursorY, ComputedStyle $childStyle, float $contentWidth): float
    {
        $marginTop = $childStyle->marginTop->resolve($contentWidth);
        return $precedingCursorY + $marginTop + $childFragment->rect->height;
    }

    /**
     * M7-T6 (CSS 2.2 §9.5, floats): coloca un hijo BlockBox|ImageBox flotante DENTRO del BFC de
     * esta caja ($bfc). El hijo se layoutea PRIMERO en un origen "de trabajo" (contentX, $minY)
     * para conocer su MARGIN BOX real -- un BlockBox usa shrink-to-fit width (declarado gana si
     * existe, ver shrinkToFitWidth(), mismo criterio que un inline-block); un ImageBox usa su
     * tamaño de replaced element normal (ya es "shrink-to-fit" por naturaleza, resolveReplacedSize()
     * ya no depende del ancho disponible salvo para el clamp de min/max-width) -- y LUEGO se pide
     * a FloatContext::place() dónde cabe DE VERDAD (bandas existentes de su lado); la diferencia
     * entre ambas posiciones se aplica como un shift 2D (GeometryShift::translateXY) sobre el
     * fragment YA layouteado, sin recalcular nada (mismo principio de seguridad que
     * GeometryShift::translateY(), documentado ahí: X/Y solo entran de forma ADITIVA en este
     * motor). El CALLER (el bucle de layout()) es quien garantiza que el cursor de flujo normal
     * NO avanza tras esta llamada (CSS 2.2 §9.5: "a float is removed from the normal flow").
     *
     * Un float SIEMPRE establece su PROPIO block formatting context para sus hijos (CSS 2.2
     * §9.4.1) -- de ahí floatContext: null en la llamada recursiva de más abajo, igual criterio
     * que overflow:hidden/flex/table/inline-block (ver el docblock de FloatContext).
     */
    private function layoutFloatChild(
        BlockBox|ImageBox $child,
        float $contentX,
        float $contentWidth,
        float $minY,
        FloatContext $bfc,
        Rect $childPositionedCB,
    ): BoxFragment {
        $style = $child->style;
        $side = $style->float ?? FloatSide::Left; // Nunca null aquí -- guard del caller.

        if ($child instanceof ImageBox) {
            $fragment = $this->layoutImage($child, new Rect($contentX, $minY, $contentWidth, INF));
        } else {
            $usedWidth = $this->shrinkToFitWidth($child, $contentWidth);
            $fragment = $this->layout($child, new Rect($contentX, $minY, $contentWidth, INF), $usedWidth, floatContext: null, positionedCB: $childPositionedCB);
        }

        $marginLeft = $style->marginLeft->resolve($contentWidth);
        $marginRight = $style->marginRight->resolve($contentWidth);
        $marginTop = $style->marginTop->resolve($contentWidth);
        $marginBottom = $style->marginBottom->resolve($contentWidth);

        $marginBoxWidth = $marginLeft + $fragment->rect->width + $marginRight;
        $marginBoxHeight = $marginTop + $fragment->rect->height + $marginBottom;

        $placed = $bfc->place($side, $marginBoxWidth, $marginBoxHeight, $minY);

        // El fragment YA fue layouteado con su margin box en ($contentX, $minY) -- ver arriba
        // ($this->layout()/layoutImage() suman marginLeft/marginTop internamente al
        // containingBlock->x/y recibido, que aquí es exactamente ($contentX, $minY)). El delta
        // entre esa posición "de trabajo" y la FINAL decidida por FloatContext es lo único que
        // hace falta desplazar.
        $deltaX = $placed->x - $contentX;
        $deltaY = $placed->y - $minY;
        if ($deltaX === 0.0 && $deltaY === 0.0) {
            return $fragment;
        }
        return GeometryShift::translateXY($fragment, $deltaX, $deltaY);
    }

    /**
     * M7-T6 (CSS 2.2 §9.4.3/§10.3.7, position:absolute reducido): coloca un hijo BlockBox|ImageBox
     * position:absolute contra $cb (el containing block positioned más cercano, o el CB inicial
     * de página si ninguno -- ver $childPositionedCB en layout()). SIMPLIFICACIÓN DELIBERADA del
     * brief: ancho/alto SIEMPRE shrink-to-fit cuando son auto -- NO se implementa el caso "left Y
     * right ambos declarados resuelve el ancho" de los 10 casos completos del algoritmo real de la
     * spec (CSS 2.2 §10.3.7 tabla completa). $flowCursorY es la Y ACTUAL del cursor de flujo del
     * padre -- fallback de "posición estática" (CSS 2.2 §10.3.7 caso "static position") cuando NI
     * top NI bottom están declarados: un navegador real calcularía la posición exacta que el
     * elemento tendría en flujo normal; aquí basta con "donde está el cursor ahora mismo"
     * (aproximación documentada).
     *
     * Igual que un float, la caja se layoutea PRIMERO en un origen "de trabajo" (el propio $cb) y
     * LUEGO se desplaza (GeometryShift::translateXY) a su posición final -- mismo principio que
     * layoutFloatChild(). El fragment resultante viaja como hijo más del $children del CALLER
     * (nunca se burbujea hasta el ancestro CB real, aunque esté varios niveles por encima) --
     * adjudicación EXPLÍCITA del brief: "rides along as a child fragment of its parent's
     * BoxFragment for painting/pagination purposes" -- válida en este motor porque TODO el árbol
     * de fragments usa coordenadas ABSOLUTAS de página (nunca locales al padre), así que el rect
     * final es correcto sin importar en qué nivel del árbol de fragments quede colgado.
     *
     * Un `position:absolute` SIEMPRE establece su PROPIO BFC para sus hijos (igual que un float,
     * flex/table/inline-block) -- floatContext: null en la llamada recursiva de más abajo.
     *
     * "Warns if taller than page" (brief): aproximado aquí como "excede la altura de $cb" -- solo
     * verificable cuando $cb tiene una altura FINITA conocida (el CB raíz que Engine::render()
     * plumbea con la altura REAL del área de contenido de página, ver su docblock); un ancestro
     * positioned con altura todavía sin resolver (INF, ver layout()) no permite este chequeo --
     * gap documentado, no cubierto por ningún test de esta tarea salvo el caso raíz.
     */
    private function layoutAbsoluteChild(BlockBox|ImageBox $child, Rect $cb, float $flowCursorY): BoxFragment
    {
        $style = $child->style;

        if ($child instanceof ImageBox) {
            $fragment = $this->layoutImage($child, new Rect($cb->x, $cb->y, $cb->width, INF));
        } else {
            $usedWidth = $this->shrinkToFitWidth($child, $cb->width);
            $fragment = $this->layout($child, new Rect($cb->x, $cb->y, $cb->width, INF), $usedWidth, floatContext: null, positionedCB: $cb);
        }

        $marginLeft = $style->marginLeft->resolve($cb->width);
        $marginRight = $style->marginRight->resolve($cb->width);
        $marginTop = $style->marginTop->resolve($cb->width);
        $marginBottom = $style->marginBottom->resolve($cb->width);
        $marginBoxWidth = $marginLeft + $fragment->rect->width + $marginRight;
        $marginBoxHeight = $marginTop + $fragment->rect->height + $marginBottom;

        $left = $style->left?->resolve($cb->width);
        $right = $style->right?->resolve($cb->width);
        $top = $style->top?->resolve($cb->width);
        $bottom = $style->bottom?->resolve($cb->width);

        $desiredMarginBoxX = match (true) {
            $left !== null => $cb->x + $left,
            $right !== null => $cb->x + $cb->width - $right - $marginBoxWidth,
            default => $cb->x, // Posición estática aproximada (borde izquierdo del CB).
        };
        $desiredMarginBoxY = match (true) {
            $top !== null => $cb->y + $top,
            $bottom !== null && is_finite($cb->height) => $cb->y + $cb->height - $bottom - $marginBoxHeight,
            $bottom !== null => (function () use ($flowCursorY): float {
                // Gap documentado (ver docblock del método): `bottom` contra un CB de altura
                // todavía no resuelta (ancestro position:relative/absolute cuya propia altura es
                // content-driven, ver layout()) no puede resolverse con precisión -- se avisa y se
                // cae al fallback de posición estática.
                $this->warnings?->addWarning(
                    'position:absolute with "bottom" against an auto-height containing block is not supported; falling back to the static position',
                );
                return $flowCursorY;
            })(),
            default => $flowCursorY, // Posición estática aproximada (CSS 2.2 §10.3.7).
        };

        if (is_finite($cb->height) && $desiredMarginBoxY + $marginBoxHeight > $cb->y + $cb->height) {
            $this->warnings?->addWarning(
                'position:absolute box exceeds its containing block height; no independent pagination for absolutely positioned boxes',
            );
        }

        $deltaX = $desiredMarginBoxX - $cb->x;
        $deltaY = $desiredMarginBoxY - $cb->y;
        if ($deltaX === 0.0 && $deltaY === 0.0) {
            return $fragment;
        }
        return GeometryShift::translateXY($fragment, $deltaX, $deltaY);
    }

    /**
     * M7-T6: ancho shrink-to-fit border-box para un BlockBox flotante o position:absolute --
     * width declarado (resuelto contra $availableWidth, convertido a border-box si
     * box-sizing:content-box) gana; si no, min(max-content, $availableWidth) -- MISMO criterio
     * que InlineFlowContext::layoutInlineBlockAtomic() para un inline-block (duplicado aquí en vez
     * de compartir código entre clases, mismo criterio "sin trait" ya documentado en
     * FlexFormattingContext).
     *
     * Bugfix (M7 final-review Finding C): el docblock de este método afirmaba que min/max-width
     * NO se clampan aquí porque "BlockFlowContext::layout() ya lo hace internamente sobre el
     * $usedWidthOverride resultante" -- FALSO. layout() solo aplica ese clamp dentro de la rama SIN
     * override (ver su docblock M7-T5, "Se aplica solo en la rama SIN override"); CUALQUIER valor
     * que llegue vía $usedWidthOverride (que es exactamente lo que devuelve este método a sus DOS
     * callers, layoutFloatChild()/layoutAbsoluteChild()) se usa TAL CUAL, sin pasar nunca por ese
     * clamp -- un float o position:absolute con min/max-width propio los veía completamente
     * ignorados (repro: `float{width:300px;max-width:100px}` renderizaba a 300px, no 100px). El
     * clamp se aplica aquí, DESPUÉS de resolver el ancho usado por CUALQUIER camino (declarado o
     * shrink-to-fit) -- mismo patrón "min(max(minW, fit), maxW)" que
     * InlineFlowContext::layoutInlineBlockAtomic() ya usa para un inline-block (max primero, min
     * después: CSS 2.2 §10.4, "si el min resultante excede el max, el min gana").
     */
    private function shrinkToFitWidth(BlockBox $box, float $availableWidth): float
    {
        $style = $box->style;
        $declaredWidthPx = $style->width?->resolve($availableWidth);
        if ($declaredWidthPx !== null) {
            if ($style->boxSizing === 'border-box') {
                $usedWidth = max(0.0, $declaredWidthPx);
            } else {
                $paddingLeft = $style->paddingLeft->resolve($availableWidth);
                $paddingRight = $style->paddingRight->resolve($availableWidth);
                $borderLeft = $style->borderLeft->widthPx;
                $borderRight = $style->borderRight->widthPx;
                $usedWidth = max(0.0, $declaredWidthPx + $paddingLeft + $paddingRight + $borderLeft + $borderRight);
            }
        } else {
            $maxContent = $this->intrinsicSizer()->maxContentWidth($box);
            $usedWidth = max(0.0, min($maxContent, $availableWidth));
        }

        $minWidthPx = $style->minWidth?->resolve($availableWidth);
        $maxWidthPx = $style->maxWidth?->resolve($availableWidth);
        if ($minWidthPx === null && $maxWidthPx === null) {
            return $usedWidth;
        }
        $paddingH = $style->paddingLeft->resolve($availableWidth) + $style->paddingRight->resolve($availableWidth);
        $borderH = $style->borderLeft->widthPx + $style->borderRight->widthPx;
        $toBorderBox = static fn(float $px): float => $style->boxSizing === 'border-box' ? $px : $px + $paddingH + $borderH;
        if ($maxWidthPx !== null) {
            $usedWidth = min($usedWidth, $toBorderBox($maxWidthPx));
        }
        if ($minWidthPx !== null) {
            $usedWidth = max($usedWidth, $toBorderBox($minWidthPx));
        }
        return $usedWidth;
    }

    private function intrinsicSizer(): IntrinsicSizer
    {
        return $this->intrinsicSizer ??= new IntrinsicSizer($this->measurer, $this->catalog, $this->warnings);
    }

    /**
     * M7-T5: normaliza un valor min/max-width/height DECLARADO (mismo espacio que `width`/`height`
     * propios: border-box si box-sizing:border-box, content-box si no) al espacio de CONTENIDO,
     * restando padding+borde del eje correspondiente cuando border-box -- para poder compararlo
     * directamente contra un $contentWidth/$contentHeight ya en ese mismo espacio. Usado por AMBOS
     * clamps de este método (ancho arriba, alto abajo) -- resolveReplacedSize()/
     * InlineFlowContext::layoutInlineBlockAtomic()/FlexFormattingContext tienen su PROPIA copia de
     * esta misma fórmula de 2 líneas (duplicación deliberada, mismo criterio "sin trait" ya
     * documentado en FlexFormattingContext -- no vale la pena romper el encapsulamiento privado de
     * cada clase por una función tan pequeña).
     */
    private static function toContentSpace(float $declaredPx, string $boxSizing, float $paddingSum, float $borderSum): float
    {
        return $boxSizing === 'border-box' ? max(0.0, $declaredPx - $paddingSum - $borderSum) : $declaredPx;
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

        $fragment = new BoxFragment(
            new Rect($x, $y, $borderBoxWidth, $borderBoxHeight),
            $style->backgroundColor,
            [$imageFragment],
            new BorderSet($style->borderTop, $style->borderRight, $style->borderBottom, $style->borderLeft),
            opacity: $style->opacity,
            borderRadius: BorderRadius::fromCss($style->borderRadius, $borderBoxWidth, $borderBoxHeight),
            backgroundGradient: $style->backgroundGradient,
            boxShadow: $style->boxShadow,
            backgroundImagePath: $style->backgroundImagePath,
            backgroundSize: $style->backgroundSize,
            backgroundRepeat: $style->backgroundRepeat,
            backgroundPosition: $style->backgroundPosition,
        );

        // M7-T6: un <img> también puede ser position:relative -- mismo shift visual puro que un
        // BlockBox normal, ver el docblock de layout().
        if ($style->position === Position::Relative) {
            [$dx, $dy] = self::resolveRelativeOffset($style, $cbWidth);
            if ($dx !== 0.0 || $dy !== 0.0) {
                return GeometryShift::translateXY($fragment, $dx, $dy);
            }
        }
        return $fragment;
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
        // M7 final-review Finding E: min/max-height en un replaced element (<img>) NUNCA se
        // aplican en este motor (ver el comentario más abajo, junto al clamp de min/max-WIDTH, que
        // documenta el porqué) -- antes de esta tarea ese "no-op" era silencioso; ahora se avisa
        // UNA SOLA VEZ (WarningCollector::addWarningOnce(), no una vez por <img>) sin cambiar el
        // comportamiento (min/max-height siguen ignorados, min/max-width siguen siendo los únicos
        // que clampan).
        if ($style->minHeight !== null || $style->maxHeight !== null) {
            $this->warnings?->addWarningOnce(
                'min-max-height-on-replaced',
                'min/max-height on replaced elements not supported yet',
            );
        }
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
        // M7-T5: capturado ANTES de que el if/elseif de abajo pueda asignar $height desde el
        // ratio -- "auto" en el sentido de §10.4 significa NINGUNA de las 3 fuentes (CSS/attr) lo
        // fijó, ni siquiera indirectamente.
        $heightWasAuto = $declaredHeightPx === null && $box->attrHeight === null;

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

        // M7-T5 (CSS 2.2 §10.4 table, SIMPLIFICADO): la tabla completa de la spec tiene 9 casos
        // cruzando min/max-width CON min/max-height simultáneamente (incluyendo "shrink to
        // satisfy both" cuando entran en conflicto). Este motor solo clampa min/max-WIDTH aquí y,
        // cuando la altura era AUTO (ninguna de las 3 fuentes la fijó, ver $heightWasAuto arriba),
        // la RE-DERIVA por el aspect ratio a partir del ancho YA clampeado -- exactamente el
        // mismo mecanismo que la rama "$height === null" de arriba, aplicado una segunda vez tras
        // el clamp. min/max-HEIGHT en un replaced element NO se aplican en absoluto en esta tarea
        // (divergencia documentada: fuera del alcance reducido de M7-T5 -- un <img> con min/
        // max-height propio simplemente los ignora, igual criterio "soft" que otras
        // simplificaciones ya documentadas en este método; M7 final-review Finding E: este no-op
        // avisa ahora una vez por render, ver el inicio del método -- el comportamiento en sí
        // sigue sin cambiar). Esta rama solo se alcanza SIN override
        // (la rama con $usedWidthOverride ya retornó arriba -- mismo criterio que
        // BlockFlowContext::layout(): un item flex ya negocia su propio ancho en
        // FlexFormattingContext).
        $maxWidthPx = $style->maxWidth?->resolve($cbWidth);
        if ($maxWidthPx !== null) {
            $width = min($width, self::toContentSpace($maxWidthPx, $style->boxSizing, $paddingBorderX, 0.0));
        }
        $minWidthPx = $style->minWidth?->resolve($cbWidth);
        if ($minWidthPx !== null) {
            $width = max($width, self::toContentSpace($minWidthPx, $style->boxSizing, $paddingBorderX, 0.0));
        }
        if ($heightWasAuto && $ratio > 0.0) {
            $height = $width * $ratio;
        }

        return [$width, $height];
    }
}
