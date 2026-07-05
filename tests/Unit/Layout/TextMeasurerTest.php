<?php

declare(strict_types=1);

use Pliego\Layout\TextMeasurer;
use Pliego\Text\TtfFont;

beforeEach(function (): void {
    $this->measurer = new TextMeasurer(TtfFont::fromFile(__DIR__ . '/../../../resources/fonts/DejaVuSans.ttf'));
});

it('measures longer text as wider', function () {
    expect($this->measurer->widthOf('Hola mundo', 16.0))
        ->toBeGreaterThan($this->measurer->widthOf('Hola', 16.0));
});
it('scales width linearly with font size', function () {
    $w16 = $this->measurer->widthOf('Hola', 16.0);
    expect($this->measurer->widthOf('Hola', 32.0))->toEqualWithDelta($w16 * 2, 0.001);
});
it('computes line height as 1.2 times the font size', fn() =>
    expect($this->measurer->lineHeight(20.0))->toBe(24.0));
