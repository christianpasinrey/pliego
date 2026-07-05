<?php

declare(strict_types=1);

namespace Pliego\Box;

use Pliego\Style\Display;
use Pliego\Style\StyleMap;

final class BoxTreeBuilder
{
    private const array INLINE_TAGS = ['span', 'strong', 'em', 'b', 'i', 'a', 'small', 'code'];

    public function build(\Dom\HTMLDocument $document, StyleMap $styles): BlockBox
    {
        $body = $document->body ?? throw new \InvalidArgumentException('Document has no body');
        return $this->buildBlock($body, $styles);
    }

    private function buildBlock(\Dom\Element $element, StyleMap $styles): BlockBox
    {
        $style = $styles->get($element);
        $children = [];
        $pendingText = '';
        $flush = function () use (&$children, &$pendingText, $style): void {
            $text = trim(preg_replace('/\s+/', ' ', $pendingText) ?? '');
            if ($text !== '') {
                $children[] = new TextRun($text, $style);
            }
            $pendingText = '';
        };
        foreach ($element->childNodes as $node) {
            if ($node instanceof \Dom\Text) {
                $pendingText .= $node->textContent;
                continue;
            }
            if (!$node instanceof \Dom\Element) {
                continue;
            }
            if ($styles->get($node)->display === Display::None) {
                continue;
            }
            if (in_array(strtolower($node->tagName), self::INLINE_TAGS, true)) {
                $pendingText .= $node->textContent; // M0: inline aplanado, estilo del bloque padre
                continue;
            }
            $flush();
            $children[] = $this->buildBlock($node, $styles);
        }
        $flush();
        return new BlockBox($style, $children, strtolower($element->tagName));
    }
}
