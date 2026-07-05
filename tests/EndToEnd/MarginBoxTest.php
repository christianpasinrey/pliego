<?php

// tests/EndToEnd/MarginBoxTest.php
declare(strict_types=1);

use Pliego\Engine;
use Pliego\Text\TtfFont;

/**
 * M2-T7: @page margin boxes with counter(page)/counter(pages) (css-page-3 §6.5.3), rendered via
 * deferred Form XObjects when a box needs counter(pages) (unknown until every page is laid out),
 * or painted directly into the page content stream otherwise (cheaper, no XObject needed).
 */

/** Forces several pages: enough paragraphs to overflow a single A4 content area at the default margin. */
function marginBoxHtml(int $paragraphs = 200): string
{
    $body = '';
    for ($i = 0; $i < $paragraphs; $i++) {
        $body .= "<p>Linea de contenido numero $i con texto suficiente para ocupar espacio vertical real.</p>";
    }
    return "<body>$body</body>";
}

function glyphHexOf(string $text): string
{
    $font = TtfFont::fromFile(__DIR__ . '/../../resources/fonts/DejaVuSans.ttf');
    $hex = '';
    foreach (mb_str_split($text) as $char) {
        $hex .= sprintf('%04X', $font->glyphId(mb_ord($char)));
    }
    return $hex;
}

it('paints an @bottom-center "Pagina X de Y" label per page via one deferred XObject each, with the correct glyphs for X and the real total Y', function () {
    $path = sys_get_temp_dir() . '/pliego-marginbox-pages.pdf';
    $css = '@page { @bottom-center { content: "Pagina " counter(page) " de " counter(pages); } }';
    $report = Engine::make()->stylesheet($css)->render(marginBoxHtml())->save($path);
    $pdf = (string) file_get_contents($path);

    expect($report->pageCount)->toBeGreaterThanOrEqual(3);
    $totalPages = $report->pageCount;

    // One /XOn Do per page (one deferred XObject per page, since each captures a different
    // already-known page number but the SAME unresolved counter(pages)).
    expect(substr_count($pdf, ' Do Q'))->toBe($totalPages);
    expect(substr_count($pdf, '/Subtype /Form'))->toBe($totalPages);

    for ($page = 1; $page <= $totalPages; $page++) {
        $label = "Pagina $page de $totalPages";
        expect($pdf)->toContain('<' . glyphHexOf($label) . '> Tj');
    }
});

it('paints an @bottom-left label with only counter(page) directly into the page stream, without any deferred XObject', function () {
    $path = sys_get_temp_dir() . '/pliego-marginbox-direct.pdf';
    $css = '@page { @bottom-left { content: "Pag. " counter(page); } }';
    $report = Engine::make()->stylesheet($css)->render(marginBoxHtml())->save($path);
    $pdf = (string) file_get_contents($path);

    expect($report->pageCount)->toBeGreaterThanOrEqual(3);
    $totalPages = $report->pageCount;

    // No counter(pages) anywhere in this document -> no deferred XObject at all.
    expect($pdf)->not->toContain('/Subtype /Form');
    expect($pdf)->not->toContain(' Do Q');

    for ($page = 1; $page <= $totalPages; $page++) {
        $label = "Pag. $page";
        expect($pdf)->toContain('<' . glyphHexOf($label) . '> Tj');
    }
});

it('paints the margin-box label in the default face, 10px, at #555555', function () {
    $path = sys_get_temp_dir() . '/pliego-marginbox-style.pdf';
    $css = '@page { @bottom-left { content: "x" counter(page); } }';
    Engine::make()->stylesheet($css)->render('<body><p>Solo una pagina.</p></body>')->save($path);
    $pdf = (string) file_get_contents($path);

    expect($pdf)->toContain(sprintf('%.2F Tf', 10.0 * 0.75));
    expect($pdf)->toContain('0.333 0.333 0.333 rg'); // #555555 = 85/255
});

it('mixes a deferred (counter(pages)) box and a direct (page-only) box on the same document without cross-talk', function () {
    $path = sys_get_temp_dir() . '/pliego-marginbox-mixed.pdf';
    $css = '@page {
        @bottom-left { content: "Pag. " counter(page); }
        @bottom-right { content: counter(page) "/" counter(pages); }
    }';
    $report = Engine::make()->stylesheet($css)->render(marginBoxHtml())->save($path);
    $pdf = (string) file_get_contents($path);
    $totalPages = $report->pageCount;
    expect($totalPages)->toBeGreaterThanOrEqual(3);

    // Exactly one deferred XObject per page (the @bottom-right box); the @bottom-left box paints
    // directly, so it contributes no /Subtype /Form / Do.
    expect(substr_count($pdf, '/Subtype /Form'))->toBe($totalPages);
    expect(substr_count($pdf, ' Do Q'))->toBe($totalPages);

    for ($page = 1; $page <= $totalPages; $page++) {
        expect($pdf)->toContain('<' . glyphHexOf("Pag. $page") . '> Tj');
        expect($pdf)->toContain('<' . glyphHexOf("$page/$totalPages") . '> Tj');
    }
});

it('keeps the deferred builders\' glyphs inside the flushed font subset (encode() before flushAll())', function () {
    // Regression guard for the ordering pitfall documented in PdfWriter: if writeDeferred() ran
    // AFTER FontRegistry::flushAll(), the digits used only inside margin-box labels (e.g. '3' in
    // "de 3" on a 3-page-or-more doc) would be missing from the embedded subset's ToUnicode CMap.
    $path = sys_get_temp_dir() . '/pliego-marginbox-subset-order.pdf';
    $css = '@page { @bottom-center { content: counter(page) " de " counter(pages); } }';
    $report = Engine::make()->stylesheet($css)->render(marginBoxHtml(1000))->save($path);
    $pdf = (string) file_get_contents($path);
    $totalPages = $report->pageCount;
    expect($totalPages)->toBeGreaterThanOrEqual(10); // guarantees a 2-digit page number appears

    $font = TtfFont::fromFile(__DIR__ . '/../../resources/fonts/DejaVuSans.ttf');
    foreach (str_split((string) $totalPages) as $digit) {
        $gidHex = sprintf('%04X', $font->glyphId(mb_ord($digit)));
        expect($pdf)->toContain("<$gidHex>"); // present in a ToUnicode beginbfchar entry
    }
});
