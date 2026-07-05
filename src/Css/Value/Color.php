<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

final readonly class Color
{
    private const array KEYWORDS = [
        'black' => [0, 0, 0], 'white' => [255, 255, 255], 'red' => [255, 0, 0],
        'green' => [0, 128, 0], 'blue' => [0, 0, 255], 'yellow' => [255, 255, 0],
        'gray' => [128, 128, 128], 'grey' => [128, 128, 128], 'silver' => [192, 192, 192],
        'maroon' => [128, 0, 0], 'navy' => [0, 0, 128], 'olive' => [128, 128, 0],
        'purple' => [128, 0, 128], 'teal' => [0, 128, 128], 'aqua' => [0, 255, 255],
        'fuchsia' => [255, 0, 255],
    ];

    public function __construct(public int $r, public int $g, public int $b) {}

    public static function fromCss(string $value): ?self
    {
        $value = strtolower(trim($value));
        if (isset(self::KEYWORDS[$value])) {
            [$r, $g, $b] = self::KEYWORDS[$value];
            return new self($r, $g, $b);
        }
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value, $m) === 1) {
            $hex = $m[1];
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            return new self((int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2)));
        }
        return null;
    }
}
