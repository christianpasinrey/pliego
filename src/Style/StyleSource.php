<?php

declare(strict_types=1);

namespace Pliego\Style;

use Pliego\Css\StyleRule;

interface StyleSource
{
    /** @return list<StyleRule> */
    public function rules(): array;
}
