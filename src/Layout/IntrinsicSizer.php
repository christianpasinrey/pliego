<?php

declare(strict_types=1);

namespace Pliego\Layout;

use Pliego\Box\BlockBox;
use Pliego\Box\ImageBox;
use Pliego\Box\LineBreakRun;
use Pliego\Box\TableBox;
use Pliego\Box\TextRun;
use Pliego\Layout\Text\BreakFinder;
use Pliego\Style\ComputedStyle;
use Pliego\Style\Display;
use Pliego\Style\FlexDirection;
use Pliego\Style\FontStyle;
use Pliego\Text\FontCatalog;
use Pliego\Text\FontFace;

/**
 * css-sizing-3 §4 (reducido para M4-T3): calcula max-content/min-content width para un
 * BlockBox|ImageBox, SIN un containing block (a diferencia de BlockFlowContext, que layouta
 * contra uno concreto) — de ahí que todo % (width/margin/padding propios o de hijos) se resuelva
 * contra 0 ("base indefinida"), documentado explícitamente en cada punto donde ocurre, en vez de
 * fallar o inventar un ancho. No consume nada todavía (M4-T4 lo hará para flex-basis:auto).
 *
 * max-content de un BlockBox = max(por cada secuencia contigua de TextRun|LineBreakRun entre
 * hijos bloque/imagen: ver maxContentOfRunSequence(); por cada hijo BlockBox|ImageBox: su propio
 * max-content + sus márgenes horizontales) + paddings/bordes horizontales PROPIOS del bloque.
 * Dentro de una secuencia, cada TextRun se mide COMPLETO con su propia cara/tamaño (nunca se
 * parte por oportunidad de salto — max-content asume una sola línea) y los anchos se SUMAN
 * (concatenación real, sin espacio de más); un LineBreakRun (<br>) corta la secuencia en dos
 * tramos independientes (cada uno es una candidata a línea distinta), tomando el más ancho.
 *
 * EXCEPCIÓN (M5-T1, housekeeping): lo anterior asume que los hijos se APILAN verticalmente (un
 * bloque normal). Un hijo `display:flex` con flex-direction ROW (el default) pone sus items uno
 * al lado del otro en su eje principal — su max-content es la SUMA de los max-content de sus
 * items + los column-gap entre ellos, NO el máximo — ver sizeBlock()/maxContentOfFlexRowChildren().
 * flex-direction:column sí apila verticalmente (mismo criterio "max" que un bloque normal, sin
 * cambios). Solo max-content cambia; min-content de un contenedor flex row sigue con el criterio
 * genérico de abajo, sin ajustar (fuera de lo adjudicado por el brief de esta tarea).
 *
 * min-content = max(por cada TextRun: la palabra más larga DENTRO de ESE run — ver
 * minContentOfRun(); por cada hijo BlockBox|ImageBox: su propio min-content + márgenes) — NO se
 * añade paddings/bordes del bloque salvo el propio, igual que en max-content. Simplificación
 * adjudicada por el brief: una "palabra" que en realidad cruza dos runs (p.ej. negrita a mitad de
 * palabra, "au<b>to</b>") se mide en dos tramos independientes en vez de como una única palabra
 * más ancha — el resultado puede INFRA-ESTIMAR el mínimo real; documentado, no hay containing
 * block real todavía que lo haga observable (T4 decide qué tan grave es).
 *
 * ImageBox: ancho usado = CSS width en px (declarado, nunca %: sin containing block que resolver
 * un % contra, un % de width se trata como si no estuviera declarado — mismo criterio que un
 * BlockBox, documentado) > atributo HTML width > intrínseco, más el padding/borde horizontal
 * propio de la imagen (box-sizing: border-box reinterpreta solo el width DECLARADO EN CSS, igual
 * que BlockFlowContext::resolveReplacedSize() — divergencia con M3: aquí NUNCA se deriva el ancho
 * a partir del alto/ratio, ni se aplica el tope al containing block, porque no existe uno).
 */
final class IntrinsicSizer
{
    private BreakFinder $breakFinder;

    public function __construct(
        private TextMeasurer $measurer,
        private FontCatalog $catalog,
    ) {
        $this->breakFinder = new BreakFinder();
    }

    public function maxContentWidth(BlockBox|ImageBox $box): float
    {
        if ($box instanceof ImageBox) {
            return $this->usedImageWidth($box);
        }
        return $this->sizeBlock($box, max: true);
    }

    public function minContentWidth(BlockBox|ImageBox $box): float
    {
        if ($box instanceof ImageBox) {
            return $this->usedImageWidth($box);
        }
        return $this->sizeBlock($box, max: false);
    }

    private function sizeBlock(BlockBox $box, bool $max): float
    {
        $style = $box->style;
        [$borderPaddingLeft, $borderPaddingRight] = $this->borderPaddingX($style);

        // width: px declarado corta la recursión entera — es el tamaño, sea cual sea el
        // contenido (css-sizing-3 §4: un width explícito fija tanto min- como max-content a ese
        // mismo valor en este modelo reducido). % width no tiene containing block contra el que
        // resolverse aquí, así que se trata como auto (fallback al contenido real), documentado.
        $declaredWidth = $style->width;
        if ($declaredWidth !== null && !$declaredWidth->isPercent) {
            $widthPx = $declaredWidth->value;
            return $style->boxSizing === 'border-box'
                ? $widthPx
                : $widthPx + $borderPaddingLeft + $borderPaddingRight;
        }

        // M5-T1 (housekeeping): un hijo `display:flex` con flex-direction ROW (el default) NO
        // apila sus items verticalmente como un bloque normal — los pone uno al lado del otro en
        // el eje principal (css-flexbox-1 §9) — así que su max-content NO es el MÁXIMO de sus
        // items (lo que maxContentOfChildren() calcularía, tratándolo como bloque genérico) sino
        // la SUMA de los max-content de todos sus items + los column-gap entre ellos, exactamente
        // como css-sizing-3 §5.3/css-flexbox-1 §9.9 definen el "min/max-content contribution" de
        // un contenedor flex en su eje principal. Column mantiene el criterio "max" existente sin
        // cambios (los items SÍ se apilan verticalmente ahí, igual que un bloque normal — el
        // ancho del contenedor es el máximo de los anchos de sus items). Solo afecta a max-content
        // (adjudicado en el brief); min-content de un contenedor flex row NO se toca aquí (queda
        // con el criterio genérico "max de los hijos", una simplificación ya documentada, no
        // resuelta por esta tarea).
        if ($max && $style->display === Display::Flex && $style->flexDirection === FlexDirection::Row) {
            $contentWidth = $this->maxContentOfFlexRowChildren($box, $style->columnGapPx);
        } else {
            $contentWidth = $max ? $this->maxContentOfChildren($box) : $this->minContentOfChildren($box);
        }
        return $contentWidth + $borderPaddingLeft + $borderPaddingRight;
    }

    /**
     * Ver el comentario junto a su único call site (sizeBlock()): SUMA de max-content + márgenes
     * horizontales de cada item flex (BlockBox|ImageBox, mismo filtro que
     * FlexFormattingContext::flexItems() — un tramo de TextRun|LineBreakRun suelto no debería
     * llegar aquí en la práctica, ver BoxTreeBuilder::wrapAnonymousFlexItems(), pero se ignora sin
     * fallar si lo hiciera, igual criterio "soft" que el resto de esta clase) más columnGap × (n−1).
     */
    private function maxContentOfFlexRowChildren(BlockBox $box, float $columnGapPx): float
    {
        $items = [];
        foreach ($box->children as $child) {
            if ($child instanceof BlockBox || $child instanceof ImageBox) {
                $items[] = $child;
            }
        }
        if ($items === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($items as $item) {
            $sum += $this->maxContentWidth($item) + $this->marginsX($item->style);
        }
        return $sum + $columnGapPx * (count($items) - 1);
    }

    private function maxContentOfChildren(BlockBox $box): float
    {
        $best = 0.0;
        /** @var list<TextRun|LineBreakRun> $pending */
        $pending = [];
        $flush = function () use (&$pending, &$best): void {
            if ($pending === []) {
                return;
            }
            $best = max($best, $this->maxContentOfRunSequence($pending));
            $pending = [];
        };

        foreach ($box->children as $child) {
            if ($child instanceof TextRun || $child instanceof LineBreakRun) {
                $pending[] = $child;
                continue;
            }
            $flush();
            // M5-T3/T4: una TableBox hija de un BLOQUE GENÉRICO (no de una celda — ver
            // TableFormattingContext::cellMaxContent()/cellMinContent(), que sí le dan a una
            // TableBox de CELDA un min/max-content propio vía esta misma clase) sigue sin aportar
            // nada a este máximo — se ignora, mismo patrón "skip, documented, no crash" que
            // BlockFlowContext aplica en su bucle de layout. Gap conocido y documentado, no
            // cubierto por ningún test requerido de M5: una tabla anidada dentro de un bloque
            // normal (o dentro de una celda, vía la misma ruta) no ensancha el max-content de ese
            // contenedor aunque su propio contenido sea más ancho.
            if ($child instanceof TableBox) {
                continue;
            }
            $best = max($best, $this->maxContentWidth($child) + $this->marginsX($child->style));
        }
        $flush();

        return $best;
    }

    private function minContentOfChildren(BlockBox $box): float
    {
        $best = 0.0;
        foreach ($box->children as $child) {
            if ($child instanceof TextRun) {
                $best = max($best, $this->minContentOfRun($child));
                continue;
            }
            if ($child instanceof LineBreakRun) {
                // min-content ignora los saltos forzados: solo cuenta la palabra más larga por
                // run (ver docblock de clase), y un <br> no es un TextRun.
                continue;
            }
            // M5-T3/T4: ver el comentario análogo en maxContentOfChildren().
            if ($child instanceof TableBox) {
                continue;
            }
            $best = max($best, $this->minContentWidth($child) + $this->marginsX($child->style));
        }
        return $best;
    }

    /** @param list<TextRun|LineBreakRun> $runs una secuencia contigua ya recortada de hijos bloque/imagen */
    private function maxContentOfRunSequence(array $runs): float
    {
        $max = 0.0;
        $current = 0.0;
        foreach ($runs as $run) {
            if ($run instanceof LineBreakRun) {
                $max = max($max, $current);
                $current = 0.0;
                continue;
            }
            $face = $this->faceFor($run->style);
            $current += $this->measurer->widthOf($run->text, $face, $run->style->fontSizePx);
        }
        return max($max, $current);
    }

    /** Palabra más larga DENTRO del texto propio de este run (BreakFinder aplicado a $run->text). */
    private function minContentOfRun(TextRun $run): float
    {
        $face = $this->faceFor($run->style);
        $fontSize = $run->style->fontSizePx;
        $text = $run->text;
        $segStart = 0;
        $max = 0.0;

        foreach ($this->breakFinder->find($text) as $opportunity) {
            $end = $opportunity->byteOffset;
            $slice = $this->stripTrailingSpace(substr($text, $segStart, $end - $segStart));
            $max = max($max, $this->measurer->widthOf($slice, $face, $fontSize));
            $segStart = $end;
        }
        if ($segStart < strlen($text)) {
            $max = max($max, $this->measurer->widthOf(substr($text, $segStart), $face, $fontSize));
        }

        return $max;
    }

    /**
     * BreakFinder reporta la oportunidad justo DESPUÉS del espacio (igual que InlineFlowContext),
     * así que el tramo incluye ese espacio de frontera; para la "palabra" en sí (lo que cuenta
     * como min-content real) se descarta — el espacio es un separador invisible, no ancho de
     * contenido irreducible. Un guion de fin de tramo (LB21a) SÍ se conserva: es parte visible de
     * la palabra.
     */
    private function stripTrailingSpace(string $text): string
    {
        return str_ends_with($text, ' ') ? substr($text, 0, -1) : $text;
    }

    private function usedImageWidth(ImageBox $box): float
    {
        $style = $box->style;
        [$borderPaddingLeft, $borderPaddingRight] = $this->borderPaddingX($style);

        $declaredWidth = $style->width;
        $declaredWidthPx = $declaredWidth !== null && !$declaredWidth->isPercent ? $declaredWidth->value : null;
        if ($style->boxSizing === 'border-box' && $declaredWidthPx !== null) {
            $declaredWidthPx = max(0.0, $declaredWidthPx - $borderPaddingLeft - $borderPaddingRight);
        }

        $contentWidth = $declaredWidthPx ?? $box->attrWidth ?? (float) $box->intrinsicWidth;

        return $contentWidth + $borderPaddingLeft + $borderPaddingRight;
    }

    /** @return array{0: float, 1: float} padding+borde IZQUIERDO/DERECHO propios, % resuelto contra 0 */
    private function borderPaddingX(ComputedStyle $style): array
    {
        return [
            $style->paddingLeft->resolve(0.0) + $style->borderLeft->widthPx,
            $style->paddingRight->resolve(0.0) + $style->borderRight->widthPx,
        ];
    }

    /** Márgenes horizontales de un HIJO bloque/imagen, % resuelto contra 0 (sin containing block). */
    private function marginsX(ComputedStyle $style): float
    {
        return $style->marginLeft->resolve(0.0) + $style->marginRight->resolve(0.0);
    }

    private function faceFor(ComputedStyle $style): FontFace
    {
        return $this->catalog->select($style->fontFamily, $style->fontWeight, $style->fontStyle === FontStyle::Italic);
    }
}
