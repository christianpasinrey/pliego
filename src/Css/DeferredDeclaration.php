<?php

declare(strict_types=1);

namespace Pliego\Css;

/**
 * M6-T4 (css-variables-1 §2-3): marca una declaración cuyo valor contiene var(...) — no se puede
 * tipar en tiempo de parseo porque el valor final depende de las custom properties heredadas del
 * elemento, que solo se conocen en StyleResolver (compute-time, por elemento). $rawValue conserva
 * el texto EXACTO tal como llegó de StylesheetParser (incluida la propiedad shorthand sin expandir,
 * p.ej. "margin" -> "var(--sp) 10px"); DeclarationParser::parse() se reinvoca sobre el texto YA
 * sustituido en StyleResolver::resolveDeferred(), reusando el mismo tipado/expansión de shorthand
 * que las declaraciones sin var() (fast path intacto, ver StylesheetParser).
 */
final readonly class DeferredDeclaration
{
    public function __construct(public string $rawValue) {}
}
