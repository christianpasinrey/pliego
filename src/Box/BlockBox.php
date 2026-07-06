<?php

declare(strict_types=1);

namespace Pliego\Box;

use Pliego\Style\ComputedStyle;

final readonly class BlockBox
{
    /**
     * M5-T3: += TableBox — una <table> aparece como hijo directo de un bloque normal exactamente
     * igual que cualquier otro BlockBox|ImageBox (incluido dentro de un contenedor flex: es un
     * flex item DIRECTO por sí misma, ver BoxTreeBuilder::wrapAnonymousFlexItems() — el mismo
     * mecanismo que ya trataba a ImageBox como item directo sin cambios). Los consumidores de
     * layout (BlockFlowContext/FlexFormattingContext/IntrinsicSizer) todavía la SALTAN
     * explícitamente hasta M5-T4.
     * @param list<BlockBox|TextRun|LineBreakRun|ImageBox|TableBox> $children
     */
    public function __construct(
        public ComputedStyle $style,
        public array $children,
        public string $tag,
    ) {}
}
