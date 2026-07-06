<?php

declare(strict_types=1);

namespace Pliego\Style;

use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
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
        // M3-T3: a diferencia de width, height NO admite % en M3 (LENGTH_PROPERTIES en
        // DeclarationParser, no LENGTH_PERCENTAGE_PROPERTIES) — CSS 2.2 §10.5 resolvería un % de
        // height contra la altura del containing block, que este motor no rastrea; un valor como
        // "height: 50%" ya se rechaza en el parser ("Unsupported length for height: 50%") y aquí
        // simplemente no llega, dejando el eje en null (auto), warning incluido — la adjudicación
        // "% height -> warning + auto" del brief M3-T3 queda satisfecha sin código extra aquí.
        public ?Length $height,
        public ?Color $backgroundColor,
        public Color $color,
        public float $fontSizePx,
        public string $fontFamily,
        public int $fontWeight,
        public FontStyle $fontStyle,
        public ?float $lineHeightPx,
        public TextAlign $textAlign,
        public bool $underline,
        public BorderSide $borderTop,
        public BorderSide $borderRight,
        public BorderSide $borderBottom,
        public BorderSide $borderLeft,
        public string $boxSizing,
        // M4-T1: propiedades flex — ninguna hereda (css-flexbox-1: display:flex/flex-direction/
        // flex-wrap/gap/justify-content/align-items son propiedades del CONTENEDOR, flex-grow/
        // shrink/basis son propiedades del ITEM; ninguna de las dos categorías está en la lista de
        // propiedades heredadas de CSS 2.2 §6.1 ni de css-flexbox-1). Los defaults son los initial
        // values del spec, no los del padre.
        public FlexDirection $flexDirection,
        public FlexWrap $flexWrap,
        public float $rowGapPx,
        public float $columnGapPx,
        public JustifyContent $justifyContent,
        public AlignItems $alignItems,
        public float $flexGrow,
        public float $flexShrink,
        public ?LengthPercentage $flexBasis,
    ) {}

    public static function root(): self
    {
        $zero = LengthPercentage::zero();
        $rootColor = new Color(0, 0, 0);
        // Sin borde declarado en la raíz: color=currentColor (=$rootColor), igual que en
        // compute() — invariante del árbol: BorderSide::$color nunca es null en ComputedStyle.
        $noBorder = new BorderSide(0.0, BorderStyle::None, $rootColor);
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
            null,
            $rootColor,
            16.0,
            'default',
            400,
            FontStyle::Normal,
            null,
            TextAlign::Left,
            false,
            $noBorder,
            $noBorder,
            $noBorder,
            $noBorder,
            'content-box',
            FlexDirection::Row,
            FlexWrap::NoWrap,
            0.0,
            0.0,
            JustifyContent::FlexStart,
            AlignItems::Stretch,
            0.0,
            1.0,
            null,
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
        $displayValue = $declarations['display'] ?? null;
        $display = in_array($tag, self::HIDDEN_BY_DEFAULT, true) ? Display::None : Display::Block;
        if ($displayValue === 'none') {
            $display = Display::None;
        } elseif ($displayValue === 'flex') {
            // css-flexbox-1 §2: sigue siendo un block-level box en el flujo normal — M4-T4
            // introduce FlexFormattingContext; hasta entonces fluye como Block (ver Display::Flex).
            $display = Display::Flex;
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

        // color se computa antes de ensamblar los bordes: border-{side}-color por defecto
        // es currentColor (CSS 2.2 §8.5.3), es decir, el color computado de este elemento.
        $color = ($declarations['color'] ?? null) instanceof Color ? $declarations['color'] : $parent->color;

        $borderSide = static function (string $side) use ($declarations, $color): BorderSide {
            $width = $declarations["border-$side-width"] ?? null;
            $style = $declarations["border-$side-style"] ?? null;
            $sideColor = $declarations["border-$side-color"] ?? null;
            $resolvedStyle = $style instanceof BorderStyle ? $style : BorderStyle::None;
            // CSS 2.2 §8.5.3: "if the value of the border-style property is none... the
            // computed value of the border width is 0" — el ancho USADO se calcula aquí, en
            // origen, para que ningún consumidor (BlockFlowContext, Painter) pueda leer
            // ->widthPx sin pasar por esta regla.
            $widthPx = $resolvedStyle === BorderStyle::Solid && $width instanceof Length ? $width->px : 0.0;
            return new BorderSide(
                $widthPx,
                $resolvedStyle,
                $sideColor instanceof Color ? $sideColor : $color,
            );
        };

        $boxSizingValue = $declarations['box-sizing'] ?? null;
        // box-sizing NO hereda (CSS Box Sizing L3 §2): el initial value es siempre content-box,
        // independientemente de $parent->boxSizing.
        $boxSizing = $boxSizingValue === 'border-box' ? 'border-box' : 'content-box';

        // M4-T1: ninguna propiedad flex hereda (ver comentario del constructor) — cada rama cae
        // directamente al initial value del spec cuando no hay declaración propia, nunca a
        // $parent->....
        $flexDirection = ($declarations['flex-direction'] ?? null) === 'column'
            ? FlexDirection::Column
            : FlexDirection::Row;
        $flexWrap = ($declarations['flex-wrap'] ?? null) === 'wrap' ? FlexWrap::Wrap : FlexWrap::NoWrap;
        $justifyContent = match ($declarations['justify-content'] ?? null) {
            'center' => JustifyContent::Center,
            'flex-end' => JustifyContent::FlexEnd,
            'space-between' => JustifyContent::SpaceBetween,
            default => JustifyContent::FlexStart,
        };
        $alignItems = match ($declarations['align-items'] ?? null) {
            'flex-start' => AlignItems::FlexStart,
            'center' => AlignItems::Center,
            'flex-end' => AlignItems::FlexEnd,
            default => AlignItems::Stretch,
        };
        $rowGapValue = $declarations['row-gap'] ?? null;
        $rowGapPx = $rowGapValue instanceof Length ? $rowGapValue->px : 0.0;
        $columnGapValue = $declarations['column-gap'] ?? null;
        $columnGapPx = $columnGapValue instanceof Length ? $columnGapValue->px : 0.0;
        $flexGrowValue = $declarations['flex-grow'] ?? null;
        $flexGrow = is_float($flexGrowValue) ? $flexGrowValue : 0.0;
        $flexShrinkValue = $declarations['flex-shrink'] ?? null;
        $flexShrink = is_float($flexShrinkValue) ? $flexShrinkValue : 1.0;
        // flex-basis: la longhand/shorthand emiten 'auto' (string) o un LengthPercentage; el
        // sentinel 'auto' y la ausencia de declaración colapsan al mismo null (= auto), igual que
        // el resto de propiedades opcionales de este método.
        $flexBasisValue = $declarations['flex-basis'] ?? null;
        $flexBasis = $flexBasisValue instanceof LengthPercentage ? $flexBasisValue : null;

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
            // height NO hereda (igual que width): siempre parte de las propias declaraciones del
            // elemento, nunca del padre.
            $length('height'),
            ($declarations['background-color'] ?? null) instanceof Color ? $declarations['background-color'] : null,
            $color,
            $fontSizePx,
            is_string($declarations['font-family'] ?? null) ? $declarations['font-family'] : $parent->fontFamily,
            $fontWeight,
            $fontStyle,
            $lineHeightPx,
            $textAlign,
            $underline,
            $borderSide('top'),
            $borderSide('right'),
            $borderSide('bottom'),
            $borderSide('left'),
            $boxSizing,
            $flexDirection,
            $flexWrap,
            $rowGapPx,
            $columnGapPx,
            $justifyContent,
            $alignItems,
            $flexGrow,
            $flexShrink,
            $flexBasis,
        );
    }
}
