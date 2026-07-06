<?php

declare(strict_types=1);

namespace Pliego\Css;

/**
 * selectors-3 §6.3: [attr], [attr=val], [attr~=val], [attr|=val], [attr^=val], [attr$=val],
 * [attr*=val]. El matching real de atributos llega en M6-T2 — en T1 solo se parsea y cuenta
 * para specificity (b += 1 por atributo, sin importar el operador).
 */
final readonly class AttributeSelector
{
    public function __construct(
        public string $name,
        public ?string $operator = null,
        public ?string $value = null,
    ) {}
}
