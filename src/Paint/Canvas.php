<?php

declare(strict_types=1);

namespace Pliego\Paint;

use Pliego\Css\Value\Color;
use Pliego\Css\Value\Gradient;
use Pliego\Layout\Fragment\BorderRadius;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;

/** Destino de pintado. Coordenadas en px CSS, origen arriba-izquierda. */
interface Canvas
{
    public function fillRect(Rect $rect, Color $color): void;

    /**
     * M8-T2 (css-backgrounds-3 §5): idéntico contrato a fillRect(), pero el path es un
     * rectángulo de esquinas redondeadas (4 líneas rectas + hasta 4 curvas Bézier, k=
     * 0.5522847498 -- ver PdfCanvas) en vez de un `re` puro. $radius YA llega resuelto a px y
     * clampeado (§5.5, ver Layout\Fragment\BorderRadius::fromCss()) -- este método nunca resuelve
     * % ni aplica el clamp de solapes, solo dibuja el path con los 4 radios tal cual se le pasan.
     * Painter solo llama a este método cuando $radius->isZero() es false; el caso zero sigue
     * usando fillRect() sin cambios (mismos bytes que antes de esta tarea).
     */
    public function fillRoundedRect(Rect $rect, BorderRadius $radius, Color $color): void;

    /**
     * M8-T2 (css-backgrounds-3 §5, bordes con border-radius uniforme): pinta el ANILLO entre
     * $outerRect/$outerRadius y $innerRect/$innerRadius (border-box exterior menos el border-box
     * interior, ya reducido por el ancho de borde -- ver Paint\Painter::paintBorders()) como UN
     * solo path con DOS subpaths (outer + inner) y regla par-impar (`f*`, ISO 32000-1 §8.5.3.1),
     * que el propio operador ya deja resuelto en "outer menos inner" sin necesidad de que este
     * Canvas calcule la resta geométricamente. Solo tiene sentido cuando los 4 lados de borde son
     * IDÉNTICOS (mismo ancho/estilo/color) -- un ancho de borde heterogéneo con radius cae a la
     * aproximación de 4 rects existente (ver Painter), que no usa este método.
     */
    public function fillRoundedRectRing(Rect $outerRect, BorderRadius $outerRadius, Rect $innerRect, BorderRadius $innerRadius, Color $color): void;

    /**
     * M8-T3 (css-images-3 §3.1 reducido; ISO 32000-1 §8.7.4.5 shadings): pinta $gradient dentro de
     * $rect (border-box del fragmento) -- recorte al rect ($radius no-cero recorta a esquinas
     * redondeadas, mismo path que fillRoundedRect(); null/cero recorta a un rect plano), luego el
     * shading LLENA ese clip por completo (`sh`, ISO 32000-1 §8.7.4.2). Pinta POR ENCIMA del
     * background-color (ver Paint\Painter::paintBackground()) -- ambos pueden coexistir.
     */
    public function paintGradient(Rect $rect, Gradient $gradient, ?BorderRadius $radius = null): void;

    public function fillText(TextFragment $text): void;

    /**
     * Segmento recto de $widthPx de grosor, en px CSS (p.ej. subrayado bajo la baseline, o un
     * lado de borde dashed/dotted heterogéneo -- ver Paint\Painter::paintBorderSide()).
     *
     * M8-T4 (ISO 32000-1 §8.4.3.6): += $dashPattern/$roundCap, AMBOS opcionales con default
     * "línea sólida" ([]/false) -- ningún caller preexistente a esta tarea (el subrayado de
     * texto) los pasa, así que su comportamiento/bytes son IDÉNTICOS a antes. $dashPattern es la
     * lista de longitudes on/off EN PX (Painter ya las calcula como múltiplos de $widthPx según
     * el BorderStyle del lado, ver Painter::dashPatternFor() -- este Canvas no conoce CSS, solo
     * dibuja lo que se le pasa); un array vacío pinta una línea SÓLIDA (sin `d`, mismos bytes que
     * antes de esta tarea). $roundCap emite el line cap redondeado (`1 J`, ISO 32000-1 §8.4.3.3)
     * en vez del butt cap por defecto del PDF -- BorderStyle::Dotted lo necesita para que cada
     * segmento de guion de longitud 0 del patrón `[0 2w] 0 d` se dibuje como un punto circular en
     * vez de desaparecer (un guion de longitud cero con cap butt no pinta nada).
     *
     * @param list<float> $dashPattern
     */
    public function strokeLine(float $x1, float $y1, float $x2, float $y2, float $widthPx, Color $color, array $dashPattern = [], bool $roundCap = false): void;

    /**
     * M8-T4 (css-backgrounds-3 §4.3, ISO 32000-1 §8.4.3.6): traza (operador `S`, NO `f`) el
     * rectángulo $rect como UN path cerrado con $dashPattern -- usado para un borde UNIFORME
     * dashed/dotted (los 4 lados comparten ancho/estilo/color, ver Painter::bordersUniform()) sin
     * border-radius: $rect YA llega como la línea CENTRAL del borde (el border-box insetado
     * $widthPx/2 en cada lado, ver Painter::paintUniformDashedBorder()), no el border-box en sí
     * -- trazar con `w`=$widthPx la línea central hace que el trazo cubra exactamente el mismo
     * ancho visual que el border-box declarado, centrado sobre él. Contraparte de fillRect() para
     * el caso "un solo path continuo, sin radio" (ver strokeRoundedRect() para el caso con radio).
     *
     * @param list<float> $dashPattern
     */
    public function strokeRect(Rect $rect, float $widthPx, Color $color, array $dashPattern, bool $roundCap): void;

    /**
     * M8-T4: variante de strokeRect() para un borde UNIFORME dashed/dotted CON border-radius --
     * traza el mismo path Bézier de esquinas redondeadas que fillRoundedRect()/
     * fillRoundedRectRing() (ver roundedRectPathOps()) en vez del `re` puro, así que el patrón de
     * guiones sigue la curva continuamente alrededor de las 4 esquinas (sin negociación de miter
     * en las esquinas entre lados de distinto color, M8 -- ver el docblock de Painter). $rect/
     * $radius YA llegan como la línea central (insetados $widthPx/2, radio reducido en la misma
     * medida) -- mismo criterio que strokeRect().
     *
     * @param list<float> $dashPattern
     */
    public function strokeRoundedRect(Rect $rect, BorderRadius $radius, float $widthPx, Color $color, array $dashPattern, bool $roundCap): void;

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

    /**
     * M7-T5 (css-overflow-3): abre un scope de recorte PDF (`q` + `x y w h re W n`, ISO 32000-1
     * §8.5.4) al rect BORDER-BOX $rect (px CSS) — TODO lo pintado después de esta llamada, hasta
     * el restoreClip() que la cierra, queda recortado a ese rectángulo. Usado por Paint\Painter
     * para envolver el pintado de los DESCENDIENTES de un BoxFragment con $clipsChildren === true
     * (overflow:hidden) — el fondo/borde de la propia caja NO necesita este scope (ya coincide con
     * $rect exactamente). Debe emparejarse SIEMPRE con una llamada a restoreClip() inmediatamente
     * después de pintar ese subárbol (mismo contrato q/Q que el resto de scopes de este Canvas).
     */
    public function clipRect(Rect $rect): void;

    /**
     * M8-T2: variante de clipRect() para overflow:hidden con border-radius no-cero -- mismo
     * contrato q/W n/Q (ver clipRect()) pero el path es el rectángulo de esquinas redondeadas
     * (ver fillRoundedRect()) en vez de un `re` puro, así que el recorte sigue la curva del
     * border-box en vez de su bounding box (css-backgrounds-3 §5, el breadcrumb de M8-T1 en
     * PdfCanvas::clipRect() anunciaba este método). Painter elige entre clipRect()/
     * clipRoundedRect() según $fragment->borderRadius->isZero().
     */
    public function clipRoundedRect(Rect $rect, BorderRadius $radius): void;

    /** Cierra el scope de recorte abierto por la llamada a clipRect() inmediatamente anterior
     * (`Q`) — ver su docblock. */
    public function restoreClip(): void;
}
