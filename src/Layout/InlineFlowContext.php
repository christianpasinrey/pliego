<?php

declare(strict_types=1);

namespace Pliego\Layout;

use Pliego\Box\LineBreakRun;
use Pliego\Box\TextRun;
use Pliego\Css\WarningCollector;
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
 */
final readonly class InlineFlowContext
{
    private FontFamilyResolver $fontFamilyResolver;

    public function __construct(
        private TextMeasurer $measurer,
        private FontCatalog $catalog,
        ?WarningCollector $warnings = null,
    ) {
        $this->fontFamilyResolver = new FontFamilyResolver($catalog, $warnings);
    }

    /**
     * @param list<TextRun|LineBreakRun> $runs
     * @return list<TextFragment>
     */
    public function layout(array $runs, float $x, float $y, float $availableWidth, ComputedStyle $blockStyle): array
    {
        $finder = new BreakFinder();

        /** @var list<TextFragment> $lines */
        $lines = [];
        $cursorY = $y;

        /** @var list<array{run: TextRun, face: FontFace, text: string, width: float}> $lineEntries */
        $lineEntries = [];
        $lineWidth = 0.0;

        /** @var list<array{run: TextRun, face: FontFace, text: string, width: float}> $carry */
        $carry = [];
        $carryWidth = 0.0;

        foreach ($runs as $run) {
            if ($run instanceof LineBreakRun) {
                $this->commitWord($carry, $carryWidth, $lines, $lineEntries, $lineWidth, $cursorY, $x, $availableWidth, $blockStyle);
                $carry = [];
                $carryWidth = 0.0;
                $this->closeLine($lines, $lineEntries, $lineWidth, $x, $cursorY, $availableWidth, $blockStyle, force: true);
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
                $carry[] = ['run' => $run, 'face' => $face, 'text' => $text, 'width' => $sliceWidth];
                $carryWidth += $sliceWidth;
                continue;
            }

            $segStart = 0;

            foreach ($finder->find($text) as $opportunity) {
                $end = $opportunity->byteOffset;
                $sliceText = substr($text, $segStart, $end - $segStart);
                $sliceWidth = $this->measurer->widthOf($sliceText, $face, $fontSize);
                $carry[] = ['run' => $run, 'face' => $face, 'text' => $sliceText, 'width' => $sliceWidth];
                $carryWidth += $sliceWidth;

                $this->commitWord($carry, $carryWidth, $lines, $lineEntries, $lineWidth, $cursorY, $x, $availableWidth, $blockStyle);
                $carry = [];
                $carryWidth = 0.0;

                if ($opportunity->mandatory) {
                    $this->closeLine($lines, $lineEntries, $lineWidth, $x, $cursorY, $availableWidth, $blockStyle, force: true);
                }

                $segStart = $end;
            }

            if ($segStart < strlen($text)) {
                $sliceText = substr($text, $segStart);
                $sliceWidth = $this->measurer->widthOf($sliceText, $face, $fontSize);
                $carry[] = ['run' => $run, 'face' => $face, 'text' => $sliceText, 'width' => $sliceWidth];
                $carryWidth += $sliceWidth;
            }
        }

        $this->commitWord($carry, $carryWidth, $lines, $lineEntries, $lineWidth, $cursorY, $x, $availableWidth, $blockStyle);
        $this->closeLine($lines, $lineEntries, $lineWidth, $x, $cursorY, $availableWidth, $blockStyle, force: false);

        return $lines;
    }

    /**
     * Decide si la "palabra" (uno o más tramos, potencialmente de runs distintos) cabe en la
     * línea actual; si no cabe y ya hay contenido, cierra la línea primero (greedy, como M0).
     * Una línea vacía SIEMPRE acepta su primera palabra sin importar el ancho (nunca bucle
     * infinito: una palabra más ancha que la línea simplemente desborda, ver brief).
     *
     * @param list<array{run: TextRun, face: FontFace, text: string, width: float}> $word
     * @param list<TextFragment> $lines
     * @param list<array{run: TextRun, face: FontFace, text: string, width: float}> $lineEntries
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
    ): void {
        if ($word === []) {
            return;
        }

        $last = $word[count($word) - 1];
        $core = str_ends_with($last['text'], ' ')
            ? $wordWidth - $this->measurer->widthOf(' ', $last['face'], $last['run']->style->fontSizePx)
            : $wordWidth;

        if ($lineEntries !== [] && $lineWidth + $core > $availableWidth) {
            $this->closeLine($lines, $lineEntries, $lineWidth, $x, $cursorY, $availableWidth, $blockStyle, force: false);
        }

        foreach ($word as $slice) {
            $this->appendEntry($lineEntries, $slice);
        }
        $lineWidth += $wordWidth;
    }

    /**
     * @param list<array{run: TextRun, face: FontFace, text: string, width: float}> $lineEntries
     * @param array{run: TextRun, face: FontFace, text: string, width: float} $slice
     */
    private function appendEntry(array &$lineEntries, array $slice): void
    {
        $lastIndex = count($lineEntries) - 1;
        if ($lastIndex >= 0 && $lineEntries[$lastIndex]['run'] === $slice['run']) {
            $lineEntries[$lastIndex]['text'] .= $slice['text'];
            $lineEntries[$lastIndex]['width'] += $slice['width'];
            return;
        }
        $lineEntries[] = $slice;
    }

    /**
     * Cierra la línea acumulada, emitiendo un TextFragment por tramo de run participante
     * (alineados con text-align del bloque) y avanza el cursor vertical. `$force` distingue un
     * cierre PEDIDO explícitamente (LineBreakRun) — que debe producir una línea (en blanco si
     * hace falta) para que el hueco cuente en el alto del bloque — de un cierre natural de
     * fin de secuencia, donde una línea sin contenido no debe generar un fragment fantasma.
     *
     * @param list<TextFragment> $lines
     * @param list<array{run: TextRun, face: FontFace, text: string, width: float}> $lineEntries
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
        if (str_ends_with($lineEntries[$lastIndex]['text'], ' ')) {
            $spaceWidth = $this->measurer->widthOf(
                ' ',
                $lineEntries[$lastIndex]['face'],
                $lineEntries[$lastIndex]['run']->style->fontSizePx,
            );
            $lineEntries[$lastIndex]['width'] -= $spaceWidth;
            $reportedWidth -= $spaceWidth;
        }

        $maxFontSize = 0.0;
        $maxAscent = 0.0;
        foreach ($lineEntries as $entry) {
            $fontSize = $entry['run']->style->fontSizePx;
            $maxFontSize = max($maxFontSize, $fontSize);
            $maxAscent = max($maxAscent, $this->measurer->ascent($entry['face'], $fontSize));
        }
        $lineHeight = max($blockStyle->lineHeightPx ?? 0.0, $this->measurer->lineHeight($maxFontSize));
        $baseline = $cursorY + ($lineHeight - $maxFontSize) / 2 + $maxAscent;

        $shiftX = match ($blockStyle->textAlign) {
            TextAlign::Center => ($availableWidth - $reportedWidth) / 2,
            TextAlign::Right => $availableWidth - $reportedWidth,
            TextAlign::Left => 0.0,
        };

        $cursorX = $x + $shiftX;
        foreach ($lineEntries as $entry) {
            $style = $entry['run']->style;
            $lines[] = new TextFragment(
                new Rect($cursorX, $cursorY, $entry['width'], $lineHeight),
                $entry['text'],
                $baseline,
                $style->fontSizePx,
                $style->color,
                $entry['face']->key,
                $style->underline,
                $style->opacity,
            );
            $cursorX += $entry['width'];
        }

        $cursorY += $lineHeight;
        $lineEntries = [];
        $lineWidth = 0.0;
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
