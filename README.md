# pliego

Pure-PHP HTML/CSS to PDF rendering engine. No binaries, no Node, no headless
browser — the full pipeline (HTML parsing, CSS cascade, box tree, block
layout, inline layout, pagination, PDF writing) runs as plain PHP code.

> **Not published to Packagist yet.** This is an early milestone (M4); the
> package is not installable via Composer from a registry at this point.

## Status: M4 — flexbox

M0 proved the pipeline end to end on a deliberately small subset of
HTML/CSS; M1 replaced its flattened, single-face, single-line-height text
with real typography. M2 adds the rest of the box model the target document
needs — solid **borders**, `width`/margin/padding **percentages**,
`box-sizing` — plus **paged media**: `@page` margins that override the
engine default per side, and repeating margin boxes (`@top-*`/`@bottom-*`)
with literal text and `counter(page)`/`counter(pages)`, so a document can
carry a real "Página X de Y" footer. M3 adds **images**: `<img>` as a
replaced block-level element, JPEG passthrough (`DCTDecode`) and PNG decoding
(gray/RGB/RGBA, `FlateDecode`, alpha via `/SMask`), intrinsic sizing from the
file's own dimensions plus HTML `width`/`height` attributes, and a
deduplicating `ImageRegistry` so the same photo referenced N times becomes
one PDF XObject. M4 adds a **flexbox subset**: `display: flex` (row and
column), `flex-wrap`, `gap`, `justify-content`, `align-items`, `flex-grow`/
`flex-shrink`/`flex-basis` (including the `flex` shorthand) — enough to lay
out the target document's photo+text cards the way an author would actually
write them (`display: flex; gap: 12px` instead of stacking blocks), with the
whole flex container treated as an atomic, indivisible unit for pagination
purposes. It is still not a general-purpose renderer — tables/floats and the
rest of flexbox-to-spec are the milestones ahead (see [Roadmap](#roadmap)).

### Supported as of M4

- **Flexbox** (css-flexbox-1 subset): `display: flex` on a block-level
  container (the container itself still participates in normal block flow —
  margins/width/`box-sizing` resolve exactly like any other block).
  - **`flex-direction`**: `row` (default) and `column`. Row wraps
    (`flex-wrap: wrap`) into multiple lines, stacked with `row-gap` between
    them — an overwide single item never splits or shrinks below its own
    hypothetical size, it just keeps its own line. `flex-direction: column`
    has **no wrap support** (a column is always exactly one vertical
    "line") and **no min-content clamp** on its shrink path (row's shrink
    does clamp to min-content and redistribute the remaining deficit —
    column allows overflow instead, a deliberate simplification since no
    document in scope needs it).
  - **`gap`** (shorthand, sets both axes) / `row-gap` / `column-gap`, px
    only. In row, `column-gap` sits between items on the same line and
    `row-gap` stacks wrapped lines; in column the axis roles swap
    (`row-gap` is the main-axis gap between stacked items).
  - **`justify-content`**: `flex-start` (default), `center`, `flex-end`,
    `space-between` — distributes the leftover main-axis space neither
    `flex-grow` nor `flex-shrink` absorbed.
  - **`align-items`**: `flex-start`, `center`, `flex-end`, `stretch`
    (default). Stretch is a **geometry-only approximation**: an item
    without a definite cross size gets its `BoxFragment` (background/
    border box) resized to the line's cross size (minus its own
    cross-axis margins, which are never stretched), but its own content is
    never re-laid-out or re-centered inside the new box — text/child
    boxes stay anchored where they were, and a stretched `<img>` keeps its
    own intrinsic bitmap at its originally-measured pixel size: only the
    surrounding box (background/borders) grows to fill the line, the
    decoded image itself is never re-scaled or genuinely re-measured.
  - **`flex-grow`**/**`flex-shrink`**/**`flex-basis`** (longhands) and the
    `flex` shorthand (css-flexbox-1 §7.1.1's full keyword/number table:
    `none`, `initial`, `auto`, `<N>`, `<width>`, `<N> <M>`, etc.). An item's
    resolved main size is always treated as its **border-box** width,
    whatever the source (`flex-basis`, its own `width`, or max-content); an
    item that ALSO declares its own CSS `width` still grows/shrinks from
    that width as its starting point instead of being locked to it — the
    resolved flex size always wins at render time (the width only seeds the
    hypothetical size flex-grow/shrink then adjust), and this holds for
    every item kind: a plain block, an `<img>`, and — since the M4
    final-review — an item that is itself a nested `display: flex`
    container, whose own declared width is likewise overridden by its
    parent's resolved main size instead of being re-resolved from scratch.
    Shrinking clamps each
    item at its own min-content (the longest unbreakable word) and
    redistributes any remaining deficit in one extra pass — not the
    spec's fully iterative resolution, but stable for the two-item cases
    a real document produces.
  - **Atomic pagination**: the `BoxFragment` a flex container produces is
    marked indivisible for `Paginator` — it crosses a page boundary and
    fits within one page → pushed whole (background, borders and every
    child fragment together) to the next page, never split leaf-by-leaf
    the way a plain block's content is; taller than one page → stays
    where it lands, uncut (same pre-existing limit already documented for
    an overlong text/image leaf, still with no warning channel here).
  - **Not supported, reported as a warning rather than silently ignored or
    approximated**: `order`, `align-self`, `align-content` (so a
    multi-line row's declared container height only acts as a floor on
    the total cross size, distributing nothing between lines),
    `display: inline-flex`, `flex-basis: content`, `flex-direction:
    row-reverse`/`column-reverse`, `flex-wrap: wrap-reverse`,
    `justify-content`/`align-items: space-around`/`space-evenly`/
    `baseline`, and any writing-mode/direction property (this engine is
    left-to-right/top-to-bottom only, css-flexbox-1's abstract
    start/end axes are never considered).

### Supported as of M3 (carried forward unchanged)

- **Images** (css-images-3 subset): `<img src="...">` as a replaced
  block-level box — inline images (an `<img>` nested inside `span`/`a`/etc.)
  are hoisted to block level with a visible warning rather than silently
  dropped or laid out inline (inline replaced boxes aren't supported yet).
  - **JPEG**: baseline (SOF0), extended-sequential (SOF1) and progressive
    (SOF2) all pass through untouched as a `DCTDecode` XObject — the file's
    own entropy-coded stream is embedded as-is, no re-encoding.
  - **PNG**: 8-bit only, color types gray (0), RGB (2) and RGBA (6),
    non-interlaced. Decoded (zlib inflate + per-scanline unfilter: None/Sub/
    Up/Average/Paeth) and re-deflated as `FlateDecode`. RGBA splits into an
    RGB `DeviceRGB` image plus its alpha channel as a separate 8-bit
    `DeviceGray` `/SMask` XObject (ISO 32000-1 §11.6.5.3).
  - **Sizing**: intrinsic size comes from the file's own pixel dimensions
    (96dpi assumed, so image px = CSS px); the HTML `width`/`height`
    attributes (purely numeric values only) override one axis, the other
    derives from the image's own aspect ratio when only one is given.
  - **Dedup**: the same resolved path referenced from multiple `<img>` tags
    produces exactly one XObject (`ImageRegistry`, keyed by path), `Do`-ed
    once per occurrence — a 6-photo repeat costs one embedded image, not six.
  - **Soft failures**: a missing file, an unsupported format/variant, or a
    `src` that fails to load reports a warning and the box is silently
    omitted (no ImageBox emitted) — never a thrown exception from the box
    tree. See limitations below for what's *not* supported and reported.

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
  longhands, 1/2/3/4-value expansion, **px or %**), `width` (px or %),
  `box-sizing` (`content-box`/`border-box`), `border`/`border-{side}`
  (shorthand and `-width`/`-style`/`-color` longhands; **`solid`/`none`
  styles only** — see limitations below), `color`, `background-color`,
  `font-size`, `font-family`, `font-weight` (400/700 or `normal`/`bold`),
  `font-style` (`normal`/`italic`, `oblique` approximated as italic),
  `line-height` (unitless multiplier or length), `text-align`
  (`left`/`center`/`right`; `justify` is a reported warning, not silent),
  `text-decoration` (`none`/`underline`).
- **Box model**: block formatting context per CSS 2.2 §9.4.1/§10, with
  inherited typographic properties (`color`, `font-size`, `font-family`,
  `font-weight`, `font-style`, `line-height`, `text-align`) down the tree.
  `width`, every `margin-*` and every `padding-*` accept a percentage,
  resolved against the containing block's width at layout time (css2 §10),
  including the vertical ones (`margin-top`/`bottom`, `padding-top`/`bottom`
  — CSS resolves those against the containing block's *width* too, not its
  height). `box-sizing: border-box` makes a declared `width` include
  padding and border instead of being pure content width. Solid borders
  paint as filled rects, in css-backgrounds-3 painting order (background,
  then border, then content) — see limitations below for what "solid" does
  *not* cover yet.
  `text-decoration`/underline is simplified as if it inherited too (a
  documented M1 approximation — real decoration propagation is M3+).
- **Paged media** (css-page-3): `@page { margin: ... }` (shorthand or
  `margin-{side}` longhands, px only) overrides the engine's uniform margin
  per side — sides the rule doesn't declare keep the engine default.
  6 of css-page-3's 16 margin boxes are supported —
  `@top-left`/`@top-center`/`@top-right`/`@bottom-left`/`@bottom-center`/
  `@bottom-right` — each with a `content` made of literal quoted strings
  concatenated with `counter(page)`/`counter(pages)` (e.g. `content:
  "Página " counter(page) " de " counter(pages)`). A box with no
  `counter(pages)` paints directly into each page's content stream; a box
  that uses `counter(pages)` is resolved through a deferred PDF Form
  XObject per page, since the total page count isn't known until every
  page has been laid out — the two paths render identically only while the
  box's text fits its column; see "Margin boxes" below for what happens
  when it doesn't (the deferred path's Form XObject clips at the column
  edge, the direct path doesn't).
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

### Explicitly deferred (not bugs — documented M1/M2 simplifications)

- No margin collapsing.
- No selector combinators beyond a single compound selector.
- A background (or a visible border) that would visually cross a page
  boundary is pushed whole to the next page rather than split — it never
  paints twice or gets cut mid-box.
- **Borders**: `solid`/`none` only — `dashed`/`dotted`/`double`/`groove`/
  etc. (CSS 2.2 §8.5.3) are reported as warnings, not approximated. Corners
  are simple butt joints (each side is one filled rect, sized so the two
  horizontal sides span the full box width and the two vertical sides fit
  between them) — there is no real miter/45°-mitered corner. Different
  widths per side are supported, but two *thick*, *differently colored*
  adjacent sides will show that seam rather than a mitered diagonal.
- **Margin boxes**: no shrink-to-fit, and clipping depends on which path
  painted the box. css-page-3's own 3-box-per-row division is honored (each
  of `@*-left`/`@*-center`/`@*-right` gets a fixed, equal third of the
  content width), but a box's text that's wider than its own column is
  handled differently depending on whether it was painted directly or
  deferred (see "Paged media" above): a box painted directly into the page
  content stream has no clip applied and overflows past the column
  boundary, keeping drawing instead of shrinking or wrapping; a box built
  as a deferred Form XObject is clipped to its own `/BBox` (ISO 32000-1
  §8.10.2), set to exactly the column's width and box height, so its
  overflow is cut at the column edge instead. Either way, overflow only
  becomes visible/lossy when the neighboring column also paints into the
  shared boundary region.
- No tables/floats (M5+); flexbox is implemented as of M4 but only the
  subset above — see "Supported as of M4" for what's excluded (`order`,
  `align-self`, `align-content`, `inline-flex`, `flex-basis: content`,
  `*-reverse`, writing modes) and the stretch/column simplifications.
- **Images**: no indexed/palette PNG (color type 3), no interlaced (Adam7)
  PNG, no bit depths other than 8, no CMYK JPEG, no formats beyond JPEG/PNG
  (no GIF/WebP/SVG/BMP). No remote `src` (`http://`/`https://` is reported as
  a warning, never fetched) — only local files resolved against
  `->basePath()`. No `object-fit`/`object-position`, no inline replaced
  boxes (an inline `<img>` is hoisted to block level, see above). `ImageLoader`
  memoizes decoded images by path (in-memory, per render), and the same
  `ImageLoader` instance is shared between `BoxTreeBuilder` (which reads
  intrinsic dimensions at layout time) and `ImageRegistry` (which builds the
  XObject at paint time), so each distinct image is decoded **once** per
  render no matter how many `<img>` occurrences reference it.
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
| **M1** | Real text: styled inline runs, UAX #14 line breaking, alignment, subsetting, ToUnicode | Bold/sizes/alignment from the target document; ~10× smaller PDFs than M0's whole-font embedding |
| **M2** | Full box model — **borders**, width/margin/padding %, `box-sizing` — plus **`@page` margins, repeating margin boxes, `counter(page)`/`counter(pages)`** ("Page X of Y") | The bordered rows and numbered footer from the target document |
| **M3** | **Images**: `<img>` JPEG passthrough + PNG (decoded to an XObject, alpha via `/SMask`), deduplicated XObjects, intrinsic + attribute sizing | The photos in the itinerary cards |
| **M4** (this release) | **Flexbox subset**: `display:flex`, `flex-direction` (row/column), `flex-wrap`, `gap`, `justify-content`, `align-items`, basic `flex-grow`/`flex-shrink`, `flex-basis`/`width`, atomic pagination | Authors write cards (photo + flexible text) assuming flex; M1–M4 together render the target document in full |
| **M5+** | Tables → floats/position → Bootstrap → Tailwind JIT → flex to spec (`order`, `align-self`, `stretch`) → grid | Tables stay necessary for third-party/email-style HTML; flex gets completed to spec in its own milestone |

## License

MIT. See [LICENSE](LICENSE).
