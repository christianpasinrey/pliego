<?php

declare(strict_types=1);

namespace Pliego\Page;

use Pliego\Css\Value\Length;

/**
 * VO final (Page\) de un bloque @page, convertido de la estructura cruda Css\PageRuleData por
 * PageRuleFactory (M2-T6). Cada margen es ?Length: null significa "no declarado" — el Engine
 * mantiene su margin uniforme para ese lado (fallback per-side, no per-rule); un valor no-null
 * OVERRIDEA Engine::margins() para ese lado exclusivamente.
 *
 * @page no admite % en los márgenes de página en esta implementación (ver PageRuleData), de ahí
 * Length y no LengthPercentage aquí también.
 */
final readonly class PageRule
{
    /**
     * @param array<string, MarginBoxContent> $marginBoxes claves: top-left|top-center|
     *        top-right|bottom-left|bottom-center|bottom-right
     */
    public function __construct(
        public ?Length $marginTop,
        public ?Length $marginRight,
        public ?Length $marginBottom,
        public ?Length $marginLeft,
        public array $marginBoxes,
    ) {}
}
