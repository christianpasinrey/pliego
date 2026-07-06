<?php

declare(strict_types=1);

namespace Pliego\Style;

use Pliego\Css\StyleRule;
use Pliego\Css\Value\Length;

final readonly class StyleResolver
{
    /** @param list<StyleSource> $sources */
    public function __construct(private array $sources) {}

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
        $declarations = $this->matchedDeclarations($root);
        $syntheticParent = ComputedStyle::root();
        $remBase = ComputedStyle::resolveFontSizePx(
            $declarations['font-size'] ?? null,
            $syntheticParent->fontSizePx,
            self::INITIAL_FONT_SIZE_PX,
        );
        $declarations['font-size'] = Length::px($remBase);
        $style = ComputedStyle::compute($declarations, $syntheticParent, $root->tagName, $remBase);
        $map->set($root, $style);
        for ($child = $root->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            $this->resolveElement($child, $style, $map, $remBase);
        }
    }

    private function resolveElement(\Dom\Element $element, ComputedStyle $parent, StyleMap $map, float $remBase): void
    {
        $declarations = $this->matchedDeclarations($element);
        $style = ComputedStyle::compute($declarations, $parent, $element->tagName, $remBase);
        $map->set($element, $style);
        for ($child = $element->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            $this->resolveElement($child, $style, $map, $remBase);
        }
    }

    /** @return array<string, mixed> */
    private function matchedDeclarations(\Dom\Element $element): array
    {
        $matching = array_values(array_filter(
            $this->allRules(),
            static fn(StyleRule $rule): bool => $rule->selector->matches($element),
        ));
        usort($matching, static function (StyleRule $a, StyleRule $b): int {
            $bySpecificity = $a->selector->specificity()->compareTo($b->selector->specificity());
            return $bySpecificity !== 0 ? $bySpecificity : $a->order <=> $b->order;
        });
        $declarations = [];
        foreach ($matching as $rule) {
            $declarations = [...$declarations, ...$rule->declarations];
        }
        return $declarations;
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
