<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/**
 * M8 final-review Finding B (css-images-3 §3.4.2): which BOX CORNER a `to <side-or-corner>`
 * linear-gradient() direction pointed at -- DeclarationParser knows WHICH corner an author wrote
 * ("to bottom right", etc.) but NOT the real angle that corner implies, because the real angle
 * depends on the element's box aspect ratio (width/height in px), which the Css\ layer never sees
 * (Css\ only ever handles VALUES, never geometry -- see deptrac.yaml). Before this fix, Gradient
 * stored a FIXED 45/135/225/315deg approximation for the 4 corners (correct only for a square
 * box) baked in at parse time; this enum instead lets the corner survive, unresolved, all the way
 * to Pdf\PdfCanvas::paintGradient() -- the one place that DOES know the box's final pixel
 * dimensions -- where the true angle (90deg +/- atan2(height, width), see PdfCanvas::
 * resolveAngleDeg()) is computed at paint time, per box.
 */
enum GradientCorner
{
    case TopRight;
    case BottomRight;
    case BottomLeft;
    case TopLeft;
}
