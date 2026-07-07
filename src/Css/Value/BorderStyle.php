<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/**
 * M2: solo solid|none eran soportados (el resto de estilos CSS 2.2 §8.5.3 generaban warning).
 *
 * M8-T4 (css-backgrounds-3 §4.3, ISO 32000-1 §8.4.3.6): += Dashed/Dotted -- pintados con el
 * operador de TRAZO (`S`, no `f`) a lo largo de la línea central del borde (rect/path insetado
 * width/2), con un patrón de guiones (`d`, ver Paint\Painter::dashPatternFor()) en vez del
 * relleno sólido de siempre. double/groove/ridge/inset/outset SIGUEN fuera de alcance (M2's
 * warning genérico de "estilo no soportado" sigue aplicando a esos).
 */
enum BorderStyle
{
    case None;
    case Solid;
    case Dashed;
    case Dotted;
}
