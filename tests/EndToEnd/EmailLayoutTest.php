<?php

// tests/EndToEnd/EmailLayoutTest.php
declare(strict_types=1);

use Pliego\Box\BoxTreeBuilder;
use Pliego\Css\StylesheetParser;
use Pliego\Css\WarningCollector;
use Pliego\Dom\HtmlParser;
use Pliego\Engine;
use Pliego\Image\ImageLoader;
use Pliego\Layout\BlockFlowContext;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\FragmentDumper;
use Pliego\Layout\Geometry\Rect;
use Pliego\Layout\TextMeasurer;
use Pliego\Page\Paginator;
use Pliego\Page\PaperSize;
use Pliego\Style\CssStyleSource;
use Pliego\Style\StyleResolver;
use Pliego\Text\FontCatalog;
use Pliego\Text\TtfFont;

/**
 * M5-T6 brief: the "classic email layout" twin of the flex-based ItineraryCompleteTest (M4-T6) —
 * the SAME target document (photo+text cards, one per itinerary day, multi-page, @page counters),
 * but authored the way a real HTML email is: a `<table>` for the outer layout (one `<tr>` per
 * card, a photo `<td>` + a text `<td>`), with a SECOND, bordered `<table>` nested inside each
 * card's text cell — a small `<thead>`/`<tbody>` data table with `<th>` headers (Fecha/Km/
 * Pernottamento) — instead of the M4 card's `display:flex` + a plain bordered `<p>`. This is the
 * document M5 was scoped around: tables stay necessary for third-party/email-style HTML (see the
 * README's Roadmap), and this fixture is the first place ALL of M5-T2..T5 land together on a
 * realistic, non-synthetic document: auto column widths (§17.5.2), `border-spacing` (§17.6.1,
 * both the outer layout table's card gutter AND the inner data table's cell spacing),
 * `vertical-align: top` (photo/text cells), a real `<thead>`/`<tbody>` (transparent row groups,
 * M5-T3), and row-atomic pagination (M5-T5) splitting the OUTER table between cards exactly the
 * way FlexFormattingContext's atomic container did in M4 — same Paginator machinery, zero
 * table-specific pagination code.
 *
 * Same two-angle split as ItineraryCompleteTest: (1) Engine::render() end to end for every
 * PDF-structural assertion (xref validity, XObject dedup, per-page footer counters, zero
 * warnings); (2) the same pipeline steps reproduced manually (Engine exposes no fragment tree) so
 * the nested-table geometry can be inspected via FragmentDumper and the row-atomic pagination
 * invariant can be checked against the real per-page Fragment objects.
 */
const EMAIL_LAYOUT_MARGIN_PX = 60.0;
const EMAIL_LAYOUT_PHOTO_WIDTH = 100.0;
const EMAIL_LAYOUT_FOOTER_SITE = 'tubuencamino.com';
const EMAIL_LAYOUT_PLACES = ['Sarria', 'Portomarín', 'Palas de Rei', 'Arzúa', 'O Pedrouzo', 'Santiago'];
/** data-cell border (1px) + horizontal padding (6px, from `padding: 3px 6px`) — used to derive a
 *  th cell's CONTENT box (border-box model, content-box sizing here since no box-sizing override)
 *  from its BoxFragment rect without re-deriving the CSS by hand at every assertion site. */
const EMAIL_LAYOUT_DATA_CELL_INSET = 7.0;

function emailLayoutCss(): string
{
    return <<<'CSS'
    body { font-size: 13px; color: #222222 }
    table.layout { width: 100%; border-spacing: 10px }
    .photo-cell { width: 100px; padding: 6px; vertical-align: top }
    .text-cell { padding: 6px; vertical-align: top }
    .day { font-weight: bold; font-size: 15px; margin: 0 0 4px 0 }
    table.data { border: 1px solid #999999; border-spacing: 2px; margin: 0 0 6px 0 }
    .data-cell { border: 1px solid #cccccc; padding: 3px 6px }
    p { line-height: 1.4; margin: 0 0 4px 0 }
    @page {
        margin: 60px;
        @bottom-left { content: "tubuencamino.com"; }
        @bottom-right { content: "Pagina " counter(page) " de " counter(pages); }
    }
    CSS;
}

/** One itinerary day card as a `<tr>` of the outer layout table: a photo cell, and a text cell
 *  holding the day title, a bordered `<thead>`/`<tbody>` data table (Fecha/Km/Pernottamento), and
 *  a description paragraph — the email-layout twin of ItineraryCompleteTest's flex `.card`. */
function emailLayoutCard(int $day, string $place, string $photoFileName): string
{
    $date = sprintf('%02d', $day) . '/09/2026';
    $km = sprintf('%d,%d', 15 + $day, $day);
    return '<tr>'
        . "<td class=\"photo-cell\"><img src=\"$photoFileName\" width=\"" . EMAIL_LAYOUT_PHOTO_WIDTH . '"></td>'
        . '<td class="text-cell">'
        . "<p class=\"day\">$place — Giorno $day</p>"
        . '<table class="data">'
        . '<thead><tr>'
        . '<th class="data-cell">Fecha</th><th class="data-cell">Km</th><th class="data-cell">Pernottamento</th>'
        . '</tr></thead>'
        . '<tbody><tr>'
        . "<td class=\"data-cell\">$date</td><td class=\"data-cell\">$km</td><td class=\"data-cell\">$place</td>"
        . '</tr></tbody>'
        . '</table>'
        . "<p>Una volta arrivato a $place, ti consigliamo di visitare la città e di goderti i suoi "
        . "monumenti e le sue strade, dove si respira già l'atmosfera del Cammino. Puoi prenotare "
        . "online o scriverci — siamo qui per aiutarti in ogni momento del tuo viaggio.</p>"
        . '</td></tr>';
}

/** @return array{0: string, 1: int} [html, cardCount] */
function emailLayoutHtml(string $photoFileName): array
{
    $rows = '';
    foreach (EMAIL_LAYOUT_PLACES as $i => $place) {
        $rows .= emailLayoutCard($i + 1, $place, $photoFileName);
    }
    $html = '<body><table class="layout">' . $rows . '</table></body>';
    return [$html, count(EMAIL_LAYOUT_PLACES)];
}

/** @return string absolute path to a fresh JPEG fixture — same GD-gradient-or-tiny.jpg-fallback
 *  convention as ItineraryCompleteTest/ItineraryWithPhotosTest, duplicated locally so this file
 *  stays self-contained when run in isolation. */
function emailLayoutPhotoFixture(): string
{
    $path = sys_get_temp_dir() . '/pliego-email-layout-photo-' . getmypid() . '.jpg';
    if (extension_loaded('gd')) {
        $width = 200;
        $height = 150; // 4:3, same ratio as the committed tiny.jpg fallback
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            $ratio = $y / ($height - 1);
            $color = imagecolorallocate($image, (int) (60 * (1 - $ratio)), (int) (90 + 100 * $ratio), 150);
            imageline($image, 0, $y, $width - 1, $y, $color);
        }
        imagejpeg($image, $path, 85);
        imagedestroy($image);
    } else {
        copy(__DIR__ . '/../../resources/images/tiny.jpg', $path);
    }
    return $path;
}

function emailLayoutGlyphHexOf(string $text): string
{
    $font = TtfFont::fromFile(__DIR__ . '/../../resources/fonts/DejaVuSans.ttf');
    $hex = '';
    foreach (mb_str_split($text) as $char) {
        $hex .= sprintf('%04X', $font->glyphId(mb_ord($char)));
    }
    return $hex;
}

/**
 * Runs the exact same pipeline steps Engine::render() runs internally, up to (and, for the
 * pagination assertions, through) Paginator — same technique as ItineraryCompleteTest.
 *
 * @return array{0: BoxFragment, 1: float} [root fragment (pre-pagination), contentHeightPx]
 */
function emailLayoutLayout(string $html, string $css, string $basePath): array
{
    $parseResult = new StylesheetParser()->parse($css);
    $document = HtmlParser::parse($html);
    $styles = new StyleResolver([new CssStyleSource($parseResult)])->resolve($document);
    $boxTree = new BoxTreeBuilder(new ImageLoader(), new WarningCollector(), $basePath)->build($document, $styles);

    $contentWidth = PaperSize::A4->widthPx() - 2 * EMAIL_LAYOUT_MARGIN_PX;
    $contentHeight = PaperSize::A4->heightPx() - 2 * EMAIL_LAYOUT_MARGIN_PX;
    $root = new BlockFlowContext(new TextMeasurer(), FontCatalog::withDefaults())
        ->layout($boxTree, new Rect(0.0, 0.0, $contentWidth, INF));

    return [$root, $contentHeight];
}

/**
 * Recursively collects every dumped 'box' node whose 'atomic' flag is true, WITHOUT descending
 * into an already-matched atomic node's children — in this document, that means exactly the
 * outer layout table's `<tr>` row fragments (M5-T5), never the NESTED data table's own atomic
 * rows (each outer row's subtree, including its nested table, is only reached as one already-
 * matched atomic node — recursion stops there). Same helper shape as
 * ItineraryCompleteTest::itineraryCompleteFindAtomicBoxes(), duplicated locally.
 *
 * @param array<string, mixed> $dump
 * @return list<array<string, mixed>>
 */
function emailLayoutFindAtomicBoxes(array $dump): array
{
    $found = [];
    if (($dump['type'] ?? null) === 'box') {
        if (($dump['atomic'] ?? false) === true) {
            $found[] = $dump;
            return $found;
        }
        /** @var list<array<string, mixed>> $children */
        $children = $dump['children'];
        foreach ($children as $child) {
            foreach (emailLayoutFindAtomicBoxes($child) as $match) {
                $found[] = $match;
            }
        }
    }
    return $found;
}

/**
 * @param array<string, mixed> $dump
 * @return list<array<string, mixed>>
 */
function emailLayoutChildrenOf(array $dump): array
{
    /** @var list<array<string, mixed>> $children */
    $children = $dump['children'];
    return $children;
}

/**
 * @param array<string, mixed> $dump
 * @return array{0: float, 1: float, 2: float, 3: float}
 */
function emailLayoutRectOf(array $dump): array
{
    /** @var array{0: float, 1: float, 2: float, 3: float} $rect */
    $rect = $dump['rect'];
    return $rect;
}

it('renders the complete email layout (6 photo+data-table cards) as a structurally valid, multi-page PDF with zero warnings', function () {
    $photoPath = emailLayoutPhotoFixture();
    [$html] = emailLayoutHtml(basename($photoPath));
    $path = sys_get_temp_dir() . '/pliego-email-layout.pdf';
    $report = Engine::make()
        ->basePath(dirname($photoPath))
        ->stylesheet(emailLayoutCss())
        ->render($html)
        ->save($path);
    $pdf = (string) file_get_contents($path);
    @unlink($photoPath);

    expect($report->warnings)->toBe([]);

    expect($pdf)->toStartWith('%PDF-1.7');
    expect(preg_match('/startxref\n(\d+)\n%%EOF\s*$/', $pdf, $m))->toBe(1);
    expect(substr($pdf, (int) $m[1], 4))->toBe('xref');

    // 6 realistic photo+data-table cards under a 60px @page margin overflow a single A4 content
    // area (same rationale as ItineraryCompleteTest) -- the whole point of this fixture.
    expect($report->pageCount)->toBeGreaterThanOrEqual(2);
});

it('dedups the same JPEG photo referenced from all 6 cards into exactly one XObject, Do-ed once per card', function () {
    $photoPath = emailLayoutPhotoFixture();
    [$html, $cardCount] = emailLayoutHtml(basename($photoPath));
    $path = sys_get_temp_dir() . '/pliego-email-layout-dedup.pdf';
    Engine::make()
        ->basePath(dirname($photoPath))
        ->stylesheet(emailLayoutCss())
        ->render($html)
        ->save($path);
    $pdf = (string) file_get_contents($path);
    @unlink($photoPath);

    expect(substr_count($pdf, '/Filter /DCTDecode'))->toBe(1);
    expect(substr_count($pdf, '/Im1 Do'))->toBe($cardCount);
});

it('paints the @bottom-left site label and the @bottom-right "Pagina X de Y" counters correctly on every page', function () {
    $photoPath = emailLayoutPhotoFixture();
    [$html] = emailLayoutHtml(basename($photoPath));
    $path = sys_get_temp_dir() . '/pliego-email-layout-footer.pdf';
    $report = Engine::make()
        ->basePath(dirname($photoPath))
        ->stylesheet(emailLayoutCss())
        ->render($html)
        ->save($path);
    $pdf = (string) file_get_contents($path);
    @unlink($photoPath);
    $totalPages = $report->pageCount;
    expect($totalPages)->toBeGreaterThanOrEqual(2);

    $siteHex = emailLayoutGlyphHexOf(EMAIL_LAYOUT_FOOTER_SITE);
    expect(substr_count($pdf, '<' . $siteHex . '> Tj'))->toBe($totalPages);

    for ($page = 1; $page <= $totalPages; $page++) {
        $label = "Pagina $page de $totalPages";
        expect($pdf)->toContain('<' . emailLayoutGlyphHexOf($label) . '> Tj');
    }
});

it('lays out every card\'s nested data table sized correctly inside its text cell, with no overlaps, via FragmentDumper', function () {
    $photoPath = emailLayoutPhotoFixture();
    [$html, $cardCount] = emailLayoutHtml(basename($photoPath));
    [$root] = emailLayoutLayout($html, emailLayoutCss(), dirname($photoPath));
    @unlink($photoPath);

    $dump = new FragmentDumper()->dump($root);
    // body -> table.layout (not atomic) -> $cardCount atomic <tr> rows.
    $table = emailLayoutChildrenOf($dump)[0];
    $rows = emailLayoutChildrenOf($table);
    expect($rows)->toHaveCount($cardCount);

    foreach ($rows as $row) {
        // Each row has exactly 2 cells: the photo cell and the text cell.
        $cells = emailLayoutChildrenOf($row);
        expect($cells)->toHaveCount(2);
        [$photoCell, $textCell] = $cells;

        $photoCellRect = emailLayoutRectOf($photoCell);
        $textCellRect = emailLayoutRectOf($textCell);
        // No horizontal overlap between the two cells of the same row.
        expect($textCellRect[0])->toBeGreaterThanOrEqual($photoCellRect[0] + $photoCellRect[2] - 0.01);

        // Text cell children, in document order: the day paragraph, the nested data table, the
        // description paragraph -- 3 direct block children.
        $textChildren = emailLayoutChildrenOf($textCell);
        expect($textChildren)->toHaveCount(3);
        [$dayBox, $dataTable, $descriptionBox] = $textChildren;

        $dayRect = emailLayoutRectOf($dayBox);
        $dataTableRect = emailLayoutRectOf($dataTable);
        $descriptionRect = emailLayoutRectOf($descriptionBox);

        // Vertical stacking, no overlap: day -> data table -> description, each strictly below
        // the previous one's bottom edge.
        expect($dataTableRect[1])->toBeGreaterThanOrEqual($dayRect[1] + $dayRect[3] - 0.01);
        expect($descriptionRect[1])->toBeGreaterThanOrEqual($dataTableRect[1] + $dataTableRect[3] - 0.01);

        // The nested data table is fully contained within the text cell's own bounds (its border
        // box never pokes outside the cell that hosts it, T4's "nested table contributes real
        // intrinsics" fix earning its keep -- before that fix, a nested-table-only column could
        // collapse to zero width and overlap its sibling).
        expect($dataTableRect[0])->toBeGreaterThanOrEqual($textCellRect[0] - 0.01);
        expect($dataTableRect[0] + $dataTableRect[2])->toBeLessThanOrEqual($textCellRect[0] + $textCellRect[2] + 0.01);
        expect($dataTableRect[1])->toBeGreaterThanOrEqual($textCellRect[1] - 0.01);
        expect($dataTableRect[1] + $dataTableRect[3])->toBeLessThanOrEqual($textCellRect[1] + $textCellRect[3] + 0.01);

        // The data table itself has exactly 2 rows (the flattened <thead>/<tbody>, M5-T3
        // transparency), each with exactly 3 cells (Fecha/Km/Pernottamento).
        $dataRows = emailLayoutChildrenOf($dataTable);
        expect($dataRows)->toHaveCount(2);
        [$headerRow, $bodyRow] = $dataRows;
        $headerCells = emailLayoutChildrenOf($headerRow);
        $bodyCells = emailLayoutChildrenOf($bodyRow);
        expect($headerCells)->toHaveCount(3);
        expect($bodyCells)->toHaveCount(3);

        // No horizontal overlap between adjacent header cells (auto column widths, §17.5.2).
        for ($i = 0; $i < 2; $i++) {
            $left = emailLayoutRectOf($headerCells[$i]);
            $right = emailLayoutRectOf($headerCells[$i + 1]);
            expect($right[0])->toBeGreaterThanOrEqual($left[0] + $left[2] - 0.01);
        }

        // Every <th> is bold (UA default, M5-T2 BOLD_BY_DEFAULT) and its text is horizontally
        // centered within its own cell's content box (UA default text-align:center for th) --
        // the "th bold+centered" invariant, verified via faceKey + rect.x symmetry rather than an
        // 'alignment' dump key (FragmentDumper never exposes text-align directly; centering is
        // only observable as a geometric fact about where InlineFlowContext placed the line).
        foreach ($headerCells as $th) {
            $thRect = emailLayoutRectOf($th);
            $thChildren = emailLayoutChildrenOf($th);
            expect($thChildren)->toHaveCount(1);
            $text = $thChildren[0];
            expect($text['type'])->toBe('text');
            expect($text['faceKey'])->toBe('default:700:normal');

            $textRect = emailLayoutRectOf($text);
            $contentX = $thRect[0] + EMAIL_LAYOUT_DATA_CELL_INSET;
            $contentRight = $thRect[0] + $thRect[2] - EMAIL_LAYOUT_DATA_CELL_INSET;
            $leftGap = $textRect[0] - $contentX;
            $rightGap = $contentRight - ($textRect[0] + $textRect[2]);
            expect(abs($leftGap - $rightGap))->toBeLessThan(0.5);
        }

        // A <td> is NOT bold and NOT centered (regular weight, left-aligned default) -- the
        // contrast case proving the th treatment above is a real, tag-specific UA default, not an
        // accident of the shared .data-cell class (which carries no font-weight/text-align of its
        // own, see emailLayoutCss()).
        foreach ($bodyCells as $td) {
            $tdChildren = emailLayoutChildrenOf($td);
            $text = $tdChildren[0];
            expect($text['faceKey'])->toBe('default:400:normal');
            $tdRect = emailLayoutRectOf($td);
            $textRect = emailLayoutRectOf($text);
            expect($textRect[0])->toEqualWithDelta($tdRect[0] + EMAIL_LAYOUT_DATA_CELL_INSET, 0.5);
        }
    }
});

it('never splits a card\'s row across a page boundary: every row fragment fits entirely within its own page (M5-T5 row-atomic pagination, on a realistic table document)', function () {
    $photoPath = emailLayoutPhotoFixture();
    [$html, $cardCount] = emailLayoutHtml(basename($photoPath));
    [$root, $contentHeight] = emailLayoutLayout($html, emailLayoutCss(), dirname($photoPath));
    @unlink($photoPath);

    $pages = iterator_to_array(new Paginator($contentHeight)->paginate($root));
    expect(count($pages))->toBeGreaterThanOrEqual(2);

    $totalAtomicRows = 0;
    foreach ($pages as $page) {
        foreach ($page->fragments as $fragment) {
            if (!$fragment instanceof BoxFragment || !$fragment->atomic) {
                continue;
            }
            $totalAtomicRows++;
            // Same invariant as ItineraryCompleteTest's atomic-flex-card check: once a row lands
            // on a page (pushed whole, or never needing pushing) it fits ENTIRELY within that
            // page's own vertical bounds -- if row atomicity were broken and Paginator decomposed
            // a row cell-by-cell instead, a straddling row's bottom half would show up on THIS
            // page with y+height exceeding contentHeight (it would have been pushed down alone).
            expect($fragment->rect->y)->toBeGreaterThanOrEqual(-0.01);
            expect($fragment->rect->y + $fragment->rect->height)->toBeLessThanOrEqual($contentHeight + 0.01);
        }
    }
    // All 6 cards are accounted for, across however many pages the document spans -- none lost,
    // none duplicated by a push-down bug, none split into two page-level row fragments.
    expect($totalAtomicRows)->toBe($cardCount);
});

it('golden-style regression: the same document laid out through FragmentDumper never shows a nested-table column collapsed to zero width (post-review M5-T4 fix, real document check)', function () {
    $photoPath = emailLayoutPhotoFixture();
    [$html] = emailLayoutHtml(basename($photoPath));
    [$root] = emailLayoutLayout($html, emailLayoutCss(), dirname($photoPath));
    @unlink($photoPath);

    $dump = new FragmentDumper()->dump($root);
    $table = emailLayoutChildrenOf($dump)[0];
    $rows = emailLayoutChildrenOf($table);
    foreach ($rows as $row) {
        [, $textCell] = emailLayoutChildrenOf($row);
        [, $dataTable] = emailLayoutChildrenOf($textCell);
        [$headerRow] = emailLayoutChildrenOf($dataTable);
        foreach (emailLayoutChildrenOf($headerRow) as $th) {
            $rect = emailLayoutRectOf($th);
            expect($rect[2])->toBeGreaterThan(0.0);
        }
    }
});
