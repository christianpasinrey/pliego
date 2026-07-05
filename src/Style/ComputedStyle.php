<?php

declare(strict_types=1);

namespace Pliego\Style;

use Pliego\Css\Value\Color;
use Pliego\Css\Value\Length;
use Pliego\Css\Value\LengthPercentage;

final readonly class ComputedStyle
{
    private const array HIDDEN_BY_DEFAULT = ['head', 'script', 'style', 'title', 'meta', 'link'];
    /** UA stylesheet: b,strong → bold (misma mecánica que HIDDEN_BY_DEFAULT). */
    private const array BOLD_BY_DEFAULT = ['b', 'strong'];
    /** UA stylesheet: i,em → italic. */
    private const array ITALIC_BY_DEFAULT = ['i', 'em'];
    /** UA stylesheet: a,u → underline. */
    private const array UNDERLINE_BY_DEFAULT = ['a', 'u'];

    public function __construct(
        public Display $display,
        public LengthPercentage $marginTop,
        public LengthPercentage $marginRight,
        public LengthPercentage $marginBottom,
        public LengthPercentage $marginLeft,
        public LengthPercentage $paddingTop,
        public LengthPercentage $paddingRight,
        public LengthPercentage $paddingBottom,
        public LengthPercentage $paddingLeft,
        public ?LengthPercentage $width,
        public ?Color $backgroundColor,
        public Color $color,
        public float $fontSizePx,
        public string $fontFamily,
        public int $fontWeight,
        public FontStyle $fontStyle,
        public ?float $lineHeightPx,
        public TextAlign $textAlign,
        public bool $underline,
    ) {}

    public static function root(): self
    {
        $zero = LengthPercentage::zero();
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
            400,
            FontStyle::Normal,
            null,
            TextAlign::Left,
            false,
        );
    }

    /**
     * CSS 2.2 §6.1-6.2: propiedades heredadas toman el computed value del padre;
     * el resto parte del initial value. Las declaraciones ganadoras sobrescriben.
     * @param array<string, mixed> $declarations
     */
    public static function compute(array $declarations, self $parent, string $tagName): self
    {
        $zero = LengthPercentage::zero();
        $tag = strtolower($tagName);
        $display = in_array($tag, self::HIDDEN_BY_DEFAULT, true) ? Display::None : Display::Block;
        if (($declarations['display'] ?? null) === 'none') {
            $display = Display::None;
        }
        $length = static fn(string $key): ?Length => ($declarations[$key] ?? null) instanceof Length ? $declarations[$key] : null;
        $lengthPercentage = static fn(string $key): LengthPercentage => ($declarations[$key] ?? null) instanceof LengthPercentage ? $declarations[$key] : $zero;
        $hasLengthPercentage = static fn(string $key): bool => ($declarations[$key] ?? null) instanceof LengthPercentage;

        // Nullsafe + ?? en la misma expresión dispara un falso positivo de PHPStan (ver
        // BlockFlowContext::layout()); se separa en dos sentencias como allí.
        $fontSizeLength = $length('font-size');
        $fontSizePx = $fontSizeLength !== null ? $fontSizeLength->px : $parent->fontSizePx;

        $fontWeightValue = $declarations['font-weight'] ?? null;
        $fontWeight = match (true) {
            is_int($fontWeightValue) => $fontWeightValue,
            in_array($tag, self::BOLD_BY_DEFAULT, true) => 700,
            default => $parent->fontWeight,
        };

        $fontStyleValue = $declarations['font-style'] ?? null;
        $fontStyle = match (true) {
            $fontStyleValue === 'italic' => FontStyle::Italic,
            $fontStyleValue === 'normal' => FontStyle::Normal,
            in_array($tag, self::ITALIC_BY_DEFAULT, true) => FontStyle::Italic,
            default => $parent->fontStyle,
        };

        $textAlignValue = $declarations['text-align'] ?? null;
        $textAlign = match (true) {
            $textAlignValue === 'left' => TextAlign::Left,
            $textAlignValue === 'center' => TextAlign::Center,
            $textAlignValue === 'right' => TextAlign::Right,
            default => $parent->textAlign,
        };

        /**
         * text-decoration/underline no hereda en CSS real (es una propiedad de decoración que se
         * aplica al elemento, no vía herencia formal). M1 la simplifica tratándola como heredada
         * porque el pipeline de texto todavía no soporta islas de decoración independientes de la
         * herencia tipográfica; T3+ revisará esto si la precisión total resulta necesaria.
         */
        $underline = match (true) {
            array_key_exists('text-decoration', $declarations) => (bool) $declarations['text-decoration'],
            in_array($tag, self::UNDERLINE_BY_DEFAULT, true) => true,
            default => $parent->underline,
        };

        $lineHeightPx = $parent->lineHeightPx;
        if (array_key_exists('line-height', $declarations)) {
            $lineHeightValue = $declarations['line-height'];
            $lineHeightPx = match (true) {
                $lineHeightValue === null => null,
                $lineHeightValue instanceof Length => $lineHeightValue->px,
                is_float($lineHeightValue) => $lineHeightValue * $fontSizePx,
                default => null,
            };
        }

        return new self(
            $display,
            $lengthPercentage('margin-top'),
            $lengthPercentage('margin-right'),
            $lengthPercentage('margin-bottom'),
            $lengthPercentage('margin-left'),
            $lengthPercentage('padding-top'),
            $lengthPercentage('padding-right'),
            $lengthPercentage('padding-bottom'),
            $lengthPercentage('padding-left'),
            $hasLengthPercentage('width') ? $lengthPercentage('width') : null,
            ($declarations['background-color'] ?? null) instanceof \Pliego\Css\Value\Color ? $declarations['background-color'] : null,
            ($declarations['color'] ?? null) instanceof \Pliego\Css\Value\Color ? $declarations['color'] : $parent->color,
            $fontSizePx,
            is_string($declarations['font-family'] ?? null) ? $declarations['font-family'] : $parent->fontFamily,
            $fontWeight,
            $fontStyle,
            $lineHeightPx,
            $textAlign,
            $underline,
        );
    }
}
