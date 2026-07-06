<?php

declare(strict_types=1);

namespace Pliego\Style;

use Pliego\Css\StyleRule;

final readonly class StyleResolver
{
    /** @param list<StyleSource> $sources */
    public function __construct(private array $sources) {}

    public function resolve(\Dom\HTMLDocument $document): StyleMap
    {
        $map = new StyleMap();
        $root = $document->documentElement;
        if ($root !== null) {
            $this->resolveElement($root, ComputedStyle::root(), $map);
        }
        return $map;
    }

    private function resolveElement(\Dom\Element $element, ComputedStyle $parent, StyleMap $map): void
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
        $style = ComputedStyle::compute($declarations, $parent, $element->tagName);
        $map->set($element, $style);
        for ($child = $element->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            $this->resolveElement($child, $style, $map);
        }
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
