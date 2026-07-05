<?php

// tests/EndToEnd/ItinerarySkeletonTest.php
declare(strict_types=1);

use Pliego\Engine;
use Pliego\Text\TtfFont;

/**
 * M2-T8 brief: an image-free skeleton of the actual target document (the multi-page travel
 * itinerary from the README's Roadmap intro) that exercises every M2 capability TOGETHER, in one
 * realistic layout, instead of the isolated single-feature fixtures the rest of M2's tests use:
 * a header band (background + inverted text color), a bordered client-data block, a right-aligned
 * price box, a yellow section band, several repeating cards (background + bold title + a bordered
 * data row + a paragraph) that force the document past one page, and an `@page` rule with a
 * uniform margin plus two bottom margin boxes — one a literal string (painted directly, M2-T7's
 * cheap path) and one with `counter(page)`/`counter(pages)` (deferred to a Form XObject, since the
 * total page count isn't known until every page is laid out).
 */
const ITINERARY_HEADER_BG = '#163a6b';
const ITINERARY_BAND_BG = '#ffd500';
const ITINERARY_CARD_BG = '#f4f4f4';
const ITINERARY_CLIENT_BORDER = '#000000';
const ITINERARY_DATA_BORDER = '#cccccc';
const ITINERARY_FOOTER_SITE = 'tubuencamino.com';

function itineraryCss(): string
{
    return sprintf(
        <<<'CSS'
        body { font-size: 14px; color: #222222 }
        .header { background-color: %s; color: #ffffff; padding: 16px; font-size: 22px }
        .client { border: 1px solid %s; padding: 10px; margin: 10px 0 }
        .price { background-color: %s; padding: 14px; font-size: 18px; text-align: right; margin: 0 0 14px 0 }
        .band { background-color: %s; padding: 10px; font-size: 18px; margin: 0 0 10px 0 }
        .card { background-color: %s; padding: 12px; margin: 0 0 10px 0 }
        .day { font-weight: bold; margin: 0 0 4px 0; font-size: 16px }
        .data { border: 1px solid %s; padding: 6px; margin: 0 0 6px 0; color: #555555 }
        p { line-height: 1.45 }
        @page {
            margin: 60px;
            @bottom-left { content: "%s"; }
            @bottom-right { content: "Pagina " counter(page) " de " counter(pages); }
        }
        CSS,
        ITINERARY_HEADER_BG,
        ITINERARY_CLIENT_BORDER,
        ITINERARY_BAND_BG,
        ITINERARY_BAND_BG,
        ITINERARY_CARD_BG,
        ITINERARY_DATA_BORDER,
        ITINERARY_FOOTER_SITE,
    );
}

/** One itinerary day card: bold title, a bordered data row, a real paragraph — repeated to force pagination. */
function itineraryCard(int $day, string $place): string
{
    return '<div class="card">'
        . "<p class=\"day\">$place — Giorno $day</p>"
        . "<p class=\"data\">Data: " . sprintf('%02d', $day) . "/09/2026 · Pernottamento a $place</p>"
        . "<p>Una volta arrivato a $place, ti consigliamo di visitare la città e di goderti i suoi "
        . "monumenti e le sue strade, dove si respira già l'atmosfera del Cammino. Puoi prenotare "
        . "online o scriverci — siamo qui per aiutarti in ogni momento del tuo viaggio.</p>"
        . '</div>';
}

/** @return array{0: string, 1: int} [html, cardCount] */
function itinerarySkeletonHtml(): array
{
    $places = ['Sarria', 'Portomarín', 'Palas de Rei', 'Arzúa', 'O Pedrouzo', 'Santiago'];
    $cards = '';
    foreach ($places as $i => $place) {
        $cards .= itineraryCard($i + 1, $place);
    }
    $html = '<body>'
        . '<div class="header">Cammino francese da Sarria</div>'
        . '<div class="client"><p>Cliente: Livia Fernandez</p><p>Prenotazione n. 136961</p></div>'
        . '<div class="price">Prezzo a persona: 296,33 €</div>'
        . '<div class="band">Itinerario</div>'
        . $cards
        . '</body>';
    return [$html, count($places)];
}

function itineraryGlyphHexOf(string $text): string
{
    $font = TtfFont::fromFile(__DIR__ . '/../../resources/fonts/DejaVuSans.ttf');
    $hex = '';
    foreach (mb_str_split($text) as $char) {
        $hex .= sprintf('%04X', $font->glyphId(mb_ord($char)));
    }
    return $hex;
}

it('renders the itinerary skeleton as a structurally valid, multi-page PDF with zero warnings', function () {
    [$html] = itinerarySkeletonHtml();
    $path = sys_get_temp_dir() . '/pliego-itinerary-skeleton.pdf';
    $report = Engine::make()->stylesheet(itineraryCss())->render($html)->save($path);
    $pdf = (string) file_get_contents($path);

    // Structurally valid PDF: header + a well-formed xref/trailer (same technique as
    // TypographyTest/KitchenSinkTest).
    expect($pdf)->toStartWith('%PDF-1.7');
    expect(preg_match('/startxref\n(\d+)\n%%EOF\s*$/', $pdf, $m))->toBe(1);
    expect(substr($pdf, (int) $m[1], 4))->toBe('xref');

    // Header, client block, price box, band, borders and 6 real-text cards are all CSS 2.2/M2
    // declarations the engine understands as of M2-T7 — zero unsupported declarations.
    expect($report->warnings)->toBe([]);

    // 6 substantial cards under a 60px @page margin (vs. the Engine default 48px) overflow a
    // single A4 content area — this is the whole point of the fixture (a realistic document that
    // actually needs pagination, not a synthetic one-box repeat).
    expect($report->pageCount)->toBeGreaterThanOrEqual(2);
});

it('paints solid borders (client block + 6 bordered data rows) as filled rects beyond the header/price/band/card backgrounds', function () {
    [$html, $cardCount] = itinerarySkeletonHtml();
    $path = sys_get_temp_dir() . '/pliego-itinerary-borders.pdf';
    Engine::make()->stylesheet(itineraryCss())->render($html)->save($path);
    $pdf = (string) file_get_contents($path);

    // Backgrounds: header + price + band (3 fixed bands) + one per card. Paginator::flatten()
    // emits exactly one paintable leaf per box regardless of how many pages the document spans
    // (M1's documented rule: a leaf that doesn't fit is pushed whole to the next page, never
    // split/duplicated) — so this count is stable however many pages the render produces.
    $expectedBackgrounds = 3 + $cardCount;
    // Borders: the client data block (4 sides) + each card's bordered .data row (4 sides).
    $expectedBorders = 4 + 4 * $cardCount;
    $expectedReF = $expectedBackgrounds + $expectedBorders;

    expect(substr_count($pdf, ' re f'))->toBe($expectedReF);

    // The actual point of this test (brief: "border ops present, beyond backgrounds"): rects
    // painted in the two border colors (client block black, data-row light gray) that precede a
    // " re f" operator, distinct from any of the three background fills.
    $clientBorderFill = sprintf('%.3F %.3F %.3F rg', 0x00 / 255, 0x00 / 255, 0x00 / 255);
    $dataBorderFill = sprintf('%.3F %.3F %.3F rg', 0xcc / 255, 0xcc / 255, 0xcc / 255);
    $borderPattern = '/^(?:' . preg_quote($clientBorderFill, '/') . '|' . preg_quote($dataBorderFill, '/')
        . ') [\d.]+ [\d.]+ [\d.]+ [\d.]+ re f$/m';
    expect(preg_match_all($borderPattern, $pdf))->toBe($expectedBorders);
});

it('paints the @bottom-left site label directly (no XObject) and the @bottom-right "Pagina X de Y" label per page via a deferred XObject each', function () {
    [$html] = itinerarySkeletonHtml();
    $path = sys_get_temp_dir() . '/pliego-itinerary-footer.pdf';
    $report = Engine::make()->stylesheet(itineraryCss())->render($html)->save($path);
    $pdf = (string) file_get_contents($path);
    $totalPages = $report->pageCount;
    expect($totalPages)->toBeGreaterThanOrEqual(2);

    // Exactly one deferred Form XObject per page: only @bottom-right needs counter(pages), which
    // is unknown until every page is laid out (M2-T7). @bottom-left has no counter(pages) at all
    // ("tubuencamino.com" is a bare literal), so it contributes zero XObjects/Do operators — if it
    // were (wrongly) deferred too, this count would be 2 per page instead of 1.
    expect(substr_count($pdf, '/Subtype /Form'))->toBe($totalPages);
    expect(substr_count($pdf, ' Do Q'))->toBe($totalPages);

    // @bottom-left: same literal label on every page, painted straight into each page's own
    // content stream (PdfCanvas::fillTextAtPage()), not inside any of the Form XObject streams
    // counted above.
    $siteHex = itineraryGlyphHexOf(ITINERARY_FOOTER_SITE);
    expect(substr_count($pdf, '<' . $siteHex . '> Tj'))->toBe($totalPages);

    // @bottom-right: a distinct "Pagina X de Y" label per page, each with the correct X (that
    // page's own number) and the real total Y — proof the deferred XObject builders actually ran
    // with per-page-correct captured state (page number) plus the shared resolved total.
    for ($page = 1; $page <= $totalPages; $page++) {
        $label = "Pagina $page de $totalPages";
        expect($pdf)->toContain('<' . itineraryGlyphHexOf($label) . '> Tj');
    }
});
