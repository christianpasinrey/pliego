<?php

declare(strict_types=1);

use Pliego\Css\Value\Length;

it('parses px values', fn() => expect(Length::fromCss('16px'))->px->toBe(16.0));
it('parses unitless zero', fn() => expect(Length::fromCss('0'))->px->toBe(0.0));
it('returns null for unsupported units', fn() => expect(Length::fromCss('2em'))->toBeNull());
