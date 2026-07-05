<?php

declare(strict_types=1);

namespace Pliego\Page;

enum PaperSize
{
    case A4;

    public function widthPx(): float
    {
        return match ($this) {
            self::A4 => 210.0 / 25.4 * 96.0,
        };  // 793.70
    }

    public function heightPx(): float
    {
        return match ($this) {
            self::A4 => 297.0 / 25.4 * 96.0,
        };  // 1122.52
    }
}
