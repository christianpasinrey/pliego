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
     *
     * M7-T2 (CSS 2.2 §6.4.1): $userAgent marca el ORIGEN de la regla — true para las reglas de
     * Style\UserAgentStylesheet (parseadas por el MISMO StylesheetParser, luego re-etiquetadas
     * vía withOrigin(), ver esa clase), false (default) para cualquier regla de autor. El orden
     * completo de §6.4.1 con los tiers que este motor soporta es: UA normal < author normal <
     * author important — StyleResolver ordena PRIMERO por $important, LUEGO por $userAgent (UA
     * antes que autor dentro del mismo tier de importancia), y solo entonces por especificidad/
     * $order (ver StyleResolver::matchedDeclarationsAndCustomProperties()). No existe tier
     * "UA important" en este motor (la hoja UA nunca declara !important), así que $important=true
     * en una regla con $userAgent=true nunca ocurre en la práctica, pero el campo no lo impide a
     * propósito (evita una invariante artificial que StyleRule tendría que validar en runtime).
     */
    public function __construct(
        public ComplexSelector $selector,
        public array $declarations,
        public int $order,
        public bool $important = false,
        public bool $userAgent = false,
    ) {}

    /** Devuelve una copia idéntica salvo el origen — usado por UserAgentStylesheet::rules() para
     * re-etiquetar en bloque el resultado de un parseo normal de StylesheetParser (que siempre
     * produce $userAgent=false) sin duplicar la lógica de construcción de StyleRule. */
    public function withOrigin(bool $userAgent): self
    {
        return new self($this->selector, $this->declarations, $this->order, $this->important, $userAgent);
    }
}
