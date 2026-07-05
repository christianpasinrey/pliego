<?php

declare(strict_types=1);

namespace Pliego\Style;

use Pliego\Css\ParseResult;
use Pliego\Css\StyleRule;

final readonly class CssStyleSource implements StyleSource
{
    public function __construct(private ParseResult $result) {}

    /** @return list<StyleRule> */
    public function rules(): array
    {
        return $this->result->rules;
    }
}
