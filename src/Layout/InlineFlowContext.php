<?php

declare(strict_types=1);

namespace Pliego\Layout;

use Pliego\Box\BlockBox;
use Pliego\Box\InlineBoxEnd;
use Pliego\Box\InlineBoxStart;
use Pliego\Box\LineBreakRun;
use Pliego\Box\TextRun;
use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\WarningCollector;
use Pliego\Layout\Fragment\BorderSet;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\Fragment;
use Pliego\Layout\Fragment\ImageFragment;
use Pliego\Layout\Fragment\InlineBoxFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;
use Pliego\Layout\Text\BreakFinder;
use Pliego\Layout\Text\FontFamilyResolver;
use Pliego\Style\ComputedStyle;
use Pliego\Style\FontStyle;
use Pliego\Style\TextAlign;
use Pliego\Text\FontCatalog;
use Pliego\Text\FontFace;

/**
 * css-inline-3 (reducido para M1): consume la secuencia TextRun|LineBreakRun de UN bloque
 * (BlockFlowContext delega TODO run, ver M1-T6 brief) y produce line boxes con posiblemente
 * varios TextFragment por línea (uno por tramo de estilo/cara).
 *
 * MODELO DE "PALABRA" (word/chunk): se recorre la secuencia de runs una sola vez. Dentro de
 * cada TextRun se buscan oportunidades de corte con BreakFinder (aplicado al texto PROPIO del
 * run — T5). El texto entre dos oportunidades consecutivas (o entre el principio del run y la
 * primera oportunidad) es un "tramo" (slice) de ESE run. Un conjunto de tramos consecutivos que
 * terminan en una oportunidad real forman una "palabra" cerrada, lista para la decisión greedy
 * de ajuste de línea. Cuando un run TERMINA sin que su último tramo llegue a una oportunidad
 * (p.ej. "auto" seguido de "<b>mundo</b>" sin espacio de por medio), ese tramo se arrastra
 * ("carry") y se combina con el/los tramo(s) inicial(es) del/de los run(s) siguiente(s) hasta
 * encontrar la próxima oportunidad — permitiendo que una "palabra" abarque más de un run/estilo,
 * igual que en un navegador real.
 *
 * ESPACIO DE FRONTERA Y ANCHO DE LÍNEA (convención T4 + adaptación M1-T6): el espacio de
 * frontera entre runs vive SIEMPRE al final del run precedente (T4), por lo que una "palabra"
 * cerrada por una oportunidad de espacio incluye ese espacio como sufijo de su propio texto.
 * Para reproducir EXACTAMENTE las decisiones de ajuste de M0 (wrapText), la comprobación de si
 * una palabra cabe en la línea usa el ancho de la palabra SIN su espacio final ("core width");
 * una vez que se decide añadirla, se acumula el ancho COMPLETO (con espacio) para que ese
 * espacio cuente como separador real si llegan más palabras después. Al cerrar una línea
 * (flush), se resta el espacio final de la ÚLTIMA palabra del ancho reportado (rect->width y el
 * ancho usado por text-align) — igual que M0, que nunca sumaba un espacio tras la última
 * palabra. El CARÁCTER de espacio en sí NO se retira del texto del fragment (sigue formando
 * parte del contenido real, coherente con T4); solo se ajusta la contabilidad de ancho. Esto es
 * inocuo para el PDF: PdfCanvas pinta usando las métricas reales de la fuente vía el operador Tj,
 * no el rect->width declarado — el espacio colgante simplemente no es visible.
 *
 * M7-T4 (css-inline-3 reducido, cajas inline reales + inline-block) — EXTENSIÓN de este modelo:
 * la secuencia de entrada ahora puede incluir InlineBoxStart/InlineBoxEnd (una caja inline con
 * bg/borde/padding visible, ver BoxTreeBuilder::hasVisibleInlineBox()) y BlockBox (un elemento
 * display:inline-block, token ATÓMICO). Ambos se tratan como "runs" más dentro del mismo bucle
 * principal: sus tokens se empujan al mismo `$carry` que los tramos de texto (permitiendo que una
 * caja abra/cierre A MITAD DE PALABRA, p.ej. "auto<span>matic</span>" sin espacio de por medio,
 * exactamente igual que ya podía pasar con dos TextRun de estilo distinto) y solo se resuelven
 * —abriendo/cerrando entradas en la pila `$openBoxStack`, midiendo extents, emitiendo
 * InlineBoxFragment— en closeLine(), una vez que el ajuste de línea greedy ya decidió qué
 * "palabras" caen en QUÉ línea. Esto es necesario porque una caja puede abrir en una línea y
 * cerrar varias líneas después (multi-línea) — su estado (seq, estilo, paddings verticales) debe
 * sobrevivir entre llamadas a closeLine(), de ahí que $openBoxStack se pase por referencia desde
 * layout() a través de commitWord()/closeLine().
 *
 * MODELO VERTICAL (línea con inline-block): un display:inline-block se mide con layout de bloque
 * completo (shrink-to-fit width, ver layoutInlineBlockAtomic()) e INDEPENDIENTE de su posición en
 * la línea; su baseline es una aproximación estándar M7 (documentada): el BORDE INFERIOR de su
 * MARGIN box coincide con la baseline del texto de la línea (igual que <img> en muchos motores
 * simplificados) — de ahí que su "ascenso" (altura por encima de la baseline) sea su ALTURA
 * COMPLETA de margin-box. Si esa altura excede el ascenso normal del texto de la línea, la línea
 * entera crece (el "strut" de texto se preserva íntegro por debajo de la baseline, ver
 * closeLine()) — cuando no excede, el resultado es matemáticamente IDÉNTICO al cálculo de antes
 * de esta tarea (mismo $lineHeight/$baseline que sin ningún inline-block presente).
 *
 * PADDING de una caja inline: SOLO el horizontal (left/right) avanza el cursor de línea —
 * consumido como si fuera una "palabra" invisible más, left en el token de apertura, right en el
 * de cierre (box-decoration-break:slice: por construcción, cada uno de esos dos tokens aparece
 * UNA ÚNICA VEZ en toda la vida de la caja, en la línea donde realmente abre/cierra — así que una
 * línea de continuación intermedia nunca ve ninguno de los dos, sin necesitar ninguna rama
 * condicional adicional). El padding VERTICAL (top/bottom), en cambio, NO afecta el ancho ni el
 * lineHeight — se aplica en TODAS las líneas por igual (top/bottom se pintan en cada slice, per
 * spec) expandiendo el `rect` del InlineBoxFragment por encima/debajo de la línea (overflow,
 * documentado, no afecta el layout de líneas subsiguientes).
 *
 * TIPADO DE ENTRIES (deliberadamente sin alias de tipo PHPStan): cada "entry" de $lineEntries/
 * $carry es un array con clave discriminante 'kind' ('text'|'box-open'|'box-close'|'atomic'),
 * escrito inline en cada @param/@var/@return en vez de un alias reutilizable — deptrac.phar
 * (nikic/php-parser, mismo motivo documentado en Fragment.php/Engine.php para otras sintaxis)
 * interpreta ese estilo de alias como una referencia de CLASE real en el namespace actual y lo
 * reporta como "dependencia sin cubrir", rompiendo `composer arch --fail-on-uncovered` — deviación
 * verificada, no una limitación de PHPStan.
 */
final class InlineFlowContext
{
    private readonly FontFamilyResolver $fontFamilyResolver;
    private ?IntrinsicSizer $intrinsicSizer = null;
    /** Ver el docblock de BlockFlowContext (mismo patrón de ruptura de ciclo constructor que
     * flexContext()/tableContext() ahí): BlockFlowContext crea esta instancia en SU propio
     * constructor y, justo después, se auto-wirea aquí vía setBlockContext() — nunca queda null en
     * el pipeline real (Engine); solo un test que construya InlineFlowContext de forma aislada, SIN
     * pasar por BlockFlowContext, y que además ejercite un inline-block, vería el LogicException de
     * blockContext() más abajo. */
    private ?BlockFlowContext $blockContext = null;

    public function __construct(
        private readonly TextMeasurer $measurer,
        private readonly FontCatalog $catalog,
        private readonly ?WarningCollector $warnings = null,
    ) {
        $this->fontFamilyResolver = new FontFamilyResolver($catalog, $warnings);
    }

    /** Ver el docblock de $blockContext arriba. */
    public function setBlockContext(BlockFlowContext $blockContext): void
    {
        $this->blockContext = $blockContext;
    }

    private function blockContext(): BlockFlowContext
    {
        return $this->blockContext
            ?? throw new \LogicException('InlineFlowContext: no BlockFlowContext wired (needed to lay out an inline-block)');
    }

    private function intrinsicSizer(): IntrinsicSizer
    {
        return $this->intrinsicSizer ??= new IntrinsicSizer($this->measurer, $this->catalog, $this->warnings);
    }

    /**
     * @param list<TextRun|LineBreakRun|InlineBoxStart|InlineBoxEnd|BlockBox> $runs
     * @return list<Fragment>
     */
    public function layout(array $runs, float $x, float $y, float $availableWidth, ComputedStyle $blockStyle): array
    {
        $finder = new BreakFinder();

        /** @var list<Fragment> $lines */
        $lines = [];
        $cursorY = $y;

        /** @var list<array{kind: 'text', run: TextRun, face: FontFace, text: string, width: float}|array{kind: 'box-open', style: ComputedStyle, tag: string, width: float, paddingRight: float, paddingTop: float, paddingBottom: float}|array{kind: 'box-close', width: float}|array{kind: 'atomic', fragment: BoxFragment, width: float, height: float}> $lineEntries */
        $lineEntries = [];
        $lineWidth = 0.0;

        /** @var list<array{kind: 'text', run: TextRun, face: FontFace, text: string, width: float}|array{kind: 'box-open', style: ComputedStyle, tag: string, width: float, paddingRight: float, paddingTop: float, paddingBottom: float}|array{kind: 'box-close', width: float}|array{kind: 'atomic', fragment: BoxFragment, width: float, height: float}> $carry */
        $carry = [];
        $carryWidth = 0.0;

        /** @var list<array{seq: int, style: ComputedStyle, tag: string, paddingTop: float, paddingBottom: float}> $openBoxStack */
        $openBoxStack = [];
        $boxSeq = 0;
        /** @var list<ComputedStyle> $pendingStyles pila LIFO de estilos de cajas abiertas a nivel
         *  de TOKEN (no de línea) — solo sirve para que InlineBoxEnd sepa de qué caja resolver el
         *  padding-right (InlineBoxEnd no lleva estilo propio); $openBoxStack, en cambio, vive a
         *  nivel de LÍNEA y se gestiona enteramente dentro de closeLine(). */
        $pendingStyles = [];

        foreach ($runs as $run) {
            if ($run instanceof LineBreakRun) {
                $this->commitWord($carry, $carryWidth, $lines, $lineEntries, $lineWidth, $cursorY, $x, $availableWidth, $blockStyle, $openBoxStack, $boxSeq);
                $carry = [];
                $carryWidth = 0.0;
                $this->closeLine($lines, $lineEntries, $lineWidth, $x, $cursorY, $availableWidth, $blockStyle, force: true, openBoxStack: $openBoxStack, boxSeq: $boxSeq);
                continue;
            }

            if ($run instanceof InlineBoxStart) {
                $style = $run->style;
                $paddingLeft = $style->paddingLeft->resolve($availableWidth);
                $paddingRight = $style->paddingRight->resolve($availableWidth);
                $paddingTop = $style->paddingTop->resolve($availableWidth);
                $paddingBottom = $style->paddingBottom->resolve($availableWidth);
                $carry[] = [
                    'kind' => 'box-open',
                    'style' => $style,
                    'tag' => $run->tag,
                    'width' => $paddingLeft,
                    'paddingRight' => $paddingRight,
                    'paddingTop' => $paddingTop,
                    'paddingBottom' => $paddingBottom,
                ];
                $carryWidth += $paddingLeft;
                $pendingStyles[] = $style;
                continue;
            }

            if ($run instanceof InlineBoxEnd) {
                $openStyle = array_pop($pendingStyles);
                $paddingRight = $openStyle !== null ? $openStyle->paddingRight->resolve($availableWidth) : 0.0;
                $carry[] = ['kind' => 'box-close', 'width' => $paddingRight];
                $carryWidth += $paddingRight;
                continue;
            }

            if ($run instanceof BlockBox) {
                // display:inline-block: un límite de caja atómica siempre es un punto de wrap
                // válido (simplificación documentada, ver el docblock de clase) -- se comete
                // primero lo que hubiera pendiente como SU PROPIA palabra, luego el atómico como
                // otra palabra independiente.
                $this->commitWord($carry, $carryWidth, $lines, $lineEntries, $lineWidth, $cursorY, $x, $availableWidth, $blockStyle, $openBoxStack, $boxSeq);
                $carry = [];
                $carryWidth = 0.0;
                $atomic = $this->layoutInlineBlockAtomic($run, $availableWidth);
                $carry[] = ['kind' => 'atomic', 'fragment' => $atomic['fragment'], 'width' => $atomic['width'], 'height' => $atomic['height']];
                $carryWidth += $atomic['width'];
                $this->commitWord($carry, $carryWidth, $lines, $lineEntries, $lineWidth, $cursorY, $x, $availableWidth, $blockStyle, $openBoxStack, $boxSeq);
                $carry = [];
                $carryWidth = 0.0;
                continue;
            }

            $face = $this->faceFor($run->style);
            $fontSize = $run->style->fontSizePx;
            $text = $run->text;

            // M7-T2 (CSS 2.2 §16.6.1, white-space:pre): SIN oportunidades de corte dentro del
            // run -- todo su texto es una única "palabra" atómica que nunca se parte a media
            // línea (overflow permitido y documentado, ver brief). BoxTreeBuilder ya convirtió
            // cada '\n' del texto fuente en un LineBreakRun real (ver textRunTokensFor()), así
            // que el salto de línea "duro" entre tramos preformateados sigue funcionando exactamente
            // igual que el resto de este bucle (rama LineBreakRun de arriba) -- lo único que se
            // desactiva aquí es el WRAP dentro de un mismo tramo.
            if ($run->style->whiteSpace === 'pre') {
                $sliceWidth = $this->measurer->widthOf($text, $face, $fontSize);
                $carry[] = ['kind' => 'text', 'run' => $run, 'face' => $face, 'text' => $text, 'width' => $sliceWidth];
                $carryWidth += $sliceWidth;
                continue;
            }

            $segStart = 0;

            foreach ($finder->find($text) as $opportunity) {
                $end = $opportunity->byteOffset;
                $sliceText = substr($text, $segStart, $end - $segStart);
                $sliceWidth = $this->measurer->widthOf($sliceText, $face, $fontSize);
                $carry[] = ['kind' => 'text', 'run' => $run, 'face' => $face, 'text' => $sliceText, 'width' => $sliceWidth];
                $carryWidth += $sliceWidth;

                $this->commitWord($carry, $carryWidth, $lines, $lineEntries, $lineWidth, $cursorY, $x, $availableWidth, $blockStyle, $openBoxStack, $boxSeq);
                $carry = [];
                $carryWidth = 0.0;

                if ($opportunity->mandatory) {
                    $this->closeLine($lines, $lineEntries, $lineWidth, $x, $cursorY, $availableWidth, $blockStyle, force: true, openBoxStack: $openBoxStack, boxSeq: $boxSeq);
                }

                $segStart = $end;
            }

            if ($segStart < strlen($text)) {
                $sliceText = substr($text, $segStart);
                $sliceWidth = $this->measurer->widthOf($sliceText, $face, $fontSize);
                $carry[] = ['kind' => 'text', 'run' => $run, 'face' => $face, 'text' => $sliceText, 'width' => $sliceWidth];
                $carryWidth += $sliceWidth;
            }
        }

        $this->commitWord($carry, $carryWidth, $lines, $lineEntries, $lineWidth, $cursorY, $x, $availableWidth, $blockStyle, $openBoxStack, $boxSeq);
        $this->closeLine($lines, $lineEntries, $lineWidth, $x, $cursorY, $availableWidth, $blockStyle, force: false, openBoxStack: $openBoxStack, boxSeq: $boxSeq);

        return $lines;
    }

    /**
     * M7-T4: layout de bloque COMPLETO (shrink-to-fit) para un elemento display:inline-block --
     * CSS 2.2 §10.3.9 simplificado per el brief: "usedWidth = min(max-content, available)" cuando
     * no hay width declarado (auto); un width declarado (px o %, resuelto contra $availableWidth --
     * el content width del bloque contenedor, el MISMO valor que un navegador real usaría como
     * containing block de este inline-block) GANA sin más, sin ningún clamp -- puede desbordar la
     * línea si es más ancho que el espacio disponible, igual que un navegador real (no se encoge un
     * width explícito). El origen (0,0) pasado a BlockFlowContext::layout() es un origen LOCAL --
     * la posición FINAL (dependiente de dónde cae en la línea, decidido en closeLine()) se aplica
     * después vía shiftFragment(), desplazando el subárbol entero.
     *
     * M7-T4 fix (review Finding 1): un width declarado es, por CSS default (box-sizing:content-box),
     * el ancho de CONTENIDO -- pero BlockFlowContext::layout() SIEMPRE interpreta $usedWidthOverride
     * como el ancho BORDER-BOX ya resuelto (ver su docblock M4-T5, mismo contrato que usa
     * FlexFormattingContext). Pasar el valor declarado tal cual (bug original) hacía que los
     * paddings/bordes horizontales del propio inline-block se "comieran" ese ancho en vez de
     * sumarse a él (repro adjudicado: .btn{width:100px;padding:6px 20px;border:1px} debía medir
     * 142px de border-box, no 100px). Se convierte aquí ANTES de llamar a layout(), replicando el
     * mismo patrón ya usado por BlockFlowContext::layout() consigo mismo cuando NO hay override
     * (rama "declared width", box-sizing incluido) e IntrinsicSizer::sizeBlock() (mismo cálculo
     * para max-content) -- box-sizing:border-box, en cambio, YA ES el ancho border-box, pasa
     * intacto. Aplica igual a % (resuelto contra $availableWidth primero, ver $style->width->resolve()
     * más abajo) que a px: la conversión ocurre DESPUÉS de resolver el valor a píxeles, así que
     * cubre ambos casos con el mismo código.
     *
     * @return array{fragment: BoxFragment, width: float, height: float} width/height = tamaño de
     *     MARGIN box (lo que avanza el cursor de línea / lo que compite por el alto de línea).
     */
    private function layoutInlineBlockAtomic(BlockBox $box, float $availableWidth): array
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

        $marginLeft = $style->marginLeft->resolve($availableWidth);
        $marginRight = $style->marginRight->resolve($availableWidth);
        $marginTop = $style->marginTop->resolve($availableWidth);
        $marginBottom = $style->marginBottom->resolve($availableWidth);

        $fragment = $this->blockContext()->layout($box, new Rect(0.0, 0.0, $availableWidth, INF), $usedWidth);

        return [
            'fragment' => $fragment,
            'width' => $marginLeft + $fragment->rect->width + $marginRight,
            'height' => $marginTop + $fragment->rect->height + $marginBottom,
        ];
    }

    /**
     * Decide si la "palabra" (uno o más tramos, potencialmente de runs y/o cajas distintas) cabe
     * en la línea actual; si no cabe y ya hay contenido, cierra la línea primero (greedy, como M0).
     * Una línea vacía SIEMPRE acepta su primera palabra sin importar el ancho (nunca bucle
     * infinito: una palabra más ancha que la línea simplemente desborda, ver brief).
     *
     * @param list<array{kind: 'text', run: TextRun, face: FontFace, text: string, width: float}|array{kind: 'box-open', style: ComputedStyle, tag: string, width: float, paddingRight: float, paddingTop: float, paddingBottom: float}|array{kind: 'box-close', width: float}|array{kind: 'atomic', fragment: BoxFragment, width: float, height: float}> $word
     * @param list<Fragment> $lines
     * @param list<array{kind: 'text', run: TextRun, face: FontFace, text: string, width: float}|array{kind: 'box-open', style: ComputedStyle, tag: string, width: float, paddingRight: float, paddingTop: float, paddingBottom: float}|array{kind: 'box-close', width: float}|array{kind: 'atomic', fragment: BoxFragment, width: float, height: float}> $lineEntries
     * @param list<array{seq: int, style: ComputedStyle, tag: string, paddingTop: float, paddingBottom: float}> $openBoxStack
     */
    private function commitWord(
        array $word,
        float $wordWidth,
        array &$lines,
        array &$lineEntries,
        float &$lineWidth,
        float &$cursorY,
        float $x,
        float $availableWidth,
        ComputedStyle $blockStyle,
        array &$openBoxStack,
        int &$boxSeq,
    ): void {
        if ($word === []) {
            return;
        }

        $last = $word[count($word) - 1];
        $core = ($last['kind'] === 'text' && str_ends_with($last['text'], ' '))
            ? $wordWidth - $this->measurer->widthOf(' ', $last['face'], $last['run']->style->fontSizePx)
            : $wordWidth;

        if ($lineEntries !== [] && $lineWidth + $core > $availableWidth) {
            $this->closeLine($lines, $lineEntries, $lineWidth, $x, $cursorY, $availableWidth, $blockStyle, force: false, openBoxStack: $openBoxStack, boxSeq: $boxSeq);
        }

        foreach ($word as $slice) {
            $this->appendEntry($lineEntries, $slice);
        }
        $lineWidth += $wordWidth;
    }

    /**
     * @param list<array{kind: 'text', run: TextRun, face: FontFace, text: string, width: float}|array{kind: 'box-open', style: ComputedStyle, tag: string, width: float, paddingRight: float, paddingTop: float, paddingBottom: float}|array{kind: 'box-close', width: float}|array{kind: 'atomic', fragment: BoxFragment, width: float, height: float}> $lineEntries
     * @param array{kind: 'text', run: TextRun, face: FontFace, text: string, width: float}|array{kind: 'box-open', style: ComputedStyle, tag: string, width: float, paddingRight: float, paddingTop: float, paddingBottom: float}|array{kind: 'box-close', width: float}|array{kind: 'atomic', fragment: BoxFragment, width: float, height: float} $slice
     */
    private function appendEntry(array &$lineEntries, array $slice): void
    {
        $lastIndex = count($lineEntries) - 1;
        if ($lastIndex >= 0
            && $lineEntries[$lastIndex]['kind'] === 'text'
            && $slice['kind'] === 'text'
            && $lineEntries[$lastIndex]['run'] === $slice['run']
        ) {
            $lineEntries[$lastIndex]['text'] .= $slice['text'];
            $lineEntries[$lastIndex]['width'] += $slice['width'];
            return;
        }
        $lineEntries[] = $slice;
    }

    /**
     * Cierra la línea acumulada, emitiendo un TextFragment por tramo de run participante
     * (alineados con text-align del bloque), un BoxFragment ya posicionado por cada inline-block,
     * e InlineBoxFragment por cada caja inline abierta durante algún tramo de esta línea — y
     * avanza el cursor vertical. `$force` distingue un cierre PEDIDO explícitamente (LineBreakRun)
     * — que debe producir una línea (en blanco si hace falta) para que el hueco cuente en el alto
     * del bloque — de un cierre natural de fin de secuencia, donde una línea sin contenido no debe
     * generar un fragment fantasma.
     *
     * ORDEN DE EMISIÓN (contrato de Painter: cajas ANTES que su texto): todos los InlineBoxFragment
     * de esta línea se añaden a $lines primero (ordenados por $seq ascendente = orden de APERTURA,
     * exterior-antes-que-interior, para que el fondo de una caja exterior no tape el de una interior
     * anidada), luego los TextFragment/BoxFragment de contenido en su orden izquierda-a-derecha.
     *
     * @param list<Fragment> $lines
     * @param list<array{kind: 'text', run: TextRun, face: FontFace, text: string, width: float}|array{kind: 'box-open', style: ComputedStyle, tag: string, width: float, paddingRight: float, paddingTop: float, paddingBottom: float}|array{kind: 'box-close', width: float}|array{kind: 'atomic', fragment: BoxFragment, width: float, height: float}> $lineEntries
     * @param list<array{seq: int, style: ComputedStyle, tag: string, paddingTop: float, paddingBottom: float}> $openBoxStack
     */
    private function closeLine(
        array &$lines,
        array &$lineEntries,
        float &$lineWidth,
        float $x,
        float &$cursorY,
        float $availableWidth,
        ComputedStyle $blockStyle,
        bool $force,
        array &$openBoxStack,
        int &$boxSeq,
    ): void {
        if ($lineEntries === []) {
            if (!$force) {
                return;
            }
            $face = $this->faceFor($blockStyle);
            $fontSize = $blockStyle->fontSizePx;
            $lineHeight = max($blockStyle->lineHeightPx ?? 0.0, $this->measurer->lineHeight($fontSize));
            $ascent = $this->measurer->ascent($face, $fontSize);
            $lines[] = new TextFragment(
                new Rect($x, $cursorY, 0.0, $lineHeight),
                '',
                $cursorY + ($lineHeight - $fontSize) / 2 + $ascent,
                $fontSize,
                $blockStyle->color,
                $face->key,
                $blockStyle->underline,
                $blockStyle->opacity,
            );
            $cursorY += $lineHeight;
            return;
        }

        // El espacio final de la ÚLTIMA palabra de la línea "cuelga" fuera del ancho reportado
        // (nunca se pintó como separador de nada más en esta línea) — ver cabecera de fichero.
        $lastIndex = count($lineEntries) - 1;
        $reportedWidth = $lineWidth;
        $lastEntry = $lineEntries[$lastIndex];
        if ($lastEntry['kind'] === 'text' && str_ends_with($lastEntry['text'], ' ')) {
            $spaceWidth = $this->measurer->widthOf(' ', $lastEntry['face'], $lastEntry['run']->style->fontSizePx);
            $lineEntries[$lastIndex]['width'] -= $spaceWidth;
            $reportedWidth -= $spaceWidth;
        }

        // --- métricas verticales: strut de texto (igual fórmula que antes de M7-T4) + posible
        // crecimiento por un inline-block más alto que el strut (ver docblock de clase). Cuando
        // ningún entry es 'atomic' (el caso normal, sin inline-block), $maxAtomicHeight se queda
        // en 0.0 y $ascentAboveTop === $normalAscentAboveTop siempre (max con 0 es un no-op), así
        // que $lineHeight/$baseline son BIT-A-BIT idénticos a la fórmula de antes de esta tarea.
        $maxFontSize = 0.0;
        $maxAscent = 0.0;
        $maxAtomicHeight = 0.0;
        // M7-T4 fix (review Finding 2): un box-open/box-close NUNCA contribuía a estas métricas --
        // inofensivo mientras la línea tuviera ALGÚN 'text'/'atomic' real (el caso normal), pero
        // dejaba una línea compuesta ÚNICAMENTE por una caja vacía (ver más abajo, hasContent) sin
        // NINGUNA fuente de altura ($maxFontSize se quedaba en 0.0). Se registra aquí, aparte, el
        // font-size/ascent propio de cada caja abierta en esta línea -- SOLO se usa como fallback
        // cuando ninguna entry 'text'/'atomic' aportó nada (ver el if de debajo), así que no cambia
        // ni un bit el resultado de ninguna línea con contenido real (byte-stable, mismo docblock
        // de arriba).
        $emptyBoxFontSize = 0.0;
        $emptyBoxAscent = 0.0;
        foreach ($lineEntries as $entry) {
            if ($entry['kind'] === 'text') {
                $fontSize = $entry['run']->style->fontSizePx;
                $maxFontSize = max($maxFontSize, $fontSize);
                $maxAscent = max($maxAscent, $this->measurer->ascent($entry['face'], $fontSize));
            } elseif ($entry['kind'] === 'atomic') {
                $maxAtomicHeight = max($maxAtomicHeight, $entry['height']);
            } elseif ($entry['kind'] === 'box-open') {
                $boxFontSize = $entry['style']->fontSizePx;
                $emptyBoxFontSize = max($emptyBoxFontSize, $boxFontSize);
                $emptyBoxAscent = max($emptyBoxAscent, $this->measurer->ascent($this->faceFor($entry['style']), $boxFontSize));
            }
        }
        if ($maxFontSize === 0.0 && $emptyBoxFontSize > 0.0) {
            // Línea SIN NINGÚN 'text'/'atomic' (solo caja(s) inline vacías con box visible, ver
            // Finding 2 más abajo): el "strut" de fuente de la propia caja (convención 1.2x, ver
            // TextMeasurer::lineHeight()) es lo único que le da altura a la línea -- documentado,
            // adjudicación del review.
            $maxFontSize = $emptyBoxFontSize;
            $maxAscent = $emptyBoxAscent;
        }
        $normalLineHeight = max($blockStyle->lineHeightPx ?? 0.0, $this->measurer->lineHeight($maxFontSize));
        $normalAscentAboveTop = ($normalLineHeight - $maxFontSize) / 2 + $maxAscent;
        $descentBelowBaseline = $normalLineHeight - $normalAscentAboveTop;
        $ascentAboveTop = max($normalAscentAboveTop, $maxAtomicHeight);
        $lineHeight = $ascentAboveTop + $descentBelowBaseline;
        $baseline = $cursorY + $ascentAboveTop;

        $shiftX = match ($blockStyle->textAlign) {
            TextAlign::Center => ($availableWidth - $reportedWidth) / 2,
            TextAlign::Right => $availableWidth - $reportedWidth,
            TextAlign::Left => 0.0,
        };

        $noSide = new BorderSide(0.0, BorderStyle::None, null);

        /** @var array<int, array{minX: ?float, maxX: ?float, hasContent: bool, openedThisLine: bool}> $lineBoxState */
        $lineBoxState = [];
        foreach ($openBoxStack as $frame) {
            $lineBoxState[$frame['seq']] = ['minX' => null, 'maxX' => null, 'hasContent' => false, 'openedThisLine' => false];
        }

        /** @var array<int, InlineBoxFragment> $emittedBoxFragments */
        $emittedBoxFragments = [];
        /** @var list<Fragment> $contentFragments */
        $contentFragments = [];

        $cursorX = $x + $shiftX;
        foreach ($lineEntries as $entry) {
            // PHPStan solo estrecha la unión discriminada de $entry (por su clave 'kind') cuando
            // la comparación literal se hace DIRECTAMENTE sobre `$entry['kind']` (no a través de
            // una variable copia como `$kind = $entry['kind']`) -- de ahí que cada rama repita el acceso.
            if ($entry['kind'] === 'box-open') {
                $seq = $boxSeq++;
                $openBoxStack[] = [
                    'seq' => $seq,
                    'style' => $entry['style'],
                    'tag' => $entry['tag'],
                    'paddingTop' => $entry['paddingTop'],
                    'paddingBottom' => $entry['paddingBottom'],
                ];
                $lineBoxState[$seq] = ['minX' => $cursorX, 'maxX' => null, 'hasContent' => false, 'openedThisLine' => true];
                $cursorX += $entry['width'];
                continue;
            }

            if ($entry['kind'] === 'box-close') {
                $cursorX += $entry['width'];
                $frame = array_pop($openBoxStack);
                if ($frame === null) {
                    // Nunca debería ocurrir (BoxTreeBuilder garantiza anidamiento balanceado) --
                    // guarda puramente defensiva, sin ningún test que la ejercite.
                    continue;
                }
                $seq = $frame['seq'];
                $state = $lineBoxState[$seq];
                $state['maxX'] = $cursorX;
                // M7-T4 fix (review Finding 2): antes se exigía además $state['hasContent'], lo que
                // descartaba SILENCIOSAMENTE una caja inline COMPLETAMENTE vacía (p.ej.
                // <span class="tag"></span> con bg/padding) -- contradiciendo CSS 2.2 (una caja
                // inline vacía sigue generando su caja: ancho = paddings horizontales, altura mínima
                // = el strut de línea, ver el fallback de más arriba). InlineBoxStart/InlineBoxEnd
                // SOLO llegan aquí para una caja YA confirmada visible por
                // BoxTreeBuilder::hasVisibleInlineBox() -- así que $state['minX'] !== null (posición
                // establecida en ESTA línea, siempre cierto tras la rama 'box-open' de arriba) es la
                // ÚNICA condición real que hace falta; exigir 'hasContent' además era redundante Y el
                // bug, porque descartaba precisamente el caso "sin contenido" que sí debe pintarse.
                if ($state['minX'] !== null) {
                    $isFirstSlice = $state['openedThisLine'];
                    $emittedBoxFragments[$seq] = $this->buildInlineBoxFragment(
                        $frame,
                        $state['minX'],
                        $state['maxX'],
                        $cursorY,
                        $lineHeight,
                        $isFirstSlice,
                        isLastSlice: true,
                        noSide: $noSide,
                    );
                }
                unset($lineBoxState[$seq]);
                continue;
            }

            if ($entry['kind'] === 'atomic') {
                $height = $entry['height'];
                $topY = $baseline - $height;
                $contentFragments[] = $this->shiftFragment($entry['fragment'], $cursorX, $topY);
                $width = $entry['width'];
                $lineBoxState = $this->markBoxesTouched($lineBoxState, $openBoxStack, $cursorX, $width);
                $cursorX += $width;
                continue;
            }

            // 'text'
            $style = $entry['run']->style;
            $width = $entry['width'];
            $contentFragments[] = new TextFragment(
                new Rect($cursorX, $cursorY, $width, $lineHeight),
                $entry['text'],
                $baseline,
                $style->fontSizePx,
                $style->color,
                $entry['face']->key,
                $style->underline,
                $style->opacity,
            );
            $lineBoxState = $this->markBoxesTouched($lineBoxState, $openBoxStack, $cursorX, $width);
            $cursorX += $width;
        }

        // Cajas que siguen abiertas al final de esta línea (continúan en la siguiente): también
        // necesitan su InlineBoxFragment de ESTA línea (isLastSlice=false) -- salvo que no hayan
        // tenido ningún contenido en ella (línea forzada en blanco a mitad de una caja, edge case
        // documentado, se omite en vez de emitir un fragment de ancho 0 sin sentido visual).
        foreach ($openBoxStack as $frame) {
            $seq = $frame['seq'];
            $state = $lineBoxState[$seq];
            if (!$state['hasContent'] || $state['minX'] === null || $state['maxX'] === null) {
                continue;
            }
            $emittedBoxFragments[$seq] = $this->buildInlineBoxFragment(
                $frame,
                $state['minX'],
                $state['maxX'],
                $cursorY,
                $lineHeight,
                isFirstSlice: $state['openedThisLine'],
                isLastSlice: false,
                noSide: $noSide,
            );
        }

        ksort($emittedBoxFragments);
        foreach ($emittedBoxFragments as $fragment) {
            $lines[] = $fragment;
        }
        foreach ($contentFragments as $fragment) {
            $lines[] = $fragment;
        }

        $cursorY += $lineHeight;
        $lineEntries = [];
        $lineWidth = 0.0;
    }

    /**
     * Marca TODAS las cajas actualmente abiertas (toda la pila, cada una anida dentro de la
     * anterior, así que el contenido pertenece a TODAS simultáneamente) como "con contenido" en
     * esta línea, extendiendo su extent [minX,maxX] hasta cubrir [$cursorX, $cursorX+$width) —
     * usado tanto por un TextFragment como por un item atómico (inline-block).
     *
     * RECONSTRUYE el elemento COMPLETO de $lineBoxState en vez de mutar claves sueltas (en vez de
     * `$lineBoxState[$seq]['hasContent'] = true` etc.) — PHPStan no puede seguir con fiabilidad la
     * forma de un array indexado por una clave DINÁMICA ($seq) a través de escrituras parciales de
     * subclaves; reconstruir el shape entero en cada actualización mantiene el array-shape
     * declarado exacto en todo momento.
     *
     * @param array<int, array{minX: ?float, maxX: ?float, hasContent: bool, openedThisLine: bool}> $lineBoxState
     * @param list<array{seq: int, style: ComputedStyle, tag: string, paddingTop: float, paddingBottom: float}> $openBoxStack
     * @return array<int, array{minX: ?float, maxX: ?float, hasContent: bool, openedThisLine: bool}>
     */
    private function markBoxesTouched(array $lineBoxState, array $openBoxStack, float $cursorX, float $width): array
    {
        foreach ($openBoxStack as $frame) {
            $seq = $frame['seq'];
            $prev = $lineBoxState[$seq];
            $lineBoxState[$seq] = [
                'minX' => $prev['minX'] ?? $cursorX,
                'maxX' => $cursorX + $width,
                'hasContent' => true,
                'openedThisLine' => $prev['openedThisLine'],
            ];
        }
        return $lineBoxState;
    }

    /**
     * @param array{seq: int, style: ComputedStyle, tag: string, paddingTop: float, paddingBottom: float} $frame
     */
    private function buildInlineBoxFragment(
        array $frame,
        float $minX,
        float $maxX,
        float $lineTopY,
        float $lineHeight,
        bool $isFirstSlice,
        bool $isLastSlice,
        BorderSide $noSide,
    ): InlineBoxFragment {
        $style = $frame['style'];
        $rectY = $lineTopY - $frame['paddingTop'];
        $rectHeight = $lineHeight + $frame['paddingTop'] + $frame['paddingBottom'];
        $borders = new BorderSet(
            $style->borderTop,
            $isLastSlice ? $style->borderRight : $noSide,
            $style->borderBottom,
            $isFirstSlice ? $style->borderLeft : $noSide,
        );
        return new InlineBoxFragment(
            new Rect($minX, $rectY, $maxX - $minX, $rectHeight),
            $style->backgroundColor,
            $borders,
            $style->opacity,
            $isFirstSlice,
            $isLastSlice,
        );
    }

    /** Desplaza un Fragment (y, recursivamente, cualquier descendiente de un BoxFragment) por
     * (dx,dy) — usado para posicionar el subárbol de un inline-block, medido/layouteado con
     * origen local (0,0) en layoutInlineBlockAtomic(), en su posición FINAL dentro de la línea
     * (solo conocida aquí, en closeLine(), una vez decidido cursorX/baseline). */
    private function shiftFragment(Fragment $fragment, float $dx, float $dy): Fragment
    {
        if ($fragment instanceof BoxFragment) {
            return new BoxFragment(
                new Rect($fragment->rect->x + $dx, $fragment->rect->y + $dy, $fragment->rect->width, $fragment->rect->height),
                $fragment->background,
                array_map(fn(Fragment $child): Fragment => $this->shiftFragment($child, $dx, $dy), $fragment->children),
                $fragment->borders,
                $fragment->atomic,
                $fragment->opacity,
            );
        }
        if ($fragment instanceof TextFragment) {
            return new TextFragment(
                new Rect($fragment->rect->x + $dx, $fragment->rect->y + $dy, $fragment->rect->width, $fragment->rect->height),
                $fragment->text,
                $fragment->baselineY + $dy,
                $fragment->fontSizePx,
                $fragment->color,
                $fragment->faceKey,
                $fragment->underline,
                $fragment->opacity,
            );
        }
        if ($fragment instanceof ImageFragment) {
            return new ImageFragment(
                new Rect($fragment->rect->x + $dx, $fragment->rect->y + $dy, $fragment->rect->width, $fragment->rect->height),
                $fragment->imageKey,
                $fragment->opacity,
            );
        }
        if ($fragment instanceof InlineBoxFragment) {
            return new InlineBoxFragment(
                new Rect($fragment->rect->x + $dx, $fragment->rect->y + $dy, $fragment->rect->width, $fragment->rect->height),
                $fragment->background,
                $fragment->borders,
                $fragment->opacity,
                $fragment->isFirstSlice,
                $fragment->isLastSlice,
            );
        }
        throw new \LogicException('Unknown fragment kind: ' . $fragment::class);
    }

    /** M7-T2: $style->fontFamily es ahora una lista de fallback (ver ComputedStyle) — se resuelve
     * a UNA familia concreta (genérico traducido o primer nombre registrado, ver
     * FontFamilyResolver) antes de pedirle la cara a FontCatalog. */
    private function faceFor(ComputedStyle $style): FontFace
    {
        $family = $this->fontFamilyResolver->resolve($style->fontFamily);
        return $this->catalog->select($family, $style->fontWeight, $style->fontStyle === FontStyle::Italic);
    }
}
