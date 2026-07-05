<?php

declare(strict_types=1);

use Pliego\Css\Value\Color;

it('parses 6-digit hex', fn() => expect(Color::fromCss('#8b5e34'))
    ->r->toBe(139)->g->toBe(94)->b->toBe(52));
it('parses 3-digit hex', fn() => expect(Color::fromCss('#f00'))
    ->r->toBe(255)->g->toBe(0)->b->toBe(0));
it('parses keywords case-insensitively', fn() => expect(Color::fromCss('White'))
    ->r->toBe(255)->g->toBe(255)->b->toBe(255));
it('returns null for unsupported values', fn() => expect(Color::fromCss('rgb(1,2,3)'))->toBeNull());
