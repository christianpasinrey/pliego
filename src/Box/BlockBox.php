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
     * mecanismo que ya trataba a ImageBox como item directo sin cambios). BlockFlowContext la
     * layoutea (delegación a TableFormattingContext, M5-T4) e IntrinsicSizer le da su propio
     * min/max-content real (bugfix post-review M5-T4, ver el docblock de esa clase);
     * FlexFormattingContext SIGUE excluyéndola como item flex directo (adjudicación deliberada, ver
     * su propio docblock).
     * @param list<BlockBox|TextRun|LineBreakRun|ImageBox|TableBox> $children
     */
    public function __construct(
        public ComputedStyle $style,
        public array $children,
        public string $tag,
        // M7-T3 (css-lists-3 §3, atributo HTML `start` de <ol>): valor inicial del contador
        // decimal de ESTE contenedor (ol/ul) — null cuando el elemento no es un <ol> con `start`
        // válido (el caso normal, incluida cualquier <ul>: nunca numerada). NO es un dato de
        // marcador (eso vive enteramente en Layout, ver BlockFlowContext) — es la MISMA clase de
        // dato que ImageBox::$attrWidth/$attrHeight: un atributo HTML crudo que BoxTreeBuilder lee
        // una vez y transporta tal cual, sin interpretarlo. BlockFlowContext lo lee al iterar los
        // hijos de ESTE BlockBox para saber en qué número empezar a contar sus <li> hijos directos
        // (ver su docblock de clase, "contador por lista").
        public ?int $listStart = null,
    ) {}
}
