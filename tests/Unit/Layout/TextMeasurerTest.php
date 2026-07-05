<?php

declare(strict_types=1);

use Pliego\Layout\TextMeasurer;
use Pliego\Text\FontCatalog;

beforeEach(function (): void {
    $this->measurer = new TextMeasurer();
    $catalog = FontCatalog::withDefaults();
    $this->regular = $catalog->select('default', 400, false);
    $this->bold = $catalog->select('default', 700, false);
});

it('measures longer text as wider', function () {
    expect($this->measurer->widthOf('Hola mundo', $this->regular, 16.0))
        ->toBeGreaterThan($this->measurer->widthOf('Hola', $this->regular, 16.0));
});
it('scales width linearly with font size', function () {
    $w16 = $this->measurer->widthOf('Hola', $this->regular, 16.0);
    expect($this->measurer->widthOf('Hola', $this->regular, 32.0))->toEqualWithDelta($w16 * 2, 0.001);
});
it('measures different faces of the same text with different widths', function () {
    expect($this->measurer->widthOf('Hola', $this->bold, 16.0))
        ->toBeGreaterThan($this->measurer->widthOf('Hola', $this->regular, 16.0));
});
it('computes line height as 1.2 times the font size', fn() =>
    expect($this->measurer->lineHeight(20.0))->toBe(24.0));
it('computes ascent proportionally to font size', function () {
    $ascent16 = $this->measurer->ascent($this->regular, 16.0);
    expect($this->measurer->ascent($this->regular, 32.0))->toEqualWithDelta($ascent16 * 2, 0.001);
});
it('computes descent as a positive magnitude', function () {
    expect($this->measurer->descent($this->regular, 16.0))->toBeGreaterThan(0.0);
});
