<?php

declare(strict_types=1);

namespace Pliego\Css;

final readonly class ParseResult
{
    /**
     * @param list<StyleRule> $rules
     * @param list<string> $warnings
     */
    public function __construct(
        public array $rules,
        public array $warnings,
        public ?PageRuleData $pageRule = null,
    ) {}
}
