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

    /**
     * Pinta la imagen de $imageKey (ruta ya resuelta y verificada, ver ImageFragment) dentro de
     * $rectPx (content box del replaced element, px CSS). M3-T4: dedup por $imageKey delegado en
     * Pdf\ImageRegistry — pintar la misma imagen varias veces solo escribe un XObject.
     *
     * M6-T5: $opacity (0-1, default 1.0/opaco) es la opacity PROPIA del elemento <img>
     * (ImageFragment::$opacity) — una imagen no tiene un Color propio en el que combinarla (a
     * diferencia de fillRect/fillText/strokeLine, cuyo Color YA trae el alpha efectivo), así que
     * viaja como parámetro aparte hasta el ExtGState (ISO 32000-1 §8.4.5, ver PdfCanvas).
     */
    public function drawImage(Rect $rectPx, string $imageKey, float $opacity = 1.0): void;
}
