# pliego

Pure-PHP HTML/CSS to PDF rendering engine. No binaries, no Node, no headless
browser — the full pipeline (HTML parsing, CSS cascade, box tree, block
layout, inline layout, pagination, PDF writing) runs as plain PHP code.

> **Not published to Packagist yet.** This is an early milestone (M1); the
> package is not installable via Composer from a registry at this point.

## Status: M1 — real text

M0 proved the pipeline end to end on a deliberately small subset of
HTML/CSS. M1 replaces M0's flattened, single-face, single-line-height text
with real typography: styled inline runs, UAX #14 line breaking, alignment,
custom line-height, multi-face font embedding with subsetting, and
`ToUnicode` CMaps. It is still not a general-purpose renderer — box model
(borders, `@page`, counters), images, and flexbox are the milestones ahead
(see [Roadmap](#roadmap)).

### Supported as of M1

- **HTML**: a `<body>` with block elements and inline tags (`span`,
  `strong`, `em`, `b`, `i`, `u`, `a`, `small`, `code`) that keep their own
  computed style — each inline element can carry its own weight/style/
  underline/color independently of its surrounding block, laid out through a
  real (if simplified) inline formatting context rather than flattened into
  the block's text. `<br>` forces a line break.
- **Selectors**: type (`p`), class (`.note`), id (`#total`), and a single
  compound of the three (`p.note`). No combinators (no descendant, child,
  sibling selectors).
- **Properties**: `display: block|none`, `margin`/`padding` (shorthand and
  longhands, 1/2/3/4-value expansion), `width`, `color`,
  `background-color`, `font-size`, `font-family`, `font-weight` (400/700 or
  `normal`/`bold`), `font-style` (`normal`/`italic`, `oblique` approximated
  as italic), `line-height` (unitless multiplier or length), `text-align`
  (`left`/`center`/`right`; `justify` is a reported warning, not silent),
  `text-decoration` (`none`/`underline`).
- **Box model**: block formatting context per CSS 2.2 §9.4.1, with inherited
  typographic properties (`color`, `font-size`, `font-family`,
  `font-weight`, `font-style`, `line-height`, `text-align`) down the tree.
  `text-decoration`/underline is simplified as if it inherited too (a
  documented M1 approximation — real decoration propagation is M3+).
- **Text**: UAX #14-based line breaking (a practical subset: whitespace,
  hyphens and a mandatory break at `<br>`) instead of M0's naive
  space-splitting, measured per inline run against whichever font face
  (family/weight/style) that run actually resolves to, so a line can mix
  regular, bold and italic fragments sharing one baseline.
- **Fonts**: a `FontCatalog` resolves (family, weight, style) to a TTF with
  weight/style fallback; every face actually used gets its own embedded
  Type0/CIDFontType2 object, **subsetted** to only the glyphs used (keep-gid
  technique — glyph IDs are never renumbered) with a standard 6-letter
  subset tag in `/BaseFont`, plus a `ToUnicode` CMap so the text is
  copy-pasteable as real Unicode (not just Identity-H glyph IDs, as in M0).
- **Pagination**: streaming, page-by-page fragmentation that pushes a leaf
  down to the next page when it would otherwise be split.

### Explicitly deferred (not bugs — documented M1 simplifications)

- No margin collapsing.
- No selector combinators beyond a single compound selector.
- A background that would visually cross a page boundary stays entirely on
  its starting page.
- No borders, `@page`, running headers/footers, or page counters (M2).
- No images (M3), no flexbox (M4), no tables/floats (M5+).
- `text-decoration`/underline is treated as inheriting through the tree for
  simplicity, which isn't how real CSS decoration propagation works (see
  above) — precise decoration-island tracking is deferred past M1.
- `text-align: justify` is reported as a warning rather than silently
  approximated.
- Unsupported CSS is reported as non-fatal warnings, not rendering failures.
- A unitless `line-height` is resolved to px against the declaring element's
  own `font-size` and inherited by descendants as that already-resolved px
  value, not as the unitless multiplier — unlike real CSS, where each
  descendant would re-resolve the inherited multiplier against its own
  `font-size`. A descendant with a different `font-size` than its ancestor
  will therefore get the ancestor's resolved line-height in px, not its own
  multiplier × its own `font-size`.
- A declared `line-height` below `1.2 × font-size` is floored to
  `1.2 × font-size` in M1 (the same value used for `normal`), rather than
  allowing tighter line boxes than the font's normal metric.

## API example

```php
use Pliego\Css\Value\Length;
use Pliego\Engine;

$html = <<<'HTML'
<body>
  <h1>Invoice</h1>
  <p class="box">Rendered by pliego: DOM, cascade, box tree, block flow,
  streaming pagination and a from-scratch PDF writer.</p>
</body>
HTML;

$css = 'h1 { font-size: 28px; color: #8b5e34; margin: 0 0 16px 0 }
p { margin: 0 0 10px 0 } .box { background-color: #eee; padding: 14px }';

$report = Engine::make()
    ->stylesheet($css)
    ->margins(Length::px(60))
    ->render($html)
    ->save('out.pdf');

echo "{$report->pageCount} page(s), " . count($report->warnings) . " warning(s)\n";
```

`Engine` also exposes `->paper(PaperSize $size)` (default A4) and
`->fontFile(string $ttfPath)` (default: a bundled DejaVu Sans, registered as
the `default` family's regular face). `->font(string $family, int $weight,
FontStyle $style, string $ttfPath)` registers additional faces (other
weights/styles of `default`, or other font families entirely) — only faces
actually referenced by matched CSS get embedded. Instead of `->save($path)`,
`RenderResult::toStream($resource)` writes to any open stream resource.

## Playground

`index.php` doubles as a runnable playground: a two-pane web UI (CodeMirror
HTML/CSS editors on the left, a live PDF preview on the right) backed by the
same `Engine` API described above, plus a warnings panel that surfaces every
unsupported CSS declaration the engine reported instead of silently
dropping it.

To run it locally:

- **Laragon** (or any PHP dev server pointed at the repo): open
  `http://localhost/php-pdf-engine/` in a browser.
- **Plain PHP built-in server**: `php -S localhost:8000` from the repo root,
  then open `http://localhost:8000/`.

Paste (or edit the pre-loaded sample) HTML in the "HTML" tab and CSS in the
"CSS" tab, then click **Generar PDF** (or press Ctrl/Cmd+Enter) — the PDF
renders inline in the preview pane, the warnings panel lists anything the
CSS parser didn't understand, and the status line reports page count, file
size and render time. **Descargar** saves the same PDF to disk. Running
`php index.php` from the CLI instead skips the web UI and writes a demo
`out.pdf` straight to the repo root.

## Roadmap

Re-prioritized after a real target document (a multi-page travel itinerary:
branded header with a background, data blocks, photo+text cards, a section
band, a repeating "Page X of Y" footer) pulled paged media and images ahead
of flexbox/grid:

| Milestone | Scope | Why |
|---|---|---|
| **M1** (this release) | Real text: styled inline runs, UAX #14 line breaking, alignment, subsetting, ToUnicode | Bold/sizes/alignment from the target document; ~10× smaller PDFs than M0's whole-font embedding |
| **M2** | Full box model — **borders**, width/height %, min/max, basic overflow — plus **`@page`, repeating headers/footers, counters** ("Page X of Y") | The bordered rows and numbered footer from the target document |
| **M3** | **Images**: `<img>` JPEG passthrough + PNG (decoded to an XObject), basic `object-fit`, intrinsic sizing | The photos in the itinerary cards |
| **M4** | **Flexbox subset**: `display:flex`, `flex-direction` (row/column), `flex-wrap`, `gap`, `justify-content`, `align-items`, basic `flex-grow`/`flex-shrink`, `flex-basis`/`width` | Authors write cards (photo + flexible text) assuming flex; M1–M4 together render the target document in full |
| **M5+** | Tables → floats/position → Bootstrap → Tailwind JIT → flex to spec (`order`, `align-self`, `stretch`) → grid | Tables stay necessary for third-party/email-style HTML; flex gets completed to spec in its own milestone |

## License

MIT. See [LICENSE](LICENSE).
