<?php

declare(strict_types=1);

namespace Pliego\Style;

/**
 * M7-T3 (css-lists-3 §3, reducido): los 5 valores soportados de list-style-type. INHERITS (CSS
 * 2.2 §12.5.1 / css-lists-3 §3: list-style-type es una propiedad heredada, a diferencia de la
 * mayoría de propiedades de caja de ComputedStyle) — ver ComputedStyle::$listStyleType y su rama
 * en compute(), que cae a $parent->listStyleType cuando no hay declaración propia, nunca al
 * initial value directamente salvo en la raíz del árbol (ComputedStyle::root()).
 *
 * Initial value real de CSS es 'disc' — ComputedStyle::root() lo fija así; UserAgentStylesheet
 * refuerza lo mismo de forma explícita vía `ul { list-style-type: disc }` (redundante con el
 * initial value pero documenta la intención igual que cualquier otra regla UA de esta hoja) y
 * añade `ol { list-style-type: decimal }` + los niveles de anidamiento `ul ul`/`ul ul ul` (circle/
 * square, ver su docblock).
 *
 * list-style-image (bullets con imagen) está fuera de alcance M7 (ver RESTRICCIONES GLOBALES del
 * milestone, "Excluidos M7 con warning") — no hay ningún case para ello aquí.
 */
enum ListStyleType: string
{
    case Disc = 'disc';
    case Circle = 'circle';
    case Square = 'square';
    case Decimal = 'decimal';
    case None = 'none';
}
