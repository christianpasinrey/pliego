<?php

declare(strict_types=1);

namespace Pliego\Box;

use Pliego\Style\ComputedStyle;

/**
 * M7-T4 (css-inline-3 reducido): marcador de APERTURA de una caja inline REAL dentro de la
 * secuencia de tokens de un bloque (ver BoxTreeBuilder::collectChildren()/collectInline()) --
 * emitido SOLO cuando el elemento tiene al menos una propiedad de caja visible (background,
 * borde visible o padding no-cero en cualquier lado, ver BoxTreeBuilder::hasVisibleInlineBox()):
 * un <span>/<strong>/... sin ninguna de esas propiedades NUNCA produce este token (fast path,
 * ver el docblock de esa función para la prueba de estabilidad de goldens). $style es el
 * ComputedStyle PROPIO del elemento (ya heredado vía StyleResolver, igual que el de cualquier
 * TextRun interior) -- InlineFlowContext lo usa para resolver padding/border/background al
 * emitir el InlineBoxFragment de cada línea. $tag es puramente informativo (paridad con
 * TextRun/ImageBox, que no lo llevan -- aquí se incluye porque puede ser útil para depuración/
 * tests, sin ningún consumidor de layout que dependa de su valor).
 */
final readonly class InlineBoxStart
{
    public function __construct(
        public ComputedStyle $style,
        public string $tag,
    ) {}
}
