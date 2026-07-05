<?php

// tests/EndToEnd/RenderTest.php
declare(strict_types=1);

use Pliego\Engine;

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
