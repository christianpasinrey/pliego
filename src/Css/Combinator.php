<?php

declare(strict_types=1);

namespace Pliego\Css;

/** selectors-3 §8: combinadores entre dos compuestos de un selector complejo. */
enum Combinator
{
    case Descendant;
    case Child;
    case NextSibling;
    case SubsequentSibling;
}
