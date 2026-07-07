<?php

declare(strict_types=1);

namespace Pliego\Box;

use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\LengthPercentage;
use Pliego\Css\WarningCollector;
use Pliego\Image\ImageException;
use Pliego\Image\ImageLoader;
use Pliego\Image\ImagePathResolver;
use Pliego\Style\ComputedStyle;
use Pliego\Style\Display;
use Pliego\Style\Position;
use Pliego\Style\StyleMap;
use Pliego\Style\TextTransform;

/**
 * M7-T4 (css-inline-3 reducido): "¿es este tag inline?" ya NO se decide aquí con una lista de
 * tags hardcoded (el antiguo INLINE_TAGS, presente desde M1 hasta M7-T3) -- migró a
 * Style\UserAgentStylesheet como una regla CSS real (`span, strong, ... { display: inline }`),
 * consultada vía ComputedStyle::$display === Display::Inline en collectChildren()/collectInline()
 * de más abajo. Ver el docblock de esa hoja para el razonamiento completo de la migración.
 */
final class BoxTreeBuilder
{
    public function __construct(
        private readonly ImageLoader $imageLoader,
        private readonly WarningCollector $warnings,
        private readonly string $basePath,
    ) {}

    public function build(\Dom\HTMLDocument $document, StyleMap $styles): BlockBox
    {
        $body = $document->body ?? throw new \InvalidArgumentException('Document has no body');
        return $this->buildBlock($body, $styles);
    }

    private function buildBlock(\Dom\Element $element, StyleMap $styles): BlockBox
    {
        $this->warnIfInlineStyleAttribute($element);
        $style = $styles->get($element);
        $isFlex = $style->display === Display::Flex;
        // M10-T2 (navbar investigation, css-flexbox-1 §4): $isFlex threads into collectChildren()
        // so a DIRECT Display::Inline child is treated as its own flex item (blockified) instead
        // of flattened into the InlineBoxStart/pending-run sequence — see collectChildren()'s own
        // docblock for the full rationale (root cause of the navbar box-model mismatch this task
        // investigated: two adjacent boxed inline siblings used to merge into ONE flex item).
        $children = $this->collectChildren($element, $styles, $style, $isFlex);
        if ($isFlex) {
            $children = $this->wrapAnonymousFlexItems($children, $style);
        }
        return new BlockBox($style, $children, strtolower($element->tagName), self::parseListStart($element));
    }

    /** M7-T3 (css-lists-3 §3, atributo HTML `start` de <ol>): entero con signo opcional; ausente
     * o no puramente numérico -> null (= "empieza en 1", el default real per HTML). No se gatea
     * por tag (leerlo en cualquier elemento es inocuo: solo el BlockBox de un <ol> real llega a
     * consultarse para esto, ver BlockFlowContext) — mismo criterio de permisividad que
     * parseColspan(), a diferencia de rowspan (que sí avisa por su sola presencia). */
    private static function parseListStart(\Dom\Element $element): ?int
    {
        $value = $element->getAttribute('start');
        if ($value === null || preg_match('/^-?\d+$/', trim($value)) !== 1) {
            return null;
        }
        return (int) trim($value);
    }

    /**
     * M5-T3: recorrido de hijos EXTRAÍDO de buildBlock() sin cambiar su comportamiento, para que
     * buildTableCell() pueda reutilizarlo TAL CUAL (el brief exige "children normales — reutiliza
     * el pipeline entero") sin pasar por el envoltorio BlockBox ni por wrapAnonymousFlexItems()
     * (una celda no es, por sí misma, un contenedor flex salvo que declare su propio
     * display:flex, caso en el que ComputedStyle ya la habría dejado de reconocer como
     * Display::TableCell — ver docblock de collectTableRows()/buildTableRow(), fuera de alcance
     * de esta tarea). Único cambio de comportamiento respecto al buildBlock() de M4: un hijo cuyo
     * Display es Table construye un TableBox (buildTable()) en vez de recursar como BlockBox
     * plano — ver buildChildBox().
     *
     * M10-T2 (navbar investigation, css-flexbox-1 §4 "each in-flow child of a flex container
     * becomes a flex item"): $parentIsFlex (new, defaults false — buildTableCell()'s call site
     * never passes it, a cell is not itself a flex container just because ITS ANCESTOR might be,
     * see this method's own class docblock reference above) changes exactly ONE decision: when
     * true AND a direct child is Display::Inline, that child is treated EXACTLY like a
     * Display::Block child (flush + buildChildBox(), the same fallback branch a real block element
     * already takes at the bottom of this method) instead of being flattened into the
     * InlineBoxStart/pending-run sequence at all. Root cause this fixes (found investigating
     * fixture 07's navbar, M10-T2's oracle task): `.navbar-brand`/`.navbar-text` (an `<a>`/`<span>`,
     * UA-default Display::Inline, both with real Bootstrap padding) are two ADJACENT direct
     * children of a `display:flex` container — before this fix, wrapAnonymousFlexItems() treated
     * their InlineBoxStart/InlineBoxEnd tokens exactly like bare TextRun (see that method's own
     * docblock), merging BOTH into ONE shared anonymous flex item instead of two separate ones,
     * breaking both their cross-axis placement (justify-content:space-between never saw two items
     * to space apart) and the container's auto height (one blended inline-flow line instead of two
     * items each sized to their own line-height). Un-gated by hasVisibleInlineBox() -- this now
     * also fixes the BOXLESS case (two plain `<span>`s with no box CSS at all), which the old
     * flattened-sequence representation could never have told apart anyway (their boundary
     * information is genuinely gone once flattened, see hasVisibleInlineBox()'s own docblock on
     * the M7-T4 fast path this still reuses for the NON-flex-parent case).
     *
     * M10 final-review Finding A: the paragraph above USED to say Display::InlineBlock direct
     * children were left out of this fix's scope ("no verified regression traces to that case") --
     * that carve-out was wrong. A `display:flex` container with two adjacent inline-block children
     * (Bootstrap's own `.btn-group`-shaped markup: `<span class="ib">a</span><span
     * class="ib">b</span>`, no text between) merged BOTH into ONE shared anonymous flex item,
     * exactly the same css-flexbox-1 §4 violation the Display::Inline case above was fixed for --
     * `justify-content:space-between` never saw two items to space apart. Display::InlineBlock now
     * takes the SAME $parentIsFlex branch as Display::Inline (see the check just below): flush +
     * buildChildBox(), never entering the pending/run token sequence when the parent is a flex
     * container. wrapAnonymousFlexItems()'s own InlineBlock-coalesce branch is consequently DEAD
     * code (it only ever receives children collected with $parentIsFlex=true — buildBlock() is its
     * only call site, always under `if ($isFlex)`) and was removed there; the OLD, still-live
     * behavior (coalescing an inline-block into a shared anonymous item alongside a NON-flex
     * ancestor's other inline content, e.g. nested inside a plain `<p>`) is unaffected — this
     * change only touches inline-block children whose DIRECT parent is itself a flex container.
     *
     * @return list<BlockBox|TextRun|LineBreakRun|ImageBox|TableBox|InlineBoxStart|InlineBoxEnd>
     */
    private function collectChildren(\Dom\Element $element, StyleMap $styles, ComputedStyle $style, bool $parentIsFlex = false): array
    {
        $children = [];
        /**
         * @var list<TextRun|LineBreakRun|ImageBox|InlineBoxStart|InlineBoxEnd|BlockBox> $pending
         *     secuencia inline pendiente de colapsar (M1-T4). Puede incluir ImageBox (M3-T2 defect
         *     fix): una <img> anidada dentro de un elemento inline se "hoistea" aquí como token de
         *     la secuencia — ver collectInline() — y collapse() la trata como separador de
         *     secuencia, igual que un LineBreakRun. M7-T4: += InlineBoxStart/InlineBoxEnd (caja
         *     inline real, ver hasVisibleInlineBox()) y BlockBox (un elemento display:inline-block
         *     ENTERO, construido vía buildBlock() como cualquier bloque normal, pero colocado AQUÍ
         *     —en la secuencia de runs— como token atómico en vez de en $children, para que
         *     InlineFlowContext lo trate como un "glifo" gigante dentro de la línea).
         */
        $pending = [];
        $flush = function () use (&$children, &$pending): void {
            foreach (self::collapse($pending) as $run) {
                $children[] = $run;
            }
            $pending = [];
        };
        foreach ($element->childNodes as $node) {
            if ($node instanceof \Dom\Text) {
                foreach (self::textRunTokensFor($node->textContent ?? '', $style) as $token) {
                    $pending[] = $token;
                }
                continue;
            }
            if (!$node instanceof \Dom\Element) {
                continue;
            }
            $childStyle = $styles->get($node);
            if ($childStyle->display === Display::None) {
                continue;
            }
            $this->warnIfInlineStyleAttribute($node);
            $tag = strtolower($node->tagName);
            if ($tag === 'br') {
                $pending[] = new LineBreakRun();
                continue;
            }
            if ($tag === 'img') {
                $flush();
                $imageBox = $this->buildImage($node, $childStyle);
                if ($imageBox !== null) {
                    $children[] = $imageBox;
                }
                continue;
            }
            // M7-T4: display:inline-block es un token ATÓMICO dentro de la MISMA secuencia de
            // runs que TextRun/LineBreakRun (nunca se "flushea" a $children como un bloque
            // normal) — buildBlock() reutiliza el pipeline COMPLETO (collectChildren recursivo,
            // igual que cualquier hijo bloque real), la única diferencia es DÓNDE aterriza el
            // BlockBox resultante. Se comprueba ANTES que Display::Inline porque ambos son
            // mutuamente excluyentes (un elemento no puede tener los dos display a la vez).
            //
            // M10 final-review Finding A: `&& !$parentIsFlex` -- same $parentIsFlex gate as the
            // Display::Inline branch just below (M10-T2), now widened to cover InlineBlock too
            // (see this method's own docblock above for the root cause this closes). When the
            // parent IS a flex container, an inline-block child falls through to the generic
            // Display::Block-shaped fallback at the bottom of this method instead (flush() +
            // buildChildBox()), becoming its own top-level flex item.
            if ($childStyle->display === Display::InlineBlock && !$parentIsFlex) {
                $this->warnIfFloatOrAbsoluteOnInlineBlock($childStyle);
                $pending[] = $this->buildBlock($node, $styles);
                continue;
            }
            if ($childStyle->display === Display::Inline && !$parentIsFlex) {
                $this->warnIfUnsupportedSubOrSup($tag);
                $this->warnIfFloatOrPositionOnInline($childStyle);
                $hasBox = self::hasVisibleInlineBox($childStyle);
                if ($hasBox) {
                    $pending[] = new InlineBoxStart($childStyle, $tag);
                }
                $this->collectInline($node, $styles, $pending);
                if ($hasBox) {
                    $pending[] = new InlineBoxEnd();
                }
                continue;
            }
            // M10-T2: a Display::Inline child falls through to here too when $parentIsFlex --
            // buildChildBox() below builds it as its own top-level BlockBox (blockified flex
            // item, see this method's own docblock), exactly like any Display::Block child always
            // has.
            $flush();
            $children[] = $this->buildChildBox($node, $styles, $childStyle);
        }
        $flush();
        return $children;
    }

    /**
     * M7-T4 (css-inline-3 reducido, fast path CRÍTICO para estabilidad de goldens): un elemento
     * Display::Inline SIN ninguna propiedad de caja visible (sin background, sin borde visible en
     * ningún lado, sin padding no-cero en ningún lado) NUNCA produce InlineBoxStart/InlineBoxEnd —
     * su contenido se sigue aplanando exactamente igual que ANTES de esta tarea (M1-M7-T3: un
     * <span>/<strong>/... "normal", sin CSS de caja propio, como los usados en TODOS los goldens
     * existentes) — collectChildren()/collectInline() nunca llaman a este método salvo para
     * decidir SI envolver, así que el resultado de collapse() para un documento sin cajas inline
     * visibles es BYTE-IDÉNTICO al de antes de esta tarea (misma secuencia de TextRun/LineBreakRun/
     * ImageBox, sin ningún token nuevo intercalado que pudiera romper la fusión de runs adyacentes
     * del mismo estilo, ver collapse()).
     *
     * Padding VERTICAL (top/bottom) se incluye en el chequeo aunque, por sí solo (sin background
     * ni borde), no produce ninguna diferencia observable (ver InlineFlowContext: solo el padding
     * HORIZONTAL afecta el ancho de línea) — incluirlo de todas formas es inofensivo (simplemente
     * hace el fast path un pelín menos agresivo en ese caso límite) y evita tener que documentar
     * una tercera categoría de "padding visible pero sin efecto".
     */
    private static function hasVisibleInlineBox(ComputedStyle $style): bool
    {
        if ($style->backgroundColor !== null) {
            return true;
        }
        // M8-T4: `!== None` en vez de `=== Solid` -- un <span> con border: 2px dashed/dotted
        // también necesita el camino InlineBoxStart/InlineBoxEnd (sin esto, el fast path de M7-T4
        // se lo tragaría entero, dejando ese borde invisible: Dashed/Dotted no existían todavía
        // cuando este chequeo se escribió). No-op observacional para M2-M7 (Solid/None eran los
        // únicos dos valores posibles).
        foreach ([$style->borderTop, $style->borderRight, $style->borderBottom, $style->borderLeft] as $side) {
            if ($side->style !== BorderStyle::None && $side->widthPx > 0.0) {
                return true;
            }
        }
        // M8-T4 (brief: "InlineBoxFragment: NO shadow M8 -- declarado en inline -> warning,
        // documentado"): un box-shadow declarado en un <span> SIN ningún otro CSS de caja visible
        // debe seguir tomando el camino InlineBoxStart/InlineBoxEnd -- de lo contrario el fast
        // path de arriba se lo tragaría entero y el warning de
        // InlineFlowContext::buildInlineBoxFragment() (el único sitio que lo emite) nunca llegaría
        // a dispararse, dejando el box-shadow "silenciosamente" descartado sin aviso (viola "todo
        // lo excluido avisa"). El propio box-shadow nunca se pinta de todas formas (M8), así que
        // forzar el camino solo sirve para llegar hasta ese warning.
        if ($style->boxShadow !== null) {
            return true;
        }
        // M8-T6 (brief: "InlineBoxFragment: NO background-image support M8 -- declarado en inline
        // -> warning, documentado"): mismo motivo exacto que $boxShadow justo arriba -- un
        // background-image en un <span> SIN ningún otro CSS de caja visible debe seguir tomando el
        // camino InlineBoxStart/InlineBoxEnd, o el warning de InlineFlowContext::
        // buildInlineBoxFragment() (el único sitio que lo emite) nunca llegaría a dispararse,
        // dejando el background-image "silenciosamente" descartado sin aviso.
        if ($style->backgroundImagePath !== null) {
            return true;
        }
        // M8 final-review Finding C: a `background` gradient (linear-gradient()/radial-gradient())
        // declared on a <span> with NO other box CSS was missing from this list entirely -- it fell
        // through the fast path exactly like the box-shadow/background-image gaps just above USED
        // to (both already fixed, see their comments), silently dropping the gradient with NO ink
        // and NO warning (unlike shadow/background-image, InlineBoxFragment DOES support a
        // per-slice gradient, see InlineFlowContext::buildInlineBoxFragment() -- so this omission
        // wasn't just a missing-warning gap, it was disabling a real, working feature).
        if ($style->backgroundGradient !== null) {
            return true;
        }
        $nonZero = static fn(LengthPercentage $lp): bool => $lp->calc !== null || $lp->value !== 0.0;
        return $nonZero($style->paddingLeft) || $nonZero($style->paddingRight)
            || $nonZero($style->paddingTop) || $nonZero($style->paddingBottom);
    }

    /**
     * M5-T3: dispatch por Display::Table entre TableBox y BlockBox — el ÚNICO punto donde
     * collectChildren() (y, transitivamente, wrapElementInAnonymousRow()/buildTableRow() para un
     * elemento "no-fila"/"no-celda" que resulta ser él mismo otra tabla) decide construir una
     * TableBox en vez de recursar como bloque plano. $childStyle se recibe ya resuelto por el
     * caller para no repetir el lookup en StyleMap.
     */
    private function buildChildBox(\Dom\Element $element, StyleMap $styles, ComputedStyle $childStyle): BlockBox|TableBox
    {
        return $childStyle->display === Display::Table
            ? $this->buildTable($element, $styles)
            : $this->buildBlock($element, $styles);
    }

    /**
     * M5-T3 (css-tables-3 §2 / CSS 2.2 §17.2.1): elemento con Display::Table (típicamente
     * <table>, UA default desde M5-T2 — ver ComputedStyle::TABLE_DISPLAY_BY_TAG). $rows llega ya
     * APLANADA y con la estructura anónima MÍNIMA aplicada por collectTableRows() — ver el
     * docblock de esa función para el detalle de qué se cubre y qué NO (documentado también más
     * abajo, junto a wrapElementInAnonymousRow()).
     */
    private function buildTable(\Dom\Element $element, StyleMap $styles): TableBox
    {
        $style = $styles->get($element);
        $rows = $this->collectTableRows($element, $styles, false);
        return new TableBox($style, $rows, strtolower($element->tagName));
    }

    /**
     * css-tables-3 §2: recorre los hijos DIRECTOS de $container —una <table> (llamada raíz, con
     * $isHeader=false) o, recursivamente, uno de sus <thead>/<tbody> (Display::TableHeaderGroup/
     * TableRowGroup)— devolviendo la lista APLANADA de TableRowBox que le corresponde.
     * thead/tbody son TRANSPARENTES: no producen ningún nivel propio en el árbol de caja, sus
     * filas (y cualquier contenido suelto que necesiten envolver, ver más abajo) se insertan
     * DIRECTAMENTE en la lista que devuelve esta llamada, en el mismo orden de documento en que
     * aparecen sus hijos. $isHeader se propaga a TODO lo que esta llamada concreta genere: true
     * quiere decir "estamos recorriendo un <thead>" (así que tanto sus <tr> reales como cualquier
     * fila anónima que genere por contenido suelto quedan marcadas isHeader=true); false cubre
     * tanto la llamada raíz (nivel <table>) como un <tbody>.
     *
     * Variante MÍNIMA de generación de cajas anónimas (§17.2.1 reducido, adjudicación del brief
     * M5-T3) — lo que SÍ cubre:
     *   - Texto no-blanco suelto directamente en $container (después de colapsar whitespace
     *     interno y recortar los extremos — texto puramente blanco NO genera ninguna caja, igual
     *     que el whitespace-collapsing normal de CSS) → fila anónima con una única celda anónima
     *     conteniendo un TextRun (ver wrapLooseContentInAnonymousRow()).
     *   - Cualquier otro elemento hijo que NO sea <tr>/thead/tbody (p.ej. un <div>, un elemento
     *     inline, u OTRA <table> anidada directamente sin pasar por una fila) → fila anónima con
     *     una única celda anónima envolviendo su subárbol COMPLETO, construido con el pipeline
     *     normal (buildChildBox(), ver wrapElementInAnonymousRow()).
     *
     * Lo que NO cubre (documentado, fuera del alcance "reducido" de esta tarea):
     *   - NO fusiona hermanos sueltos ADYACENTES en una única caja anónima compartida — cada
     *     tramo suelto (texto o elemento) genera su PROPIA fila anónima, a diferencia del
     *     algoritmo completo de §17.2.1, que agruparía un tramo contiguo completo en un solo
     *     anónimo (ver el test "does not merge adjacent loose siblings").
     *   - <caption>/<col>/<colgroup>/<tfoot> no tienen ningún tratamiento especial (fuera de
     *     alcance M5, ver plan de milestone): al no tener un Display de tabla propio (UA default
     *     cae a Display::Block, igual que cualquier tag desconocido), un <caption> como hijo
     *     directo de <table> caería en la rama "elemento no-fila" de más abajo — comportamiento
     *     no equivalente al de un navegador real, pero consistente y sin excepción.
     *   - Un elemento con Display::TableRow/TableCell/TableHeaderGroup/TableRowGroup que aparece
     *     FUERA de un árbol de tabla (nunca alcanzado por buildTable()) no genera ninguna caja
     *     anónima de tabla a su alrededor: simplemente fluye por collectChildren() como un
     *     BlockBox genérico (mismo comportamiento que M5-T2 dejó documentado en su report).
     *
     * NOTA DE ALCANCE (verificado empíricamente, no una decisión de diseño): \Dom\HTMLDocument —
     * la fuente real de DOM para cualquier documento que llega vía HtmlParser::parse() — ejecuta
     * el algoritmo de construcción de árbol de HTML5 completo, que ya hace FOSTER PARENTING de
     * cualquier texto no-blanco o elemento que no sea de estructura de tabla encontrado
     * directamente dentro de <table>/<tr>: lo saca del todo (como hermano PRECEDENTE de la
     * tabla), nunca llega a ser hijo real del elemento de tabla en el DOM. Por eso este método
     * (y buildTableRow()) son en la práctica INALCANZABLES a través de HtmlParser::parse() sobre
     * una cadena HTML — solo se ejercitan en los tests construyendo el DOM de forma imperativa
     * (createElement()+appendChild(), que NO pasa por el algoritmo de inserción del parser) — el
     * mismo tipo de árbol que produciría una fuente de DOM no-HTML5 (XML/XHTML, u otro
     * Dom\HTMLDocument construido a mano). El código sigue siendo correcto y necesario: nada en
     * BoxTreeBuilder asume que su entrada viene siempre de HtmlParser.
     *
     * @return list<TableRowBox>
     */
    private function collectTableRows(\Dom\Element $container, StyleMap $styles, bool $isHeader): array
    {
        $containerStyle = $styles->get($container);
        $rows = [];
        foreach ($container->childNodes as $node) {
            if ($node instanceof \Dom\Text) {
                $text = self::collapseAndTrim($node->textContent ?? '');
                if ($text === '') {
                    continue;
                }
                $rows[] = $this->wrapLooseContentInAnonymousRow($text, $containerStyle, $isHeader);
                continue;
            }
            if (!$node instanceof \Dom\Element) {
                continue;
            }
            $childStyle = $styles->get($node);
            if ($childStyle->display === Display::None) {
                continue;
            }
            $this->warnIfInlineStyleAttribute($node);
            if ($childStyle->display === Display::TableRow) {
                $rows[] = $this->buildTableRow($node, $styles, $isHeader);
                continue;
            }
            if ($childStyle->display === Display::TableHeaderGroup) {
                foreach ($this->collectTableRows($node, $styles, true) as $row) {
                    $rows[] = $row;
                }
                continue;
            }
            if ($childStyle->display === Display::TableRowGroup) {
                foreach ($this->collectTableRows($node, $styles, false) as $row) {
                    $rows[] = $row;
                }
                continue;
            }
            $rows[] = $this->wrapElementInAnonymousRow($node, $styles, $childStyle, $containerStyle, $isHeader);
        }
        return $rows;
    }

    /** Ver collectTableRows(): texto suelto directamente en table/thead/tbody → fila+celda
     * anónimas propias. $textRun conserva $containerStyle (misma convención que un TextRun de
     * texto suelto en collectChildren(): el nodo de texto en sí no tiene ComputedStyle propio,
     * adopta el de su elemento contenedor). */
    private function wrapLooseContentInAnonymousRow(string $text, ComputedStyle $containerStyle, bool $isHeader): TableRowBox
    {
        // M6-T3: $remBase es inerte aquí — declarations=[] nunca contiene un CssLength en rem,
        // así que cualquier valor produce el mismo resultado (ver ComputedStyle::compute()).
        $cellStyle = ComputedStyle::compute([], $containerStyle, 'td', $containerStyle->fontSizePx);
        $cell = new TableCellBox($cellStyle, [new TextRun($text, $containerStyle)], 1, 'anonymous');
        $rowStyle = ComputedStyle::compute([], $containerStyle, 'tr', $containerStyle->fontSizePx);
        return new TableRowBox($rowStyle, [$cell], $isHeader);
    }

    /** Ver collectTableRows(): un elemento no-fila directamente en table/thead/tbody → fila+celda
     * anónimas propias, envolviendo el elemento ENTERO (su subárbol completo vía buildChildBox() —
     * si el elemento resulta ser él mismo otra <table>, se construye como TableBox anidada, no
     * como BlockBox). */
    private function wrapElementInAnonymousRow(
        \Dom\Element $element,
        StyleMap $styles,
        ComputedStyle $childStyle,
        ComputedStyle $containerStyle,
        bool $isHeader,
    ): TableRowBox {
        $cellStyle = ComputedStyle::compute([], $containerStyle, 'td', $containerStyle->fontSizePx);
        $cell = new TableCellBox($cellStyle, [$this->buildChildBox($element, $styles, $childStyle)], 1, 'anonymous');
        $rowStyle = ComputedStyle::compute([], $containerStyle, 'tr', $containerStyle->fontSizePx);
        return new TableRowBox($rowStyle, [$cell], $isHeader);
    }

    /**
     * css-tables-3 §2: una <tr> real (encontrada directamente, o ya aplanada desde un thead/tbody
     * transparente por collectTableRows()). Recorre sus hijos DIRECTOS: td/th (Display::TableCell)
     * se construyen con buildTableCell(); texto no-blanco suelto o cualquier otro elemento se
     * envuelve en una celda anónima PROPIA (misma divergencia de no-fusión documentada en
     * collectTableRows()) — a diferencia del nivel tabla, aquí NUNCA hace falta generar una fila
     * anónima: la fila YA EXISTE, es este mismo <tr>.
     */
    private function buildTableRow(\Dom\Element $trElement, StyleMap $styles, bool $isHeader): TableRowBox
    {
        $trStyle = $styles->get($trElement);
        $cells = [];
        foreach ($trElement->childNodes as $node) {
            if ($node instanceof \Dom\Text) {
                $text = self::collapseAndTrim($node->textContent ?? '');
                if ($text === '') {
                    continue;
                }
                $cellStyle = ComputedStyle::compute([], $trStyle, 'td', $trStyle->fontSizePx);
                $cells[] = new TableCellBox($cellStyle, [new TextRun($text, $trStyle)], 1, 'anonymous');
                continue;
            }
            if (!$node instanceof \Dom\Element) {
                continue;
            }
            $childStyle = $styles->get($node);
            if ($childStyle->display === Display::None) {
                continue;
            }
            $this->warnIfInlineStyleAttribute($node);
            if ($childStyle->display === Display::TableCell) {
                $cells[] = $this->buildTableCell($node, $styles);
                continue;
            }
            $cellStyle = ComputedStyle::compute([], $trStyle, 'td', $trStyle->fontSizePx);
            $cells[] = new TableCellBox($cellStyle, [$this->buildChildBox($node, $styles, $childStyle)], 1, 'anonymous');
        }
        return new TableRowBox($trStyle, $cells, $isHeader);
    }

    /**
     * css-tables-3 §2: celda real (<td>/<th>). $children reutiliza collectChildren() — el MISMO
     * recorrido que un BlockBox normal (bloques/inline/imágenes/tabla anidada, ver
     * buildChildBox()), documentado en el brief como "reutiliza el pipeline entero": la celda no
     * impone ninguna regla de contenido propia. rowspan NO está soportado (M6): su sola PRESENCIA
     * como atributo (cualquier valor, incluido "1") dispara un warning suave y la celda se trata
     * como si no lo tuviera — nunca lanza una excepción.
     */
    private function buildTableCell(\Dom\Element $cellElement, StyleMap $styles): TableCellBox
    {
        $style = $styles->get($cellElement);
        if ($cellElement->getAttribute('rowspan') !== null) {
            $this->warnings->addWarning('rowspan not supported yet: treated as 1');
        }
        $children = $this->collectChildren($cellElement, $styles, $style);
        return new TableCellBox($style, $children, self::parseColspan($cellElement), strtolower($cellElement->tagName));
    }

    /** colspan es un entero ≥1 (CSS 2.2 §17.2 / HTML): ausente, no puramente numérico ("abc",
     * "2.5", con signo) o "0" cae al default 1 — nunca un warning (a diferencia de rowspan, un
     * colspan inválido/ausente es indistinguible del caso normal "sin colspan"). */
    private static function parseColspan(\Dom\Element $element): int
    {
        $value = $element->getAttribute('colspan');
        if ($value === null || preg_match('/^\d+$/', $value) !== 1) {
            return 1;
        }
        $number = (int) $value;
        return $number >= 1 ? $number : 1;
    }

    /** Colapsa whitespace interno a un único espacio Y recorta los extremos — a diferencia de
     * collapseInternalWhitespace() (usada para TextRun normales, donde el recorte de frontera lo
     * decide collapse() mirando a los runs vecinos), aquí cada tramo suelto de tabla es
     * INDEPENDIENTE (ver collectTableRows()/buildTableRow(): nunca se fusiona con un vecino), así
     * que no hay frontera que negociar: se recorta por completo. */
    private static function collapseAndTrim(string $raw): string
    {
        return trim(self::collapseInternalWhitespace($raw));
    }

    /**
     * css-flexbox-1 §4: cada hijo directo de un flex container se convierte en un "flex item".
     * En este punto $children ya es la secuencia final y aplanada de hijos directos del
     * contenedor (BlockBox/ImageBox directos + TextRun/LineBreakRun ya colapsados por
     * collapse(), incluida cualquier ImageBox "hoisteada" desde un inline — ver collectInline()).
     * Un tramo CONTIGUO de TextRun|LineBreakRun se envuelve en un ÚNICO BlockBox anónimo (tag
     * "anonymous") por tramo; BlockBox, ImageBox y TableBox ya son items directos por sí mismos y
     * NUNCA entran en el anónimo — una ImageBox es un replaced box (css-images-3) y una TableBox
     * (M5-T3) es una caja de tabla, ambas son, en flexbox, su propio item directo igual que un
     * BlockBox, así que cortan el tramo de texto exactamente igual que ya hace un LineBreakRun
     * (nunca lo hace un anónimo distinto por LineBreakRun: ese sigue siendo un separador DENTRO
     * del tramo de texto, per brief M4-T2).
     *
     * El estilo del anónimo es ComputedStyle::compute([], $containerStyle, 'div'): sin
     * declaraciones propias, así que cae al initial value de todo salvo las propiedades
     * heredadas de CSS 2.2 §6.1 (color, font-*, line-height, text-align, underline...), que
     * toman el computed value de $containerStyle — nunca hereda las propiedades flex del
     * contenedor (M4-T1: ninguna de esas hereda), así que el anónimo nunca es él mismo un flex
     * container aunque su padre lo sea.
     *
     * M7-T4: += InlineBoxStart/InlineBoxEnd (caja inline real) al tramo "suelto" que se envuelve
     * en el anónimo — TRATADOS IGUAL que TextRun/LineBreakRun (nunca son, por sí mismos, un flex
     * item directo). NOTA (M10 final-review Finding A): en la práctica, este $children YA llega
     * sin ningún InlineBoxStart/InlineBoxEnd -- collectChildren() (único productor de esos
     * tokens) solo los emite cuando `!$parentIsFlex` (M10-T2), y este método SOLO se invoca desde
     * buildBlock() bajo `if ($isFlex)`, con esos MISMOS $children -- así que la rama de abajo para
     * esos dos tokens es, de hecho, código defensivo inalcanzable ya desde M10-T2 (no introducido
     * ni tocado por esta tarea, fuera de su alcance verificado; se deja tal cual).
     *
     * M10 final-review Finding A: la rama gemela que coalescía un BlockBox con
     * display:InlineBlock ("token atómico de inline-block") en el mismo tramo suelto SÍ se ha
     * ELIMINADO -- collectChildren() ya no produce ese token cuando $parentIsFlex (ver el propio
     * docblock de esa rama, arriba), así que aquí sería la MISMA clase de código inalcanzable, pero
     * a diferencia de InlineBoxStart/InlineBoxEnd, esta sí es la rama que ESTA tarea deja de
     * producir, así que se retira en el mismo cambio en vez de quedar como comentario mintiendo
     * sobre un comportamiento que ya no ocurre (`<div style="display:flex"><span
     * class="ib">a</span><span class="ib">b</span></div>` produce ahora DOS BlockBox flex items
     * reales, tag "span", nunca un "anonymous" compartido — ver BoxTreeBuilderTest).
     *
     * @param list<BlockBox|TextRun|LineBreakRun|ImageBox|TableBox|InlineBoxStart|InlineBoxEnd> $children
     * @return list<BlockBox|ImageBox|TableBox>
     */
    private function wrapAnonymousFlexItems(array $children, ComputedStyle $containerStyle): array
    {
        $items = [];
        /** @var list<TextRun|LineBreakRun|InlineBoxStart|InlineBoxEnd> $run */
        $run = [];
        $flushRun = function () use (&$items, &$run, $containerStyle): void {
            if ($run === []) {
                return;
            }
            $anonymousStyle = ComputedStyle::compute([], $containerStyle, 'div', $containerStyle->fontSizePx);
            $items[] = new BlockBox($anonymousStyle, $run, 'anonymous');
            $run = [];
        };
        foreach ($children as $child) {
            if ($child instanceof TextRun || $child instanceof LineBreakRun
                || $child instanceof InlineBoxStart || $child instanceof InlineBoxEnd
            ) {
                $run[] = $child;
                continue;
            }
            $flushRun();
            $items[] = $child;
        }
        $flushRun();
        return $items;
    }

    /**
     * M3-T2: <img> es replaced block-level (nunca aporta a la secuencia inline de runs, brief
     * M3-T2). Errores suaves: src remoto (http/https), fichero ausente o formato no soportado
     * por Image\ImageLoader → warning en el WarningCollector compartido + null (la caja se omite,
     * nunca se lanza una excepción desde aquí). Los atributos HTML width/height solo se leen si
     * son puramente numéricos; cualquier otro valor (%, auto, vacío, ausente) se ignora en
     * silencio — no es un fallo, solo un atributo no soportado en M3.
     */
    private function buildImage(\Dom\Element $element, ComputedStyle $style): ?ImageBox
    {
        $src = $element->getAttribute('src');
        if ($src === null || $src === '') {
            $this->warnings->addWarning('Image missing src attribute');
            return null;
        }
        if (preg_match('#^https?://#i', $src) === 1) {
            $this->warnings->addWarning("remote images not supported yet: $src");
            return null;
        }
        $resolved = $this->resolvePath($src);
        try {
            $image = $this->imageLoader->load($resolved);
        } catch (ImageException $e) {
            $this->warnings->addWarning("Could not load image \"$src\": " . $e->getMessage());
            return null;
        }
        return new ImageBox(
            $style,
            $resolved,
            $image->widthPx(),
            $image->heightPx(),
            self::numericAttribute($element, 'width'),
            self::numericAttribute($element, 'height'),
        );
    }

    /** M8-T6: delegado en Image\ImagePathResolver (extraído VERBATIM de aquí) -- ver su docblock
     * de clase para el porqué (background-image, parseado en Css\ pero resuelto/cargado en tiempo
     * de pintado por Paint\Painter, necesita la MISMA resolución para que Pdf\ImageRegistry
     * deduplique un <img> y un background-image que apunten al mismo fichero bajo una única
     * entrada). Comportamiento byte-idéntico a antes de esta tarea. */
    private function resolvePath(string $src): string
    {
        return ImagePathResolver::resolve($this->basePath, $src);
    }

    /** Valores <= 0 se tratan como ausentes (null), igual que los navegadores ignoran un
     * width/height no positivo y recurren al tamaño intrínseco. */
    private static function numericAttribute(\Dom\Element $element, string $name): ?float
    {
        $value = $element->getAttribute($name);
        if ($value === null || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        return $number > 0 ? $number : null;
    }

    /**
     * M1-T4: recorre el subárbol de un elemento INLINE generando TextRun/LineBreakRun con el
     * ComputedStyle de CADA elemento inline propio (ya heredado del bloque vía StyleResolver),
     * en vez de aplanar a texto plano con el estilo del bloque (comportamiento M0). Cualquier
     * descendiente con display:none se poda (arregla el leak de M0). Los tags anidados no
     * necesitan estar en INLINE_TAGS: se recorren igualmente, con permisividad heredada de M0.
     *
     * M3-T2 defect fix — aproximación documentada: una <img> anidada dentro de un inline
     * (`<a href><img></a>`, `<span><img></span>`) NO tiene layout inline en M3 (los "replaced
     * inline boxes" con wrapping de línea llegarán más adelante). En vez de descartarla en
     * silencio (bug original: se recorría como elemento sin hijos y no producía nada), se
     * "hoistea" a nivel de bloque: se emite como token ImageBox dentro de la MISMA secuencia
     * `$pending` que los TextRun/LineBreakRun de alrededor (preservando el ORDEN relativo:
     * texto-antes → ImageBox → texto-después), reusando el mismo buildImage() con sus mismos
     * warnings de fallo suave (src remoto, fichero ausente, formato no soportado). `collapse()`
     * trata el token ImageBox como separador de secuencia (igual que un LineBreakRun): no se le
     * aplica el whitespace-collapsing propio de texto, ni genera espacio de frontera con el
     * texto adyacente. SIEMPRE se añade un warning adicional
     * (independiente del éxito/fallo de buildImage) para que la aproximación quede visible en
     * RenderReport — el consumidor del reporte necesita saber que el layout aquí no es fiel al
     * documento fuente.
     *
     * M7-T4: += InlineBoxStart/InlineBoxEnd para un descendiente Display::Inline con caja visible
     * propia (mismo criterio que collectChildren(), ver hasVisibleInlineBox()) y += BlockBox para
     * un descendiente display:inline-block (token atómico, igual tratamiento que en
     * collectChildren()) — un <a class="btn">, aunque esté anidado DENTRO de otro inline
     * (`<span><a class="btn">...</a></span>`), se resuelve exactamente igual sea cual sea su
     * profundidad de anidamiento. Un descendiente que NO sea Display::Inline ni InlineBlock (p.ej.
     * un bloque anidado por error dentro de un inline, HTML inválido) conserva el comportamiento
     * permisivo heredado de M0/M1: se recorre igualmente, aplanando su contenido, SIN envolverlo en
     * ningún token de caja (divergencia documentada, fuera de alcance — ver el brief, que solo
     * pide cubrir el caso Inline/InlineBlock real).
     *
     * @param list<TextRun|LineBreakRun|ImageBox|InlineBoxStart|InlineBoxEnd|BlockBox> $pending
     */
    private function collectInline(\Dom\Element $element, StyleMap $styles, array &$pending): void
    {
        $style = $styles->get($element);
        foreach ($element->childNodes as $node) {
            if ($node instanceof \Dom\Text) {
                foreach (self::textRunTokensFor($node->textContent ?? '', $style) as $token) {
                    $pending[] = $token;
                }
                continue;
            }
            if (!$node instanceof \Dom\Element) {
                continue;
            }
            $childStyle = $styles->get($node);
            if ($childStyle->display === Display::None) {
                continue;
            }
            $this->warnIfInlineStyleAttribute($node);
            $tag = strtolower($node->tagName);
            if ($tag === 'br') {
                $pending[] = new LineBreakRun();
                continue;
            }
            if ($tag === 'img') {
                $src = $node->getAttribute('src') ?? '';
                // M5-T1 (housekeeping): un src vacío ya no arrastra un "): " colgante y feo al
                // final del mensaje -- el sufijo con el src solo se añade cuando hay algo que
                // mostrar.
                $message = 'inline image hoisted to block level (inline replaced boxes not supported yet)';
                $this->warnings->addWarning($src === '' ? $message : "$message: $src");
                $imageBox = $this->buildImage($node, $childStyle);
                if ($imageBox !== null) {
                    $pending[] = $imageBox;
                }
                continue;
            }
            if ($childStyle->display === Display::InlineBlock) {
                $this->warnIfFloatOrAbsoluteOnInlineBlock($childStyle);
                $pending[] = $this->buildBlock($node, $styles);
                continue;
            }
            $this->warnIfUnsupportedSubOrSup($tag);
            if ($childStyle->display === Display::Inline) {
                $this->warnIfFloatOrPositionOnInline($childStyle);
            }
            $hasBox = $childStyle->display === Display::Inline && self::hasVisibleInlineBox($childStyle);
            if ($hasBox) {
                $pending[] = new InlineBoxStart($childStyle, $tag);
            }
            $this->collectInline($node, $styles, $pending);
            if ($hasBox) {
                $pending[] = new InlineBoxEnd();
            }
        }
    }

    /**
     * M9-T1 housekeeping (README scope note: "inline style="" attributes are not supported"):
     * this engine only parses `<style>` stylesheets (and its own UA stylesheet, see
     * Style\UserAgentStylesheet) -- StyleResolver/CssStyleSource never read an element's `style`
     * attribute, so any inline style is silently dropped with NO warning until this task. Called
     * from every dispatch point that inspects a real \Dom\Element (buildBlock() -- covers the
     * body root plus any block/inline-block/table-child element -- and the collectChildren()/
     * collectInline()/collectTableRows()/buildTableRow() loops, which cover everything buildBlock()
     * doesn't: plain inline elements, <img>/<br>, and table structure elements td/th/tr/thead/
     * tbody), so effectively every element the tree-builder visits is checked at least once.
     * addWarningOnce (not addWarning): a document with a `style=""` attribute on every element
     * would otherwise flood RenderReport with one identical message per element -- one mention
     * per render is enough to alert the caller that the attribute is being ignored entirely.
     */
    private function warnIfInlineStyleAttribute(\Dom\Element $element): void
    {
        if ($element->getAttribute('style') !== null) {
            $this->warnings->addWarningOnce(
                'inline-style-attribute',
                'inline style="" attributes are not supported; use a stylesheet',
            );
        }
    }

    /** M7-T2: sub/sup se soportan como texto inline plano (ver INLINE_TAGS) pero SIN el
     * desplazamiento vertical de vertical-align:sub/super (fuera de alcance, M8) -- se avisa en
     * cada aparición para que RenderReport deje constancia de la aproximación, igual criterio que
     * el warning de "inline image hoisted" de arriba. */
    private function warnIfUnsupportedSubOrSup(string $tag): void
    {
        if ($tag === 'sub' || $tag === 'sup') {
            $this->warnings->addWarning(
                "<$tag> rendered as plain inline text (vertical-align: sub/super not supported yet, M8)",
            );
        }
    }

    /**
     * M7 final-review Finding D: `float` y/o `position: relative|absolute` declarados en un
     * elemento Display::Inline no tienen NINGÚN efecto en este motor -- InlineFlowContext nunca
     * examina ComputedStyle::$float/$position de un run/InlineBoxStart (solo BlockFlowContext lo
     * hace, y únicamente para sus hijos de BLOQUE directos, ver el bucle de su layout()); un
     * `<span style="float:left">`/`<a style="position:relative;top:10px">` simplemente se aplana
     * como texto inline normal, sin sacarse de flujo ni desplazarse -- antes de esta tarea, ese
     * "no-op" era completamente silencioso. Se avisa UNA SOLA VEZ por causa (WarningCollector::
     * addWarningOnce(), no una vez por elemento/aparición -- a diferencia del warning de sub/sup
     * de arriba, que SÍ es por-ocurrencia, M7-T2, sin tocar aquí) durante la vida del colector
     * compartido, para que RenderReport deje constancia de la aproximación sin inundar de mensajes
     * repetidos un documento con muchos `<span>` flotantes/posicionados. Llamado desde AMBOS
     * dispatch points de un elemento Inline (collectChildren() para el hijo directo,
     * collectInline() para un descendiente anidado a cualquier profundidad).
     */
    private function warnIfFloatOrPositionOnInline(ComputedStyle $childStyle): void
    {
        if ($childStyle->float !== null) {
            $this->warnings->addWarningOnce(
                'float-on-inline',
                'float on an inline-level element has no effect (not supported yet): the element stays in normal inline flow',
            );
        }
        if ($childStyle->position !== Position::Static) {
            $this->warnings->addWarningOnce(
                'position-on-inline',
                'position:relative/absolute on an inline-level element has no effect (not supported yet): no offset is applied',
            );
        }
    }

    /**
     * M8-T1 housekeeping (M7 final-review Finding D, remaining gap): `float` y `position:absolute`
     * declarados en un elemento `display:inline-block` tampoco tienen NINGÚN efecto en este motor,
     * pero por una razón DISTINTA a la de warnIfFloatOrPositionOnInline() de arriba -- un
     * inline-block SÍ genera un BlockBox real (buildBlock(), llamado justo después de este chequeo
     * en ambos dispatch points) y SÍ se layoutea como tal, pero SIEMPRE como el token atómico que
     * InlineFlowContext::layoutInlineBlockAtomic() coloca en la secuencia de línea -- nunca pasa
     * por el bucle de hijos DIRECTOS de BlockFlowContext::layout(), que es el ÚNICO sitio que
     * consulta `$child->style->float`/`position === Absolute` para sacar una caja de flujo (ver los
     * docblocks de esos dos chequeos ahí). `position:relative`, en cambio, SÍ funciona ya sin
     * cambios: layoutInlineBlockAtomic() delega en BlockFlowContext::layout() para la caja propia
     * del inline-block, y ESE método aplica el shift de position:relative a CUALQUIER BlockBox que
     * layoutea, sea cual sea quién lo invoque -- por eso este chequeo, a diferencia del de arriba,
     * NO cubre Position::Relative (de ahí "float/position:absolute" en vez de "float/position" en
     * el mensaje). Mismo criterio addWarningOnce que el resto de esta clase (una sola vez por
     * causa, no por elemento).
     */
    private function warnIfFloatOrAbsoluteOnInlineBlock(ComputedStyle $childStyle): void
    {
        if ($childStyle->float !== null) {
            $this->warnings->addWarningOnce(
                'float-on-inline-block',
                'float on a display:inline-block element has no effect (not supported yet): the element stays in normal inline flow as an atomic token',
            );
        }
        if ($childStyle->position === Position::Absolute) {
            $this->warnings->addWarningOnce(
                'position-absolute-on-inline-block',
                'position:absolute on a display:inline-block element has no effect (not supported yet): the element stays in normal inline flow as an atomic token',
            );
        }
    }

    private static function collapseInternalWhitespace(string $raw): string
    {
        return preg_replace('/\s+/', ' ', $raw) ?? '';
    }

    /**
     * M7-T2 (CSS 2.2 §16.6.1, white-space:pre): un nodo de texto normal siempre produce un ÚNICO
     * TextRun con su whitespace interno ya colapsado (collapseInternalWhitespace) -- collapse()
     * hará el recorte de frontera después. Bajo white-space:pre, en cambio, NADA se colapsa (los
     * espacios/tabs se conservan literalmente) y cada '\n' del texto fuente se convierte en un
     * LineBreakRun real (idéntico a un <br> explícito) -- así el resto del pipeline (collapse(),
     * InlineFlowContext) no necesita saber que el salto vino de un carácter en vez de una
     * etiqueta. Un segmento vacío entre dos '\n' consecutivos (línea en blanco) no produce
     * TextRun (no aporta nada, ver collapse()) pero el LineBreakRun that lo rodea ya fuerza el
     * avance de línea por sí solo. \r\n se normaliza a \n primero (mismo criterio que cualquier
     * editor/HTML parser); un '\r' suelto (Mac clásico, prácticamente inexistente hoy) NO se
     * trata como salto -- fuera de alcance, no forma parte del contrato de esta tarea.
     *
     * M8-T5 (css-text-3 §8 reducido): text-transform se aplica AQUÍ, al texto de CADA TextRun
     * recién construido -- ANTES de que llegue a ninguna medición (TextMeasurer::widthOf()) o a
     * collapse() (que puede fundir runs adyacentes del mismo estilo). $style->textTransform ===
     * None (el default) es un no-op observacional (applyTextTransform() devuelve $raw/$line tal
     * cual) -- ningún golden existente declara text-transform, así que este cambio es byte-stable
     * para M1-M8-T4.
     *
     * DIVERGENCIA DOCUMENTADA (capitalize + fusión entre nodos): cada nodo de texto fuente se
     * transforma de forma INDEPENDIENTE, antes de que collapse() pueda fundirlo con un run
     * ADYACENTE del mismo ComputedStyle (p.ej. dos nodos de texto separados por un elemento
     * display:none podado, "wor<span style=display:none>X</span>ld" -- ver collapse()). Un
     * capitalize en ese escenario trataría el INICIO de cada nodo fuente como frontera de palabra,
     * aunque tras la fusión no lo sea realmente ("world" partido en "wor"+"ld" sin espacio de por
     * medio produciría "Wor"+"ld" en vez de "World") -- edge case sin cobertura de test (ningún
     * golden lo ejercita), aceptado por el mismo motivo que el brief ya adjudica "hyphen no es
     * frontera" como divergencia de M8 reducido.
     *
     * @return list<TextRun|LineBreakRun>
     */
    private static function textRunTokensFor(string $raw, ComputedStyle $style): array
    {
        if ($style->whiteSpace !== 'pre') {
            $text = self::applyTextTransform(self::collapseInternalWhitespace($raw), $style->textTransform);
            return [new TextRun($text, $style)];
        }
        $lines = explode("\n", str_replace("\r\n", "\n", $raw));
        $tokens = [];
        $lastIndex = count($lines) - 1;
        foreach ($lines as $index => $line) {
            if ($line !== '') {
                $tokens[] = new TextRun(self::applyTextTransform($line, $style->textTransform), $style);
            }
            if ($index !== $lastIndex) {
                $tokens[] = new LineBreakRun();
            }
        }
        return $tokens;
    }

    /** M8-T5: dispatch por el valor computado de text-transform -- None es un no-op literal
     *  (devuelve $text sin tocar, ver el docblock de textRunTokensFor() para por qué esto importa
     *  para la estabilidad de goldens). */
    private static function applyTextTransform(string $text, TextTransform $transform): string
    {
        return match ($transform) {
            TextTransform::None => $text,
            TextTransform::Uppercase => mb_convert_case($text, MB_CASE_UPPER, 'UTF-8'),
            TextTransform::Lowercase => mb_convert_case($text, MB_CASE_LOWER, 'UTF-8'),
            TextTransform::Capitalize => self::capitalizeWords($text),
        };
    }

    /**
     * M8-T5 (css-text-3 §8, "first typographic letter unit of each word"): implementación
     * DIRIGIDA en vez de MB_CASE_TITLE (mb_convert_case) -- MB_CASE_TITLE difiere sutilmente de
     * esta regla (por ejemplo, trata cualquier carácter no-letra como frontera, no solo espacio/
     * tab) y no es configurable, así que se rueda a mano: frontera de palabra = inicio de cadena o
     * una tira de espacios/tabs (adjudicación M8-T5 -- guiones y otra puntuación NUNCA son
     * frontera, a diferencia de algunos navegadores reales, ver el brief; '\n' nunca aparece
     * DENTRO de un TextRun -- ver textRunTokensFor(), que ya trocea 'pre' por línea antes de
     * llegar aquí, y 'normal' colapsa \n a un espacio antes de esta llamada). Dentro de cada
     * palabra, el PRIMER carácter alfabético (\p{L}, soporta acentos: á → Á) se mayúsculiza --
     * cualquier puntuación que lo preceda dentro de la misma palabra (p.ej. "(hello)" ->
     * "(Hello)") se conserva intacta, y el resto de la palabra NUNCA se toca.
     */
    private static function capitalizeWords(string $text): string
    {
        $result = preg_replace_callback(
            '/(^|[ \t]+)([^\p{L} \t]*)(\p{L})/u',
            static fn(array $m): string => $m[1] . $m[2] . mb_convert_case($m[3], MB_CASE_UPPER, 'UTF-8'),
            $text,
        );
        return $result ?? $text;
    }

    /**
     * Colapsa una secuencia completa de runs de un bloque (CSS 2.2 §16.6.1 simplificado):
     * el whitespace interno de cada chunk ya viene reducido a un único espacio
     * (collapseInternalWhitespace); aquí se recorta el espacio inicial/final de la secuencia
     * y se conserva EXACTAMENTE un espacio de frontera entre chunks adyacentes cuando alguno
     * de los dos lados aportaba whitespace. Convención (documentada en el brief M1-T4): el
     * espacio de frontera se adjunta SIEMPRE al final del run YA EMITIDO precedente, nunca
     * como prefijo del run siguiente — así "Hola <b>mundo</b>!" da "Hola ", "mundo", "!" y
     * "Hola <b> mundo</b>" (doble espacio de frontera) colapsa a "Hola ", "mundo". Un <br>
     * corta la secuencia: reinicia el recorte de inicio/fin igual que un límite de bloque.
     * Runs adyacentes con el mismo ComputedStyle (p.ej. texto partido por un display:none
     * podado en medio) se fusionan en un único TextRun.
     *
     * M3-T2 defect fix: un token ImageBox (imagen inline "hoisteada" por collectInline()) se
     * trata como separador de secuencia exactamente igual que un LineBreakRun — corta el
     * recorte de inicio/fin y no participa del whitespace-collapsing de texto — pero, a
     * diferencia de LineBreakRun, no es un marcador: es la propia caja de imagen, que queda
     * intercalada en el mismo orden en que apareció en el DOM entre el texto anterior y el
     * posterior.
     *
     * M7-T4: += InlineBoxStart/InlineBoxEnd/BlockBox(inline-block). A diferencia de LineBreakRun/
     * ImageBox (separadores "duros": cortan TAMBIÉN el espacio de frontera pendiente, $pendingSpace
     * se resetea a false), estos tres son separadores "transparentes": SIEMPRE se emiten tal cual
     * a $result (nunca se funden entre sí ni con un TextRun vecino — $mergeEligible se apaga), pero
     * $pendingSpace NO se resetea, porque no representan ningún salto de línea/contenido opaco —
     * un espacio de frontera que estaba pendiente ANTES de abrir/cerrar una caja (o antes de un
     * inline-block) sigue "vivo" para el próximo TextRun real, y se adjunta —por la MISMA
     * convención de siempre— al ÚLTIMO TextRun ya emitido, aunque uno o más de estos marcadores se
     * hayan intercalado entre medias en $result (de ahí que el índice de ese último TextRun se
     * rastree aparte, $lastTextIndex, en vez de asumir que siempre es el último elemento de
     * $result — con marcadores de por medio, ya no lo es). Limitación documentada: si el espacio
     * de frontera pendiente en realidad pertenece a un hermano SUELTO fuera de ambas cajas (p.ej.
     * "<span>a</span> <span>b</span>", el espacio del medio no es hijo de ningún span) puede
     * terminar adjuntado dentro de la caja del span ANTERIOR en vez de quedar fuera de toda caja —
     * mismo tipo de aproximación que esta convención ya aceptaba antes de esta tarea (el espacio
     * de frontera siempre "vive" en el run ya cerrado, nunca como token propio), ahora con una
     * caja pintable de por medio en vez de solo texto invisible; no cubierto por ningún test de
     * este milestone (edge case sin bg/borde solapado en ningún golden existente).
     *
     * @param list<TextRun|LineBreakRun|ImageBox|InlineBoxStart|InlineBoxEnd|BlockBox> $tokens
     * @return list<TextRun|LineBreakRun|ImageBox|InlineBoxStart|InlineBoxEnd|BlockBox>
     */
    private static function collapse(array $tokens): array
    {
        $result = [];
        // $lastTextIndex (índice en $result del último TextRun real emitido) sustituye a la
        // variable $lastText de antes de esta tarea: con marcadores de caja intercalados,
        // "el último TextRun" ya no es necesariamente el último ELEMENTO de $result, así que
        // array_pop()+push() (el truco de antes) dejaría de apuntar al TextRun correcto —
        // se escribe por índice explícito en su lugar. $mergeEligible es true solo cuando NADA
        // (ni un marcador de caja, ni un separador duro) se ha emitido desde ese TextRun: es lo
        // que impide fusionar dos TextRun de un mismo estilo que caen en lados opuestos de una
        // caja (deben seguir siendo runs separados para que InlineFlowContext pueda medir el
        // contenido de CADA caja de forma independiente).
        $lastTextIndex = null;
        $mergeEligible = false;
        $pendingSpace = false;
        foreach ($tokens as $token) {
            if ($token instanceof LineBreakRun || $token instanceof ImageBox) {
                $result[] = $token;
                $lastTextIndex = null;
                $mergeEligible = false;
                $pendingSpace = false;
                continue;
            }
            if ($token instanceof InlineBoxStart || $token instanceof InlineBoxEnd || $token instanceof BlockBox) {
                $result[] = $token;
                $mergeEligible = false;
                continue;
            }
            // M7-T2 (CSS 2.2 §16.6.1, white-space:pre): NINGÚN colapso/recorte/fusión de
            // frontera para un TextRun 'pre' -- se emite verbatim, exactamente como llegó de
            // textRunTokensFor() (que ya troceó por \n en LineBreakRun reales), y se trata como
            // separador de secuencia para el run VECINO (igual que LineBreakRun/ImageBox arriba)
            // para que un espacio de frontera normal no colapsado no se filtre hacia dentro/fuera
            // de un tramo preformateado.
            if ($token->style->whiteSpace === 'pre') {
                if ($token->text !== '') {
                    $result[] = $token;
                }
                $lastTextIndex = null;
                $mergeEligible = false;
                $pendingSpace = false;
                continue;
            }
            $text = $token->text;
            $leading = str_starts_with($text, ' ');
            $trailing = str_ends_with($text, ' ');
            $core = trim($text, ' ');
            $needsBoundarySpace = $pendingSpace || $leading;
            if ($core === '') {
                $pendingSpace = $pendingSpace || $leading || $trailing;
                continue;
            }
            $prevText = $lastTextIndex !== null ? $result[$lastTextIndex] : null;
            if ($mergeEligible && $prevText instanceof TextRun && $prevText->style === $token->style) {
                $result[$lastTextIndex] = new TextRun($prevText->text . ($needsBoundarySpace ? ' ' : '') . $core, $token->style);
            } else {
                if ($needsBoundarySpace && $prevText instanceof TextRun) {
                    // $lastTextIndex es necesariamente no-null aquí -- es la ÚNICA vía por la que
                    // $prevText puede ser un TextRun (ver la asignación ternaria de arriba).
                    $result[$lastTextIndex] = new TextRun($prevText->text . ' ', $prevText->style);
                }
                $result[] = new TextRun($core, $token->style);
                $lastTextIndex = count($result) - 1;
                $mergeEligible = true;
            }
            $pendingSpace = $trailing;
        }
        return $result;
    }
}
