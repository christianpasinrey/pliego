<?php

declare(strict_types=1);

namespace Pliego\Style;

use Pliego\Css\DeclarationParser;
use Pliego\Css\DeferredDeclaration;
use Pliego\Css\StyleRule;
use Pliego\Css\Value\Length;
use Pliego\Css\VarResolver;
use Pliego\Css\WarningCollector;

final class StyleResolver
{
    /** @param list<StyleSource> $sources */
    public function __construct(
        private readonly array $sources,
        // M6-T4: mismo patrón que Layout\*FormattingContext/Paginator (WarningCollector opcional
        // compartido, ver Engine) — recibe los warnings de var()/calc() que solo pueden detectarse
        // aquí (ciclos, referencias desconocidas, % en una propiedad sin soporte de %, división por
        // cero cuyo divisor depende de em/rem propios del elemento).
        private readonly ?WarningCollector $warnings = null,
    ) {
        $this->declarationParser = new DeclarationParser();
    }

    /** M6-T4: instancia ÚNICA reutilizada para reparsear declaraciones diferidas (var()) una vez
     * sustituidas — DeclarationParser es stateless entre llamadas salvo su buffer de warnings,
     * que se drena tras cada uso (ver resolveDeferred()); reusarla evita crear un objeto nuevo por
     * cada declaración diferida de cada elemento. */
    private readonly DeclarationParser $declarationParser;

    /**
     * M7-T1 housekeeping (M6 final-review finding): allRules() concatenaba TODAS las reglas de
     * TODAS las StyleSource en cada llamada — una vez POR ELEMENTO del documento, vía
     * matchedDeclarationsAndCustomProperties() — en vez de una vez por resolve(). A 500 reglas ×
     * 4000 elementos eso son 4000 concatenaciones de un array de 500, medido en ~841ms en la
     * review. Las StyleSource son inmutables durante un resolve() (constructor readonly, sin
     * setters), así que el resultado nunca cambia entre elementos del MISMO árbol — se computa una
     * única vez y se cachea aquí. NO puede ser readonly (a diferencia de $sources/$warnings/
     * $declarationParser arriba): necesita reescribirse a null en cada resolve() (ver ahí) para que
     * una segunda llamada a resolve() en la misma instancia (con las mismas $sources, pero
     * potencialmente invocada desde un test o Engine que reutiliza el resolver) no herede un
     * $rulesCache "atascado" de forma sorprendente — aunque en la práctica el resultado sería
     * idéntico (las fuentes no cambian), resetear el cache al empezar resolve() documenta la
     * invariante "el cache vive dentro del ámbito de UN resolve()" en vez de "vive para siempre en
     * la instancia", evitando que un futuro cambio (p.ej. StyleSource mutable) reintroduzca un bug
     * de cache obsoleto sin que nadie lo note aquí.
     *
     * @var list<StyleRule>|null
     */
    private ?array $rulesCache = null;

    /** css-values-3 §5.2: font-size initial value — base de rem hasta que se resuelva el
     * font-size real del documentElement (ver resolveRoot()). */
    private const float INITIAL_FONT_SIZE_PX = 16.0;

    public function resolve(\Dom\HTMLDocument $document): StyleMap
    {
        // M7-T1: ver docblock de $rulesCache — recalculado a demanda por allRules() en su primer
        // uso de ESTE resolve(), nunca reutilizado de una llamada anterior.
        $this->rulesCache = null;
        $map = new StyleMap();
        $root = $document->documentElement;
        if ($root !== null) {
            $this->resolveRoot($root, $map);
        }
        return $map;
    }

    /**
     * M6-T3: el font-size PROPIO del documentElement (html) es el remBase para TODO el árbol,
     * pero un rem EN ESE MISMO font-size no puede resolverse contra sí mismo (circular) — css-
     * values-3 §5.2 lo fija contra el initial value (16px) en ese único caso. Se calcula el
     * remBase UNA SOLA VEZ con ComputedStyle::resolveFontSizePx() (el mismo cálculo que hace
     * compute() por dentro, ver ahí) y se sustituye la declaración 'font-size' del root por su
     * resultado YA RESUELTO (Length) antes de llamar a compute() — así compute() nunca
     * reinterpreta un rem/em/% simbólico contra un remBase distinto al usado para derivarlo
     * (llamar a compute() dos veces con remBases distintos duplicaría un font-size en rem, por
     * ejemplo html{font-size:2rem} acabaría en 2×32=64 en vez de 2×16=32 en la segunda pasada).
     */
    private function resolveRoot(\Dom\Element $root, StyleMap $map): void
    {
        $syntheticParent = ComputedStyle::root();
        [$declarations, $customProperties] = $this->matchedDeclarationsAndCustomProperties($root, []);
        $remBase = ComputedStyle::resolveFontSizePx(
            $declarations['font-size'] ?? null,
            $syntheticParent->fontSizePx,
            self::INITIAL_FONT_SIZE_PX,
        );
        $declarations['font-size'] = Length::px($remBase);
        $style = ComputedStyle::compute($declarations, $syntheticParent, $root->tagName, $remBase, $customProperties, $this->warnings);
        $map->set($root, $style);
        for ($child = $root->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            $this->resolveElement($child, $style, $map, $remBase);
        }
    }

    private function resolveElement(\Dom\Element $element, ComputedStyle $parent, StyleMap $map, float $remBase): void
    {
        [$declarations, $customProperties] = $this->matchedDeclarationsAndCustomProperties($element, $parent->customProperties);
        $style = ComputedStyle::compute($declarations, $parent, $element->tagName, $remBase, $customProperties, $this->warnings);
        $map->set($element, $style);
        for ($child = $element->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            $this->resolveElement($child, $style, $map, $remBase);
        }
    }

    /**
     * M6-T4 (css-variables-1 §2-3): dos pasadas sobre las mismas reglas ya ordenadas por cascade.
     * PASADA 1 calcula las custom properties FINALES de este elemento (heredadas del padre +
     * propias del cascade, propias ganan — igual regla de "última declaración gana" que cualquier
     * otra propiedad) — se necesita COMPLETA antes de resolver var() en ninguna declaración normal,
     * porque una custom property puede citarse antes de declararse en el orden textual de la hoja
     * (css-variables-1: la sustitución usa el valor CASCADEADO final, no el de "hasta ahora").
     * PASADA 2 reconstruye el mapa de declaraciones tipadas EN EL MISMO ORDEN DE CASCADE que hoy:
     * las declaraciones sin var() se copian tal cual (idéntico a antes de esta tarea); las
     * DeferredDeclaration se sustituyen y tipan AHORA, en su posición original — así, si una regla
     * de mayor especificidad ya fijó (por ejemplo) margin-top de forma directa, ese valor no puede
     * ser pisado por la expansión tardía de un "margin: var(--sp) 10px" de menor especificidad,
     * porque ambas se procesan en el mismo orden en que "ganarían" en el algoritmo original.
     *
     * @param array<string, string> $parentCustomProperties
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function matchedDeclarationsAndCustomProperties(\Dom\Element $element, array $parentCustomProperties): array
    {
        $matching = array_values(array_filter(
            $this->allRules(),
            static fn(StyleRule $rule): bool => $rule->selector->matches($element),
        ));
        // M6 final-review fix (Finding 1, CSS 2.2 §6.4.2): el tier !important es la clave de
        // ordenación PRIMARIA — un StyleRule marcado important siempre se procesa DESPUÉS de
        // cualquiera normal (bool false < true en PHP, así que el <=> ya deja lo normal primero),
        // sin importar especificidad, porque el bucle de más abajo va aplicando "última
        // declaración gana" sobre $matching en este mismo orden. Especificidad y $order siguen
        // siendo el desempate de siempre, pero SOLO dentro del mismo tier (nunca comparados entre
        // tiers distintos, ver StyleRule). No hay tier user/UA important en este motor (solo
        // author), así que dos niveles (normal, important) bastan.
        usort($matching, static function (StyleRule $a, StyleRule $b): int {
            $byImportant = $a->important <=> $b->important;
            if ($byImportant !== 0) {
                return $byImportant;
            }
            $bySpecificity = $a->selector->specificity()->compareTo($b->selector->specificity());
            return $bySpecificity !== 0 ? $bySpecificity : $a->order <=> $b->order;
        });

        $ownCustomProperties = [];
        foreach ($matching as $rule) {
            foreach ($rule->declarations as $property => $value) {
                if (str_starts_with($property, '--') && is_string($value)) {
                    $ownCustomProperties[$property] = $value;
                }
            }
        }
        $customProperties = [...$parentCustomProperties, ...$ownCustomProperties];

        $declarations = [];
        foreach ($matching as $rule) {
            foreach ($rule->declarations as $property => $value) {
                if (str_starts_with($property, '--')) {
                    continue; // ya incorporada a $customProperties arriba.
                }
                if ($value instanceof DeferredDeclaration) {
                    $resolved = $this->resolveDeferred($property, $value->rawValue, $customProperties);
                    // M7-T1 fix (css-variables-1 §3, IACVT): un array vacío aquí significa
                    // "invalid at computed-value time" (sustitución fallida SIN fallback, o texto
                    // sustituido que DeclarationParser::parse() rechaza) — la corrección es tratar
                    // la declaración como si NUNCA se hubiera escrito, no como un no-op. Antes de
                    // esta tarea un no-op dejaba filtrarse el valor de una regla ANTERIOR y de menor
                    // especificidad para la MISMA propiedad (bug de la review: "p{color:red}
                    // p{color:var(--missing)}" seguía en rojo) — unset() fuerza que
                    // ComputedStyle::compute() vea la propiedad como no declarada en absoluto, así
                    // que cae a SU regla real de herencia (inherit para las heredadas, initial para
                    // el resto — ninguna de las dos es "el valor que ganaba antes en el cascade").
                    if ($resolved === []) {
                        foreach ($this->longhandsOf($property) as $longhand) {
                            unset($declarations[$longhand]);
                        }
                        continue;
                    }
                    foreach ($resolved as $resolvedProperty => $resolvedValue) {
                        $declarations[$resolvedProperty] = $resolvedValue;
                    }
                    continue;
                }
                $declarations[$property] = $value;
            }
        }
        return [$declarations, $customProperties];
    }

    /**
     * Sustituye var(...) en $rawValue contra $customProperties (VarResolver, con detección de
     * ciclos) y, si la sustitución tiene éxito, reparsea el resultado con DeclarationParser —
     * exactamente el mismo tipado/expansión de shorthand que una declaración sin var() habría
     * recibido en StylesheetParser, solo que aquí, en compute-time (ver DeferredDeclaration).
     * Un fallo de sustitución (ciclo o custom property desconocida sin fallback) es "invalid at
     * computed-value time" (css-variables-1 §3): la declaración entera se descarta, con warning.
     *
     * @param array<string, string> $customProperties
     * @return array<string, mixed>
     */
    private function resolveDeferred(string $property, string $rawValue, array $customProperties): array
    {
        $varResolver = new VarResolver($customProperties);
        $substituted = $varResolver->substitute($rawValue);
        foreach ($varResolver->drainWarnings() as $warning) {
            $this->warnings?->addWarning($warning);
        }
        if ($substituted === null) {
            $this->warnings?->addWarning("Invalid value for $property (unresolved var()): $rawValue");
            return [];
        }
        $result = $this->declarationParser->parse($property, $substituted);
        foreach ($this->declarationParser->drainWarnings() as $warning) {
            $this->warnings?->addWarning($warning);
        }
        return $result;
    }

    /** @return list<StyleRule> */
    private function allRules(): array
    {
        if ($this->rulesCache !== null) {
            return $this->rulesCache;
        }
        $rules = [];
        foreach ($this->sources as $source) {
            $rules = [...$rules, ...$source->rules()];
        }
        return $this->rulesCache = $rules;
    }

    /**
     * M7-T1 housekeeping (css-variables-1 §3, IACVT): qué propiedades TIPADAS deja "sin valor" una
     * declaración diferida que resulta invalid-at-computed-value-time (ver resolveDeferred()) —
     * para un longhand es la propiedad misma; para un shorthand son TODOS los longhands que
     * expandBoxShorthand()/expandBorderShorthand()/parseFlexShorthand()/expandGapShorthand()
     * producirían en caso de éxito, porque IACVT invalida el shorthand COMPLETO, no una mezcla
     * parcial. La lista es deliberadamente la de DeclarationParser (misma fuente de verdad que la
     * expansión real), no una copia con drift propio.
     *
     * @return list<string>
     */
    private function longhandsOf(string $property): array
    {
        return match ($property) {
            'margin' => ['margin-top', 'margin-right', 'margin-bottom', 'margin-left'],
            'padding' => ['padding-top', 'padding-right', 'padding-bottom', 'padding-left'],
            'gap' => ['row-gap', 'column-gap'],
            'flex' => ['flex-grow', 'flex-shrink', 'flex-basis'],
            'border' => [
                ...self::borderLonghandsForSide('top'),
                ...self::borderLonghandsForSide('right'),
                ...self::borderLonghandsForSide('bottom'),
                ...self::borderLonghandsForSide('left'),
            ],
            'border-top', 'border-right', 'border-bottom', 'border-left' => self::borderLonghandsForSide(
                substr($property, strlen('border-')),
            ),
            default => [$property],
        };
    }

    /** @return list<string> */
    private static function borderLonghandsForSide(string $side): array
    {
        return ["border-$side-width", "border-$side-style", "border-$side-color"];
    }
}
