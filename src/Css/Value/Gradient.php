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
 *     keyword de lado (`to top`/`to right`/...) o de esquina (`to top right`/...) ya se convierte
 *     a este mismo ángulo en DeclarationParser::parseGradientDirection() -- las esquinas usan una
 *     aproximación de CAJA CUADRADA (45/135/225/315deg fijos), divergencia documentada frente al
 *     cálculo real dependiente del aspect-ratio de la caja (css-images-3 §3.4.2, "para cualquier
 *     caja"); ver el docblock de ese método para el porqué (fuera de alcance "reducido" de M8: el
 *     ángulo real de un `to bottom right` en una caja NO cuadrada depende de sus dimensiones en
 *     píxeles, que DeclarationParser -- capa Css\ -- nunca conoce).
 *   - Radial: SIN significado geométrico -- este M8 reducido solo soporta `circle at center`
 *     (ver GradientKind::Radial), así que $angleDeg queda en 0.0 por convención (ningún consumidor
 *     de Radial lo lee).
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
    ) {}
}
