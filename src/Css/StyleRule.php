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
     *
     * M6 final-review fix (Finding 1, CSS 2.2 §6.4.2): $important marca el TIER completo de esta
     * instancia, no una declaración suelta — un bloque de origen con declaraciones mezcladas
     * (algunas !important, otras no) se parte en HASTA DOS StyleRule por selector, mismo
     * selector/orden de aparición, uno con $declarations = solo las !important
     * ($important=true) y otro con el resto ($important=false); ver StylesheetParser, que hace
     * el split ANTES de construir cada instancia (nunca hay una mezcla de tiers dentro de un
     * mismo StyleRule). StyleResolver ordena por ($important, especificidad, $order) — author-
     * important siempre gana sobre author-normal sin importar especificidad, exactamente igual
     * en ambos tiers si hay empate en importancia (no hay tier de user/UA important en este
     * motor, fuera de alcance).
     */
    public function __construct(
        public ComplexSelector $selector,
        public array $declarations,
        public int $order,
        public bool $important = false,
    ) {}
}
