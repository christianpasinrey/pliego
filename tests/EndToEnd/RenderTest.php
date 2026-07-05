<?php

// tests/EndToEnd/RenderTest.php
declare(strict_types=1);

use Pliego\Engine;
use Pliego\Style\FontStyle;

function sampleHtml(int $paragraphs): string
{
    $body = '<h1>Factura FA-2026-0001</h1>';
    for ($i = 0; $i < $paragraphs; $i++) {
        $body .= "<p>Línea de concepto número $i con su descripción correspondiente y detalle adicional.</p>";
    }
    return "<body>$body</body>";
}

const SAMPLE_CSS = 'h1 { font-size: 28px; color: #8b5e34; margin: 0 0 16px 0 }
p { margin: 0 0 8px 0 } .box { background-color: #eee; padding: 12px }';

it('renders a valid single-page PDF', function () {
    $path = sys_get_temp_dir() . '/pliego-e2e-1.pdf';
    $report = Engine::make()->stylesheet(SAMPLE_CSS)->render(sampleHtml(3))->save($path);
    $pdf = (string) file_get_contents($path);
    expect($pdf)->toStartWith('%PDF-1.7');
    expect($report->pageCount)->toBe(1);
    expect(substr_count($pdf, '/Type /Page /Parent'))->toBe(1);
});
it('splits long content across pages in streaming', function () {
    $path = sys_get_temp_dir() . '/pliego-e2e-2.pdf';
    $report = Engine::make()->stylesheet(SAMPLE_CSS)->render(sampleHtml(80))->save($path);
    expect($report->pageCount)->toBeGreaterThan(1);
});
it('reports unsupported CSS as warnings without failing', function () {
    $path = sys_get_temp_dir() . '/pliego-e2e-3.pdf';
    $report = Engine::make()->stylesheet('p { float: left; color: #f00 }')->render(sampleHtml(1))->save($path);
    expect($report->warnings)->not->toBeEmpty();
    expect((string) file_get_contents($path))->toStartWith('%PDF-1.7');
});
it('keeps memory bounded on large documents (streaming O(page))', function () {
    $before = memory_get_peak_usage(true);
    Engine::make()->render(sampleHtml(2000))->save(sys_get_temp_dir() . '/pliego-e2e-4.pdf');
    // El box/fragment tree completo sí vive en memoria en M0 (streaming pleno = M2);
    // este umbral holgado detecta regresiones groseras (acumular todas las páginas pintadas).
    expect(memory_get_peak_usage(true) - $before)->toBeLessThan(128 * 1024 * 1024);
});

it('embeds a separate font object per used face end-to-end (regular + bold)', function () {
    // M1-T9: FontRegistry crea/embebe una cara por faceKey realmente usado; <b> resuelve a la
    // cara "default:700:normal" (builtin de FontCatalog::withDefaults()), distinta de la
    // regular usada por el resto del texto.
    $path = sys_get_temp_dir() . '/pliego-e2e-multiface.pdf';
    Engine::make()->render('<body><p>Texto normal <b>y texto en negrita</b>.</p></body>')->save($path);
    $pdf = (string) file_get_contents($path);

    expect(substr_count($pdf, '/Subtype /Type0'))->toBe(2);
    expect(substr_count($pdf, '/FontFile2'))->toBe(2);
});

it('registers an extra font family via ->font() and embeds it only when referenced', function () {
    // ->font() añade una cara nueva al catálogo; aquí reusamos DejaVuSans-Bold.ttf bajo la
    // familia 'acme' solo para comprobar el wiring Engine -> FontCatalog -> FontRegistry (no
    // nos interesa el glifo en sí). Como ninguna regla CSS referencia 'acme', el documento solo
    // debe seguir usando la cara 'default' (1 sola Type0) — el catálogo puede tener caras
    // registradas sin usar sin que eso embeba nada.
    $path = sys_get_temp_dir() . '/pliego-e2e-extra-font.pdf';
    $ttf = __DIR__ . '/../../resources/fonts/DejaVuSans-Bold.ttf';
    Engine::make()->font('acme', 400, FontStyle::Normal, $ttf)->render(sampleHtml(1))->save($path);
    $pdf = (string) file_get_contents($path);

    expect($pdf)->toStartWith('%PDF-1.7');
    expect(substr_count($pdf, '/Subtype /Type0'))->toBe(1);

    // Ahora sí se referencia 'acme' desde CSS: debe embeberse como cara adicional (2 Type0).
    $path2 = sys_get_temp_dir() . '/pliego-e2e-extra-font-used.pdf';
    Engine::make()->font('acme', 400, FontStyle::Normal, $ttf)
        ->stylesheet('h1 { font-family: acme }')
        ->render(sampleHtml(1))->save($path2);
    $pdf2 = (string) file_get_contents($path2);

    expect(substr_count($pdf2, '/Subtype /Type0'))->toBe(2);
});

it('paints solid borders as filled rects beyond the background, in css-backgrounds-3 painting order (M2-T5)', function () {
    // Una única caja con fondo + borde uniforme visible en los 4 lados: 1 "re f" para el fondo
    // + 4 "re f" para los lados del borde (Painter::paintBorders). Nada más en el documento
    // pinta rects (body sin fondo, sin otras cajas con borde/background).
    $path = sys_get_temp_dir() . '/pliego-e2e-border.pdf';
    Engine::make()
        ->stylesheet('.box { background-color: #eee; border: 2px solid #000; padding: 12px }')
        ->render('<body><div class="box">Con borde</div></body>')
        ->save($path);
    $pdf = (string) file_get_contents($path);

    expect(substr_count($pdf, ' re f'))->toBe(5);

    // El color de relleno del borde (negro) debe preceder a exactamente 4 operadores "re f"
    // (los 4 lados) — el texto "Con borde" también es negro por defecto (color inicial CSS 2.2),
    // pero eso es un bloque BT...Tj ET, no un "re f", así que no se cuela en este conteo.
    $blackFill = sprintf('%.3F %.3F %.3F rg', 0, 0, 0);
    $pattern = '/^' . preg_quote($blackFill, '/') . ' [\d.]+ [\d.]+ [\d.]+ [\d.]+ re f$/m';
    expect(preg_match_all($pattern, $pdf))->toBe(4);
});
