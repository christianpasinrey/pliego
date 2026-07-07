<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/**
 * M8-T3 (css-images-3 §3.1 reducido): representación CRUDA (sin resolver contra ninguna caja
 * todavía -- eso ocurre en Pdf\PdfCanvas::paintGradient(), en tiempo de pintado, contra el
 * border-box final del fragmento) de un linear-gradient()/radial-gradient().
 *
 * $angleDeg tiene doble uso según $kind (interfaz del milestone, un único campo para ambos casos):
 *   - Linear: el ángulo CSS en grados (convención css-images-3 §3.4.2: 0deg = "to top", crece en
 *     sentido horario -- 90deg = "to right", 180deg = "to bottom", 270deg = "to left"). Un
 *     keyword de lado (`to top`/`to right`/...) ya se convierte a este mismo ángulo en
 *     DeclarationParser::parseGradientDirection() -- SIEMPRE correcto, un lado no depende del
 *     aspect-ratio de la caja. Un keyword de ESQUINA (`to top right`/...) también deja aquí un
 *     valor de $angleDeg (la aproximación de CAJA CUADRADA, 45/135/225/315deg fijos -- correcta
 *     SOLO cuando la caja es cuadrada) PERO, desde el fix de M8 final-review Finding B, esa
 *     aproximación ya NO es lo que se pinta: $corner (ver abajo) lleva la información real de QUÉ
 *     esquina se declaró, y Pdf\PdfCanvas::paintGradient() -- que sí conoce las dimensiones px
 *     finales de la caja, algo que esta capa Css\ nunca conoce (deptrac.yaml) -- recalcula el
 *     ángulo VERDADERO en tiempo de pintado a partir de $corner (ver PdfCanvas::resolveAngleDeg():
 *     90deg +/- atan2(height, width), degenerando exactamente a 45/135/225/315 en una caja
 *     cuadrada, así que $angleDeg sigue siendo válido como aproximación/fallback y como parte de
 *     la firma de dedup de PdfCanvas::gradientSignature(), pero deja de ser la fuente de verdad
 *     geométrica cuando $corner !== null).
 *   - Radial: SIN significado geométrico -- este M8 reducido solo soporta `circle at center`
 *     (ver GradientKind::Radial), así que $angleDeg queda en 0.0 por convención (ningún consumidor
 *     de Radial lo lee); $corner también queda null (nunca aplica a un radial-gradient()).
 *
 * $corner (M8 final-review Finding B): null cuando la dirección NO fue una esquina (ángulo
 * numérico, lado cardinal, o el default sin dirección declarada) -- en ese caso $angleDeg YA es
 * el ángulo real y definitivo, sin importar el aspect-ratio de la caja. No-null únicamente para
 * las 4 esquinas (`to top right`/`to bottom right`/`to bottom left`/`to top left`, en cualquier
 * orden de palabras) -- ver Css\Value\GradientCorner.
 *
 * $stops (ver @param en el constructor) trae SIEMPRE al menos 2 elementos (DeclarationParser
 * rechaza con warning cualquier gradiente con menos), YA con posiciones distribuidas (ver
 * GradientStop).
 */
final readonly class Gradient
{
    /** @param list<GradientStop> $stops */
    public function __construct(
        public GradientKind $kind,
        public float $angleDeg,
        public array $stops,
        public ?GradientCorner $corner = null,
    ) {}
}
