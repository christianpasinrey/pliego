<?php

declare(strict_types=1);

namespace Pliego\Layout\Fragment;

use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;

/**
 * Los 4 lados de borde de una BoxFragment (M2-T4), listos para que T5 los pinte. Se ensamblan
 * en BlockFlowContext a partir de ComputedStyle::$borderTop/Right/Bottom/Left y viajan con la
 * caja sin cambios a través de Paginator::relocate (igual que el background).
 */
final readonly class BorderSet
{
    public function __construct(
        public BorderSide $top,
        public BorderSide $right,
        public BorderSide $bottom,
        public BorderSide $left,
    ) {}

    /** Caja sin bordes declarados (default de BoxFragment en construcciones de test/fixture). */
    public static function none(): self
    {
        $none = new BorderSide(0.0, BorderStyle::None, null);
        return new self($none, $none, $none, $none);
    }

    /** true si al menos un lado tiene anchura > 0 y estilo Solid (css-backgrounds-3: visible border). */
    public function isVisible(): bool
    {
        foreach ([$this->top, $this->right, $this->bottom, $this->left] as $side) {
            if ($side->widthPx > 0.0 && $side->style === BorderStyle::Solid) {
                return true;
            }
        }
        return false;
    }
}
