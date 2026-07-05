<?php

declare(strict_types=1);

namespace Pliego\Css;

use Pliego\Css\Value\Length;

/**
 * Estructura CRUDA (Css\) de un bloque @page — css-page-3. La conversión a Page\PageRule
 * (VO final consumido por el motor de paginación) es responsabilidad de T6; deptrac prohíbe
 * que Css\ dependa de Page\, así que esta clase se queda deliberadamente "tonta": solo tipos
 * ya disponibles en Css\Value.
 *
 * Forma elegida:
 * - $margins: únicamente los lados realmente declarados (margin shorthand o longhand
 *   margin-{side}); ausencia de una clave = "sin override" (T6 decide el fallback a
 *   Engine::margins()). @page no admite % en los márgenes de página en esta implementación
 *   (a diferencia de margin-* de caja, que sí admite % desde T2) — de ahí Length y no
 *   LengthPercentage, coherente con la interfaz de Page\PageRule (?Length).
 * - $marginBoxes: solo las 6 cajas de margen soportadas (top/bottom × left/center/right).
 *   Cada valor es la lista de "parts" del descriptor content de esa caja, en orden: cada
 *   elemento es un string literal (contenido de una cadena entre comillas, comillas ya
 *   despojadas) o uno de los dos sentinels 'counter(page)' / 'counter(pages)' (aún sin
 *   convertir a CounterRef — esa conversión también es de T6).
 */
final readonly class PageRuleData
{
    /**
     * @param array<string, Length> $margins claves posibles: top|right|bottom|left
     * @param array<string, list<string>> $marginBoxes claves posibles: top-left|top-center|
     *        top-right|bottom-left|bottom-center|bottom-right
     */
    public function __construct(
        public array $margins,
        public array $marginBoxes,
    ) {}
}
