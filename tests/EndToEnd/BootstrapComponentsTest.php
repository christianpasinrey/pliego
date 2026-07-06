<?php

// tests/EndToEnd/BootstrapComponentsTest.php
declare(strict_types=1);

use Pliego\Box\BoxTreeBuilder;
use Pliego\Css\StylesheetParser;
use Pliego\Css\Value\Color;
use Pliego\Css\WarningCollector;
use Pliego\Dom\HtmlParser;
use Pliego\Engine;
use Pliego\Image\ImageLoader;
use Pliego\Layout\BlockFlowContext;
use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\InlineBoxFragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\FragmentDumper;
use Pliego\Layout\Geometry\Rect;
use Pliego\Layout\TextMeasurer;
use Pliego\Style\CssStyleSource;
use Pliego\Style\StyleResolver;
use Pliego\Text\FontCatalog;

/**
 * M7-T7: closing E2E for the whole M7 layout milestone -- a REAL Bootstrap-flavored page (a
 * `.btn` inline-block, a `.badge` plain inline span, a `.card` with a position:relative overlay,
 * a nested `<ul>`, a `<blockquote>`, a `<pre><code>` block, and a `float:left` image wrapped by
 * text) rendered through the actual Engine pipeline. Every M7 task lands in ONE document:
 * T2 (UA stylesheet: h1-h6/blockquote/pre monospace+white-space:pre), T3 (list markers,
 * disc/circle), T4 (real inline boxes + inline-block -- THE .btn, finally dead the M6
 * "flattened inline" limitation), T5 (min-height), T6 (floats with line shortening,
 * position:relative without leaking into flow) -- plus M6's var()/calc()/rem/!important cascade,
 * exercised together the way a real Bootstrap-derived stylesheet would use them.
 *
 * Helper functions are named with a `bootstrapComponents` prefix (not reused from
 * BootstrapLikeTest's/InlineBoxAndInlineBlockTest's own helpers) so this file stays runnable in
 * isolation, e.g. `pest tests/EndToEnd/BootstrapComponentsTest.php` -- PHP would fatal on "cannot
 * redeclare" if two files in the SAME suite process declared same-named top-level functions (Pest
 * loads every test file into one process for a full run, not just pairwise).
 */

/** @return array{0: string, 1: \Pliego\RenderReport} */
function bootstrapComponentsRenderToPdfString(string $css, string $html, string $basePath): array
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::make()->basePath($basePath)->stylesheet($css)->render($html)->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

/** Same recipe as BootstrapLikeTest's bootstrapLayoutFragment(): threads a caller-owned
 * WarningCollector through StyleResolver/BoxTreeBuilder/BlockFlowContext so a test can assert
 * "0 warnings" at the layout level too, not just through Engine's RenderReport. */
function bootstrapComponentsLayoutFragment(string $html, string $css, float $width, WarningCollector $warnings, string $basePath = __DIR__): BoxFragment
{
    $doc = HtmlParser::parse($html);
    $parseResult = new StylesheetParser()->parse($css);
    foreach ($parseResult->warnings as $warning) {
        $warnings->addWarning($warning);
    }
    $map = new StyleResolver([new CssStyleSource($parseResult)], $warnings)->resolve($doc);
    $root = new BoxTreeBuilder(new ImageLoader(), $warnings, $basePath)->build($doc, $map);
    return new BlockFlowContext(new TextMeasurer(), FontCatalog::withDefaults(), $warnings)
        ->layout($root, new Rect(0.0, 0.0, $width, INF));
}

/** @param array<string, mixed> $dump */
function assertMatchesBootstrapComponentsGolden(string $name, array $dump): void
{
    $path = __DIR__ . '/goldens/' . $name . '.json';
    $encoded = json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($encoded === false) {
        throw new RuntimeException("Failed to encode golden dump '$name' as JSON");
    }
    $json = $encoded . "\n";

    if (getenv('UPDATE_GOLDENS') === '1') {
        file_put_contents($path, $json);
        test()->markTestSkipped('golden regenerated');
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Missing golden file: $path (run with UPDATE_GOLDENS=1 to generate it)");
    }
    /** @var array<string, mixed> $golden */
    $golden = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    expect($dump)->toBe($golden);
}

/** @return list<TextFragment> */
function bootstrapComponentsTextFragments(BoxFragment $box): array
{
    $out = [];
    foreach ($box->children as $child) {
        if ($child instanceof TextFragment) {
            $out[] = $child;
        } elseif ($child instanceof BoxFragment) {
            $out = [...$out, ...bootstrapComponentsTextFragments($child)];
        }
    }
    return $out;
}

/** @return list<InlineBoxFragment> */
function bootstrapComponentsInlineBoxFragments(BoxFragment $box): array
{
    $out = [];
    foreach ($box->children as $child) {
        if ($child instanceof InlineBoxFragment) {
            $out[] = $child;
        } elseif ($child instanceof BoxFragment) {
            $out = [...$out, ...bootstrapComponentsInlineBoxFragments($child)];
        }
    }
    return $out;
}

function bootstrapComponentsFindBoxByBackground(BoxFragment $box, string $hex): ?BoxFragment
{
    foreach ($box->children as $child) {
        if (!$child instanceof BoxFragment) {
            continue;
        }
        if ($child->background !== null && bootstrapComponentsHex($child->background) === $hex) {
            return $child;
        }
        $found = bootstrapComponentsFindBoxByBackground($child, $hex);
        if ($found !== null) {
            return $found;
        }
    }
    return null;
}

/** M7-T3: el marcador de un <li> es SIEMPRE su último hijo, ver BlockFlowContext::listMarkerFragment(). */
function bootstrapComponentsMarkerOf(BoxFragment $li): TextFragment
{
    $marker = $li->children[count($li->children) - 1];
    assert($marker instanceof TextFragment);
    return $marker;
}

function bootstrapComponentsHex(Color $color): string
{
    return sprintf('#%02x%02x%02x', $color->r, $color->g, $color->b);
}

// --- The bootstrap-flavored stylesheet + page, shared by the full E2E render below -------------

const BOOTSTRAP_COMPONENTS_IMAGES_DIR = __DIR__ . '/../../resources/images';

const BOOTSTRAP_COMPONENTS_CSS = <<<'CSS'
:root {
  --bs-primary: #0d6efd;
  --bs-border-color: #dee2e6;
  --bs-spacing: 1rem;
}
/* Deliberately MORE specific than .btn (0,1,1 > 0,1,0) but NOT !important -- proves the cascade
 * still lets a lower-specificity !important declaration win (M6 cascade order: !important before
 * specificity), the same "Bootstrap idiom" a real .btn override relies on. */
a.btn { background-color: #ff0000; }
.btn {
  display: inline-block;
  padding: 6px 12px;
  background-color: var(--bs-primary) !important;
  border: 1px solid var(--bs-border-color);
  color: #ffffff;
}
.badge {
  background-color: #6c757d;
  padding: 0 6px;
  color: #ffffff;
}
.card {
  position: relative;
  border: 1px solid #adb5bd;
  padding: calc(var(--bs-spacing) * .75);
  min-height: 60px;
  background-color: #f8f9fa;
}
.badge-overlay {
  position: relative;
  top: -6px;
  left: 4px;
  background-color: #dc3545;
  color: #ffffff;
  padding: 2px 6px;
}
pre { background-color: #f1f3f5; padding: .5rem; }
.float-wrap { width: 260px; }
.ph { float: left; margin-right: 12px; }
CSS;

const BOOTSTRAP_COMPONENTS_HTML = <<<'HTML'
<body>
  <p>Click <a class="btn">Submit</a> to continue. Status: <span class="badge">42</span> unread.</p>

  <div class="card">
    <div class="badge-overlay">NEW</div>
    Card body copy, enough words to prove the min-height floor is not the only thing giving this box some height.
  </div>
  <p class="after-card">Sibling paragraph right after the card, proving the badge's relative shift never leaks into normal flow.</p>

  <ul>
    <li>Uno</li>
    <li>Dos<ul><li>Dos.uno</li><li>Dos.dos</li></ul></li>
    <li>Tres</li>
  </ul>

  <blockquote>A quoted line, indented per the UA blockquote margin.</blockquote>

  <pre><code>function hola() {
  return 1;
}</code></pre>

  <div class="float-wrap">
    <img class="ph" src="tiny.jpg" width="60">
    <p>Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut
    labore et dolore magna aliqua ut enim ad minim veniam quis nostrud exercitation ullamco
    laboris nisi ut aliquip ex ea commodo consequat.</p>
  </div>
</body>
HTML;

it('renders a real Bootstrap-flavored page (.btn/.badge/.card/list/blockquote/pre/float) end to end, with 0 warnings and a valid PDF', function () {
    [$pdf, $report] = bootstrapComponentsRenderToPdfString(BOOTSTRAP_COMPONENTS_CSS, BOOTSTRAP_COMPONENTS_HTML, BOOTSTRAP_COMPONENTS_IMAGES_DIR);

    expect($pdf)->toStartWith('%PDF-1.7');
    expect($report->warnings)->toBe([]);

    // .btn's background-color: var(--bs-primary) !important -> #0d6efd = rgb(13, 110, 253) --
    // WINS over the higher-specificity, non-important a.btn { background-color: #ff0000 }.
    $btnFill = sprintf('%.3F %.3F %.3F rg', 13 / 255, 110 / 255, 253 / 255);
    expect($pdf)->toContain($btnFill);
    $loserFill = sprintf('%.3F %.3F %.3F rg', 1.0, 0.0, 0.0);
    expect($pdf)->not->toContain($loserFill);

    // .btn's border: 1px solid var(--bs-border-color) -> #dee2e6 = rgb(222, 226, 230), 4 sides.
    $btnBorderFill = sprintf('%.3F %.3F %.3F rg', 222 / 255, 226 / 255, 230 / 255);
    $btnBorderPattern = '/^' . preg_quote($btnBorderFill, '/') . ' [\d.]+ [\d.]+ [\d.]+ [\d.]+ re f$/m';
    expect(preg_match_all($btnBorderPattern, $pdf))->toBe(4);

    // .badge (plain inline span, no inline-block) -- #6c757d = rgb(108, 117, 125).
    $badgeFill = sprintf('%.3F %.3F %.3F rg', 108 / 255, 117 / 255, 125 / 255);
    expect($pdf)->toContain($badgeFill);

    // .badge-overlay (position:relative block, painted at its SHIFTED visual position) -- #dc3545.
    $overlayFill = sprintf('%.3F %.3F %.3F rg', 220 / 255, 53 / 255, 69 / 255);
    expect($pdf)->toContain($overlayFill);

    // Text is really painted (glyph-index Tj ops), not skipped for any of the components above.
    expect(preg_match_all('/<[0-9A-Fa-f]+> Tj/', $pdf))->toBeGreaterThanOrEqual(10);
});

it('structural acceptance (FragmentDumper-level): .btn paints inline as an atomic inline-block box, .badge as a real InlineBoxFragment, markers/monospace/float-shortening/relative-no-leak all hold in ONE document', function () {
    $warnings = new WarningCollector();
    $fragment = bootstrapComponentsLayoutFragment(BOOTSTRAP_COMPONENTS_HTML, BOOTSTRAP_COMPONENTS_CSS, 500.0, $warnings, BOOTSTRAP_COMPONENTS_IMAGES_DIR);

    expect($warnings->drain())->toBe([]);
    expect($fragment->children)->toHaveCount(7);
    [$mainP, $card, $afterCard, $ul, $blockquote, $pre, $floatWrap] = $fragment->children;
    assert($mainP instanceof BoxFragment && $card instanceof BoxFragment && $afterCard instanceof BoxFragment);
    assert($ul instanceof BoxFragment && $blockquote instanceof BoxFragment && $pre instanceof BoxFragment && $floatWrap instanceof BoxFragment);

    $measurer = new TextMeasurer();
    $catalog = FontCatalog::withDefaults();
    $face = $catalog->select('default', 400, false);

    // --- THE .btn: an atomic BoxFragment (inline-block), NOT an InlineBoxFragment (M7-T4) --------
    // Hand-computed shrink-to-fit border-box width: text("Submit") + padding(12*2) + border(1*2).
    $btn = bootstrapComponentsFindBoxByBackground($mainP, '#0d6efd');
    expect($btn)->not->toBeNull();
    assert($btn instanceof BoxFragment);
    $submitWidth = $measurer->widthOf('Submit', $face, 16.0);
    expect($btn->rect->width)->toEqualWithDelta($submitWidth + 12.0 * 2 + 1.0 * 2, 0.5);
    expect($btn->borders->top->widthPx)->toBe(1.0);
    expect(bootstrapComponentsHex($btn->borders->top->color ?? new Color(0, 0, 0)))->toBe('#dee2e6');

    // --- .badge: a REAL InlineBoxFragment (plain inline span with bg+padding, css-inline-3) ------
    $badgeBoxes = bootstrapComponentsInlineBoxFragments($mainP);
    expect($badgeBoxes)->toHaveCount(1);
    $badge = $badgeBoxes[0];
    expect($badge->background)->not->toBeNull();
    assert($badge->background instanceof Color);
    expect(bootstrapComponentsHex($badge->background))->toBe('#6c757d');
    // NOTE: the loose boundary space between </span> and "unread" attaches to the badge's OWN
    // last TextRun ("42 ", trailing space included) rather than floating free -- pre-existing
    // BoxTreeBuilder::collapse() convention (M7-T4 report: "boundary space always hangs off the
    // end of the already-emitted run"), so the hand-computed width must include it too.
    $fortyTwoWidth = $measurer->widthOf('42 ', $face, 16.0);
    expect($badge->rect->width)->toEqualWithDelta($fortyTwoWidth + 6.0 * 2, 0.5);
    expect($badge->isFirstSlice)->toBeTrue();
    expect($badge->isLastSlice)->toBeTrue();

    // --- .card / .badge-overlay: position:relative shifts PAINT ONLY, never leaks into flow ------
    $overlay = bootstrapComponentsFindBoxByBackground($card, '#dc3545');
    expect($overlay)->not->toBeNull();
    assert($overlay instanceof BoxFragment);
    $cardContentTop = $overlay->rect->y + 6.0; // top:-6px -- pre-shift y recovered by adding it back
    $cardContentX = $card->rect->x + 1.0 + 12.0; // card's own border(1) + padding(calc(1rem*.75)=12)
    expect($overlay->rect->x)->toBe($cardContentX + 4.0); // left:4px

    // bootstrapComponentsTextFragments() visits $card's children in order -- [0] is the
    // badge-overlay's OWN "NEW" label (recursed into first), [1] is the flow text that follows it.
    $cardText = bootstrapComponentsTextFragments($card)[1];
    // The flow-following text starts where the OVERLAY would have landed WITHOUT its relative
    // shift (contentTop + its own unshifted height) -- not where it was actually PAINTED.
    expect($cardText->rect->y)->toBe($cardContentTop + $overlay->rect->height);

    // The paragraph AFTER .card starts exactly at the card's own border-box bottom plus the <p>'s
    // own UA leading margin (1em = 16px, M7-T2 "p, ul, ol, dl { margin: 1em 0 }") -- no
    // collapsing in this engine (documented since M1) -- the badge's internal relative shift never
    // escaped the card's own box either way.
    $afterCardText = bootstrapComponentsTextFragments($afterCard)[0];
    expect($afterCardText->rect->y)->toBe($card->rect->bottom() + 16.0);

    // --- list markers present: outer disc, nested ul->ul circle (M7-T3) --------------------------
    expect($ul->children)->toHaveCount(3);
    [$li1, $li2, $li3] = $ul->children;
    assert($li1 instanceof BoxFragment && $li2 instanceof BoxFragment && $li3 instanceof BoxFragment);
    expect(bootstrapComponentsMarkerOf($li1)->text)->toBe("\u{2022}");
    expect(bootstrapComponentsMarkerOf($li3)->text)->toBe("\u{2022}");
    $nestedUl = $li2->children[1];
    assert($nestedUl instanceof BoxFragment);
    expect($nestedUl->children)->toHaveCount(2);
    [$innerLi1, $innerLi2] = $nestedUl->children;
    assert($innerLi1 instanceof BoxFragment && $innerLi2 instanceof BoxFragment);
    expect(bootstrapComponentsMarkerOf($innerLi1)->text)->toBe("\u{25e6}");
    expect(bootstrapComponentsMarkerOf($innerLi2)->text)->toBe("\u{25e6}");

    // --- blockquote: UA margin (1em 40px) lands (M7-T2) -------------------------------------------
    expect($blockquote->rect->x)->toBe(40.0);

    // --- pre>code: monospace face, real newline-driven multi-line (white-space:pre, M7-T2) --------
    $preLines = bootstrapComponentsTextFragments($pre);
    expect(count($preLines))->toBeGreaterThanOrEqual(3); // "function hola() {" / "  return 1;" / "}"
    foreach ($preLines as $line) {
        expect($line->faceKey)->toContain('monospace');
    }

    // --- float:left image shortens the wrapping paragraph's lines (M7-T6) -------------------------
    $floatImg = $floatWrap->children[0];
    $wrappingP = $floatWrap->children[1];
    assert($floatImg instanceof BoxFragment && $wrappingP instanceof BoxFragment);
    $wrapLines = bootstrapComponentsTextFragments($wrappingP);
    expect(count($wrapLines))->toBeGreaterThan(3); // long enough to wrap past the float's bottom edge
    // Band width = image(60px, HTML width attr) + margin-right(12px) = 72px -- lines beside the
    // float start there; the LAST line, past the float's bottom edge, returns to the full column.
    expect($wrapLines[0]->rect->x)->toBe(72.0);
    expect($wrapLines[count($wrapLines) - 1]->rect->x)->toBe(0.0);
});

// --- Golden 1: inline-box slices multi-line (box-decoration-break:slice, css-inline-3) ----------

it('golden: a bordered/padded inline span slices across two wrapped lines -- lateral border/padding only on the extreme slices', function () {
    $measurer = new TextMeasurer();
    $catalog = FontCatalog::withDefaults();
    $face = $catalog->select('default', 400, false);
    $warnings = new WarningCollector();
    // Same forced-wrap recipe as InlineFlowContext/BlockFlowContext unit tests: "aaa" fits but
    // "aaa bbb" doesn't, forcing the wrap between the two words.
    $aaaSpaceWidth = $measurer->widthOf('aaa ', $face, 16.0);
    $bbbWidth = $measurer->widthOf('bbb', $face, 16.0);
    $availableWidth = $aaaSpaceWidth + $bbbWidth * 0.5;

    $html = '<body><p><span class="tag">aaa bbb</span></p></body>';
    $css = '.tag { border: 2px solid #333333; padding: 0 4px; background-color: #ffe08a; }';
    $fragment = bootstrapComponentsLayoutFragment($html, $css, $availableWidth, $warnings);

    expect($warnings->drain())->toBe([]);

    $p = $fragment->children[0];
    assert($p instanceof BoxFragment);
    $boxes = bootstrapComponentsInlineBoxFragments($p);
    expect($boxes)->toHaveCount(2);
    [$firstSlice, $lastSlice] = $boxes;
    expect($firstSlice->isFirstSlice)->toBeTrue();
    expect($firstSlice->isLastSlice)->toBeFalse();
    expect($firstSlice->borders->left->widthPx)->toBeGreaterThan(0.0);
    expect($firstSlice->borders->right->widthPx)->toBe(0.0);
    expect($lastSlice->isFirstSlice)->toBeFalse();
    expect($lastSlice->isLastSlice)->toBeTrue();
    expect($lastSlice->borders->left->widthPx)->toBe(0.0);
    expect($lastSlice->borders->right->widthPx)->toBeGreaterThan(0.0);

    assertMatchesBootstrapComponentsGolden('bootstrap-inline-box-slices', new FragmentDumper()->dump($fragment));
});

// --- Golden 2: nested list (css-lists-3, M7-T3) --------------------------------------------------

it('golden: a nested <ul> -- outer disc markers, inner circle markers, decimal counter unaffected by nesting', function () {
    $warnings = new WarningCollector();
    $html = '<body><ul><li>Uno</li><li>Dos<ul><li>Dos.uno</li><li>Dos.dos</li></ul></li><li>Tres</li></ul></body>';
    $fragment = bootstrapComponentsLayoutFragment($html, '', 300.0, $warnings);

    expect($warnings->drain())->toBe([]);

    $ul = $fragment->children[0];
    assert($ul instanceof BoxFragment);
    expect($ul->children)->toHaveCount(3);
    [$li1, $li2, $li3] = $ul->children;
    assert($li1 instanceof BoxFragment && $li2 instanceof BoxFragment && $li3 instanceof BoxFragment);
    expect(bootstrapComponentsMarkerOf($li1)->text)->toBe("\u{2022}");
    expect(bootstrapComponentsMarkerOf($li3)->text)->toBe("\u{2022}");

    $nestedUl = $li2->children[1];
    assert($nestedUl instanceof BoxFragment);
    expect($nestedUl->children)->toHaveCount(2);
    [$innerLi1, $innerLi2] = $nestedUl->children;
    assert($innerLi1 instanceof BoxFragment && $innerLi2 instanceof BoxFragment);
    expect(bootstrapComponentsMarkerOf($innerLi1)->text)->toBe("\u{25e6}");
    expect(bootstrapComponentsMarkerOf($innerLi2)->text)->toBe("\u{25e6}");

    assertMatchesBootstrapComponentsGolden('bootstrap-nested-list', new FragmentDumper()->dump($fragment));
});

// --- Golden 3: float con texto (CSS 2.2 §9.5, M7-T6) --------------------------------------------

it('golden: a float:left image shortens a wrapping paragraph\'s lines, returning to full width past the image\'s bottom edge', function () {
    $warnings = new WarningCollector();
    $html = '<body><div class="wrap"><img class="ph" src="tiny.jpg" width="60">'
        . '<p>' . trim(str_repeat('lorem ipsum dolor sit amet ', 6)) . '</p></div></body>';
    $css = '.wrap { width: 260px; } .ph { float: left; margin-right: 12px; }';
    $fragment = bootstrapComponentsLayoutFragment($html, $css, 300.0, $warnings, BOOTSTRAP_COMPONENTS_IMAGES_DIR);

    expect($warnings->drain())->toBe([]);

    $wrap = $fragment->children[0];
    assert($wrap instanceof BoxFragment);
    [$img, $p] = $wrap->children;
    assert($img instanceof BoxFragment && $p instanceof BoxFragment);

    $lines = bootstrapComponentsTextFragments($p);
    expect(count($lines))->toBeGreaterThan(3);
    expect($lines[0]->rect->x)->toBe(72.0); // 60px image + 12px margin-right
    expect($lines[count($lines) - 1]->rect->x)->toBe(0.0); // past the float's bottom edge

    assertMatchesBootstrapComponentsGolden('bootstrap-float-text', new FragmentDumper()->dump($fragment));
});
