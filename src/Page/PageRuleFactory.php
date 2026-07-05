<?php

declare(strict_types=1);

namespace Pliego\Page;

use Pliego\Css\PageRuleData;

/**
 * Convierte la estructura cruda Css\PageRuleData (T2) al VO final Page\PageRule (M2-T6).
 * deptrac permite Page -> Css (ver deptrac.yaml), así que esta clase es la única autorizada a
 * leer Css\PageRuleData fuera de Css\ mismo.
 *
 * Instancia + drainWarnings(), igual convención que Css\DeclarationParser: los márgenes de
 * página ya vienen validados por StylesheetParser (solo Length válidos llegan a $margins), pero
 * $marginBoxes de un PageRuleData construido a mano (no necesariamente vía el parser) podría
 * traer una clave de posición no soportada — se descarta con warning aquí también, como
 * defensa en profundidad de la propia interfaz pública de Page\.
 */
final class PageRuleFactory
{
    /** css-page-3 §6.5.3: las 6 cajas de margen soportadas en M2. */
    private const array VALID_MARGIN_BOXES = [
        'top-left', 'top-center', 'top-right',
        'bottom-left', 'bottom-center', 'bottom-right',
    ];

    /** @var list<string> */
    private array $warnings = [];

    public function fromCssData(?PageRuleData $data): ?PageRule
    {
        if ($data === null) {
            return null;
        }
        $marginBoxes = [];
        foreach ($data->marginBoxes as $position => $parts) {
            if (!in_array($position, self::VALID_MARGIN_BOXES, true)) {
                $this->warnings[] = "Unsupported margin box position: $position";
                continue;
            }
            $marginBoxes[$position] = new MarginBoxContent(array_map($this->convertPart(...), $parts));
        }
        return new PageRule(
            $data->margins['top'] ?? null,
            $data->margins['right'] ?? null,
            $data->margins['bottom'] ?? null,
            $data->margins['left'] ?? null,
            $marginBoxes,
        );
    }

    private function convertPart(string $part): string|CounterRef
    {
        return match ($part) {
            'counter(page)' => CounterRef::Page,
            'counter(pages)' => CounterRef::Pages,
            default => $part,
        };
    }

    /** @return list<string> */
    public function drainWarnings(): array
    {
        $warnings = $this->warnings;
        $this->warnings = [];
        return $warnings;
    }
}
