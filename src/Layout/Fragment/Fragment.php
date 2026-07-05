<?php

declare(strict_types=1);

namespace Pliego\Layout\Fragment;

use Pliego\Layout\Geometry\Rect;

interface Fragment
{
    // Deviación documentada (ver task-8-report.md): el brief especifica esta interfaz con
    // un property hook de PHP 8.4 (`public Rect $rect { get; }`), sintaxis válida que PHP
    // y PHPStan aceptan y que una propiedad `readonly` normal satisface. Sin embargo el
    // parser interno de deptrac.phar (nikic/php-parser v4.9-dev, sin soporte de hooks)
    // no puede parsear el fichero y lo marca "Syntax Error", dejando toda dependencia sobre
    // Fragment como "uncovered" y rompiendo `--fail-on-uncovered`. Fallback mínimo autorizado
    // por el brief: método getter. BoxFragment/TextFragment conservan la propiedad pública
    // `$rect` (acceso directo `$fragment->rect->x` intacto en los tests) y además implementan
    // este método como delegado trivial.
    public function rect(): Rect;
}
