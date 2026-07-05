<?php

declare(strict_types=1);

namespace Pliego\Page;

/**
 * Contenido convertido de un margin box de @page (css-page-3 §6.5.3), p.ej.
 * `content: "Pagina " counter(page) " de " counter(pages)`. Cada parte es un literal de cadena
 * o una referencia a counter(page)/counter(pages) (T7 los resuelve al pintar; esta tarea solo
 * los deja tipados y en orden).
 */
final readonly class MarginBoxContent
{
    /** @param list<string|CounterRef> $parts */
    public function __construct(public array $parts) {}
}
