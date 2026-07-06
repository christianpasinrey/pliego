<?php

declare(strict_types=1);

namespace Pliego\Layout\Text;

use Pliego\Css\WarningCollector;
use Pliego\Text\FontCatalog;

/**
 * M7-T2 (css-fonts-3 §5.3.1): resuelve la LISTA cruda de font-family de un ComputedStyle
 * (ComputedStyle::$fontFamily, ver su docblock) contra las familias REALMENTE registradas en un
 * FontCatalog, un nombre a la vez, en orden de preferencia — exactamente el algoritmo de fallback
 * de CSS ("Arial, "Helvetica Neue", sans-serif" -> la primera familia de la lista que exista
 * gana; un nombre desconocido se salta en silencio, nunca es un error).
 *
 * Vive en Layout (no en Style, ni en Text) a propósito: Style no puede depender de Text
 * (deptrac.yaml: `Style: [Css, Vendor]`, sin Text) — el mapeo de genéricos CSS (sans-serif/serif/
 * monospace) a una familia CONCRETA del catálogo es, en sí, una decisión de resolución de
 * estilos, pero solo puede tomarse donde FontCatalog vive, que es aquí (Layout: [Box, Style, Css,
 * Text]). InlineFlowContext e IntrinsicSizer comparten la MISMA lógica a través de esta clase en
 * vez de duplicarla cada uno en su propio faceFor().
 *
 * Genéricos soportados (el resto de la tabla css-fonts-3 §5.3.1 -- cursive/fantasy/system-ui --
 * no tiene cara registrada en este motor y cae al mismo camino "no resuelto" que un nombre
 * desconocido, sin warning propio: no son parte del contrato de esta tarea).
 */
final class FontFamilyResolver
{
    private const array GENERIC_FAMILIES = [
        'sans-serif' => 'default',
        'serif' => 'serif',
        'monospace' => 'monospace',
    ];

    public function __construct(
        private readonly FontCatalog $catalog,
        private readonly ?WarningCollector $warnings = null,
    ) {}

    /**
     * @param list<string> $families ComputedStyle::$fontFamily -- ya troceada/limpiada de
     *     comillas por DeclarationParser::parseFontFamily(), nunca vacía en la práctica
     *     (ComputedStyle::compute() cae a la lista heredada del padre cuando lo estaría).
     */
    public function resolve(array $families): string
    {
        foreach ($families as $raw) {
            $name = trim($raw);
            if ($name === '') {
                continue;
            }
            $target = self::GENERIC_FAMILIES[strtolower($name)] ?? $name;
            if ($this->catalog->hasFamily($target)) {
                return $target;
            }
        }
        // Ninguna familia de la lista está registrada (incluidos los genéricos que pudiera
        // contener) -- CSS 2.2 §15.3 termina en la familia "default" del UA cuando la lista
        // entera se agota; se avisa (una sola vez por render, ver WarningCollector::
        // addWarningOnce()) solo cuando la causa es un GENÉRICO sin cara registrada (serif/
        // monospace, si algún día dejaran de embeberse -- ver FontCatalog::withDefaults()): un
        // nombre de autor desconocido ("Comic Sans") es el caso normal de CSS ("se salta en
        // silencio"), no merece warning propio.
        foreach ($families as $raw) {
            $lower = strtolower(trim($raw));
            if ($lower === 'monospace' || $lower === 'serif') {
                $this->warnings?->addWarningOnce(
                    "missing-generic-font:$lower",
                    "Generic font family '$lower' has no registered face; falling back to 'default'",
                );
                break;
            }
        }
        return 'default';
    }
}
