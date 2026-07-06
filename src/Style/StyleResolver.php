<?php

declare(strict_types=1);

namespace Pliego\Style;

use Pliego\Css\DeclarationParser;
use Pliego\Css\DeferredDeclaration;
use Pliego\Css\StyleRule;
use Pliego\Css\Value\Length;
use Pliego\Css\VarResolver;
use Pliego\Css\WarningCollector;

final readonly class StyleResolver
{
    /** @param list<StyleSource> $sources */
    public function __construct(
        private array $sources,
        // M6-T4: mismo patrón que Layout\*FormattingContext/Paginator (WarningCollector opcional
        // compartido, ver Engine) — recibe los warnings de var()/calc() que solo pueden detectarse
        // aquí (ciclos, referencias desconocidas, % en una propiedad sin soporte de %, división por
        // cero cuyo divisor depende de em/rem propios del elemento).
        private ?WarningCollector $warnings = null,
    ) {
        $this->declarationParser = new DeclarationParser();
    }

    /** M6-T4: instancia ÚNICA reutilizada para reparsear declaraciones diferidas (var()) una vez
     * sustituidas — DeclarationParser es stateless entre llamadas salvo su buffer de warnings,
     * que se drena tras cada uso (ver resolveDeferred()); reusarla evita crear un objeto nuevo por
     * cada declaración diferida de cada elemento. */
    private DeclarationParser $declarationParser;

    /** css-values-3 §5.2: font-size initial value — base de rem hasta que se resuelva el
     * font-size real del documentElement (ver resolveRoot()). */
    private const float INITIAL_FONT_SIZE_PX = 16.0;

    public function resolve(\Dom\HTMLDocument $document): StyleMap
    {
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
        usort($matching, static function (StyleRule $a, StyleRule $b): int {
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
                    foreach ($this->resolveDeferred($property, $value->rawValue, $customProperties) as $resolvedProperty => $resolvedValue) {
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
        $rules = [];
        foreach ($this->sources as $source) {
            $rules = [...$rules, ...$source->rules()];
        }
        return $rules;
    }
}
