<?php

// tests/Unit/Layout/FragmentDumperTest.php
declare(strict_types=1);

use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\Color;
use Pliego\Layout\Fragment\BorderSet;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\ImageFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\FragmentDumper;
use Pliego\Layout\Geometry\Rect;

/**
 * Unit-level contract for FragmentDumper (M1-T10 brief): fragment tree -> plain nested arrays
 * suitable for json_encode()/golden comparisons. Exercised here in isolation (hand-built
 * Fragment objects, no HTML/CSS pipeline) so the exact key names, key order and rounding
 * behaviour are locked down independently of the golden-document tests in
 * FragmentDumperGoldenTest.php.
 */
it('dumps a box fragment with a stable key order and a hex background', function () {
    $text = new TextFragment(
        new Rect(1.234, 2.346, 3.127, 4.991),
        'hi',
        7.006,
        16.0,
        new Color(0, 0, 0),
        'default:400:normal',
        false,
    );
    $box = new BoxFragment(
        new Rect(0.001, 0.002, 10.004, 20.006),
        new Color(255, 0, 0),
        [$text],
        BorderSet::none(),
    );

    $dump = new FragmentDumper()->dump($box);

    expect($dump)->toBe([
        'type' => 'box',
        'rect' => [0.0, 0.0, 10.0, 20.01],
        'background' => '#ff0000',
        'borders' => null,
        'atomic' => false,
        'children' => [
            [
                'type' => 'text',
                'rect' => [1.23, 2.35, 3.13, 4.99],
                'text' => 'hi',
                'faceKey' => 'default:400:normal',
                'underline' => false,
                'baselineY' => 7.01,
            ],
        ],
    ]);
});

it('dumps a box fragment with no background as null', function () {
    $box = new BoxFragment(new Rect(0.0, 0.0, 100.0, 50.0), null, [], BorderSet::none());

    $dump = new FragmentDumper()->dump($box);

    expect($dump)->toBe([
        'type' => 'box',
        'rect' => [0.0, 0.0, 100.0, 50.0],
        'background' => null,
        'borders' => null,
        'atomic' => false,
        'children' => [],
    ]);
});

it('dumps a box fragment with visible borders (M2-T8), a hex color per solid side and null for a none side', function () {
    $solidRedTop = new BorderSide(2.0, BorderStyle::Solid, new Color(255, 0, 0));
    $solidBlueRight = new BorderSide(1.5, BorderStyle::Solid, new Color(0, 0, 255));
    $noneBottom = new BorderSide(0.0, BorderStyle::None, null);
    // Solid style but zero width: css-backgrounds-3 "computed border width is 0 for style none",
    // and BorderSet::isVisible() already treats a zero-width solid side as invisible too — the
    // dump must agree and show this side as null, same as an explicit style:none side.
    $zeroWidthSolidLeft = new BorderSide(0.0, BorderStyle::Solid, new Color(0, 255, 0));
    $borders = new BorderSet($solidRedTop, $solidBlueRight, $noneBottom, $zeroWidthSolidLeft);
    $box = new BoxFragment(new Rect(0.0, 0.0, 100.0, 50.0), null, [], $borders);

    $dump = new FragmentDumper()->dump($box);

    expect($dump)->toBe([
        'type' => 'box',
        'rect' => [0.0, 0.0, 100.0, 50.0],
        'background' => null,
        'borders' => [
            'top' => ['widthPx' => 2.0, 'color' => '#ff0000'],
            'right' => ['widthPx' => 1.5, 'color' => '#0000ff'],
            'bottom' => null,
            'left' => null,
        ],
        'atomic' => false,
        'children' => [],
    ]);
});

it('dumps a text fragment with underline true', function () {
    $text = new TextFragment(
        new Rect(0.0, 0.0, 40.0, 19.2),
        'mundo',
        15.36,
        16.0,
        new Color(0, 0, 0),
        'default:700:italic',
        true,
    );

    $dump = new FragmentDumper()->dump($text);

    expect($dump)->toBe([
        'type' => 'text',
        'rect' => [0.0, 0.0, 40.0, 19.2],
        'text' => 'mundo',
        'faceKey' => 'default:700:italic',
        'underline' => true,
        'baselineY' => 15.36,
    ]);
});

it('dumps an image fragment with the imageKey reduced to its basename (M3-T3: machine-independent goldens)', function () {
    $image = new ImageFragment(new Rect(1.234, 2.346, 40.004, 30.006), '/some/machine/specific/path/photo.jpg');

    $dump = new FragmentDumper()->dump($image);

    expect($dump)->toBe([
        'type' => 'image',
        'rect' => [1.23, 2.35, 40.0, 30.01],
        'imageKey' => 'photo.jpg',
    ]);
});
