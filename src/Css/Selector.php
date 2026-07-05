<?php

declare(strict_types=1);

namespace Pliego\Css;

/** M0 subset: un único selector compuesto (tipo, .clase, #id). Sin combinadores. */
final readonly class Selector
{
    private function __construct(
        public ?string $type,
        public ?string $class,
        public ?string $id,
    ) {}

    public static function fromString(string $selector): ?self
    {
        $selector = trim($selector);
        if (preg_match('/^(?<type>[a-zA-Z][\w-]*)?(?:\.(?<class>[\w-]+))?(?:#(?<id>[\w-]+))?$/', $selector, $m) !== 1) {
            return null;
        }
        $type = ($m['type'] ?? '') !== '' ? strtolower($m['type']) : null;
        $class = ($m['class'] ?? '') !== '' ? $m['class'] : null;
        $id = ($m['id'] ?? '') !== '' ? $m['id'] : null;
        if ($type === null && $class === null && $id === null) {
            return null;
        }
        return new self($type, $class, $id);
    }

    /** CSS 2.2 §6.4.3 colapsado a un entero (sin !important ni inline en M0). */
    public function specificity(): int
    {
        return ($this->id !== null ? 100 : 0)
            + ($this->class !== null ? 10 : 0)
            + ($this->type !== null ? 1 : 0);
    }

    public function matches(\Dom\Element $element): bool
    {
        if ($this->type !== null && strtolower($element->tagName) !== $this->type) {
            return false;
        }
        if ($this->class !== null && !$element->classList->contains($this->class)) {
            return false;
        }
        return $this->id === null || $element->getAttribute('id') === $this->id;
    }
}
