<?php

declare(strict_types=1);

namespace Pliego\Style;

use Pliego\Css\Value\Color;
use Pliego\Css\Value\Length;

final readonly class ComputedStyle
{
    private const array HIDDEN_BY_DEFAULT = ['head', 'script', 'style', 'title', 'meta', 'link'];

    public function __construct(
        public Display $display,
        public Length $marginTop,
        public Length $marginRight,
        public Length $marginBottom,
        public Length $marginLeft,
        public Length $paddingTop,
        public Length $paddingRight,
        public Length $paddingBottom,
        public Length $paddingLeft,
        public ?Length $width,
        public ?Color $backgroundColor,
        public Color $color,
        public float $fontSizePx,
        public string $fontFamily,
    ) {}

    public static function root(): self
    {
        $zero = Length::zero();
        return new self(
            Display::Block,
            $zero,
            $zero,
            $zero,
            $zero,
            $zero,
            $zero,
            $zero,
            $zero,
            null,
            null,
            new Color(0, 0, 0),
            16.0,
            'default',
        );
    }

    /**
     * CSS 2.2 §6.1-6.2: propiedades heredadas toman el computed value del padre;
     * el resto parte del initial value. Las declaraciones ganadoras sobrescriben.
     * @param array<string, mixed> $declarations
     */
    public static function compute(array $declarations, self $parent, string $tagName): self
    {
        $zero = Length::zero();
        $display = in_array(strtolower($tagName), self::HIDDEN_BY_DEFAULT, true) ? Display::None : Display::Block;
        if (($declarations['display'] ?? null) === 'none') {
            $display = Display::None;
        }
        $length = static fn(string $key): Length => $declarations[$key] instanceof Length ? $declarations[$key] : $zero;
        $has = static fn(string $key): bool => ($declarations[$key] ?? null) instanceof Length;
        return new self(
            $display,
            $has('margin-top') ? $length('margin-top') : $zero,
            $has('margin-right') ? $length('margin-right') : $zero,
            $has('margin-bottom') ? $length('margin-bottom') : $zero,
            $has('margin-left') ? $length('margin-left') : $zero,
            $has('padding-top') ? $length('padding-top') : $zero,
            $has('padding-right') ? $length('padding-right') : $zero,
            $has('padding-bottom') ? $length('padding-bottom') : $zero,
            $has('padding-left') ? $length('padding-left') : $zero,
            $has('width') ? $length('width') : null,
            ($declarations['background-color'] ?? null) instanceof \Pliego\Css\Value\Color ? $declarations['background-color'] : null,
            ($declarations['color'] ?? null) instanceof \Pliego\Css\Value\Color ? $declarations['color'] : $parent->color,
            $has('font-size') ? $length('font-size')->px : $parent->fontSizePx,
            is_string($declarations['font-family'] ?? null) ? $declarations['font-family'] : $parent->fontFamily,
        );
    }
}
