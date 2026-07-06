<?php

declare(strict_types=1);

namespace Pliego\Style;

use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\CalcExpr;
use Pliego\Css\Value\CalcValue;
use Pliego\Css\Value\Color;
use Pliego\Css\Value\CssLength;
use Pliego\Css\Value\Length;
use Pliego\Css\Value\LengthPercentage;
use Pliego\Css\Value\LengthUnit;
use Pliego\Css\WarningCollector;

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
        // M6-T4 (css-variables-1 §2-3): custom properties SÍ heredan siempre (a diferencia de
        // casi todas las demás propiedades de este constructor, que no heredan por defecto) — es
        // el mecanismo de herencia el que hace que var(--bs-primary) funcione en cualquier
        // descendiente de :root sin que cada regla tenga que redeclarar la variable. Valor CRUDO
        // (string, puede contener var() anidado sin resolver todavía si vino de un padre cuyo
        // propio --x también depende de otro — VarResolver resuelve la cadena completa on-demand
        // en cada uso, ver StyleResolver::resolveDeferred()).
        /** @var array<string, string> */
        public array $customProperties = [],
    ) {}

    /**
     * M6-T3 (css-values-3 §5.2): resolución de font-size — la ÚNICA propiedad donde em/%
     * se miden contra el font-size del PADRE (nunca el propio, evita la circularidad
     * "relativo a sí mismo"); rem contra $remBase. Extraído a un método público estático
     * porque StyleResolver::resolveRoot() necesita este MISMO cálculo para derivar el remBase
     * del árbol a partir del font-size del documentElement, sin duplicar la lógica ni caer en
     * el bug de recalcular dos veces con un remBase distinto cada vez (ver StyleResolver).
     */
    public static function resolveFontSizePx(mixed $fontSizeValue, float $parentFontSizePx, float $remBase): float
    {
        if ($fontSizeValue instanceof Length) {
            return $fontSizeValue->px;
        }
        if ($fontSizeValue instanceof CssLength) {
            return match ($fontSizeValue->unit) {
                LengthUnit::Percent => ($fontSizeValue->value / 100.0) * $parentFontSizePx,
                LengthUnit::Em => $fontSizeValue->value * $parentFontSizePx,
                LengthUnit::Rem => $fontSizeValue->value * $remBase,
                default => $parentFontSizePx,
            };
        }
        // M6-T4 (css-values-3 §8): font-size es la única propiedad donde % TAMBIÉN se resuelve
        // ya mismo (contra el font-size del padre, igual que em) — nunca se difiere a Layout
        // como en width/margin/padding — así que $percentBase se pasa igual que $emBase
        // (foldCalcWithOwnBase asume ambos iguales, ver su docblock).
        if ($fontSizeValue instanceof CalcExpr) {
            return self::foldCalcWithOwnBase($fontSizeValue, $parentFontSizePx, $remBase);
        }
        return $parentFontSizePx;
    }

    /**
     * M6-T4: plegado de un calc() en un contexto donde % NUNCA se difiere a Layout — font-size
     * (% y em contra el font-size del padre) y line-height (% y em contra el font-size PROPIO,
     * ya resuelto) comparten esta forma: un único $ownBase sirve de base tanto para em como para
     * %, así que fold() SIEMPRE devuelve un float puro aquí (nunca un CalcValue diferido) — el
     * fallback a $ownBase solo satisface el tipo CalcValue|float de fold() ante PHPStan, nunca se
     * alcanza en la práctica.
     */
    private static function foldCalcWithOwnBase(CalcExpr $expr, float $ownBase, float $remBase): float
    {
        $folded = $expr->fold($ownBase, $remBase, $ownBase);
        return is_float($folded) ? $folded : $ownBase;
    }

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
     *
     * M6-T3 (css-values-3 §5-6): $remBase es el font-size computado del elemento raíz
     * (documentElement), capturado y threadeado por StyleResolver — 1rem se resuelve contra
     * ESTE valor en todo el árbol, nunca contra el padre inmediato. Es donde las unidades
     * simbólicas (Em/Rem/Percent-en-font-size/line-height, ver CssLength) mueren: cualquier
     * CssLength que sobreviva hasta aquí se resuelve a Length/LengthPercentage (solo px|%) antes
     * de construir el ComputedStyle — ningún consumidor fuera de Style\ ve jamás un CssLength.
     *
     * M6-T4: $customProperties es el mapa YA FUSIONADO (heredado del padre + propio del cascade,
     * propio gana) que StyleResolver calculó para este elemento — se limita a threadearse hasta
     * el nuevo campo homónimo (para que los hijos lo hereden a su vez); la sustitución de var()
     * en las declaraciones normales YA ocurrió antes de llegar aquí (ver
     * StyleResolver::resolveDeferred()), así que $declarations nunca contiene var() sin resolver
     * a estas alturas. $warnings (opcional, igual patrón que Layout\*FormattingContext) recibe los
     * warnings de plegado de calc() que solo pueden detectarse aquí (depende del font-size propio/
     * raíz de ESTE elemento): % en una propiedad que no la admite (height/gap/border-width/
     * border-spacing) y — en tareas futuras — signo inválido.
     *
     * @param array<string, mixed> $declarations
     * @param array<string, string> $customProperties
     */
    public static function compute(
        array $declarations,
        self $parent,
        string $tagName,
        float $remBase,
        array $customProperties = [],
        ?WarningCollector $warnings = null,
    ): self {
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
        // M6-T3: font-size se resuelve ANTES que cualquier otra propiedad porque su resultado
        // ($fontSizePx) es la base "own font-size" que usan em/% en TODAS las demás propiedades
        // de este elemento (ver $resolveCssLength más abajo). font-size es la ÚNICA propiedad
        // donde em/% se miden contra el font-size del PADRE, no el propio (css-values-3 §5.2 /
        // CSS 2.2 §10.8.1: evita la circularidad "font-size relativo a sí mismo"); rem siempre
        // contra $remBase, igual que en cualquier otra propiedad.
        $fontSizeValue = $declarations['font-size'] ?? null;
        $fontSizePx = self::resolveFontSizePx($fontSizeValue, $parent->fontSizePx, $remBase);

        // Resolución genérica de CssLength simbólico para TODAS las demás propiedades (margin/
        // padding/width/height/row-gap/column-gap/border-width/border-spacing/flex-basis): em
        // contra el font-size PROPIO ($fontSizePx, ya resuelto arriba), rem contra $remBase. Px
        // (incluye pt/cm/mm/in, ya plegados en CssLength::fromCss) pasa el valor tal cual.
        $resolveCssLength = static fn(CssLength $css): float => match ($css->unit) {
            LengthUnit::Em => $css->value * $fontSizePx,
            LengthUnit::Rem => $css->value * $remBase,
            default => $css->value,
        };
        // M6-T4 (css-values-3 §8): plegado de un CalcExpr en un contexto de longitud PURA (sin
        // %, height/row-gap/column-gap/border-*-width/border-spacing) — $percentBase=null en
        // fold(): si el árbol contenía %, el resultado es un CalcValue (no un float) y eso es
        // justo la señal de "% no soportado aquí", igual que el rechazo ya existente de "50%"
        // literal en esas mismas propiedades (ver DeclarationParser::LENGTH_PROPERTIES) — mismo
        // resultado observable (warning + valor descartado), vía un camino distinto.
        $resolveCalcPure = static function (CalcExpr $expr, string $label, float $default) use ($fontSizePx, $remBase, $warnings): float {
            $folded = $expr->fold($fontSizePx, $remBase, null);
            if ($folded instanceof CalcValue) {
                $warnings?->addWarning("calc() with % not supported for $label (percentage discarded)");
                return $default;
            }
            return $folded;
        };
        // Contraparte para longitud+porcentaje (margin/padding/width/flex-basis): % SÍ se admite,
        // pero se difiere a Layout igual que un "50%" literal — LengthPercentage::calc() envuelve
        // el CalcValue diferido, resolve($containingBlockPx) ya sabe interpretarlo (ver esa clase).
        $resolveCalcLengthPercentage = static function (CalcExpr $expr) use ($fontSizePx, $remBase): LengthPercentage {
            $folded = $expr->fold($fontSizePx, $remBase, null);
            return $folded instanceof CalcValue ? LengthPercentage::calc($folded) : LengthPercentage::px($folded);
        };
        $length = static function (string $key) use ($declarations, $resolveCssLength, $resolveCalcPure): ?Length {
            $v = $declarations[$key] ?? null;
            return match (true) {
                $v instanceof Length => $v,
                $v instanceof CssLength => Length::px($resolveCssLength($v)),
                $v instanceof CalcExpr => Length::px($resolveCalcPure($v, $key, 0.0)),
                default => null,
            };
        };
        $lengthPercentage = static function (string $key) use ($declarations, $resolveCssLength, $resolveCalcLengthPercentage, $zero): LengthPercentage {
            $v = $declarations[$key] ?? null;
            return match (true) {
                $v instanceof LengthPercentage => $v,
                $v instanceof CssLength => LengthPercentage::px($resolveCssLength($v)),
                $v instanceof CalcExpr => $resolveCalcLengthPercentage($v),
                default => $zero,
            };
        };
        $hasLengthPercentage = static function (string $key) use ($declarations): bool {
            $v = $declarations[$key] ?? null;
            return $v instanceof LengthPercentage || $v instanceof CssLength || $v instanceof CalcExpr;
        };

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
                // M6-T3: %/em en line-height se miden contra el font-size PROPIO (ya resuelto
                // arriba, igual que el multiplicador unitless de la rama anterior); rem contra
                // $remBase, igual que en cualquier otra propiedad no-font-size.
                $lineHeightValue instanceof CssLength => match ($lineHeightValue->unit) {
                    LengthUnit::Percent => ($lineHeightValue->value / 100.0) * $fontSizePx,
                    LengthUnit::Em => $lineHeightValue->value * $fontSizePx,
                    LengthUnit::Rem => $lineHeightValue->value * $remBase,
                    default => null,
                },
                // M6-T4: igual que font-size, % en line-height se resuelve YA (contra el propio
                // font-size, no diferido a Layout) — comparte foldCalcWithOwnBase con font-size.
                $lineHeightValue instanceof CalcExpr => self::foldCalcWithOwnBase($lineHeightValue, $fontSizePx, $remBase),
                default => null,
            };
        }

        // color se computa antes de ensamblar los bordes: border-{side}-color por defecto
        // es currentColor (CSS 2.2 §8.5.3), es decir, el color computado de este elemento.
        $color = ($declarations['color'] ?? null) instanceof Color ? $declarations['color'] : $parent->color;

        $borderSide = static function (string $side) use ($declarations, $color, $resolveCssLength, $resolveCalcPure): BorderSide {
            $width = $declarations["border-$side-width"] ?? null;
            $style = $declarations["border-$side-style"] ?? null;
            $sideColor = $declarations["border-$side-color"] ?? null;
            $resolvedStyle = $style instanceof BorderStyle ? $style : BorderStyle::None;
            // CSS 2.2 §8.5.3: "if the value of the border-style property is none... the
            // computed value of the border width is 0" — el ancho USADO se calcula aquí, en
            // origen, para que ningún consumidor (BlockFlowContext, Painter) pueda leer
            // ->widthPx sin pasar por esta regla.
            $widthPx = match (true) {
                $resolvedStyle !== BorderStyle::Solid => 0.0,
                $width instanceof Length => $width->px,
                $width instanceof CssLength => $resolveCssLength($width),
                $width instanceof CalcExpr => $resolveCalcPure($width, "border-$side-width", 0.0),
                default => 0.0,
            };
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
        $rowGapPx = match (true) {
            $rowGapValue instanceof Length => $rowGapValue->px,
            $rowGapValue instanceof CssLength => $resolveCssLength($rowGapValue),
            $rowGapValue instanceof CalcExpr => $resolveCalcPure($rowGapValue, 'row-gap', 0.0),
            default => 0.0,
        };
        $columnGapValue = $declarations['column-gap'] ?? null;
        $columnGapPx = match (true) {
            $columnGapValue instanceof Length => $columnGapValue->px,
            $columnGapValue instanceof CssLength => $resolveCssLength($columnGapValue),
            $columnGapValue instanceof CalcExpr => $resolveCalcPure($columnGapValue, 'column-gap', 0.0),
            default => 0.0,
        };
        $flexGrowValue = $declarations['flex-grow'] ?? null;
        $flexGrow = is_float($flexGrowValue) ? $flexGrowValue : 0.0;
        $flexShrinkValue = $declarations['flex-shrink'] ?? null;
        $flexShrink = is_float($flexShrinkValue) ? $flexShrinkValue : 1.0;
        // flex-basis: la longhand/shorthand emiten 'auto' (string), un LengthPercentage o un
        // CssLength simbólico (em/rem, M6-T3, resuelto contra el font-size propio/raíz igual que
        // el resto de longitudes no-font-size); el sentinel 'auto' y la ausencia de declaración
        // colapsan al mismo null (= auto), igual que el resto de propiedades opcionales de este
        // método.
        $flexBasisValue = $declarations['flex-basis'] ?? null;
        $flexBasis = match (true) {
            $flexBasisValue instanceof LengthPercentage => $flexBasisValue,
            $flexBasisValue instanceof CssLength => LengthPercentage::px($resolveCssLength($flexBasisValue)),
            $flexBasisValue instanceof CalcExpr => $resolveCalcLengthPercentage($flexBasisValue),
            default => null,
        };

        // M5-T2: border-spacing SÍ hereda (CSS 2.2 §17.6.1) — a diferencia de todo lo demás en
        // esta sección, el fallback sin declaración propia es $parent->borderSpacingPx, no 0.0.
        $borderSpacingValue = $declarations['border-spacing'] ?? null;
        $borderSpacingPx = match (true) {
            $borderSpacingValue instanceof Length => $borderSpacingValue->px,
            $borderSpacingValue instanceof CssLength => $resolveCssLength($borderSpacingValue),
            $borderSpacingValue instanceof CalcExpr => $resolveCalcPure($borderSpacingValue, 'border-spacing', $parent->borderSpacingPx),
            default => $parent->borderSpacingPx,
        };

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
            $customProperties,
        );
    }
}
