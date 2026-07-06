<?php

declare(strict_types=1);

namespace Pliego\Layout\Fragment;

use Pliego\Css\Value\Color;
use Pliego\Layout\Geometry\Rect;

final readonly class TextFragment implements Fragment
{
    public function __construct(
        public Rect $rect,
        public string $text,
        public float $baselineY,
        public float $fontSizePx,
        public Color $color,
        public string $faceKey,
        public bool $underline,
        // M6-T5: opacity PROPIA del elemento de texto (ComputedStyle::$opacity, default 1.0) —
        // combinada con $color->alpha en PdfCanvas::fillText()/Paint\Painter::paintUnderline()
        // (Color::withOpacity()), nunca horneada en $color (mismo motivo que BoxFragment::
        // $opacity: no debe filtrarse a un color HEREDADO por un descendiente).
        public float $opacity = 1.0,
    ) {}

    public function rect(): Rect
    {
        return $this->rect;
    }
}
