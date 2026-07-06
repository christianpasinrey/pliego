<?php

declare(strict_types=1);

namespace Pliego\Css;

final readonly class StyleRule
{
    /**
     * @param array<string, mixed> $declarations claves canónicas => Length|Color|string (valor
     *   tipado), o (M6-T4) string cruda si la clave empieza por "--" (custom property, css-
     *   variables-1 §2, nunca tipada), o DeferredDeclaration si el valor original contenía
     *   var(...) (tipado diferido a StyleResolver, compute-time, ver StylesheetParser).
     */
    public function __construct(
        public ComplexSelector $selector,
        public array $declarations,
        public int $order,
    ) {}
}
