<?php

declare(strict_types=1);

namespace Pliego\Style;

/**
 * CSS 2.2 §10.8.1 / css-tables-3 §3: vertical-align solo tiene efecto en table cells y en
 * elementos inline en M5 (el motor únicamente lo consume desde TableCellBox — M5-T4). El
 * spec real tiene más keywords (baseline, sub, super, text-top, text-bottom, <percentage>) y
 * 'baseline' es el initial value; este motor solo soporta top|middle|bottom (M5) y hace que
 * el default de ComputedStyle sea Top, no Baseline — divergencia documentada en
 * ComputedStyle::root()/compute() porque M5 no implementa layout de baseline.
 */
enum VerticalAlign
{
    case Top;
    case Middle;
    case Bottom;
}
