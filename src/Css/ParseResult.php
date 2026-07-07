<?php

declare(strict_types=1);

namespace Pliego\Css;

use Pliego\Css\Value\FontFaceRule;

final readonly class ParseResult
{
    /**
     * @param list<StyleRule> $rules
     * @param list<string> $warnings
     * @param list<FontFaceRule> $fontFaceRules M8-T7: @font-face rules parsed from the
     *     stylesheet, in document order; Engine registers each into Text\FontCatalog (basePath
     *     resolution + missing/unparseable-file warnings live there, not here — see
     *     Engine::render()).
     */
    public function __construct(
        public array $rules,
        public array $warnings,
        public ?PageRuleData $pageRule = null,
        public array $fontFaceRules = [],
    ) {}
}
