<?php

declare(strict_types=1);

namespace Pliego\Page;

/**
 * css-page-3 §6.5 / css-lists-3: los dos únicos counters con significado especial en el
 * contexto de paginación — `counter(page)` (número de la página actual) y `counter(pages)`
 * (total de páginas del documento). El total no se conoce en streaming (M2-T6); T7 lo resuelve
 * vía XObjects diferidos en Pdf\.
 */
enum CounterRef
{
    case Page;
    case Pages;
}
