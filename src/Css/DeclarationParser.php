<?php

declare(strict_types=1);

namespace Pliego\Css;

use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\Color;
use Pliego\Css\Value\Length;
use Pliego\Css\Value\LengthPercentage;

final class DeclarationParser
{
    /** font-size/height/row-gap/column-gap NO admiten % (M3+ para font-size; height no está en
     * el contrato T2; row-gap/column-gap son px-only en M4, css-flexbox-1 §8.1 nota "% fuera de
     * alcance aquí" — Length::fromCss ya rechaza % de forma natural, generando el warning). */
    private const array LENGTH_PROPERTIES = ['font-size', 'height', 'row-gap', 'column-gap'];
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
     */
    private const array NON_NEGATIVE_PROPERTIES = ['padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'width'];
    private const array COLOR_PROPERTIES = ['color', 'background-color'];
    private const array KEYWORD_PROPERTIES = [
        'display' => ['block', 'none', 'flex'],
        'font-family' => null,
        'box-sizing' => ['content-box', 'border-box'],
        // css-flexbox-1 §5.1/§5.2/§8.2/§8.3: *-reverse, wrap-reverse, space-around/evenly y
        // baseline son válidos en CSS pero fuera de alcance en M4 — al no estar en la lista
        // "allowed" caen al warning genérico de KEYWORD_PROPERTIES, igual que box-sizing:padding-box.
        'flex-direction' => ['row', 'column'],
        'flex-wrap' => ['nowrap', 'wrap'],
        'justify-content' => ['flex-start', 'center', 'flex-end', 'space-between'],
        'align-items' => ['stretch', 'flex-start', 'center', 'flex-end'],
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
            $lengthPercentage = LengthPercentage::fromCss($value);
            if ($lengthPercentage === null) {
                return $this->warn("Unsupported length for $property: $value");
            }
            if ($lengthPercentage->value < 0.0 && in_array($property, self::NON_NEGATIVE_PROPERTIES, true)) {
                return $this->warn("Negative value not allowed for $property: $value");
            }
            return [$property => $lengthPercentage];
        }
        if (in_array($property, self::LENGTH_PROPERTIES, true)) {
            $length = Length::fromCss($value);
            if ($length === null) {
                return $this->warn("Unsupported length for $property: $value");
            }
            // font-size y height (únicos miembros de LENGTH_PROPERTIES) son siempre no-negativos.
            if ($length->px < 0.0) {
                return $this->warn("Negative value not allowed for $property: $value");
            }
            return [$property => $length];
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

    /** @return array<string, mixed> */
    private function parseBorderWidth(string $property, string $value): array
    {
        $length = $this->borderWidthFromToken($value);
        if ($length === null) {
            return $this->warn("Unsupported border width for $property: $value");
        }
        if ($length->px < 0.0) {
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

    private function borderWidthFromToken(string $token): ?Length
    {
        $keyword = strtolower($token);
        if (array_key_exists($keyword, self::BORDER_WIDTH_KEYWORDS)) {
            return Length::px(self::BORDER_WIDTH_KEYWORDS[$keyword]);
        }
        return Length::fromCss($token);
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
        $tokens = preg_split('/\s+/', $value) ?: [];
        if ($tokens === [] || $tokens === ['']) {
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
                if ($tokenWidth->px < 0.0) {
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
     * propiedades en NON_NEGATIVE_PROPERTIES — así que se descarta con warning. % en
     * line-height (relativo al propio font-size) es M3+, igual que en font-size: no se
     * reconoce aquí y cae al warning genérico de "unsupported".
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
        $length = Length::fromCss($value);
        if ($length !== null) {
            if ($length->px < 0.0) {
                return $this->warn("Negative value not allowed for line-height: $value");
            }
            return ['line-height' => $length];
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
     * Ahora en LengthPercentage: acepta % mezclado con px (p.ej. "10px 5%").
     *
     * @return array<string, mixed>
     */
    private function expandBoxShorthand(string $property, string $value): array
    {
        $parts = preg_split('/\s+/', $value) ?: [];
        $lengths = array_map(LengthPercentage::fromCss(...), $parts);
        if (in_array(null, $lengths, true) || $lengths === []) {
            return $this->warn("Unsupported shorthand for $property: $value");
        }
        /** @var list<LengthPercentage> $lengths */
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
     * ejes, dos valores fijan fila y luego columna (nunca % en M4: Length rechaza % de forma
     * natural, propagando el warning genérico de shorthand).
     *
     * @return array<string, mixed>
     */
    private function expandGapShorthand(string $value): array
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        $lengths = array_map(Length::fromCss(...), $parts);
        if ($parts === [] || $parts === [''] || in_array(null, $lengths, true) || count($lengths) > 2) {
            return $this->warn("Unsupported shorthand for gap: $value");
        }
        /** @var list<Length> $lengths */
        foreach ($lengths as $length) {
            if ($length->px < 0.0) {
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
     * este parser) o un LengthPercentage no negativo (px/%). 'content' y cualquier otro token
     * inválido devuelven null, que el llamador convierte en warning.
     */
    private function flexBasisToken(string $token): LengthPercentage|string|null
    {
        if (strtolower(trim($token)) === 'auto') {
            return 'auto';
        }
        $length = LengthPercentage::fromCss($token);
        if ($length !== null && $length->value < 0.0) {
            return null;
        }
        return $length;
    }

    /**
     * css-flexbox-1 §7.1.1 — tabla completa del shorthand `flex`:
     *   none              → flex-grow:0    flex-shrink:0  flex-basis:auto
     *   initial           → flex-grow:0    flex-shrink:1  flex-basis:auto  (initial value)
     *   auto              → flex-grow:1    flex-shrink:1  flex-basis:auto
     *   <N>               → flex-grow:N    flex-shrink:1  flex-basis:0
     *   <width>           → flex-grow:1    flex-shrink:1  flex-basis:<width>
     *   <N> <M>           → flex-grow:N    flex-shrink:M  flex-basis:0
     *   <N> <width>       → flex-grow:N    flex-shrink:1  flex-basis:<width>
     *   <N> <M> <width>   → flex-grow:N    flex-shrink:M  flex-basis:<width>
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
        $tokens = preg_split('/\s+/', trim($value)) ?: [];
        if ($tokens === [] || $tokens === ['']) {
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
            return ['flex-grow' => (float) $token, 'flex-shrink' => 1.0, 'flex-basis' => LengthPercentage::zero()];
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
            return ['flex-grow' => $grow, 'flex-shrink' => (float) $second, 'flex-basis' => LengthPercentage::zero()];
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
