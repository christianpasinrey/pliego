<?php

declare(strict_types=1);

namespace Pliego\Style;

final class StyleMap
{
    /** @var \SplObjectStorage<\Dom\Element, ComputedStyle> */
    private \SplObjectStorage $styles;

    public function __construct()
    {
        $this->styles = new \SplObjectStorage();
    }

    public function set(\Dom\Element $element, ComputedStyle $style): void
    {
        $this->styles[$element] = $style;
    }

    public function get(\Dom\Element $element): ComputedStyle
    {
        return $this->styles[$element];
    }
}
