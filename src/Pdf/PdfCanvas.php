<?php

declare(strict_types=1);

namespace Pliego\Pdf;

use Pliego\Css\Value\Color;
use Pliego\Css\Value\Gradient;
use Pliego\Css\Value\GradientKind;
use Pliego\Css\Value\GradientStop;
use Pliego\Layout\Fragment\BorderRadius;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;
use Pliego\Page\PaperSize;
use Pliego\Paint\Canvas;

/** Canvas PDF: px CSS (origen arriba-izda) => pt PDF (origen abajo-izda, ×0.75). */
final class PdfCanvas implements Canvas
{
    private const float PX_TO_PT = 0.75;
    /** css-backgrounds-3 §5 / M8-T2: constante estándar para aproximar un cuarto de círculo con
     * UNA curva Bézier cúbica (4 curvas cubren las 4 esquinas de un rect) -- error máximo ~0.03%
     * del radio, imperceptible. Ver PdfCanvas::roundedRectPathOps(). */
    private const float BEZIER_K = 0.5522847498;
    private string $ops = '';
    /** @var array<string, int> resource name (e.g. "XO1") => object id, used by THIS page only */
    private array $xobjectRefs = [];

    public function __construct(
        private readonly PdfWriter $writer,
        private readonly FontRegistry $fonts,
        private readonly ImageRegistry $images,
        private readonly PaperSize $paper,
        private readonly float $offsetX,
        private readonly float $offsetY,
    ) {}

    public function beginPage(): void
    {
        $this->ops = '';
        $this->xobjectRefs = [];
    }

    public function endPage(): void
    {
        // M2-T7's deferred-form XObjects (XO*, from placeXObject()) and M3-T4's image XObjects
        // (Im*, from drawImage()) share this page's /Resources /XObject dict — distinct name
        // prefixes, so the merge can never collide.
        //
        // M6-T5: extGStatePageResources() follows the SAME "accumulates globally, every page
        // over-includes" convention as fonts/images pageResources() (see PdfWriter) — a page
        // painted before a later /GSn was ever registered simply lists fewer entries; harmless
        // (a PDF Resources dict is allowed to list resources this page's content stream never
        // references, ISO 32000-1 §7.8.3).
        $this->writer->addPage(
            $this->paper->widthPx() * self::PX_TO_PT,
            $this->paper->heightPx() * self::PX_TO_PT,
            $this->ops,
            $this->fonts->pageResources(),
            [...$this->xobjectRefs, ...$this->images->pageResources()],
            $this->writer->extGStatePageResources(),
            // M8-T3: mismo patrón "acumula globalmente, sobre-incluye en cada página" que
            // extGStatePageResources() — ver el docblock de PdfWriter::shadingPageResources().
            $this->writer->shadingPageResources(),
        );
    }

    public function fillRect(Rect $rect, Color $color): void
    {
        $x = ($rect->x + $this->offsetX) * self::PX_TO_PT;
        $y = ($this->paper->heightPx() - ($rect->y + $this->offsetY) - $rect->height) * self::PX_TO_PT;
        $body = sprintf(
            "%s %.2F %.2F %.2F %.2F re f\n",
            $this->rg($color),
            $x,
            $y,
            $rect->width * self::PX_TO_PT,
            $rect->height * self::PX_TO_PT,
        );
        $this->emitWithAlpha($color->alpha, $body);
    }

    /**
     * M8-T2 (css-backgrounds-3 §5): idéntico a fillRect() salvo por el path -- roundedRectPathOps()
     * (4 líneas + hasta 4 curvas Bézier, k=BEZIER_K) en vez de un `re` puro, cerrado con `f`
     * (nonzero winding, un único subpath simple no necesita even-odd). Painter solo llama a este
     * método cuando el BorderRadius del fragmento NO es zero (ver su docblock) -- el caso
     * zero-radius sigue siendo fillRect(), byte a byte idéntico a antes de esta tarea.
     */
    public function fillRoundedRect(Rect $rect, BorderRadius $radius, Color $color): void
    {
        $body = $this->rg($color) . "\n" . $this->roundedRectPathOps($rect, $radius) . "f\n";
        $this->emitWithAlpha($color->alpha, $body);
    }

    /**
     * M8-T2 (ISO 32000-1 §8.5.3.1, "Even-Odd Rule"): un ÚNICO path con DOS subpaths cerrados
     * (outer, luego inner) rellenado con `f*` -- la regla par-impar cuenta cruces de raya: un
     * punto DENTRO del outer pero FUERA del inner cruza el path UNA vez (impar -> se pinta); un
     * punto dentro de AMBOS (el hueco interior) cruza DOS veces (par -> NO se pinta). El resultado
     * es exactamente el anillo (outer menos inner) sin que este método tenga que calcular esa
     * resta geométricamente -- la regla del propio operador de pintado la resuelve. Sin `q`/`W n`
     * de por medio: esto PINTA directamente (a diferencia de clipRoundedRect()).
     */
    public function fillRoundedRectRing(Rect $outerRect, BorderRadius $outerRadius, Rect $innerRect, BorderRadius $innerRadius, Color $color): void
    {
        $body = $this->rg($color) . "\n"
            . $this->roundedRectPathOps($outerRect, $outerRadius)
            . $this->roundedRectPathOps($innerRect, $innerRadius)
            . "f*\n";
        $this->emitWithAlpha($color->alpha, $body);
    }

    /**
     * M8-T3 (ISO 32000-1 §8.7.4.5, shadings; css-images-3 §3.1 reducido): pinta $gradient dentro
     * de $rect -- `q`, recorte al rect (rectangular o de esquinas redondeadas si $radius no es
     * cero/null, mismo path Bézier que fillRoundedRect(), ver roundedRectPathOps()), `/ShN sh`
     * (ISO 32000-1 §8.7.4.2: el operador `sh` PINTA el shading directamente dentro del clipping
     * path actual, sin necesidad de un patrón/`scn` -- el shading LLENA lo que sea que esté
     * recortado), `Q`. El shading en sí (registerShading()/buildShadingDict()) se dedup por
     * FIRMA de contenido (rect + Gradient, ver gradientSignature()) -- dos elementos con el mismo
     * rect y el mismo Gradient CSS comparten un único objeto /Shading, sin recalcular ni
     * reescribir su(s) función(es) subyacente(s).
     */
    public function paintGradient(Rect $rect, Gradient $gradient, ?BorderRadius $radius = null): void
    {
        $signature = $this->gradientSignature($rect, $gradient);
        $name = $this->writer->shadingResourceName($signature, fn(): string => $this->buildShadingDict($rect, $gradient));

        $this->ops .= "q\n";
        if ($radius !== null && !$radius->isZero()) {
            $this->ops .= $this->roundedRectPathOps($rect, $radius) . "W n\n";
        } else {
            $x = ($rect->x + $this->offsetX) * self::PX_TO_PT;
            $y = ($this->paper->heightPx() - ($rect->y + $this->offsetY) - $rect->height) * self::PX_TO_PT;
            $this->ops .= sprintf(
                "%.2F %.2F %.2F %.2F re W n\n",
                $x,
                $y,
                $rect->width * self::PX_TO_PT,
                $rect->height * self::PX_TO_PT,
            );
        }
        $this->ops .= "/$name sh\n";
        $this->ops .= "Q\n";
    }

    /**
     * Firma de dedup: rect (redondeado a 2 decimales, la misma precisión con la que las /Coords
     * se escribirán en el PDF -- dos rects que solo difieren más allá de esa precisión producirían
     * bytes IDÉNTICOS de todas formas, así que compartir el shading es correcto) + kind + ángulo +
     * cada stop (color + posición). Una cadena PLANA (sin serialize()/json_encode(), evita
     * dependencias de formato) es suficiente: solo se usa como clave de un array, nunca se
     * decodifica.
     */
    private function gradientSignature(Rect $rect, Gradient $gradient): string
    {
        $parts = [
            $gradient->kind->name,
            sprintf('%.2F', $gradient->angleDeg),
            sprintf('%.2F,%.2F,%.2F,%.2F', $rect->x, $rect->y, $rect->width, $rect->height),
        ];
        foreach ($gradient->stops as $stop) {
            $parts[] = sprintf('%d:%d:%d@%.2F', $stop->color->r, $stop->color->g, $stop->color->b, $stop->positionPct ?? 0.0);
        }
        return implode('|', $parts);
    }

    /**
     * ISO 32000-1 §8.7.4.5.3 (Type 2, axial)/§8.7.4.5.4 (Type 3, radial): construye el /Shading
     * dict COMPLETO -- incluye allocar+escribir el/los /Function subyacente(s) (buildFunctionDict())
     * ANTES de referenciarlos por object id, vía $this->writer directamente (mismo PdfWriter que
     * ya posee el objectId reservado para ESTE shading, ver PdfWriter::registerShading() -- los
     * objetos de función se escriben con ids MENORES o mayores sin que el orden de escritura en el
     * stream importe, ISO 32000-1 no exige orden ascendente de object id).
     */
    private function buildShadingDict(Rect $rect, Gradient $gradient): string
    {
        $functionId = $this->writeFunctionDict($gradient->stops);
        if ($gradient->kind === GradientKind::Linear) {
            [[$x0, $y0], [$x1, $y1]] = $this->linearGradientEndpointsPt($rect, $gradient->angleDeg);
            $coords = sprintf('%.2F %.2F %.2F %.2F', $x0, $y0, $x1, $y1);
            return "<< /ShadingType 2 /ColorSpace /DeviceRGB /Coords [$coords] /Function $functionId 0 R /Extend [true true] >>";
        }
        [$cx, $cy, $r] = $this->radialGradientGeometryPt($rect);
        $coords = sprintf('%.2F %.2F 0 %.2F %.2F %.2F', $cx, $cy, $cx, $cy, $r);
        return "<< /ShadingType 3 /ColorSpace /DeviceRGB /Coords [$coords] /Function $functionId 0 R /Extend [true true] >>";
    }

    /**
     * css-images-3 §3.4.2 ("abstract gradient line"): el vector de dirección se computa en
     * espacio PX CSS (origen arriba-izquierda, Y crece hacia abajo -- $angleDeg 0=arriba,
     * 90=derecha, igual convención que Css\Value\Gradient) contra el CENTRO de $rect, con
     * media-longitud = (W·|sin(θ)|+H·|cos(θ)|)/2 (fórmula estándar del "gradient line length" --
     * hand-verificable: a 0deg da H/2 exacto (línea vertical completa), a 90deg da W/2 exacto, a
     * 45deg en una caja CUADRADA da la diagonal completa, ver PdfCanvasTest). El punto de INICIO
     * (0% stop) queda en la dirección OPUESTA al ángulo, el de FIN (100%) en la dirección del
     * ángulo -- de ahí que 0deg produzca "bottom→top" (el punto de abajo es el 0%, el de arriba el
     * 100%, coherente con "0deg = to top").
     *
     * Los dos puntos, en PX CSS, se convierten a PDF pt con la MISMA transformación por-punto que
     * strokeLine() ya usa para sus dos extremos (flip de Y + offsetX/offsetY + escala 0.75) --
     * nunca se piensa en términos de "voltear el ángulo": son dos puntos literales que pasan por
     * la conversión de coordenadas de siempre.
     *
     * @return array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}
     */
    private function linearGradientEndpointsPt(Rect $rect, float $angleDeg): array
    {
        $cx = $rect->x + $rect->width / 2.0;
        $cy = $rect->y + $rect->height / 2.0;
        $rad = deg2rad($angleDeg);
        $dx = sin($rad);
        $dy = -cos($rad);
        $halfLen = ($rect->width * abs(sin($rad)) + $rect->height * abs(cos($rad))) / 2.0;

        $startX = $cx - $halfLen * $dx;
        $startY = $cy - $halfLen * $dy;
        $endX = $cx + $halfLen * $dx;
        $endY = $cy + $halfLen * $dy;

        return [$this->toPagePointPt($startX, $startY), $this->toPagePointPt($endX, $endY)];
    }

    /**
     * css-images-3 §3.1 reducido: SIEMPRE circle-at-center -- centro de $rect, radio = distancia al
     * corner MÁS LEJANO (default real "farthest-corner" del spec para un radial-gradient sin
     * tamaño explícito), suficiente para que el círculo cubra la caja entera. El radio (una
     * MAGNITUD, no un punto) se escala directamente por PX_TO_PT -- sin distorsión posible: la
     * conversión px->pt de este motor es una escala UNIFORME (mismo factor en ambos ejes, ver
     * PX_TO_PT), a diferencia de un rect con aspect ratio que SÍ necesita las 2 coordenadas del
     * punto por separado.
     *
     * @return array{0: float, 1: float, 2: float} [cxPt, cyPt, rPt]
     */
    private function radialGradientGeometryPt(Rect $rect): array
    {
        $cx = $rect->x + $rect->width / 2.0;
        $cy = $rect->y + $rect->height / 2.0;
        $rPx = sqrt(($rect->width / 2.0) ** 2 + ($rect->height / 2.0) ** 2);
        [$cxPt, $cyPt] = $this->toPagePointPt($cx, $cy);
        return [$cxPt, $cyPt, $rPx * self::PX_TO_PT];
    }

    /** Mismo flip de Y + offsetX/offsetY + escala 0.75 que fillRect()/strokeLine() usan para un
     *  punto suelto en px CSS -- convierte a PDF pt (origen abajo-izquierda).
     *
     * M8-T3: pasa por cleanZero() -- a diferencia de fillRect()/strokeLine() (cuyas coordenadas
     * de entrada son siempre exactas, rects/líneas declaradas), las endpoints de un gradiente
     * salen de sin()/cos() (ver linearGradientEndpointsPt()): un ángulo "limpio" como 45deg en una
     * caja cuadrada produce, en aritmética de coma flotante, un resultado matemáticamente CERO
     * pero representado como un residuo negativo minúsculo (p.ej. -7.1e-15) -- sin esta limpieza,
     * `sprintf('%.2F', ...)` lo formatea como el feo/confuso "-0.00" en vez de "0.00" en los bytes
     * del PDF (ISO 32000-1 no lo prohíbe, pero es ruido innecesario que un hand-computed test no
     * debería tener que tolerar).
     *
     * @return array{0: float, 1: float} */
    private function toPagePointPt(float $xPx, float $yPx): array
    {
        return [
            self::cleanZero(($xPx + $this->offsetX) * self::PX_TO_PT),
            self::cleanZero(($this->paper->heightPx() - ($yPx + $this->offsetY)) * self::PX_TO_PT),
        ];
    }

    /** Ver el docblock de toPagePointPt() -- normaliza un -0.0 residual de coma flotante (tras
     *  redondear a 2 decimales, la misma precisión de sprintf('%.2F', ...)) a un 0.0 limpio. */
    private static function cleanZero(float $value): float
    {
        $rounded = round($value, 2);
        return $rounded === 0.0 ? 0.0 : $rounded;
    }

    /**
     * ISO 32000-1 §7.10.2 (Type 2, exponential interpolation, N=1 -> lineal) para exactamente 2
     * stops; §7.10.3 (Type 3, stitching) para N>2 -- cada tramo entre stops consecutivos es su
     * PROPIA función Type 2 (Domain [0 1], C0/C1 = los 2 colores del tramo), y /Bounds son las
     * posiciones INTERIORES (ni la primera ni la última, ya implícitas en el Domain [0 1] del
     * stitching) expresadas como fracción 0-1 (positionPct/100 -- distributeStopPositions() en
     * DeclarationParser YA garantiza first=0/last=100, así que esta división es siempre exacta).
     * /Encode es [0 1] repetido por cada sub-función (cada tramo interpola linealmente dentro de
     * SU propio rango, ISO 32000-1 §7.10.3 nota: el remapeo real tramo->[0,1] ya lo hace /Bounds).
     *
     * Escribe la función (y, si Type 3, cada sub-función Type 2) inmediatamente y devuelve su
     * object id -- llamado SOLO en caché-miss de registerShading() (ver su docblock), así que
     * nunca escribe una función huérfana que ningún /Shading llegue a referenciar.
     *
     * @param list<GradientStop> $stops
     */
    private function writeFunctionDict(array $stops): int
    {
        $n = count($stops);
        if ($n === 2) {
            return $this->writeExponentialFunction($stops[0]->color, $stops[1]->color);
        }
        $subFunctionIds = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $subFunctionIds[] = $this->writeExponentialFunction($stops[$i]->color, $stops[$i + 1]->color);
        }
        $bounds = [];
        for ($i = 1; $i < $n - 1; $i++) {
            $bounds[] = sprintf('%.4F', ($stops[$i]->positionPct ?? 0.0) / 100.0);
        }
        $functionsRefs = implode(' ', array_map(static fn(int $id): string => "$id 0 R", $subFunctionIds));
        // Un par "0 1" por sub-función (ISO 32000-1 §7.10.3: /Encode remapea cada tramo [Bounds_i,
        // Bounds_i+1] al Domain [0 1] de SU PROPIA sub-función Type 2).
        $encodeStr = implode(' ', array_fill(0, $n - 1, '0 1'));
        $boundsStr = implode(' ', $bounds);
        $objectId = $this->writer->allocateObjectId();
        $this->writer->writeObject(
            $objectId,
            "<< /FunctionType 3 /Domain [0 1] /Functions [$functionsRefs] /Bounds [$boundsStr] /Encode [$encodeStr] >>",
        );
        return $objectId;
    }

    /** ISO 32000-1 §7.10.2, N=1 (interpolación lineal -- "exponential interpolation" con exponente
     *  1 es exactamente lineal, la ÚNICA variante que este motor necesita: M8 no tiene forma de
     *  declarar un exponente CSS custom). C0/C1 en 0-1 (mismo /255 que rg()/rgStroke()). */
    private function writeExponentialFunction(Color $c0, Color $c1): int
    {
        $objectId = $this->writer->allocateObjectId();
        $this->writer->writeObject(
            $objectId,
            sprintf(
                '<< /FunctionType 2 /Domain [0 1] /C0 [%.3F %.3F %.3F] /C1 [%.3F %.3F %.3F] /N 1 >>',
                $c0->r / 255,
                $c0->g / 255,
                $c0->b / 255,
                $c1->r / 255,
                $c1->g / 255,
                $c1->b / 255,
            ),
        );
        return $objectId;
    }

    /**
     * M7-T5 (ISO 32000-1 §8.5.4, "Clipping Path Operators"): `q` abre un scope propio (recortes
     * PDF son parte del graphics state, revertidos por el `Q` de restoreClip() -- nunca deben
     * "escaparse" al resto de la página), luego el rect en pt (misma transformación px CSS -> pt
     * PDF que fillRect: flip de Y + offsetX/offsetY + escala 0.75) se añade al path actual con
     * `re`, y `W n` lo fija como clipping path SIN pintarlo (`n` = "no-op paint", el operador de
     * pintado nulo -- el propio rect nunca se ve, solo recorta lo que venga después dentro de este
     * mismo scope).
     *
     * M8-T1 breadcrumb (preparando M8-T2, css-backgrounds-3 §5, BorderRadius): este `re` es un
     * rectángulo puro -- CORRECTO hoy porque overflow:hidden (el único caller, ver
     * Paint\Painter::paintFragment()) nunca se combina con border-radius (M8-T2 los introduce
     * juntos por primera vez). Un box con `border-radius` NO-CERO y `overflow: hidden` recorta a un
     * rectángulo de ESQUINAS REDONDEADAS (spec: el clip sigue la curva del border-box, no su bounding
     * box), así que esta llamada tendrá que sustituirse por un path Bézier (4 arcos, mismo criterio
     * que el `fillRoundedRect`/paths Bézier que M8-T2 añade a esta clase) cuando ESE box tenga un
     * BorderRadius no-cero -- `clipRect()` seguirá siendo válida tal cual para el caso común
     * (border-radius: 0, la inmensa mayoría de los overflow:hidden existentes), así que lo más
     * probable es que M8-T2 añada un `clipRoundedRect(Rect, BorderRadius)` NUEVO en vez de mutar
     * este método, y Painter decida cuál invocar según si el fragment trae radios no-cero.
     */
    public function clipRect(Rect $rect): void
    {
        $x = ($rect->x + $this->offsetX) * self::PX_TO_PT;
        $y = ($this->paper->heightPx() - ($rect->y + $this->offsetY) - $rect->height) * self::PX_TO_PT;
        $this->ops .= sprintf(
            "q\n%.2F %.2F %.2F %.2F re W n\n",
            $x,
            $y,
            $rect->width * self::PX_TO_PT,
            $rect->height * self::PX_TO_PT,
        );
    }

    /** Cierra el scope abierto por clipRect() -- ver su docblock. */
    public function restoreClip(): void
    {
        $this->ops .= "Q\n";
    }

    /**
     * M8-T2: variante de clipRect() para border-radius no-cero -- mismo scope q/W n (cerrado por
     * el MISMO restoreClip()) pero con el path de esquinas redondeadas de roundedRectPathOps() en
     * vez de un `re` puro, así que el recorte sigue la curva del border-box (ver el breadcrumb de
     * M8-T1 en clipRect(), que anunciaba este método).
     */
    public function clipRoundedRect(Rect $rect, BorderRadius $radius): void
    {
        $this->ops .= "q\n" . $this->roundedRectPathOps($rect, $radius) . "W n\n";
    }

    public function fillText(TextFragment $text): void
    {
        // M6-T5: fillText() recibe el TextFragment ENTERO (Painter no lo intercepta, ver su
        // docblock) — combina $text->color con $text->opacity AQUÍ, con el mismo Color::
        // withOpacity() que Painter usa para fillRect/strokeLine.
        $color = $text->color->withOpacity($text->opacity);
        if ($color->alpha !== null && $color->alpha <= 0.0) {
            return; // completamente transparente: no pinta nada (ni registra glifos).
        }
        $x = ($text->rect->x + $this->offsetX) * self::PX_TO_PT;
        $baseline = ($this->paper->heightPx() - ($text->baselineY + $this->offsetY)) * self::PX_TO_PT;
        $resourceName = $this->fonts->resourceNameFor($text->faceKey);
        $hex = $this->fonts->embedderFor($text->faceKey)->encode($text->text);
        $body = sprintf(
            "BT /%s %.2F Tf %s %.2F %.2F Td <%s> Tj ET\n",
            $resourceName,
            $text->fontSizePx * self::PX_TO_PT,
            $this->rg($color),
            $x,
            $baseline,
            $hex,
        );
        $this->emitWithAlpha($color->alpha, $body);
    }

    /**
     * M8-T4: += $dashPattern/$roundCap (ambos opcionales, default "línea sólida") -- ver el
     * docblock del contrato en Paint\Canvas. Cuando ambos están en su default ([]/false, el caso
     * PREEXISTENTE del subrayado de texto), $needsScope es false y el cuerpo emitido es BYTE A
     * BYTE idéntico al de antes de esta tarea (mismo `%s\n%.2F w\n%.2F %.2F m %.2F %.2F l S\n`,
     * sin ningún `q`/`Q` extra). Con dash/cap, TODO el estado de trazo (w/d/J) se envuelve en su
     * propio `q ... Q` -- sin esto, un `d`/`J` fijado aquí sería PERSISTENTE en el graphics state
     * de la página (ISO 32000-1 §8.4: igual que `rg`/`RG`, sobrevive hasta el próximo cambio) y
     * podría filtrarse a un strokeLine() posterior en la MISMA página (p.ej. un subrayado de texto
     * pintado después de un borde dashed) que nunca pidió dash/cap propio.
     *
     * @param list<float> $dashPattern
     */
    public function strokeLine(float $x1, float $y1, float $x2, float $y2, float $widthPx, Color $color, array $dashPattern = [], bool $roundCap = false): void
    {
        $px1 = ($x1 + $this->offsetX) * self::PX_TO_PT;
        $py1 = ($this->paper->heightPx() - ($y1 + $this->offsetY)) * self::PX_TO_PT;
        $px2 = ($x2 + $this->offsetX) * self::PX_TO_PT;
        $py2 = ($this->paper->heightPx() - ($y2 + $this->offsetY)) * self::PX_TO_PT;
        $needsScope = $dashPattern !== [] || $roundCap;
        $body = ($needsScope ? "q\n" : '') . sprintf(
            "%s\n%.2F w\n",
            $this->rgStroke($color),
            $widthPx * self::PX_TO_PT,
        );
        if ($needsScope) {
            $body .= $this->dashOp($dashPattern) . ($roundCap ? "1 J\n" : '');
        }
        $body .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $px1, $py1, $px2, $py2);
        $body .= $needsScope ? "Q\n" : '';
        $this->emitWithAlpha($color->alpha, $body);
    }

    /**
     * M8-T4 (css-backgrounds-3 §4.3, ISO 32000-1 §8.4.3.6): borde UNIFORME dashed/dotted SIN
     * border-radius -- traza (`S`) $rect (YA la línea CENTRAL del borde, ver el docblock del
     * contrato) como un `re` puro con `w`/`d`/`J` propios, envuelto en su propio `q`/`Q` (mismo
     * motivo que strokeLine(): sin esto, el `d`/`J` fijado aquí se filtraría a cualquier op
     * posterior en la misma página).
     *
     * @param list<float> $dashPattern
     */
    public function strokeRect(Rect $rect, float $widthPx, Color $color, array $dashPattern, bool $roundCap): void
    {
        $x = ($rect->x + $this->offsetX) * self::PX_TO_PT;
        $y = ($this->paper->heightPx() - ($rect->y + $this->offsetY) - $rect->height) * self::PX_TO_PT;
        $body = "q\n" . $this->rgStroke($color) . "\n"
            . sprintf("%.2F w\n", $widthPx * self::PX_TO_PT)
            . $this->dashOp($dashPattern)
            . ($roundCap ? "1 J\n" : '')
            . sprintf("%.2F %.2F %.2F %.2F re S\n", $x, $y, $rect->width * self::PX_TO_PT, $rect->height * self::PX_TO_PT)
            . "Q\n";
        $this->emitWithAlpha($color->alpha, $body);
    }

    /**
     * M8-T4: variante de strokeRect() para un borde UNIFORME dashed/dotted CON border-radius --
     * mismo path Bézier de roundedRectPathOps() que fillRoundedRect(), trazado (`S`) en vez de
     * relleno (`f`), con el mismo envoltorio `q`/`Q` de w/d/J que strokeRect()/strokeLine().
     *
     * @param list<float> $dashPattern
     */
    public function strokeRoundedRect(Rect $rect, BorderRadius $radius, float $widthPx, Color $color, array $dashPattern, bool $roundCap): void
    {
        $body = "q\n" . $this->rgStroke($color) . "\n"
            . sprintf("%.2F w\n", $widthPx * self::PX_TO_PT)
            . $this->dashOp($dashPattern)
            . ($roundCap ? "1 J\n" : '')
            . $this->roundedRectPathOps($rect, $radius) . "S\n"
            . "Q\n";
        $this->emitWithAlpha($color->alpha, $body);
    }

    /**
     * ISO 32000-1 §8.4.3.6: `[dashArray] dashPhase d` -- $dashPatternPx (longitudes on/off en px
     * CSS, ver Paint\Painter::dashPatternFor()) se convierte a pt con la MISMA escala PX_TO_PT que
     * cualquier otra longitud de esta clase; la fase siempre es 0 (adjudicación M8: ningún estilo
     * soportado necesita desplazar la fase del patrón). Array vacío -> cadena vacía (ningún
     * operador `d` emitido, línea/path sólido -- el llamador solo invoca este helper cuando
     * $needsScope ya es true, pero se deja defensivo para cualquier otro uso futuro).
     *
     * @param list<float> $dashPatternPx
     */
    private function dashOp(array $dashPatternPx): string
    {
        if ($dashPatternPx === []) {
            return '';
        }
        $parts = array_map(
            static fn(float $v): string => sprintf('%.2F', $v * self::PX_TO_PT),
            $dashPatternPx,
        );
        return '[' . implode(' ', $parts) . "] 0 d\n";
    }

    /**
     * M2-T7: draws text at page-absolute px coordinates (top-left origin), bypassing offsetX/
     * offsetY — @page margin boxes are positioned relative to the whole page, not the content
     * area those offsets represent. Used for margin-box labels painted directly (no
     * counter(pages), so no need to defer — see MarginBoxPainter).
     */
    public function fillTextAtPage(float $xPx, float $baselinePx, string $text, float $fontSizePx, Color $color, string $faceKey): void
    {
        $x = $xPx * self::PX_TO_PT;
        $baseline = ($this->paper->heightPx() - $baselinePx) * self::PX_TO_PT;
        $resourceName = $this->fonts->resourceNameFor($faceKey);
        $hex = $this->fonts->embedderFor($faceKey)->encode($text);
        $this->ops .= sprintf(
            "BT /%s %.2F Tf %s %.2F %.2F Td <%s> Tj ET\n",
            $resourceName,
            $fontSizePx * self::PX_TO_PT,
            $this->rg($color),
            $x,
            $baseline,
            $hex,
        );
    }

    /**
     * M2-T7: places a deferred Form XObject (PdfWriter::defer()) at page-absolute px coordinates
     * — `q 1 0 0 1 x y cm /XOn Do Q` (ISO 32000-1 §8.10.2) — and registers it as this page's
     * resource. ($xPx, $bottomYPx) is the BOTTOM-LEFT corner of the XObject's own BBox in page px
     * space (top-left origin, Y grows down — so "bottom" means the LARGER px y); PDF space has Y
     * grow up from that same corner, matching the XObject's own BBox starting at (0,0).
     */
    public function placeXObject(DeferredXObject $xobject, float $xPx, float $bottomYPx): void
    {
        $x = $xPx * self::PX_TO_PT;
        $y = ($this->paper->heightPx() - $bottomYPx) * self::PX_TO_PT;
        $this->ops .= sprintf("q 1 0 0 1 %.2F %.2F cm /%s Do Q\n", $x, $y, $xobject->name);
        $this->xobjectRefs[$xobject->name] = $xobject->objectId;
    }

    /**
     * M3-T4: draws the image XObject for $imageKey (ImageRegistry::xobjectFor(), lazy + memoized
     * — the same imageKey drawn twice reuses the same XObject) scaled to fill $rectPx (content
     * box px, same coordinate space as fillRect/fillText: offsetX/offsetY-relative, top-left
     * origin) — `q wPt 0 0 hPt xPt yPt cm /ImN Do Q` (ISO 32000-1 §8.10.2: the image's own
     * (0,0)-(1,1) unit square is remapped to the destination rect by the `cm` matrix; no
     * translation/rotation needed beyond the width/height scale + origin offset). $xPt/$yPt are
     * the DESTINATION rect's bottom-left corner in PDF space (Y grows up): the same flip used by
     * fillRect (y = pageHeightPx - rectPx.y - rectPx.height, offset-adjusted, then ×0.75).
     *
     * M6-T5: $opacity < 1.0 inserts `/GSn gs` right after the opening `q` — same q/Q scope the
     * image draw already used for its `cm` matrix, so no extra wrapping is needed; opacity<=0
     * (fully transparent) paints NOTHING — not even the XObject registration/`Do` call, same
     * "transparent skips the op entirely" rule as fillRect/fillText/strokeLine (see
     * emitWithAlpha()).
     */
    public function drawImage(Rect $rectPx, string $imageKey, float $opacity = 1.0): void
    {
        if ($opacity <= 0.0) {
            return;
        }
        $ref = $this->images->xobjectFor($imageKey);
        $xPt = ($rectPx->x + $this->offsetX) * self::PX_TO_PT;
        $yPt = ($this->paper->heightPx() - ($rectPx->y + $this->offsetY) - $rectPx->height) * self::PX_TO_PT;
        $wPt = $rectPx->width * self::PX_TO_PT;
        $hPt = $rectPx->height * self::PX_TO_PT;
        // El caso opaco (la inmensa mayoría) conserva el formato BYTE-A-BYTE anterior a esta tarea
        // ("q %.2F..." con espacio, sin salto de línea) — solo opacity<1.0 inserta el `gs` tras un
        // salto de línea propio, en un formato distinto.
        $this->ops .= $opacity < 1.0
            ? sprintf("q\n/%s gs\n%.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $this->writer->extGStateResourceName($opacity), $wPt, $hPt, $xPt, $yPt, $ref->name)
            : sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $wPt, $hPt, $xPt, $yPt, $ref->name);
        $this->xobjectRefs[$ref->name] = $ref->objectId;
    }

    /**
     * ISO 32000-1 §8.4.5 (ExtGState /ca /CA): wraps $body in its OWN q/Q scope with a `/GSn gs`
     * right before it, whenever $alpha is set and less than fully opaque — `gs` sets PERSISTENT
     * graphics state (ISO 32000-1 §8.4: unlike `rg`/`RG`, which only affect the NEXT painting
     * operation, an alpha set by `gs` stays in effect for every subsequent op until changed or
     * until the enclosing q/Q scope ends) — wrapping every alpha'd op in its own q/Q, rather than
     * emitting a single `gs` and trusting every op after it to also want that alpha (or resetting
     * to an opaque GS1 afterwards), keeps each op's alpha local and impossible to leak into an
     * unrelated later op. A fully-opaque $alpha (null or >=1.0) is the common case (the vast
     * majority of colors) and emits $body completely unchanged — zero bytes of overhead,
     * byte-for-byte identical to the pre-M6-T5 output. $alpha<=0.0 (fully transparent — the
     * 'transparent' keyword, or opacity multiplying a color down to 0) paints NOTHING: not even
     * a q/Q pair, since a fully transparent op has no visible effect regardless of scoping.
     */
    private function emitWithAlpha(?float $alpha, string $body): void
    {
        if ($alpha === null || $alpha >= 1.0) {
            $this->ops .= $body;
            return;
        }
        if ($alpha <= 0.0) {
            return;
        }
        $name = $this->writer->extGStateResourceName($alpha);
        $this->ops .= "q\n/$name gs\n" . $body . "Q\n";
    }

    private function rg(Color $color): string
    {
        return sprintf('%.3F %.3F %.3F rg', $color->r / 255, $color->g / 255, $color->b / 255);
    }

    private function rgStroke(Color $color): string
    {
        return sprintf('%.3F %.3F %.3F RG', $color->r / 255, $color->g / 255, $color->b / 255);
    }

    /**
     * M8-T2 (css-backgrounds-3 §5): construye el path de un rect de esquinas redondeadas -- `m`
     * al punto justo a la derecha de la esquina superior-izquierda, luego (por cada lado, en
     * sentido horario: top, right, bottom, left) una línea recta hasta justo ANTES de la
     * siguiente esquina y, SI esa esquina tiene radio > 0, una curva `c` que la rodea; una esquina
     * con radio 0 se salta la curva por completo (la línea del lado siguiente ya llega exactamente
     * a ese punto, produciendo una esquina recta sin más código) -- así, una caja con SOLO alguna
     * esquina redondeada (el caso de InlineBoxFragment por slice, ver su docblock) produce el
     * número EXACTO de curvas que le corresponde, ni una de más. Termina con `h` (closepath),
     * SIN el operador de pintado/recorte final -- eso lo decide el llamador (`f` en
     * fillRoundedRect(), `f*` tras dos subpaths en fillRoundedRectRing(), `W n` en
     * clipRoundedRect()).
     *
     * Cada curva usa el control point estándar de aproximación de un cuarto de círculo con una
     * Bézier cúbica: partiendo del punto de tangencia sobre el lado recto, el primer control point
     * se desplaza k×r HACIA la esquina (k=BEZIER_K); el segundo, ya en el lado perpendicular, se
     * desplaza k×r DESDE la esquina hacia el punto final -- equivalente a "x ± r×(1-k)" medido
     * desde el vértice geométrico de la esquina (fórmula citada en el brief), la misma cantidad
     * expresada desde el otro extremo.
     */
    private function roundedRectPathOps(Rect $rect, BorderRadius $radius): string
    {
        $xLeft = ($rect->x + $this->offsetX) * self::PX_TO_PT;
        $xRight = $xLeft + $rect->width * self::PX_TO_PT;
        $yBottom = ($this->paper->heightPx() - ($rect->y + $this->offsetY) - $rect->height) * self::PX_TO_PT;
        $yTop = $yBottom + $rect->height * self::PX_TO_PT;
        $k = self::BEZIER_K;
        $tl = $radius->tl * self::PX_TO_PT;
        $tr = $radius->tr * self::PX_TO_PT;
        $br = $radius->br * self::PX_TO_PT;
        $bl = $radius->bl * self::PX_TO_PT;

        $ops = sprintf("%.2F %.2F m\n", $xLeft + $tl, $yTop);

        $ops .= sprintf("%.2F %.2F l\n", $xRight - $tr, $yTop);
        if ($tr > 0.0) {
            $ops .= sprintf(
                "%.2F %.2F %.2F %.2F %.2F %.2F c\n",
                $xRight - $tr + $k * $tr,
                $yTop,
                $xRight,
                $yTop - $tr + $k * $tr,
                $xRight,
                $yTop - $tr,
            );
        }

        $ops .= sprintf("%.2F %.2F l\n", $xRight, $yBottom + $br);
        if ($br > 0.0) {
            $ops .= sprintf(
                "%.2F %.2F %.2F %.2F %.2F %.2F c\n",
                $xRight,
                $yBottom + $br - $k * $br,
                $xRight - $br + $k * $br,
                $yBottom,
                $xRight - $br,
                $yBottom,
            );
        }

        $ops .= sprintf("%.2F %.2F l\n", $xLeft + $bl, $yBottom);
        if ($bl > 0.0) {
            $ops .= sprintf(
                "%.2F %.2F %.2F %.2F %.2F %.2F c\n",
                $xLeft + $bl - $k * $bl,
                $yBottom,
                $xLeft,
                $yBottom + $bl - $k * $bl,
                $xLeft,
                $yBottom + $bl,
            );
        }

        $ops .= sprintf("%.2F %.2F l\n", $xLeft, $yTop - $tl);
        if ($tl > 0.0) {
            $ops .= sprintf(
                "%.2F %.2F %.2F %.2F %.2F %.2F c\n",
                $xLeft,
                $yTop - $tl + $k * $tl,
                $xLeft + $tl - $k * $tl,
                $yTop,
                $xLeft + $tl,
                $yTop,
            );
        }

        return $ops . "h\n";
    }
}
