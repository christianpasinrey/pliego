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
    // M7-T6: `float` gained real support (Style\FloatSide) — swapped to `writing-mode`
    // (explicitly excluded-with-warning by the M7 milestone brief) to keep demonstrating warning
    // discipline for a genuinely unsupported property.
    $path = sys_get_temp_dir() . '/pliego-e2e-3.pdf';
    $report = Engine::make()->stylesheet('p { writing-mode: vertical-rl; color: #f00 }')->render(sampleHtml(1))->save($path);
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
    // debe seguir usando la familia 'default' — el catálogo puede tener caras registradas sin usar
    // sin que eso embeba nada. sampleHtml() SIEMPRE incluye un <h1> (M7-T2: la hoja UA le da
    // font-weight:bold, ver UserAgentStylesheet), así que 'default' ya aporta 2 caras por sí sola
    // (400 para el <p>, 700 para el <h1>) incluso sin 'acme' de por medio.
    $path = sys_get_temp_dir() . '/pliego-e2e-extra-font.pdf';
    $ttf = __DIR__ . '/../../resources/fonts/DejaVuSans-Bold.ttf';
    Engine::make()->font('acme', 400, FontStyle::Normal, $ttf)->render(sampleHtml(1))->save($path);
    $pdf = (string) file_get_contents($path);

    expect($pdf)->toStartWith('%PDF-1.7');
    expect(substr_count($pdf, '/Subtype /Type0'))->toBe(2);

    // Ahora sí se referencia 'acme' desde CSS: debe embeberse como cara adicional (2 Type0).
    $path2 = sys_get_temp_dir() . '/pliego-e2e-extra-font-used.pdf';
    Engine::make()->font('acme', 400, FontStyle::Normal, $ttf)
        ->stylesheet('h1 { font-family: acme }')
        ->render(sampleHtml(1))->save($path2);
    $pdf2 = (string) file_get_contents($path2);

    expect(substr_count($pdf2, '/Subtype /Type0'))->toBe(2);
});

it('resolves relative <img> src against ->basePath() and renders a valid PDF (M3-T2)', function () {
    // M3-T3 aún no consume ImageBox en layout (ver BlockFlowContext), así que esto solo verifica
    // el wiring Engine -> BoxTreeBuilder -> WarningCollector -> RenderReport: 0 warnings cuando la
    // imagen se resuelve y carga correctamente contra basePath.
    $path = sys_get_temp_dir() . '/pliego-e2e-image-ok.pdf';
    $report = Engine::make()
        ->basePath(__DIR__ . '/../../resources/images')
        ->render('<body><img src="tiny.jpg"></body>')
        ->save($path);
    expect($report->warnings)->toBe([]);
    expect((string) file_get_contents($path))->toStartWith('%PDF-1.7');
});

it('reports a missing/remote <img> src as a soft warning, PDF still valid (M3-T2)', function () {
    $path = sys_get_temp_dir() . '/pliego-e2e-image-missing.pdf';
    $report = Engine::make()
        ->basePath(__DIR__ . '/../../resources/images')
        ->render('<body><img src="does-not-exist.png"><img src="https://example.com/a.jpg"></body>')
        ->save($path);
    expect($report->warnings)->toHaveCount(2);
    expect((string) file_get_contents($path))->toStartWith('%PDF-1.7');
});

it('reports a truncated-IDAT PNG (short after inflate) as a soft warning, PDF still valid (M3 final-review)', function () {
    // A PNG with a valid IHDR + a valid zlib stream that inflates to fewer bytes than
    // height*(stride+1) requires -- PngImage::fromBytes() must throw ImageException instead of
    // letting the raw offset run past the string end (see PngImageTest's dedicated unit test),
    // and the ImageException must surface here as a normal warning + still-valid PDF, same soft
    // failure contract as the missing/remote cases above.
    $width = 2;
    $height = 2;
    $ihdr = pack('N', $width) . pack('N', $height) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0);
    $shortRaw = str_repeat("\x00", 7); // needs 2 * (1 + 2*3) = 14 bytes, only 7 supplied
    $idatData = (string) zlib_encode($shortRaw, ZLIB_ENCODING_DEFLATE);
    $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $bytes = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', $ihdr) . $chunk('IDAT', $idatData) . $chunk('IEND', '');

    $pngPath = sys_get_temp_dir() . '/pliego-e2e-truncated-idat.png';
    file_put_contents($pngPath, $bytes);
    $path = sys_get_temp_dir() . '/pliego-e2e-image-truncated.pdf';
    try {
        $report = Engine::make()
            ->basePath(sys_get_temp_dir())
            ->render('<body><img src="pliego-e2e-truncated-idat.png"></body>')
            ->save($path);
    } finally {
        unlink($pngPath);
    }

    expect($report->warnings)->toHaveCount(1);
    expect($report->warnings[0])->toContain('PNG data truncated');
    $pdf = (string) file_get_contents($path);
    expect($pdf)->toStartWith('%PDF-1.7');
    expect(preg_match('/startxref\n(\d+)\n%%EOF\s*$/', $pdf, $m))->toBe(1);
    expect(substr($pdf, (int) $m[1], 4))->toBe('xref');
});

it('paints a <img src="..."> JPEG as an image XObject, in a structurally valid PDF (M3-T4)', function () {
    $path = sys_get_temp_dir() . '/pliego-e2e-image-paint.pdf';
    $report = Engine::make()
        ->basePath(__DIR__ . '/../../resources/images')
        ->render('<body><img src="tiny.jpg"></body>')
        ->save($path);
    $pdf = (string) file_get_contents($path);

    expect($report->warnings)->toBe([]);
    // Structurally valid PDF: header + a well-formed xref/trailer (same technique as RenderTest's
    // other structural checks / PdfWriterTest).
    expect($pdf)->toStartWith('%PDF-1.7');
    expect(preg_match('/startxref\n(\d+)\n%%EOF\s*$/', $pdf, $m))->toBe(1);
    expect(substr($pdf, (int) $m[1], 4))->toBe('xref');

    expect($pdf)->toContain('/Type /XObject')->toContain('/Subtype /Image')->toContain('/Filter /DCTDecode');
    expect($pdf)->toContain(' cm /Im1 Do Q');
});

it('dedups the same <img src> referenced 3 times into a single image XObject (M3-T4)', function () {
    $path = sys_get_temp_dir() . '/pliego-e2e-image-dedup.pdf';
    Engine::make()
        ->basePath(__DIR__ . '/../../resources/images')
        ->render('<body><img src="tiny.jpg"><img src="tiny.jpg"><img src="tiny.jpg"></body>')
        ->save($path);
    $pdf = (string) file_get_contents($path);

    expect(substr_count($pdf, '/Subtype /Image'))->toBe(1); // one XObject definition...
    expect(substr_count($pdf, '/Im1 Do'))->toBe(3);          // ...Do-ed 3 times
});

it('paints an RGBA PNG with alpha as a DeviceRGB XObject plus its own DeviceGray SMask XObject, through the full Engine pipeline (M3-T5)', function () {
    // T4's ImageRegistryTest already covers this at the registry level (unit test, PdfWriter
    // wired by hand); this is the same assertion driven end-to-end through Engine::render() —
    // HtmlParser -> BoxTreeBuilder -> BlockFlowContext -> Painter -> PdfCanvas -> ImageRegistry.
    $path = sys_get_temp_dir() . '/pliego-e2e-image-rgba.pdf';
    $report = Engine::make()
        ->basePath(__DIR__ . '/../../resources/images')
        ->render('<body><img src="tiny-rgba-paeth.png"></body>')
        ->save($path);
    $pdf = (string) file_get_contents($path);

    expect($report->warnings)->toBe([]);
    expect($pdf)->toStartWith('%PDF-1.7');
    expect(preg_match('/\/SMask (\d+) 0 R/', $pdf, $m))->toBe(1);
    expect($pdf)->toContain('/ColorSpace /DeviceGray'); // the SMask's own colorspace
    expect($pdf)->toContain('/ColorSpace /DeviceRGB');  // the main image's colorspace
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

// --- M6-T2: real selector matching end to end (:nth-child(odd), striped table) -----------------

it('renders a striped table with tr:nth-child(odd) painting alternating row backgrounds', function () {
    // 4 <tr> rows, no <thead>: rows 1 and 3 (1-based, :nth-child(odd)) get the light fill; rows
    // 2 and 4 stay unpainted. Each striped <tr> paints its OWN background rect (TableFormattingContext,
    // see docblock: "sí pinta su propio background, detrás de las celdas") — a real combinator/
    // pseudo-class rule (M6-T1 staged it; M6-T2 makes it actually apply).
    $path = sys_get_temp_dir() . '/pliego-e2e-striped-table.pdf';
    $css = 'tr:nth-child(odd) { background-color: #eeeeee }';
    $html = '<body><table>'
        . '<tr><td>Fila 1</td></tr>'
        . '<tr><td>Fila 2</td></tr>'
        . '<tr><td>Fila 3</td></tr>'
        . '<tr><td>Fila 4</td></tr>'
        . '</table></body>';
    $report = Engine::make()->stylesheet($css)->render($html)->save($path);
    $pdf = (string) file_get_contents($path);

    expect($report->warnings)->toBe([]);
    $stripeFill = sprintf('%.3F %.3F %.3F rg', 0xee / 255, 0xee / 255, 0xee / 255);
    $pattern = '/^' . preg_quote($stripeFill, '/') . ' [\d.]+ [\d.]+ [\d.]+ [\d.]+ re f$/m';
    // Exactamente 2 rects rellenos con el color de la franja: filas 1 y 3, no 2 ni 4.
    expect(preg_match_all($pattern, $pdf))->toBe(2);
});

// --- M5-T1: warning channel end to end (Engine -> BlockFlowContext/FlexFormattingContext/Paginator) --

it('warns when an atomic flex fragment is taller than the page and stays unsplit (M5-T1)', function () {
    // Default A4 + default 48px margins -> content height ~1026px; an <img> forced to 1200px
    // tall via the HTML height attribute inside a flex row container makes the WHOLE container
    // (atomic for pagination, see FlexFormattingContext's docblock) taller than the page.
    $path = sys_get_temp_dir() . '/pliego-e2e-warning-atomic-taller.pdf';
    $report = Engine::make()
        ->basePath(__DIR__ . '/../../resources/images')
        ->stylesheet('.row { display: flex }')
        ->render('<body><div class="row"><img src="tiny.jpg" height="1200"></div></body>')
        ->save($path);

    expect($report->warnings)->toContain('atomic fragment taller than page, kept unsplit');
    expect((string) file_get_contents($path))->toStartWith('%PDF-1.7');
});

it('warns when a flex column\'s justify-content has no effect because the container has no declared height (M5-T1)', function () {
    $path = sys_get_temp_dir() . '/pliego-e2e-warning-column-justify.pdf';
    $report = Engine::make()
        ->stylesheet('.col { display: flex; flex-direction: column; justify-content: center }
                      .item { height: 20px }')
        ->render('<body><div class="col"><div class="item"></div><div class="item"></div></div></body>')
        ->save($path);

    expect($report->warnings)->toContain(
        'flex column: justify-content has no effect without a declared container height (auto height hugs content)',
    );
    expect((string) file_get_contents($path))->toStartWith('%PDF-1.7');
});
