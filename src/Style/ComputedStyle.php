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
    /** UA stylesheet: b,strong → bold; M5-T2 añade th (CSS 2.1 §17.2, misma mecánica que HIDDEN_BY_DEFAULT). */
    private const array BOLD_BY_DEFAULT = ['b', 'strong', 'th'];
    /** UA stylesheet: i,em → italic. */
    private const array ITALIC_BY_DEFAULT = ['i', 'em'];
    /** UA stylesheet: a,u → underline. */
    private const array UNDERLINE_BY_DEFAULT = ['a', 'u'];
    /** UA stylesheet M5-T2: th → text-align center (CSS 2.1 §17.2, html.css de referencia). */
    private const array CENTER_ALIGN_BY_DEFAULT = ['th'];
    /**
     * UA stylesheet M5-T2: display por defecto de los elementos de tabla (CSS 2.1 §17.2 /
     * html5 rendering hints). td y th comparten table-cell; tr/thead/tbody se mapean 1:1.
     * tfoot/col/colgroup/caption quedan fuera del contrato M5 (no listados en el brief) y caen
     * al default genérico (Display::Block), igual que cualquier otro tag desconocido.
     */
    private const array TABLE_DISPLAY_BY_TAG = [
        'table' => Display::Table,
        'tr' => Display::TableRow,
        'td' => Display::TableCell,
        'th' => Display::TableCell,
        'thead' => Display::TableHeaderGroup,
        'tbody' => Display::TableRowGroup,
    ];

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
        // M5-T2: border-spacing SÍ hereda (CSS 2.2 §17.6.1: "This property... is inherited"),
        // a diferencia de casi todas las demás propiedades de caja — de ahí que caiga a
        // $parent->borderSpacingPx en compute() en vez de al initial value 0 cuando no hay
        // declaración propia. table-layout y vertical-align, en cambio, NO heredan (CSS 2.2
        // §17.5.2 y §10.8.1 respectivamente): cada uno parte siempre del initial value del
        // spec. vertical-align diverge del spec real (initial value = baseline, no soportado
        // en M5) usando Top como default — ver VerticalAlign.
        public float $borderSpacingPx,
        public string $tableLayout,
        public VerticalAlign $verticalAlign,
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
            0.0,
            'auto',
            VerticalAlign::Top,
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
        // M5-T2: el default por tag ahora consulta también TABLE_DISPLAY_BY_TAG antes de caer a
        // Block — HIDDEN_BY_DEFAULT sigue teniendo prioridad (p.ej. un <title> nunca es tabla).
        $display = match (true) {
            in_array($tag, self::HIDDEN_BY_DEFAULT, true) => Display::None,
            array_key_exists($tag, self::TABLE_DISPLAY_BY_TAG) => self::TABLE_DISPLAY_BY_TAG[$tag],
            default => Display::Block,
        };
        $display = match ($displayValue) {
            'none' => Display::None,
            // css-flexbox-1 §2: sigue siendo un block-level box en el flujo normal — M4-T4
            // introduce FlexFormattingContext; hasta entonces fluye como Block (ver Display::Flex).
            'flex' => Display::Flex,
            'block' => Display::Block,
            // css-tables-3 §2: las cinco display values de tabla — M5-T3/T4 construyen
            // TableBox/TableFormattingContext a partir de estos; hasta entonces BoxTreeBuilder
            // sigue generando un BlockBox plano (ver Display::Table y comentario del case).
            'table' => Display::Table,
            'table-row' => Display::TableRow,
            'table-cell' => Display::TableCell,
            'table-header-group' => Display::TableHeaderGroup,
            'table-row-group' => Display::TableRowGroup,
            default => $display,
        };
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
            // UA stylesheet M5-T2: th → text-align center (CENTER_ALIGN_BY_DEFAULT), aplicada
            // antes de caer a la herencia normal — misma prioridad que BOLD_BY_DEFAULT arriba.
            in_array($tag, self::CENTER_ALIGN_BY_DEFAULT, true) => TextAlign::Center,
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

        // M5-T2: border-spacing SÍ hereda (CSS 2.2 §17.6.1) — a diferencia de todo lo demás en
        // esta sección, el fallback sin declaración propia es $parent->borderSpacingPx, no 0.0.
        $borderSpacingValue = $declarations['border-spacing'] ?? null;
        $borderSpacingPx = $borderSpacingValue instanceof Length ? $borderSpacingValue->px : $parent->borderSpacingPx;

        // table-layout NO hereda (CSS 2.2 §17.5.2): initial value 'auto' siempre que no haya
        // declaración propia, nunca $parent->tableLayout.
        $tableLayout = ($declarations['table-layout'] ?? null) === 'fixed' ? 'fixed' : 'auto';

        // vertical-align NO hereda en CSS real (CSS 2.2 §10.8.1 no lo lista entre las
        // propiedades heredadas de §6.1) — cada elemento parte de VerticalAlign::Top (divergencia
        // documentada del initial value real "baseline", ver VerticalAlign) cuando no hay
        // declaración propia, nunca de $parent->verticalAlign.
        $verticalAlign = match ($declarations['vertical-align'] ?? null) {
            'middle' => VerticalAlign::Middle,
            'bottom' => VerticalAlign::Bottom,
            'top' => VerticalAlign::Top,
            default => VerticalAlign::Top,
        };

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
            $borderSpacingPx,
            $tableLayout,
            $verticalAlign,
        );
    }
}
