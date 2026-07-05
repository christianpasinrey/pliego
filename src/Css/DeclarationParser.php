<?php

declare(strict_types=1);

namespace Pliego\Css;

use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\Color;
use Pliego\Css\Value\Length;
use Pliego\Css\Value\LengthPercentage;

final class DeclarationParser
{
    /** font-size/height NO admiten % (M3+ para font-size; height no está en el contrato T2). */
    private const array LENGTH_PROPERTIES = ['font-size', 'height'];
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
    private const array KEYWORD_PROPERTIES = ['display' => ['block', 'none'], 'font-family' => null];

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
}
