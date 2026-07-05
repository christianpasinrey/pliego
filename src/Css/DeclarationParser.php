<?php

declare(strict_types=1);

namespace Pliego\Css;

use Pliego\Css\Value\Color;
use Pliego\Css\Value\Length;

final class DeclarationParser
{
    private const array LENGTH_PROPERTIES = [
        'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'font-size', 'width', 'height',
    ];
    private const array COLOR_PROPERTIES = ['color', 'background-color'];
    private const array KEYWORD_PROPERTIES = ['display' => ['block', 'none'], 'font-family' => null];

    /** @var list<string> */
    private array $warnings = [];

    /** @return array<string, mixed> */
    public function parse(string $property, string $value): array
    {
        $property = strtolower(trim($property));
        $value = trim($value);
        if ($property === 'margin' || $property === 'padding') {
            return $this->expandShorthand($property, $value);
        }
        if (in_array($property, self::LENGTH_PROPERTIES, true)) {
            $length = Length::fromCss($value);
            if ($length === null) {
                $this->warnings[] = "Unsupported length for $property: $value";
                return [];
            }
            return [$property => $length];
        }
        if (in_array($property, self::COLOR_PROPERTIES, true)) {
            $color = Color::fromCss($value);
            if ($color === null) {
                $this->warnings[] = "Unsupported color for $property: $value";
                return [];
            }
            return [$property => $color];
        }
        if (array_key_exists($property, self::KEYWORD_PROPERTIES)) {
            $allowed = self::KEYWORD_PROPERTIES[$property];
            $keyword = strtolower($value);
            if ($allowed !== null && !in_array($keyword, $allowed, true)) {
                $this->warnings[] = "Unsupported keyword for $property: $value";
                return [];
            }
            return [$property => $property === 'font-family' ? trim($value, '"\' ') : $keyword];
        }
        $this->warnings[] = "Unsupported property: $property";
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
     *
     * @return array<string, mixed>
     */
    private function expandShorthand(string $property, string $value): array
    {
        $parts = preg_split('/\s+/', $value) ?: [];
        $lengths = array_map(Length::fromCss(...), $parts);
        if (in_array(null, $lengths, true) || $lengths === []) {
            $this->warnings[] = "Unsupported shorthand for $property: $value";
            return [];
        }
        /** @var list<Length> $lengths */
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
