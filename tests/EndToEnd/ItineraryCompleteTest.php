<?php

// tests/EndToEnd/ItineraryCompleteTest.php
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
 * M4-T6 brief: the FINAL, COMPLETE twin of ItinerarySkeletonTest (M2-T8, plain divs) and
 * ItineraryWithPhotosTest (M3-T5, `<img>` as its own block-level box) — the actual target document
 * described in the README's Roadmap intro, now with the piece M1-M3 couldn't express: the photo and
 * the text column of each day card are laid out with REAL flexbox (`display: flex; gap: 12px`,
 * photo 120px + a `flex: 1` text column), instead of stacking the photo above the text as a plain
 * block. Every M4 capability lands here TOGETHER, in the realistic layout the whole milestone was
 * scoped around: `display:flex`, `gap` (both axes via the shorthand), `flex: 1` (grow+shrink+basis
 * shorthand, css-flexbox-1 §7.1.1), the flex container's own background/padding/margin (a normal
 * block box per §2, laid out by BlockFlowContext delegating to FlexFormattingContext), and atomic
 * pagination (M4-T5) keeping every card's photo+text together on one page even though 6 realistic
 * cards overflow a single A4 content area.
 *
 * Two complementary angles, mirroring the brief's own split: (1) Engine::render() end to end, for
 * every PDF-structural assertion (xref validity, XObject dedup, per-page footer counters, zero
 * warnings) — same technique as every prior itinerary test; (2) the SAME pipeline steps Engine runs
 * internally (StylesheetParser -> HtmlParser -> StyleResolver -> BoxTreeBuilder -> BlockFlowContext
 * -> Paginator), reproduced here manually (Engine itself exposes no fragment tree) so the flex
 * geometry can be inspected directly via FragmentDumper and the atomic-pagination invariant can be
 * checked against the REAL per-page Fragment objects, not just re-asserted in isolation the way the
 * FlexFormattingContextTest/PaginatorTest unit tests already do.
 */
const ITINERARY_COMPLETE_MARGIN_PX = 60.0;
const ITINERARY_COMPLETE_PHOTO_WIDTH = 120.0;
const ITINERARY_COMPLETE_GAP = 12.0;
const ITINERARY_COMPLETE_FOOTER_SITE = 'tubuencamino.com';
const ITINERARY_COMPLETE_PLACES = ['Sarria', 'Portomarín', 'Palas de Rei', 'Arzúa', 'O Pedrouzo', 'Santiago'];

function itineraryCompleteCss(): string
{
    return <<<'CSS'
    body { font-size: 14px; color: #222222 }
    .header { background-color: #163a6b; color: #ffffff; padding: 16px; font-size: 22px }
    .client { border: 1px solid #000000; padding: 10px; margin: 10px 0 }
    .price { background-color: #ffd500; padding: 14px; font-size: 18px; text-align: right; margin: 0 0 14px 0 }
    .band { background-color: #ffd500; padding: 10px; font-size: 18px; margin: 0 0 10px 0 }
    .card { display: flex; gap: 12px; background-color: #f4f4f4; padding: 12px; margin: 0 0 10px 0 }
    .info { flex: 1 }
    .day { font-weight: bold; margin: 0 0 4px 0; font-size: 16px }
    .data { border: 1px solid #cccccc; padding: 6px; margin: 0 0 6px 0; color: #555555 }
    p { line-height: 1.45 }
    @page {
        margin: 60px;
        @bottom-left { content: "tubuencamino.com"; }
        @bottom-right { content: "Pagina " counter(page) " de " counter(pages); }
    }
    CSS;
}

/** One itinerary day card, now flex: photo (120px, `flex: 1` text column with a bold title, a bordered data row, a paragraph. */
function itineraryCompleteCard(int $day, string $place, string $photoFileName): string
{
    return '<div class="card">'
        . "<img src=\"$photoFileName\" width=\"" . ITINERARY_COMPLETE_PHOTO_WIDTH . '">'
        . '<div class="info">'
        . "<p class=\"day\">$place — Giorno $day</p>"
        . "<p class=\"data\">Data: " . sprintf('%02d', $day) . "/09/2026 · Pernottamento a $place</p>"
        . "<p>Una volta arrivato a $place, ti consigliamo di visitare la città e di goderti i suoi "
        . "monumenti e le sue strade, dove si respira già l'atmosfera del Cammino. Puoi prenotare "
        . "online o scriverci — siamo qui per aiutarti in ogni momento del tuo viaggio.</p>"
        . '</div></div>';
}

/** @return array{0: string, 1: int} [html, cardCount] */
function itineraryCompleteHtml(string $photoFileName): array
{
    $cards = '';
    foreach (ITINERARY_COMPLETE_PLACES as $i => $place) {
        $cards .= itineraryCompleteCard($i + 1, $place, $photoFileName);
    }
    $html = '<body>'
        . '<div class="header">Cammino francese da Sarria</div>'
        . '<div class="client"><p>Cliente: Livia Fernandez</p><p>Prenotazione n. 136961</p></div>'
        . '<div class="price">Prezzo a persona: 296,33 €</div>'
        . '<div class="band">Itinerario</div>'
        . $cards
        . '</body>';
    return [$html, count(ITINERARY_COMPLETE_PLACES)];
}

/** @return string absolute path to a fresh JPEG fixture (GD gradient, or a copy of tiny.jpg) — same
 *  fallback convention as ItineraryWithPhotosTest (M3-T5), duplicated locally (own name, own temp
 *  file) so this test file stays self-contained when run in isolation. */
function itineraryCompletePhotoFixture(): string
{
    $path = sys_get_temp_dir() . '/pliego-itinerary-complete-photo-' . getmypid() . '.jpg';
    if (extension_loaded('gd')) {
        $width = 200;
        $height = 150; // 4:3, same ratio as the committed tiny.jpg fallback
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            $ratio = $y / ($height - 1);
            $color = imagecolorallocate($image, (int) (50 + 150 * $ratio), (int) (120 * (1 - $ratio)), 130);
            imageline($image, 0, $y, $width - 1, $y, $color);
        }
        imagejpeg($image, $path, 85);
        imagedestroy($image);
    } else {
        copy(__DIR__ . '/../../resources/images/tiny.jpg', $path);
    }
    return $path;
}

function itineraryCompleteGlyphHexOf(string $text): string
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
 * pagination assertions, through) Paginator — Engine itself never exposes the fragment tree, so
 * this is the only way to inspect flex geometry (via FragmentDumper) and per-page atomic-pagination
 * behaviour (via the real BoxFragment objects Paginator hands each Page) directly.
 *
 * @return array{0: BoxFragment, 1: float} [root fragment (pre-pagination), contentHeightPx]
 */
function itineraryCompleteLayout(string $html, string $css, string $basePath): array
{
    $parseResult = new StylesheetParser()->parse($css);
    $document = HtmlParser::parse($html);
    $styles = new StyleResolver([new CssStyleSource($parseResult)])->resolve($document);
    $boxTree = new BoxTreeBuilder(new ImageLoader(), new WarningCollector(), $basePath)->build($document, $styles);

    $contentWidth = PaperSize::A4->widthPx() - 2 * ITINERARY_COMPLETE_MARGIN_PX;
    $contentHeight = PaperSize::A4->heightPx() - 2 * ITINERARY_COMPLETE_MARGIN_PX;
    $root = new BlockFlowContext(new TextMeasurer(), FontCatalog::withDefaults())
        ->layout($boxTree, new Rect(0.0, 0.0, $contentWidth, INF));

    return [$root, $contentHeight];
}

/**
 * Recursively collects every dumped 'box' node (FragmentDumper arrays) whose 'atomic' flag is true
 * — in this document, exactly the 6 flex `.card` containers (nothing else uses display:flex), each
 * carrying its photo + info-column children intact (M4-T5's atomic-pagination unit, see
 * FlexFormattingContext's docblock). Recursion does not descend into an already-matched atomic
 * node's children (a nested flex container inside a card isn't part of this document, but the
 * top-level match is what matters either way).
 *
 * @param array<string, mixed> $dump
 * @return list<array<string, mixed>>
 */
function itineraryCompleteFindAtomicBoxes(array $dump): array
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
            foreach (itineraryCompleteFindAtomicBoxes($child) as $match) {
                $found[] = $match;
            }
        }
    }
    return $found;
}

it('renders the complete itinerary (header, client data, price, band, 6 flex photo+text cards) as a structurally valid, multi-page PDF with zero warnings', function () {
    $photoPath = itineraryCompletePhotoFixture();
    [$html] = itineraryCompleteHtml(basename($photoPath));
    $path = sys_get_temp_dir() . '/pliego-itinerary-complete.pdf';
    $report = Engine::make()
        ->basePath(dirname($photoPath))
        ->stylesheet(itineraryCompleteCss())
        ->render($html)
        ->save($path);
    $pdf = (string) file_get_contents($path);
    @unlink($photoPath);

    expect($report->warnings)->toBe([]);

    expect($pdf)->toStartWith('%PDF-1.7');
    expect(preg_match('/startxref\n(\d+)\n%%EOF\s*$/', $pdf, $m))->toBe(1);
    expect(substr($pdf, (int) $m[1], 4))->toBe('xref');

    // 6 realistic photo+text cards under a 60px @page margin overflow a single A4 content area —
    // the whole point of this fixture (same rationale as ItinerarySkeletonTest, now with flex cards).
    expect($report->pageCount)->toBeGreaterThanOrEqual(2);
});

it('dedups the same JPEG photo referenced from all 6 flex cards into exactly one XObject, Do-ed once per card', function () {
    $photoPath = itineraryCompletePhotoFixture();
    [$html, $cardCount] = itineraryCompleteHtml(basename($photoPath));
    $path = sys_get_temp_dir() . '/pliego-itinerary-complete-dedup.pdf';
    Engine::make()
        ->basePath(dirname($photoPath))
        ->stylesheet(itineraryCompleteCss())
        ->render($html)
        ->save($path);
    $pdf = (string) file_get_contents($path);
    @unlink($photoPath);

    // The same photo, referenced from all 6 cards, dedups into exactly one DCTDecode XObject
    // (registered first -> "Im1"), Do-ed once per card (same technique as ItineraryWithPhotosTest).
    expect(substr_count($pdf, '/Filter /DCTDecode'))->toBe(1);
    expect(substr_count($pdf, '/Im1 Do'))->toBe($cardCount);
});

it('paints the @bottom-left site label and the @bottom-right "Pagina X de Y" counters correctly on every page', function () {
    $photoPath = itineraryCompletePhotoFixture();
    [$html] = itineraryCompleteHtml(basename($photoPath));
    $path = sys_get_temp_dir() . '/pliego-itinerary-complete-footer.pdf';
    $report = Engine::make()
        ->basePath(dirname($photoPath))
        ->stylesheet(itineraryCompleteCss())
        ->render($html)
        ->save($path);
    $pdf = (string) file_get_contents($path);
    @unlink($photoPath);
    $totalPages = $report->pageCount;
    expect($totalPages)->toBeGreaterThanOrEqual(2);

    $siteHex = itineraryCompleteGlyphHexOf(ITINERARY_COMPLETE_FOOTER_SITE);
    expect(substr_count($pdf, '<' . $siteHex . '> Tj'))->toBe($totalPages);

    for ($page = 1; $page <= $totalPages; $page++) {
        $label = "Pagina $page de $totalPages";
        expect($pdf)->toContain('<' . itineraryCompleteGlyphHexOf($label) . '> Tj');
    }
});

/**
 * Typed accessors for a FragmentDumper dump node (a plain array<string, mixed> — its 'children'
 * and 'rect' entries are themselves `mixed` as far as PHPStan is concerned, since dump()'s return
 * type can't express a recursive shape). Giving these their own proper @return annotations (not an
 * inline @var override at the call site) lets the geometry test below destructure/index the result
 * without a wall of "on mixed" baseline suppressions for what is, in practice, exactly the shape
 * FragmentDumper's own docblock guarantees.
 *
 * @param array<string, mixed> $dump
 * @return list<array<string, mixed>>
 */
function itineraryCompleteChildrenOf(array $dump): array
{
    /** @var list<array<string, mixed>> $children */
    $children = $dump['children'];
    return $children;
}

/**
 * @param array<string, mixed> $dump
 * @return array{0: float, 1: float, 2: float, 3: float}
 */
function itineraryCompleteRectOf(array $dump): array
{
    /** @var array{0: float, 1: float, 2: float, 3: float} $rect */
    $rect = $dump['rect'];
    return $rect;
}

it('lays out every flex card with sane geometry (photo left, flex:1 text column right, no overlap) via FragmentDumper', function () {
    $photoPath = itineraryCompletePhotoFixture();
    [$html, $cardCount] = itineraryCompleteHtml(basename($photoPath));
    [$root] = itineraryCompleteLayout($html, itineraryCompleteCss(), dirname($photoPath));
    @unlink($photoPath);

    $dump = new FragmentDumper()->dump($root);
    $cards = itineraryCompleteFindAtomicBoxes($dump);
    expect($cards)->toHaveCount($cardCount);

    foreach ($cards as $card) {
        // Each card's own FlexFormattingContext fragment has exactly 2 direct children: the photo
        // (an ImageBox wrapped in its own BoxFragment, M3's replaced-box model) and the `.info`
        // text column (itself a BoxFragment, since it has its own ComputedStyle/children).
        $children = itineraryCompleteChildrenOf($card);
        expect($children)->toHaveCount(2);
        [$photo, $info] = $children;

        $photoRect = itineraryCompleteRectOf($photo);
        $infoRect = itineraryCompleteRectOf($info);
        $photoX = $photoRect[0];
        $photoWidth = $photoRect[2];
        $infoX = $infoRect[0];

        expect($photoWidth)->toBe(ITINERARY_COMPLETE_PHOTO_WIDTH);
        // The brief's own invariant: the text column starts no earlier than photo's right edge
        // plus the declared gap -- i.e. no horizontal overlap between the two flex items.
        expect($infoX)->toBeGreaterThanOrEqual($photoX + ITINERARY_COMPLETE_PHOTO_WIDTH + ITINERARY_COMPLETE_GAP - 0.01);
        // Same row: both items share the card's top edge (align-items default stretch keeps the
        // shorter item's BOX anchored at y=0 within the line, see FlexFormattingContext::withHeight()).
        expect($photoRect[1])->toBe($infoRect[1]);
    }
});

it('never splits an atomic flex card across a page boundary: every card fragment fits entirely within its own page (M4-T5 atomic pagination, on the real target document)', function () {
    $photoPath = itineraryCompletePhotoFixture();
    [$html, $cardCount] = itineraryCompleteHtml(basename($photoPath));
    [$root, $contentHeight] = itineraryCompleteLayout($html, itineraryCompleteCss(), dirname($photoPath));
    @unlink($photoPath);

    $pages = iterator_to_array(new Paginator($contentHeight)->paginate($root));
    expect(count($pages))->toBeGreaterThanOrEqual(2);

    $totalAtomicCards = 0;
    foreach ($pages as $page) {
        foreach ($page->fragments as $fragment) {
            if (!$fragment instanceof BoxFragment || !$fragment->atomic) {
                continue;
            }
            $totalAtomicCards++;
            // The card was either pushed whole to the next page (fits entirely within
            // [0, contentHeight]) or never needed pushing at all -- either way, once it lands on a
            // page it must fit ENTIRELY within that page's own vertical bounds (page-local
            // coordinates, Paginator::relocate() already re-based them to start at 0): if the
            // atomic mechanism were broken and Paginator decomposed the card leaf-by-leaf instead,
            // a straddling card's bottom half would show up as fragments whose y+height exceeds the
            // page content height on THIS page (they'd have been pushed to the next one alone).
            expect($fragment->rect->y)->toBeGreaterThanOrEqual(-0.01);
            expect($fragment->rect->y + $fragment->rect->height)->toBeLessThanOrEqual($contentHeight + 0.01);
        }
    }
    // All 6 cards are accounted for, across however many pages the document spans -- none lost,
    // none duplicated by a push-down bug, none split into two page-level fragments.
    expect($totalAtomicCards)->toBe($cardCount);
});
