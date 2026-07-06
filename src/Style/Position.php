<?php

declare(strict_types=1);

namespace Pliego\Style;

/**
 * M7-T6 (CSS 2.2 §9.4.3/§10, position reducido): Static (el initial value real) es SIEMPRE el
 * default de ComputedStyle::compute() cuando no hay declaración propia -- position NO hereda (CSS
 * 2.2 §9.3.1 no lo lista entre las propiedades heredadas de §6.1), así que nunca cae a
 * $parent->position (a diferencia de list-style-type, que sí lo hace).
 *
 * 'fixed' queda fuera de alcance de este motor (M8+, la repetición por página ya la cubren los
 * margin boxes de @page, ver el brief del milestone) y 'sticky' no tiene análogo sensato en un
 * motor de PAGINACIÓN ESTÁTICA (no hay scroll) — AMBOS producen un warning en
 * DeclarationParser::parse() (keyword no reconocido dentro de la lista permitida de 'position') y
 * colapsan silenciosamente a Static, el MISMO resultado observable que si la propiedad no se
 * hubiera declarado en absoluto (ninguna rama especial hace falta aquí ni en ComputedStyle).
 */
enum Position
{
    case Static;
    case Relative;
    case Absolute;
}
