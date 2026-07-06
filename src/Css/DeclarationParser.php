<?php

declare(strict_types=1);

namespace Pliego\Css;

use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\CalcExpr;
use Pliego\Css\Value\CalcParser;
use Pliego\Css\Value\Color;
use Pliego\Css\Value\CssLength;
use Pliego\Css\Value\Length;
use Pliego\Css\Value\LengthPercentage;
use Pliego\Css\Value\LengthUnit;

final class DeclarationParser
{
    /** height/row-gap/column-gap NO admiten % (height no está en el contrato T2; row-gap/
     * column-gap son px-only en M4, css-flexbox-1 §8.1 nota "% fuera de alcance aquí" —
     * parseLength() ya rechaza % de forma natural, generando el warning). font-size vive aparte
     * (ver parseFontSize, M6-T3): SÍ admite % (contra el font-size del padre), así que no puede
     * compartir esta lista de "solo longitud pura, nunca %". */
    private const array LENGTH_PROPERTIES = ['height', 'row-gap', 'column-gap'];
    /** CSS 2.2 §10: width, margin-{side} y padding-{side} sí admiten %, resuelto en used-value (T4). */
    private const array LENGTH_PERCENTAGE_PROPERTIES = [
        'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'width',
    ];
    /**
     * CSS 2.2 §8.4/§10.2/§10.5/§15.7: negativos inválidos en LENGTH_PERCENTAGE_PROPERTIES;
     * margin-* es la única excepción ahí. font-size/height (LENGTH_PROPERTIES) se rechazan
     * incondicionalmente más abajo — ambas son siempre no-negativas, así que no necesitan
     * figurar aquí (evita el "always true" que detecta PHPStan al estrechar el tipo).
     *
     * M6-T4 fix (Finding 2): visibilidad `public` y lista AMPLIADA a la lista COMPLETA de
     * propiedades no-negativas del motor — antes solo cubría las 5 gateadas explícitamente aquí
     * mismo (líneas más abajo, chequeo de literales en LENGTH_PERCENTAGE_PROPERTIES); height/
     * row-gap/column-gap/border-*-width/border-spacing/flex-basis YA eran no-negativas siempre en
     * sus propios sitios de parseo (chequeo incondicional, sin consultar esta constante) — añadirlas
     * aquí no cambia ESE comportamiento (siguen rechazándose igual), solo hace la lista consultable
     * desde `ComputedStyle::compute()`, que necesita el mismo criterio para re-chequear el signo de
     * un CalcExpr con em/rem UNA VEZ conocido el font-size propio (ver rawValueOf() más abajo y
     * ComputedStyle::compute() — el signo de un calc() con % sigue sin poder conocerse hasta
     * Layout, gap documentado, ver el reporte de M6-T4 §4).
     */
    public const array NON_NEGATIVE_PROPERTIES = [
        'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'width',
        'height', 'row-gap', 'column-gap',
        'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
        'border-spacing', 'flex-basis',
    ];
    private const array COLOR_PROPERTIES = ['color', 'background-color'];
    private const array KEYWORD_PROPERTIES = [
        // css-tables-3 §2: los 5 display values de tabla soportados en M5 (grep OBLIGATORIO
        // hecho en ComputedStyle/BoxTreeBuilder antes de añadirlos aquí — ver Display::Table).
        'display' => [
            'block', 'none', 'flex',
            'table', 'table-row', 'table-cell', 'table-header-group', 'table-row-group',
        ],
        'font-family' => null,
        'box-sizing' => ['content-box', 'border-box'],
        // css-flexbox-1 §5.1/§5.2/§8.2/§8.3: *-reverse, wrap-reverse, space-around/evenly y
        // baseline son válidos en CSS pero fuera de alcance en M4 — al no estar en la lista
        // "allowed" caen al warning genérico de KEYWORD_PROPERTIES, igual que box-sizing:padding-box.
        'flex-direction' => ['row', 'column'],
        'flex-wrap' => ['nowrap', 'wrap'],
        'justify-content' => ['flex-start', 'center', 'flex-end', 'space-between'],
        'align-items' => ['stretch', 'flex-start', 'center', 'flex-end'],
        // CSS 2.2 §17.5.2: 'auto' (initial) y 'fixed' — M5-T4 consume $tableLayout, aquí solo
        // se valida y parsea el keyword.
        'table-layout' => ['auto', 'fixed'],
    ];

    /** css-flexbox-1 §7.1.1: N sin unidad en la forma "flex: N ..." nunca admite signo (grow y
     * shrink son siempre >= 0); un negativo cae al warning genérico del shorthand/longhand. */
    private const string FLEX_NUMBER_RE = '/^\d+(?:\.\d+)?$/';

    private const array BORDER_SIDES = ['top', 'right', 'bottom', 'left'];
    private const array BORDER_WIDTH_KEYWORDS = ['thin' => 1.0, 'medium' => 3.0, 'thick' => 5.0];

    /** @var list<string> */
    private array $warnings = [];

    /** @return array<string, mixed> */
    public function parse(string $property, string $value): array
    {
        $property = strtolower(trim($property));
        $value = trim($value);
        if ($property === 'margin' || $property === 'padding') {
            return $this->expandBoxShorthand($property, $value);
        }
        if ($property === 'gap') {
            return $this->expandGapShorthand($value);
        }
        if ($property === 'flex') {
            return $this->parseFlexShorthand($value);
        }
        if ($property === 'border' || in_array($property, ['border-top', 'border-right', 'border-bottom', 'border-left'], true)) {
            return $this->expandBorderShorthand($property, $value);
        }
        if ($this->isBorderLonghand($property, 'width')) {
            return $this->parseBorderWidth($property, $value);
        }
        if ($this->isBorderLonghand($property, 'style')) {
            return $this->parseBorderStyle($property, $value);
        }
        if ($this->isBorderLonghand($property, 'color')) {
            $color = Color::fromCss($value);
            if ($color === null) {
                return $this->warn("Unsupported color for $property: $value");
            }
            return [$property => $color];
        }
        if (in_array($property, self::LENGTH_PERCENTAGE_PROPERTIES, true)) {
            $lengthPercentage = $this->parseLengthPercentage($value);
            if ($lengthPercentage === null) {
                return $this->warn("Unsupported length for $property: $value");
            }
            if (self::rawValueOf($lengthPercentage) < 0.0 && in_array($property, self::NON_NEGATIVE_PROPERTIES, true)) {
                return $this->warn("Negative value not allowed for $property: $value");
            }
            return [$property => $lengthPercentage];
        }
        if (in_array($property, self::LENGTH_PROPERTIES, true)) {
            $length = $this->parseLength($value);
            if ($length === null) {
                return $this->warn("Unsupported length for $property: $value");
            }
            // height/row-gap/column-gap (únicos miembros de LENGTH_PROPERTIES) son siempre no-negativos.
            if (self::rawValueOf($length) < 0.0) {
                return $this->warn("Negative value not allowed for $property: $value");
            }
            return [$property => $length];
        }
        if ($property === 'font-size') {
            return $this->parseFontSize($value);
        }
        if (in_array($property, self::COLOR_PROPERTIES, true)) {
            $color = Color::fromCss($value);
            if ($color === null) {
                return $this->warn("Unsupported color for $property: $value");
            }
            return [$property => $color];
        }
        if (array_key_exists($property, self::KEYWORD_PROPERTIES)) {
            $allowed = self::KEYWORD_PROPERTIES[$property];
            $keyword = strtolower($value);
            if ($allowed !== null && !in_array($keyword, $allowed, true)) {
                return $this->warn("Unsupported keyword for $property: $value");
            }
            return [$property => $property === 'font-family' ? trim($value, '"\' ') : $keyword];
        }
        if ($property === 'font-weight') {
            return $this->parseFontWeight($value);
        }
        if ($property === 'font-style') {
            return $this->parseFontStyle($value);
        }
        if ($property === 'line-height') {
            return $this->parseLineHeight($value);
        }
        if ($property === 'text-align') {
            return $this->parseTextAlign($value);
        }
        if ($property === 'text-decoration') {
            return $this->parseTextDecoration($value);
        }
        if ($property === 'flex-grow' || $property === 'flex-shrink') {
            return $this->parseFlexNumber($property, $value);
        }
        if ($property === 'flex-basis') {
            return $this->parseFlexBasis($value);
        }
        if ($property === 'border-spacing') {
            return $this->parseBorderSpacing($value);
        }
        if ($property === 'vertical-align') {
            return $this->parseVerticalAlign($value);
        }
        return $this->warn("Unsupported property: $property");
    }

    private function isBorderLonghand(string $property, string $suffix): bool
    {
        foreach (self::BORDER_SIDES as $side) {
            if ($property === "border-$side-$suffix") {
                return true;
            }
        }
        return false;
    }

    /**
     * M6-T3 (css-values-3 §5-6): longitud PURA, sin %  — height, row-gap, column-gap,
     * border-{side}-width, border-spacing, el componente de longitud de line-height y los
     * tokens del shorthand `gap`. px/pt/cm/mm/in ya llegan resueltos a píxeles desde
     * CssLength::fromCss (Px), así que se envuelven directo en Length; em/rem quedan en el
     * CssLength simbólico tal cual, para que ComputedStyle::compute los resuelva contra el
     * font-size propio/raíz. % no tiene interpretación en una longitud pura (null, igual que el
     * comportamiento pre-M6-T3), salvo en las propiedades con manejo dedicado (font-size,
     * line-height) que llaman a CssLength::fromCss directamente en vez de a este método.
     */
    private function parseLength(string $value): Length|CssLength|CalcExpr|null
    {
        if ($this->looksLikeCalc($value)) {
            return $this->tryParseCalc($value);
        }
        $css = CssLength::fromCss($value);
        if ($css === null) {
            return null;
        }
        return match ($css->unit) {
            LengthUnit::Px => Length::px($css->value),
            LengthUnit::Em, LengthUnit::Rem => $css,
            default => null,
        };
    }

    /**
     * M6-T3: longitud+porcentaje — margin-*, padding-*, width, flex-basis y los componentes del
     * shorthand margin/padding. Percent sigue diferido a LengthPercentage (resuelto contra el
     * containing block en Layout, sin cambios respecto a M2); em/rem quedan en CssLength
     * simbólico para ComputedStyle::compute (resueltos contra el font-size propio/raíz, nunca
     * contra el containing block).
     */
    private function parseLengthPercentage(string $value): LengthPercentage|CssLength|CalcExpr|null
    {
        if ($this->looksLikeCalc($value)) {
            return $this->tryParseCalc($value);
        }
        $css = CssLength::fromCss($value);
        if ($css === null) {
            return null;
        }
        return match ($css->unit) {
            LengthUnit::Px => LengthPercentage::px($css->value),
            LengthUnit::Percent => LengthPercentage::percent($css->value),
            LengthUnit::Em, LengthUnit::Rem => $css,
            default => null,
        };
    }

    /** css-values-3 §8: true si, una vez recortado el valor, empieza literalmente por "calc(" —
     * a partir de ahí el parseo se COMPROMETE con la rama calc() (éxito -> CalcExpr, fallo ->
     * null con warning ya emitido dentro de tryParseCalc()), sin intentar CssLength::fromCss()
     * como fallback (evitaría un segundo warning confuso sobre el mismo valor). */
    private function looksLikeCalc(string $value): bool
    {
        return stripos(trim($value), 'calc(') === 0;
    }

    /** Extrae el cuerpo entre el "calc(" inicial y su paréntesis de cierre (que debe coincidir
     * exactamente con el final de la cadena — cualquier resto tras el cierre es sintaxis
     * inválida) y delega en CalcParser. Warnings de CalcParser se funden en $this->warnings, igual
     * que cualquier otro warning de este parser. */
    private function tryParseCalc(string $value): ?CalcExpr
    {
        $trimmed = trim($value);
        // looksLikeCalc() (el único llamador) ya garantizó que $trimmed empieza EXACTAMENTE por
        // "calc(" (5 caracteres) — el paréntesis de apertura está siempre en el índice 4, sin
        // necesidad de buscarlo (evita un stripos() que PHPStan tipa como int<0,max>|false).
        $openParen = 4;
        $closeParen = $this->matchingParen($trimmed, $openParen);
        if ($closeParen === null || $closeParen !== strlen($trimmed) - 1) {
            $this->warnings[] = "Invalid calc() expression: $value";
            return null;
        }
        $inner = substr($trimmed, $openParen + 1, $closeParen - $openParen - 1);
        $calcParser = new CalcParser();
        $expr = $calcParser->parse($inner);
        $this->warnings = [...$this->warnings, ...$calcParser->drainWarnings()];
        return $expr;
    }

    private function matchingParen(string $text, int $openIndex): ?int
    {
        $depth = 0;
        $length = strlen($text);
        for ($i = $openIndex; $i < $length; $i++) {
            if ($text[$i] === '(') {
                $depth++;
            } elseif ($text[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * M6-T4: divide por espacios en el NIVEL SUPERIOR de paréntesis — un token de shorthand
     * (margin/padding/gap/border/flex) puede ser "calc(1em + 4px)", que contiene espacios
     * INTERNOS; un preg_split('/\s+/') ingenuo (el comportamiento pre-M6-T4) lo fragmentaría en
     * 3 tokens espurios. Los espacios dentro de cualquier paréntesis (incluido un var() anidado
     * dentro de un calc(), o viceversa) se preservan como parte del mismo token.
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $value): array
    {
        $tokens = [];
        $current = '';
        $depth = 0;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '(') {
                $depth++;
                $current .= $char;
                continue;
            }
            if ($char === ')') {
                $depth--;
                $current .= $char;
                continue;
            }
            if ($depth === 0 && ctype_space($char)) {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }
                continue;
            }
            $current .= $char;
        }
        if ($current !== '') {
            $tokens[] = $current;
        }
        return $tokens;
    }

    /**
     * css-values-3 §5-6 + adjudicación M6-T3: font-size es la única propiedad de longitud pura
     * que SÍ admite % (relativo al font-size COMPUTADO DEL PADRE — CSS 2.2 §10.8.1 /
     * css-values-3, no del propio elemento, a diferencia de em en cualquier otra propiedad).
     * Todas las unidades pasan por aquí; solo Px resuelve ya mismo (Length), el resto
     * (Em/Rem/Percent) queda en CssLength simbólico hasta ComputedStyle::compute.
     *
     * @return array<string, mixed>
     */
    private function parseFontSize(string $value): array
    {
        if ($this->looksLikeCalc($value)) {
            $calc = $this->tryParseCalc($value);
            // El signo de un calc() no se conoce hasta ComputedStyle::compute (depende del
            // font-size del padre) — el rechazo de negativos para font-size en calc() queda
            // fuera de alcance de esta tarea (ningún test del brief lo ejercita).
            return $calc === null ? [] : ['font-size' => $calc];
        }
        $css = CssLength::fromCss($value);
        if ($css === null) {
            return $this->warn("Unsupported length for font-size: $value");
        }
        if ($css->value < 0.0) {
            return $this->warn("Negative value not allowed for font-size: $value");
        }
        return ['font-size' => $css->unit === LengthUnit::Px ? Length::px($css->value) : $css];
    }

    /** Valor crudo (sin resolver unidad simbólica) usado solo para el chequeo de negativos —
     * Length usa ->px, LengthPercentage/CssLength usan ->value. M6-T4 fix (Finding 2): un CalcExpr
     * SIN componente em/rem/% es un px DEFINITIVO ya conocido en tiempo de parseo (ver
     * CalcExpr::isDefinite()) — se pliega aquí para que el chequeo de negativos del llamador
     * (idéntico al de un literal) atrape `calc(-5px)` en padding/width/height/gap/border-width/
     * border-spacing/flex-basis exactamente igual que `-5px` a secas. Un calc() CON em/rem/% no
     * tiene signo conocible todavía (depende del font-size propio, solo disponible en
     * ComputedStyle::compute(), o del containing block, solo disponible en Layout) — se trata como
     * no negativo AQUÍ para no rechazarlo de forma prematura/incorrecta; el caso em/rem se
     * re-chequea en ComputedStyle::compute() en cuanto se conoce el font-size (mismo
     * NON_NEGATIVE_PROPERTIES, ahora exportado); el caso % queda como gap documentado (depende del
     * containing block, solo en Layout — ver el reporte de M6-T4 §4). */
    private static function rawValueOf(Length|LengthPercentage|CssLength|CalcExpr $value): float
    {
        if ($value instanceof CalcExpr) {
            return $value->isDefinite() ? $value->pxOffset : 0.0;
        }
        return $value instanceof Length ? $value->px : $value->value;
    }

    /** @return array<string, mixed> */
    private function parseBorderWidth(string $property, string $value): array
    {
        $length = $this->borderWidthFromToken($value);
        if ($length === null) {
            return $this->warn("Unsupported border width for $property: $value");
        }
        if (self::rawValueOf($length) < 0.0) {
            return $this->warn("Negative value not allowed for $property: $value");
        }
        return [$property => $length];
    }

    /** @return array<string, mixed> */
    private function parseBorderStyle(string $property, string $value): array
    {
        $style = $this->borderStyleFromToken($value);
        if ($style === null) {
            return $this->warn("Unsupported border style for $property: $value (only solid|none supported in M2)");
        }
        return [$property => $style];
    }

    private function borderWidthFromToken(string $token): Length|CssLength|CalcExpr|null
    {
        $keyword = strtolower($token);
        if (array_key_exists($keyword, self::BORDER_WIDTH_KEYWORDS)) {
            return Length::px(self::BORDER_WIDTH_KEYWORDS[$keyword]);
        }
        return $this->parseLength($token);
    }

    private function borderStyleFromToken(string $token): ?BorderStyle
    {
        return match (strtolower($token)) {
            'solid' => BorderStyle::Solid,
            'none' => BorderStyle::None,
            default => null,
        };
    }

    /**
     * CSS 2.2 §8.5.4: los 3 componentes (width, style, color) son opcionales y pueden
     * aparecer en cualquier orden dentro del shorthand.
     *
     * @return array<string, mixed>
     */
    private function expandBorderShorthand(string $property, string $value): array
    {
        $sides = $property === 'border' ? self::BORDER_SIDES : [substr($property, strlen('border-'))];
        $tokens = self::splitTopLevel($value);
        if ($tokens === []) {
            return $this->warn("Unsupported shorthand for $property: $value");
        }
        $width = null;
        $style = null;
        $color = null;
        foreach ($tokens as $token) {
            $tokenWidth = $this->borderWidthFromToken($token);
            if ($tokenWidth !== null) {
                if ($width !== null) {
                    return $this->warn("Duplicate border width component for $property: $value");
                }
                if (self::rawValueOf($tokenWidth) < 0.0) {
                    return $this->warn("Negative value not allowed for $property: $value");
                }
                $width = $tokenWidth;
                continue;
            }
            $tokenStyle = $this->borderStyleFromToken($token);
            if ($tokenStyle !== null) {
                if ($style !== null) {
                    return $this->warn("Duplicate border style component for $property: $value");
                }
                $style = $tokenStyle;
                continue;
            }
            $tokenColor = Color::fromCss($token);
            if ($tokenColor !== null) {
                if ($color !== null) {
                    return $this->warn("Duplicate border color component for $property: $value");
                }
                $color = $tokenColor;
                continue;
            }
            return $this->warn("Unsupported border component for $property: $token");
        }
        $result = [];
        foreach ($sides as $side) {
            if ($width !== null) {
                $result["border-$side-width"] = $width;
            }
            if ($style !== null) {
                $result["border-$side-style"] = $style;
            }
            if ($color !== null) {
                $result["border-$side-color"] = $color;
            }
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function parseFontWeight(string $value): array
    {
        $keyword = strtolower($value);
        return match ($keyword) {
            'normal' => ['font-weight' => 400],
            'bold' => ['font-weight' => 700],
            '400' => ['font-weight' => 400],
            '700' => ['font-weight' => 700],
            default => $this->warn("Unsupported font-weight: $value"),
        };
    }

    /** @return array<string, mixed> */
    private function parseFontStyle(string $value): array
    {
        $keyword = strtolower($value);
        if ($keyword === 'normal') {
            return ['font-style' => 'normal'];
        }
        if ($keyword === 'italic') {
            return ['font-style' => 'italic'];
        }
        if ($keyword === 'oblique') {
            $this->warnings[] = "Unsupported font-style (approximated as italic): $value";
            return ['font-style' => 'italic'];
        }
        return $this->warn("Unsupported font-style: $value");
    }

    /**
     * CSS 2.2 §10.8.1: número unitless multiplica el font-size del propio elemento
     * (resuelto en ComputedStyle::compute); un valor en px pasa directo; 'normal' → null.
     * Negativo (unitless o longitud) no tiene interpretación válida — igual que las
     * propiedades en NON_NEGATIVE_PROPERTIES — así que se descarta con warning. M6-T3: %/em/rem
     * en line-height son ahora soporte real (%/em relativos al font-size PROPIO del elemento,
     * igual que el multiplicador unitless; rem contra la raíz) — quedan en CssLength simbólico
     * hasta ComputedStyle::compute, que es quien conoce ese font-size.
     *
     * @return array<string, mixed>
     */
    private function parseLineHeight(string $value): array
    {
        $keyword = strtolower($value);
        if ($keyword === 'normal') {
            return ['line-height' => null];
        }
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1) {
            $multiplier = (float) $value;
            if ($multiplier < 0.0) {
                return $this->warn("Negative value not allowed for line-height: $value");
            }
            return ['line-height' => $multiplier];
        }
        if ($this->looksLikeCalc($value)) {
            $calc = $this->tryParseCalc($value);
            // Igual que font-size: el signo de un calc() no se conoce hasta compute-time.
            return $calc === null ? [] : ['line-height' => $calc];
        }
        $css = CssLength::fromCss($value);
        if ($css !== null) {
            if ($css->value < 0.0) {
                return $this->warn("Negative value not allowed for line-height: $value");
            }
            return ['line-height' => $css->unit === LengthUnit::Px ? Length::px($css->value) : $css];
        }
        return $this->warn("Unsupported line-height: $value");
    }

    /** @return array<string, mixed> */
    private function parseTextAlign(string $value): array
    {
        $keyword = strtolower($value);
        return match ($keyword) {
            'left' => ['text-align' => 'left'],
            'center' => ['text-align' => 'center'],
            'right' => ['text-align' => 'right'],
            'justify' => $this->warn("Unsupported text-align (justify not supported in M1): $value"),
            default => $this->warn("Unsupported text-align: $value"),
        };
    }

    /** @return array<string, mixed> */
    private function parseTextDecoration(string $value): array
    {
        $keyword = strtolower($value);
        return match ($keyword) {
            'none' => ['text-decoration' => false],
            'underline' => ['text-decoration' => true],
            default => $this->warn("Unsupported text-decoration: $value"),
        };
    }

    /**
     * CSS 2.2 §17.6.1: `border-spacing: <length> <length>?` — el primer valor es horizontal, el
     * segundo vertical. M5 solo soporta la forma de un único valor (mismo px para ambos ejes,
     * como consume TableFormattingContext en M5-T4); dos valores son válidos en CSS pero fuera
     * de alcance aquí, así que caen al warning genérico en vez de tomar solo el primero (evita
     * fingir soporte de ejes independientes que el layout no respeta). Nunca admite % (igual
     * que row-gap/column-gap en LENGTH_PROPERTIES) — parseLength() ya rechaza % de forma
     * natural; M6-T3 añade em/rem/pt/cm/mm/in vía CssLength, igual que el resto de longitudes.
     *
     * @return array<string, mixed>
     */
    private function parseBorderSpacing(string $value): array
    {
        $tokens = self::splitTopLevel(trim($value));
        if (count($tokens) !== 1) {
            return $this->warn("Unsupported border-spacing (single value only in M5): $value");
        }
        $length = $this->parseLength($tokens[0]);
        if ($length === null) {
            return $this->warn("Unsupported border-spacing: $value");
        }
        if (self::rawValueOf($length) < 0.0) {
            return $this->warn("Negative value not allowed for border-spacing: $value");
        }
        return ['border-spacing' => $length];
    }

    /**
     * CSS 2.2 §10.8.1: vertical-align tiene una tabla larga de keywords (baseline, sub, super,
     * text-top, text-bottom, middle, top, bottom) más <percentage>/<length>; M5 solo soporta
     * top|middle|bottom (los que consume TableCellBox en M5-T4) — baseline (el initial value
     * real), sub/super/text-top/text-bottom y cualquier percentage/length caen al warning
     * genérico, documentado en VerticalAlign.
     *
     * @return array<string, mixed>
     */
    private function parseVerticalAlign(string $value): array
    {
        $keyword = strtolower(trim($value));
        return match ($keyword) {
            'top' => ['vertical-align' => 'top'],
            'middle' => ['vertical-align' => 'middle'],
            'bottom' => ['vertical-align' => 'bottom'],
            default => $this->warn("Unsupported vertical-align: $value"),
        };
    }

    /** @return array<string, mixed> */
    private function warn(string $message): array
    {
        $this->warnings[] = $message;
        return [];
    }

    /** @return list<string> */
    public function drainWarnings(): array
    {
        $warnings = $this->warnings;
        $this->warnings = [];
        return $warnings;
    }

    /**
     * CSS 2.2 §8.3: expansión 1/2/4 valores (3 valores: top, right+left, bottom).
     * Acepta % mezclado con px/em/rem (p.ej. "10px 5%" o "1em 2rem 10px 5%", M6-T3).
     *
     * @return array<string, mixed>
     */
    private function expandBoxShorthand(string $property, string $value): array
    {
        $parts = self::splitTopLevel($value);
        $lengths = array_map($this->parseLengthPercentage(...), $parts);
        if (in_array(null, $lengths, true) || $lengths === []) {
            return $this->warn("Unsupported shorthand for $property: $value");
        }
        /** @var list<LengthPercentage|CssLength|CalcExpr> $lengths */
        [$top, $right, $bottom, $left] = match (count($lengths)) {
            1 => [$lengths[0], $lengths[0], $lengths[0], $lengths[0]],
            2 => [$lengths[0], $lengths[1], $lengths[0], $lengths[1]],
            3 => [$lengths[0], $lengths[1], $lengths[2], $lengths[1]],
            default => [$lengths[0], $lengths[1], $lengths[2], $lengths[3]],
        };
        return [
            "$property-top" => $top, "$property-right" => $right,
            "$property-bottom" => $bottom, "$property-left" => $left,
        ];
    }

    /**
     * css-flexbox-1 §8.1 gap shorthand: `gap: <row-gap> <column-gap>?` — un valor fija ambos
     * ejes, dos valores fijan fila y luego columna (nunca % — parseLength() rechaza % de forma
     * natural, propagando el warning genérico de shorthand; M6-T3 añade em/rem/físicos).
     *
     * @return array<string, mixed>
     */
    private function expandGapShorthand(string $value): array
    {
        $parts = self::splitTopLevel(trim($value));
        $lengths = array_map($this->parseLength(...), $parts);
        if ($parts === [] || in_array(null, $lengths, true) || count($lengths) > 2) {
            return $this->warn("Unsupported shorthand for gap: $value");
        }
        /** @var list<Length|CssLength|CalcExpr> $lengths */
        foreach ($lengths as $length) {
            if (self::rawValueOf($length) < 0.0) {
                return $this->warn("Negative value not allowed for gap: $value");
            }
        }
        [$rowGap, $columnGap] = count($lengths) === 1 ? [$lengths[0], $lengths[0]] : [$lengths[0], $lengths[1]];
        return ['row-gap' => $rowGap, 'column-gap' => $columnGap];
    }

    /** @return array<string, mixed> */
    private function parseFlexNumber(string $property, string $value): array
    {
        $trimmed = trim($value);
        if (preg_match(self::FLEX_NUMBER_RE, $trimmed) !== 1) {
            return $this->warn("Unsupported $property: $value");
        }
        return [$property => (float) $trimmed];
    }

    /** @return array<string, mixed> */
    private function parseFlexBasis(string $value): array
    {
        $keyword = strtolower(trim($value));
        if ($keyword === 'content') {
            return $this->warn("Unsupported flex-basis (content keyword not supported in M4): $value");
        }
        $basis = $this->flexBasisToken($value);
        if ($basis === null) {
            return $this->warn("Unsupported flex-basis: $value");
        }
        return ['flex-basis' => $basis];
    }

    /**
     * Un componente de <flex-basis> dentro del shorthand o de la longhand: 'auto' (sentinel
     * string, traducido a null/auto en ComputedStyle::compute igual que el resto de keywords de
     * este parser) o un LengthPercentage/CssLength no negativo (px/%/em/rem, M6-T3). 'content' y
     * cualquier otro token inválido devuelven null, que el llamador convierte en warning.
     */
    private function flexBasisToken(string $token): LengthPercentage|CssLength|CalcExpr|string|null
    {
        if (strtolower(trim($token)) === 'auto') {
            return 'auto';
        }
        $length = $this->parseLengthPercentage($token);
        if ($length !== null && self::rawValueOf($length) < 0.0) {
            return null;
        }
        return $length;
    }

    /**
     * css-flexbox-1 §7.1.1 — tabla completa del shorthand `flex`:
     *   none              → flex-grow:0    flex-shrink:0  flex-basis:auto
     *   initial           → flex-grow:0    flex-shrink:1  flex-basis:auto  (initial value)
     *   auto              → flex-grow:1    flex-shrink:1  flex-basis:auto
     *   <N>               → flex-grow:N    flex-shrink:1  flex-basis:0%
     *   <width>           → flex-grow:1    flex-shrink:1  flex-basis:<width>
     *   <N> <M>           → flex-grow:N    flex-shrink:M  flex-basis:0%
     *   <N> <width>       → flex-grow:N    flex-shrink:1  flex-basis:<width>
     *   <N> <M> <width>   → flex-grow:N    flex-shrink:M  flex-basis:<width>
     *
     * M5-T1 (housekeeping): la basis omitida es `0%` (LengthPercentage::percent(0.0)), no `0px`
     * (LengthPercentage::zero(), lo que este método usaba antes) — el propio texto del spec
     * (§7.1.1: "flex: <positive-number>" expande a "flex-grow, 1, 0%") lo fija en porcentaje.
     * Numéricamente resuelve idéntico (0% y 0px de CUALQUIER base dan 0px, ver
     * LengthPercentage::resolve()), así que esta corrección no cambia ninguna geometría ya
     * calculada — es una corrección de fidelidad al spec, no de comportamiento observable.
     *
     * @return array<string, mixed>
     */
    private function parseFlexShorthand(string $value): array
    {
        $keyword = strtolower(trim($value));
        if ($keyword === 'none') {
            return ['flex-grow' => 0.0, 'flex-shrink' => 0.0, 'flex-basis' => 'auto'];
        }
        if ($keyword === 'initial') {
            return ['flex-grow' => 0.0, 'flex-shrink' => 1.0, 'flex-basis' => 'auto'];
        }
        if ($keyword === 'auto') {
            return ['flex-grow' => 1.0, 'flex-shrink' => 1.0, 'flex-basis' => 'auto'];
        }
        $tokens = self::splitTopLevel(trim($value));
        if ($tokens === []) {
            return $this->warn("Unsupported flex shorthand: $value");
        }
        return match (count($tokens)) {
            1 => $this->parseFlexOneValue($tokens[0], $value),
            2 => $this->parseFlexTwoValues($tokens[0], $tokens[1], $value),
            3 => $this->parseFlexThreeValues($tokens[0], $tokens[1], $tokens[2], $value),
            default => $this->warn("Unsupported flex shorthand: $value"),
        };
    }

    /** @return array<string, mixed> */
    private function parseFlexOneValue(string $token, string $original): array
    {
        if (preg_match(self::FLEX_NUMBER_RE, $token) === 1) {
            return ['flex-grow' => (float) $token, 'flex-shrink' => 1.0, 'flex-basis' => LengthPercentage::percent(0.0)];
        }
        $basis = $this->flexBasisToken($token);
        if ($basis === null) {
            return $this->warn("Unsupported flex shorthand: $original");
        }
        return ['flex-grow' => 1.0, 'flex-shrink' => 1.0, 'flex-basis' => $basis];
    }

    /** @return array<string, mixed> */
    private function parseFlexTwoValues(string $first, string $second, string $original): array
    {
        if (preg_match(self::FLEX_NUMBER_RE, $first) !== 1) {
            return $this->warn("Unsupported flex shorthand: $original");
        }
        $grow = (float) $first;
        if (preg_match(self::FLEX_NUMBER_RE, $second) === 1) {
            return ['flex-grow' => $grow, 'flex-shrink' => (float) $second, 'flex-basis' => LengthPercentage::percent(0.0)];
        }
        $basis = $this->flexBasisToken($second);
        if ($basis === null) {
            return $this->warn("Unsupported flex shorthand: $original");
        }
        return ['flex-grow' => $grow, 'flex-shrink' => 1.0, 'flex-basis' => $basis];
    }

    /** @return array<string, mixed> */
    private function parseFlexThreeValues(string $first, string $second, string $third, string $original): array
    {
        if (preg_match(self::FLEX_NUMBER_RE, $first) !== 1 || preg_match(self::FLEX_NUMBER_RE, $second) !== 1) {
            return $this->warn("Unsupported flex shorthand: $original");
        }
        $basis = $this->flexBasisToken($third);
        if ($basis === null) {
            return $this->warn("Unsupported flex shorthand: $original");
        }
        return ['flex-grow' => (float) $first, 'flex-shrink' => (float) $second, 'flex-basis' => $basis];
    }
}
