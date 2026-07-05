<?php

declare(strict_types=1);

namespace Pliego\Box;

use Pliego\Style\Display;
use Pliego\Style\StyleMap;

final class BoxTreeBuilder
{
    private const array INLINE_TAGS = ['span', 'strong', 'em', 'b', 'i', 'a', 'small', 'code', 'u'];

    public function build(\Dom\HTMLDocument $document, StyleMap $styles): BlockBox
    {
        $body = $document->body ?? throw new \InvalidArgumentException('Document has no body');
        return $this->buildBlock($body, $styles);
    }

    private function buildBlock(\Dom\Element $element, StyleMap $styles): BlockBox
    {
        $style = $styles->get($element);
        $children = [];
        /** @var list<TextRun|LineBreakRun> $pending secuencia inline pendiente de colapsar (M1-T4) */
        $pending = [];
        $flush = function () use (&$children, &$pending): void {
            foreach (self::collapse($pending) as $run) {
                $children[] = $run;
            }
            $pending = [];
        };
        foreach ($element->childNodes as $node) {
            if ($node instanceof \Dom\Text) {
                $pending[] = new TextRun(self::collapseInternalWhitespace($node->textContent ?? ''), $style);
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
            if (in_array($tag, self::INLINE_TAGS, true)) {
                $this->collectInline($node, $styles, $pending);
                continue;
            }
            $flush();
            $children[] = $this->buildBlock($node, $styles);
        }
        $flush();
        return new BlockBox($style, $children, strtolower($element->tagName));
    }

    /**
     * M1-T4: recorre el subárbol de un elemento INLINE generando TextRun/LineBreakRun con el
     * ComputedStyle de CADA elemento inline propio (ya heredado del bloque vía StyleResolver),
     * en vez de aplanar a texto plano con el estilo del bloque (comportamiento M0). Cualquier
     * descendiente con display:none se poda (arregla el leak de M0). Los tags anidados no
     * necesitan estar en INLINE_TAGS: se recorren igualmente, con permisividad heredada de M0.
     *
     * @param list<TextRun|LineBreakRun> $pending
     */
    private function collectInline(\Dom\Element $element, StyleMap $styles, array &$pending): void
    {
        $style = $styles->get($element);
        foreach ($element->childNodes as $node) {
            if ($node instanceof \Dom\Text) {
                $pending[] = new TextRun(self::collapseInternalWhitespace($node->textContent ?? ''), $style);
                continue;
            }
            if (!$node instanceof \Dom\Element) {
                continue;
            }
            if ($styles->get($node)->display === Display::None) {
                continue;
            }
            if (strtolower($node->tagName) === 'br') {
                $pending[] = new LineBreakRun();
                continue;
            }
            $this->collectInline($node, $styles, $pending);
        }
    }

    private static function collapseInternalWhitespace(string $raw): string
    {
        return preg_replace('/\s+/', ' ', $raw) ?? '';
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
     * @param list<TextRun|LineBreakRun> $tokens
     * @return list<TextRun|LineBreakRun>
     */
    private static function collapse(array $tokens): array
    {
        $result = [];
        // Se muta el run precedente con array_pop()+push() (nunca por índice) para que
        // PHPStan siga viendo $result como list<...> de principio a fin.
        $lastText = null;
        $pendingSpace = false;
        foreach ($tokens as $token) {
            if ($token instanceof LineBreakRun) {
                $result[] = $token;
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
