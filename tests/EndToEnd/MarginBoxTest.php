<?php

// tests/EndToEnd/MarginBoxTest.php
declare(strict_types=1);

use Pliego\Engine;
use Pliego\Layout\TextMeasurer;
use Pliego\Text\FontFace;
use Pliego\Text\TtfFont;

/**
 * M2-T7: @page margin boxes with counter(page)/counter(pages) (css-page-3 §6.5.3), rendered via
 * deferred Form XObjects when a box needs counter(pages) (unknown until every page is laid out),
 * or painted directly into the page content stream otherwise (cheaper, no XObject needed).
 */

/**
 * Forces several pages: enough paragraphs to overflow a single A4 content area at the default
 * margin. Deliberately digit-free: the ordering-guard test below needs every digit glyph in the
 * document to come ONLY from margin-box counter(page)/counter(pages) labels, never from ordinary
 * body-text painting, or it couldn't tell the two glyph-registration paths apart.
 */
function marginBoxHtml(int $paragraphs = 200): string
{
    $body = str_repeat('<p>Linea de contenido con texto suficiente para ocupar espacio vertical real.</p>', $paragraphs);
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

/**
 * Concatenates the entries of every begin/endbfchar block found in the PDF (ToUnicode CMap,
 * ISO 32000-1 §9.10.3: "<CID hex4> <UTF-16BE hex4>" pairs, one per glyph actually embedded in the
 * subset). Distinct from a raw substring search over the whole PDF: a glyph id can also appear as
 * a `<hex> Tj` content-stream operand without being in the subset's ToUnicode map at all — only
 * a hit inside an actual beginbfchar/endbfchar block proves the glyph was registered before
 * FontEmbedder::flush() ran.
 */
function toUnicodeBfCharEntriesOf(string $pdf): string
{
    preg_match_all('/\d+ beginbfchar\n(.*?)\nendbfchar/s', $pdf, $matches);
    expect($matches[1])->not->toBeEmpty();
    return implode('', $matches[1]);
}

/**
 * [startXPx, endXPx) of a directly-painted label (PdfCanvas::fillTextAtPage() emits
 * "... <x> <y> Td <hex> Tj ..."): startXPx read straight off the Td x operand (pt -> px), endXPx
 * = startXPx + the label's own measured width (same TextMeasurer/face/size MarginBoxPainter uses)
 * — i.e., the actual painted footprint of that run of text, regardless of which column it landed in.
 *
 * @return array{0: float, 1: float}
 */
function xRangeOfLabel(string $pdf, string $label): array
{
    $hex = glyphHexOf($label);
    preg_match('/([\d.]+) [\d.]+ Td <' . $hex . '> Tj/', $pdf, $m);
    expect($m)->not->toBeEmpty();
    $startXPx = ((float) $m[1]) / 0.75;

    $font = TtfFont::fromFile(__DIR__ . '/../../resources/fonts/DejaVuSans.ttf');
    $face = new FontFace('default:400:normal', $font);
    $widthPx = (new TextMeasurer())->widthOf($label, $face, 10.0);

    return [$startXPx, $startXPx + $widthPx];
}

it('lays out three same-strip margin boxes in non-overlapping columns, even with realistic long labels', function () {
    // Regression guard: MarginBoxPainter used to align each box's text within the FULL content
    // width regardless of position, so a left box's text could extend well past where a center
    // or right box's text began — the (invisible) box bounds overlapping was harmless, but with
    // three boxes actually present and long-enough strings the PAINTED TEXT itself collided.
    // Fixed by splitting the strip into 3 equal columns (contentWidth/3) and confining each box
    // to its own column.
    $path = sys_get_temp_dir() . '/pliego-marginbox-columns.pdf';
    $left = 'Documento confidencial y reservado';
    $center = 'Seccion intermedia del informe';
    $right = 'Revision final antes de firmar';
    $css = "@page {
        @bottom-left { content: \"$left\"; }
        @bottom-center { content: \"$center\"; }
        @bottom-right { content: \"$right\"; }
    }";
    Engine::make()->stylesheet($css)->render('<body><p>Solo una pagina.</p></body>')->save($path);
    $pdf = (string) file_get_contents($path);

    [$leftStart, $leftEnd] = xRangeOfLabel($pdf, $left);
    [$centerStart, $centerEnd] = xRangeOfLabel($pdf, $center);
    [$rightStart, $rightEnd] = xRangeOfLabel($pdf, $right);

    // Sanity: left, center and right did land in that left-to-right order...
    expect($leftStart)->toBeLessThan($centerStart);
    expect($centerStart)->toBeLessThan($rightStart);
    // ...and, the actual point of this test, none of their painted x-ranges overlap.
    expect($leftEnd)->toBeLessThanOrEqual($centerStart);
    expect($centerEnd)->toBeLessThanOrEqual($rightStart);
});

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
    // AFTER FontRegistry::flushAll(), the digit glyphs used ONLY by the deferred @bottom-center
    // label (counter(page)/counter(pages)) would be missing from the embedded subset's ToUnicode
    // CMap. marginBoxHtml()'s BODY is digit-free on purpose, so every digit glyph found anywhere
    // in the PDF can only have been registered by this label's FontEmbedder::encode() call inside
    // the deferred opsBuilder (PdfWriter::writeDeferred()) — a fixture whose body ALSO contained
    // digits (the previous version of this test did: "Linea ... numero $i") would pass even with
    // the order broken, since those digits register via ordinary body-text painting regardless.
    $path = sys_get_temp_dir() . '/pliego-marginbox-subset-order.pdf';
    $css = '@page { @bottom-center { content: counter(page) " de " counter(pages); } }';
    $report = Engine::make()->stylesheet($css)->render(marginBoxHtml())->save($path);
    $pdf = (string) file_get_contents($path);
    $totalPages = $report->pageCount;
    expect($totalPages)->toBeGreaterThanOrEqual(3);

    $font = TtfFont::fromFile(__DIR__ . '/../../resources/fonts/DejaVuSans.ttf');
    $cmapEntries = toUnicodeBfCharEntriesOf($pdf);
    for ($page = 1; $page <= $totalPages; $page++) {
        foreach (str_split((string) $page) as $digit) {
            $gidHex = sprintf('%04X', $font->glyphId(mb_ord($digit)));
            expect($cmapEntries)->toContain("<$gidHex> <"); // present as a ToUnicode bfchar entry
        }
    }
});
