<?php

declare(strict_types=1);

namespace Pliego\Box;

use Pliego\Css\WarningCollector;
use Pliego\Image\ImageException;
use Pliego\Image\ImageLoader;
use Pliego\Style\ComputedStyle;
use Pliego\Style\Display;
use Pliego\Style\StyleMap;

final class BoxTreeBuilder
{
    // M7-T2: kbd/samp se añaden junto a code (mismo trato UA -- font-family:monospace, ver
    // UserAgentStylesheet -- y misma naturaleza inline que code, ya en esta lista desde M1).
    // sub/sup se añaden como INLINE (para que un <sub>/<sup> DIRECTO hijo de un bloque, p.ej.
    // "H<sub>2</sub>O" en un <p>, no se trate como caja de bloque) pero SIN ningún desplazamiento
    // vertical propio -- vertical-align: sub/super es M8 (ver el warning que se emite al
    // encontrarlos, warnIfUnsupportedSubOrSup()); el texto se renderiza en línea de base normal,
    // con el mismo tamaño/estilo heredado que cualquier otro inline sin estilo propio.
    private const array INLINE_TAGS = [
        'span', 'strong', 'em', 'b', 'i', 'a', 'small', 'code', 'u', 'kbd', 'samp', 'sub', 'sup',
    ];

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
        $style = $styles->get($element);
        $children = $this->collectChildren($element, $styles, $style);
        if ($style->display === Display::Flex) {
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
     * @return list<BlockBox|TextRun|LineBreakRun|ImageBox|TableBox>
     */
    private function collectChildren(\Dom\Element $element, StyleMap $styles, ComputedStyle $style): array
    {
        $children = [];
        /**
         * @var list<TextRun|LineBreakRun|ImageBox> $pending secuencia inline pendiente de
         *     colapsar (M1-T4). Puede incluir ImageBox (M3-T2 defect fix): una <img> anidada
         *     dentro de un elemento inline se "hoistea" aquí como token de la secuencia — ver
         *     collectInline() — y collapse() la trata como separador de secuencia, igual que un
         *     LineBreakRun.
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
            if (in_array($tag, self::INLINE_TAGS, true)) {
                $this->warnIfUnsupportedSubOrSup($tag);
                $this->collectInline($node, $styles, $pending);
                continue;
            }
            $flush();
            $children[] = $this->buildChildBox($node, $styles, $childStyle);
        }
        $flush();
        return $children;
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
     * @param list<BlockBox|TextRun|LineBreakRun|ImageBox|TableBox> $children
     * @return list<BlockBox|ImageBox|TableBox>
     */
    private function wrapAnonymousFlexItems(array $children, ComputedStyle $containerStyle): array
    {
        $items = [];
        /** @var list<TextRun|LineBreakRun> $run */
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
            if ($child instanceof TextRun || $child instanceof LineBreakRun) {
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

    /** src relativo se resuelve contra basePath (Engine::basePath(), default getcwd()); un src ya
     * absoluto (unix "/..." o Windows "C:\..."/"C:/...") se usa tal cual. */
    private function resolvePath(string $src): string
    {
        $isAbsolute = str_starts_with($src, '/') || preg_match('#^[a-zA-Z]:[\\\\/]#', $src) === 1;
        return $isAbsolute ? $src : rtrim($this->basePath, '/\\') . '/' . $src;
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
     * @param list<TextRun|LineBreakRun|ImageBox> $pending
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
            if ($styles->get($node)->display === Display::None) {
                continue;
            }
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
                $imageBox = $this->buildImage($node, $styles->get($node));
                if ($imageBox !== null) {
                    $pending[] = $imageBox;
                }
                continue;
            }
            $this->warnIfUnsupportedSubOrSup($tag);
            $this->collectInline($node, $styles, $pending);
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
     * @return list<TextRun|LineBreakRun>
     */
    private static function textRunTokensFor(string $raw, ComputedStyle $style): array
    {
        if ($style->whiteSpace !== 'pre') {
            return [new TextRun(self::collapseInternalWhitespace($raw), $style)];
        }
        $lines = explode("\n", str_replace("\r\n", "\n", $raw));
        $tokens = [];
        $lastIndex = count($lines) - 1;
        foreach ($lines as $index => $line) {
            if ($line !== '') {
                $tokens[] = new TextRun($line, $style);
            }
            if ($index !== $lastIndex) {
                $tokens[] = new LineBreakRun();
            }
        }
        return $tokens;
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
     * @param list<TextRun|LineBreakRun|ImageBox> $tokens
     * @return list<TextRun|LineBreakRun|ImageBox>
     */
    private static function collapse(array $tokens): array
    {
        $result = [];
        // Se muta el run precedente con array_pop()+push() (nunca por índice) para que
        // PHPStan siga viendo $result como list<...> de principio a fin.
        $lastText = null;
        $pendingSpace = false;
        foreach ($tokens as $token) {
            if ($token instanceof LineBreakRun || $token instanceof ImageBox) {
                $result[] = $token;
                $lastText = null;
                $pendingSpace = false;
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
                $lastText = null;
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
            if ($lastText instanceof TextRun && $lastText->style === $token->style) {
                array_pop($result);
                $lastText = new TextRun($lastText->text . ($needsBoundarySpace ? ' ' : '') . $core, $token->style);
                $result[] = $lastText;
            } else {
                if ($needsBoundarySpace && $lastText instanceof TextRun) {
                    array_pop($result);
                    $lastText = new TextRun($lastText->text . ' ', $lastText->style);
                    $result[] = $lastText;
                }
                $lastText = new TextRun($core, $token->style);
                $result[] = $lastText;
            }
            $pendingSpace = $trailing;
        }
        return $result;
    }
}
