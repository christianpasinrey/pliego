<?php

declare(strict_types=1);

namespace Pliego\Paint;

use Pliego\Css\Value\Color;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;

/** Destino de pintado. Coordenadas en px CSS, origen arriba-izquierda. */
interface Canvas
{
    public function fillRect(Rect $rect, Color $color): void;

    public function fillText(TextFragment $text): void;

    /** Segmento recto de $widthPx de grosor, en px CSS (p.ej. subrayado bajo la baseline). */
    public function strokeLine(float $x1, float $y1, float $x2, float $y2, float $widthPx, Color $color): void;
}
