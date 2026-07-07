<?php

declare(strict_types=1);

namespace Pliego\Style;

/**
 * M8-T5 (css-text-3 §8 reducido): los 4 valores del subset reducido de este milestone --
 * font-variant/full-width/full-size-kana (css-text-3 §8 real) quedan fuera de alcance, ver
 * RESTRICCIONES GLOBALES. Aplicado al TEXTO de los runs en Box\BoxTreeBuilder ANTES de medir
 * (BoxTreeBuilder::textRunTokensFor(), mb_convert_case) -- ComputedStyle/este enum solo
 * transportan el valor COMPUTADO, nunca transforman texto por sí mismos.
 */
enum TextTransform
{
    case None;
    case Uppercase;
    case Lowercase;
    case Capitalize;
}
