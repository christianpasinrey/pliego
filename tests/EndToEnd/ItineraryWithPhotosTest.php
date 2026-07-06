<?php

// tests/EndToEnd/ItineraryWithPhotosTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * M3-T5 brief: the image-carrying twin of ItinerarySkeletonTest — same target document (a
 * multi-page travel itinerary), now with a `<img>` photo per day card PLUS a small RGBA logo/map
 * thumbnail, exercising every M3 image capability TOGETHER in one realistic layout: JPEG
 * dedup (the same photo referenced from 6 cards produces exactly one XObject, `Do`-ed 6 times),
 * PNG alpha -> SMask, HTML `width` attribute sizing (the `cm` content-stream operator scaled
 * correctly from the fixture's own intrinsic aspect ratio), and zero warnings end to end — plus a
 * companion "soft failure" test for missing/remote photos (warnings, PDF still valid).
 *
 * The JPEG fixture is generated in-test via GD (extension_loaded('gd')) rather than committed —
 * same constraint as the rest of M3's fixtures (brief: "Fixtures de imagen GENERADAS
 * programáticamente en tests"). When GD isn't available, resources/images/tiny.jpg (committed
 * binary fixture, already 4:3 — same aspect ratio as the generated gradient) is copied instead,
 * so the dims-in-cm assertion holds either way without a GD-conditional expectation.
 */
const ITINERARY_PHOTO_DISPLAY_WIDTH = 120.0;

/** @return string absolute path to a fresh JPEG fixture (GD gradient, or a copy of tiny.jpg) */
function itineraryPhotoFixture(): string
{
    $path = sys_get_temp_dir() . '/pliego-itinerary-photo-' . getmypid() . '.jpg';
    if (extension_loaded('gd')) {
        $width = 200;
        $height = 150; // 4:3, same ratio as the committed tiny.jpg fallback
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            $ratio = $y / ($height - 1);
            $color = imagecolorallocate($image, (int) (60 + 160 * $ratio), (int) (140 * (1 - $ratio)), 110);
            imageline($image, 0, $y, $width - 1, $y, $color);
        }
        imagejpeg($image, $path, 85);
        imagedestroy($image);
    } else {
        copy(__DIR__ . '/../../resources/images/tiny.jpg', $path);
    }
    return $path;
}

/** @return array{0: int, 1: int} [widthPx, heightPx] */
function itineraryPhotoDims(string $path): array
{
    $info = getimagesize($path);
    expect($info)->not->toBeFalse();
    return [(int) $info[0], (int) $info[1]];
}

function itineraryPhotoCard(int $day, string $place, string $photoFileName): string
{
    return '<div class="card">'
        . "<img src=\"$photoFileName\" width=\"" . ITINERARY_PHOTO_DISPLAY_WIDTH . '">'
        . "<p class=\"day\">$place — Giorno $day</p>"
        . "<p class=\"data\">Pernottamento a $place</p>"
        . '</div>';
}

it('renders 6 itinerary cards sharing one deduplicated JPEG XObject plus one RGBA PNG thumbnail, with correctly scaled cm dims and zero warnings', function () {
    $photoPath = itineraryPhotoFixture();
    [$photoWidthPx, $photoHeightPx] = itineraryPhotoDims($photoPath);
    $ratio = $photoHeightPx / $photoWidthPx;
    $pngPath = __DIR__ . '/../../resources/images/tiny-rgba-paeth.png';

    $places = ['Sarria', 'Portomarín', 'Palas de Rei', 'Arzúa', 'O Pedrouzo', 'Santiago'];
    $cards = '';
    foreach ($places as $i => $place) {
        $cards .= itineraryPhotoCard($i + 1, $place, basename($photoPath));
    }
    $html = '<body>'
        . '<div class="header">Cammino francese da Sarria</div>'
        . '<div class="band">Itinerario</div>'
        . $cards
        // Absolute path (resolvePath() treats it as such regardless of ->basePath()): a distinct
        // RGBA PNG mixed into the SAME document as the deduplicated JPEG.
        . "<div class=\"card\"><img src=\"$pngPath\"><p>Mappa del percorso</p></div>"
        . '</body>';
    $css = '.header { background-color: #163a6b; color: #ffffff; padding: 16px; font-size: 20px }
    .band { background-color: #ffd500; padding: 10px; font-size: 18px; margin: 0 0 10px 0 }
    .card { background-color: #f4f4f4; padding: 12px; margin: 0 0 10px 0 }
    .day { font-weight: bold; margin: 4px 0 }
    .data { color: #555555 }';

    $path = sys_get_temp_dir() . '/pliego-itinerary-photos.pdf';
    $report = Engine::make()
        ->basePath(dirname($photoPath))
        ->stylesheet($css)
        ->render($html)
        ->save($path);
    $pdf = (string) file_get_contents($path);
    @unlink($photoPath);

    expect($report->warnings)->toBe([]);

    // Structurally valid PDF (same technique as ItinerarySkeletonTest/KitchenSinkTest).
    expect($pdf)->toStartWith('%PDF-1.7');
    expect(preg_match('/startxref\n(\d+)\n%%EOF\s*$/', $pdf, $m))->toBe(1);
    expect(substr($pdf, (int) $m[1], 4))->toBe('xref');

    // The same JPEG photo, referenced from 6 cards, dedups into exactly one DCTDecode XObject
    // (registered first -> "Im1"), Do-ed once per card.
    expect(substr_count($pdf, '/Filter /DCTDecode'))->toBe(1);
    expect(substr_count($pdf, '/Im1 Do'))->toBe(6);

    // The RGBA PNG (registered second -> "Im2") gets its own SMask, distinct colorspaces for the
    // main image (DeviceRGB) and the alpha plane (DeviceGray) — same wiring as
    // ImageRegistryTest/RenderTest's dedicated RGBA test, now inside a realistic multi-image doc.
    expect(preg_match('/\/SMask (\d+) 0 R/', $pdf, $sm))->toBe(1);
    expect($pdf)->toContain('/ColorSpace /DeviceGray');
    expect($pdf)->toContain('/ColorSpace /DeviceRGB');

    // Dims scaled correctly in the `cm` content-stream operator (ISO 32000-1 §8.10.2): the HTML
    // width="120" attribute drives the content width, height derived from the fixture's OWN
    // intrinsic aspect ratio (BlockFlowContext::resolveReplacedSize()) -- not hardcoded, so this
    // holds whether the GD-generated gradient or the tiny.jpg fallback ran above.
    $expectedHeightPx = ITINERARY_PHOTO_DISPLAY_WIDTH * $ratio;
    $expectedWPt = sprintf('%.2F', ITINERARY_PHOTO_DISPLAY_WIDTH * 0.75);
    $expectedHPt = sprintf('%.2F', $expectedHeightPx * 0.75);
    expect(substr_count($pdf, "$expectedWPt 0 0 $expectedHPt "))->toBe(6);
});

it('reports missing and remote itinerary photos as warnings, PDF still structurally valid (M3-T5)', function () {
    $path = sys_get_temp_dir() . '/pliego-itinerary-photos-missing.pdf';
    $html = '<body>'
        . '<div class="card"><img src="no-such-photo.jpg"><p class="day">Sarria — Giorno 1</p></div>'
        . '<div class="card"><img src="https://example.com/photo.jpg"><p class="day">Portomarín — Giorno 2</p></div>'
        . '</body>';
    $report = Engine::make()
        ->basePath(__DIR__ . '/../../resources/images')
        ->stylesheet('.card { background-color: #f4f4f4; padding: 12px; margin: 0 0 10px 0 }')
        ->render($html)
        ->save($path);
    $pdf = (string) file_get_contents($path);

    expect($report->warnings)->toHaveCount(2);
    expect($pdf)->toStartWith('%PDF-1.7');
    expect(preg_match('/startxref\n(\d+)\n%%EOF\s*$/', $pdf, $m))->toBe(1);
    expect(substr($pdf, (int) $m[1], 4))->toBe('xref');
    expect($pdf)->not->toContain('/Subtype /Image'); // neither photo ever produced an XObject
});
