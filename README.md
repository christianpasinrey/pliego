# pliego

Pure-PHP HTML/CSS to PDF rendering engine. No binaries, no Node, no headless
browser — the full pipeline (HTML parsing, CSS cascade, box tree, block
layout, inline layout, pagination, PDF writing) runs as plain PHP code.

> **Not published to Packagist yet.** M9 makes pliego genuinely usable for
> real-world **reports, invoices and transactional emails with a Bootstrap
> look** — `Engine::bootstrap()` renders real, unmodified upstream Bootstrap
> 5.3.6 markup with an honestly-measured, Chrome-verified visual fidelity
> (see [Oracle](#oracle-chrome-as-ground-truth) below). The package is still
> not installable via Composer from a registry at this point.

## Status: M9 — Real Bootstrap ingestion (vendored preset, tiling patterns, soft-mask gradient alpha, Chrome oracle)

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
purposes. M5 adds a **table subset** (css-tables-3 §2 / CSS 2.2 §17): the
`<table>`/`<thead>`/`<tbody>`/`<tr>`/`<td>`/`<th>` element set with the
separated-borders box model, auto **and** fixed column-width algorithms,
`colspan`, `vertical-align`, and row-atomic pagination — enough to render
third-party/email-style HTML (a classic email layout is exactly nested
`<table>`s: a photo cell + a text cell per row, a bordered data table inside
the text cell) without asking the author to rewrite it as flexbox first. M6
replaces the M0-M5 CSS subset (a single compound selector, px/%-only
lengths, hex/named colors) with a real **CSS core**: selector combinators
and `:nth-child`/`:not`, `em`/`rem`/physical units resolved against the
right font-size at the right time, `:root` **custom properties** (`var()`)
and `calc()`, and the **full color syntax** (`rgb()`/`rgba()`/`hsl()`/
`hsla()`, 148 named colors, `transparent`/`currentColor`) with real alpha
compositing via PDF `ExtGState`. M7 rounds out the box model that CSS core
now styles: a real **user-agent stylesheet** (`h1`-`h6`, `p`/`ul`/`ol`/`dl`/
`blockquote` margins, `pre`/`code` monospace + `white-space: pre`), **list
markers** (`disc`/`circle`/`square`/`decimal`), **real inline boxes** —
`display: inline` finally paints its own background/border/padding
in-line, sliced correctly across a wrapped line (`box-decoration-break:
slice`), the exact limitation that kept M6's `.btn`/`.badge` flat — plus
`display: inline-block`, `min-width`/`max-width`/`min-height`/`max-height`,
`overflow: hidden` clipping, and a **reduced CSS 2.2 §9.5/§9.4.3 floats +
position** subset (`float: left/right`, `clear`, line shortening around a
float band, `position: relative/absolute`). M8 stops widening the box
model and instead polishes its **look**: rounded corners (`border-radius`,
Bézier paths + an annular border ring), **native PDF gradients**
(`linear-gradient()`/`radial-gradient()` as real `/Shading` objects, not a
bitmap), an approximated `box-shadow` (4 concentric layers — explicitly
**not** a real Gaussian blur) plus `dashed`/`dotted` borders,
`letter-spacing`/`word-spacing`/`text-transform` (the `TJ` per-glyph PDF
operator), `background-image` (`cover`/`contain`/tiling, sharing the
`<img>` decode/dedup pipeline), and `@font-face` (local TrueType, case-
insensitive family lookup) — everything a Bootstrap-derived `.card`/`.btn`/
`.badge`/`.display-*` document needs to look, not just lay out, like the
real thing. M9 closes the loop opened by M6-M8's hand-picked "Bootstrap-
flavored" CSS: it ingests the **real, unmodified, vendored upstream
`bootstrap.min.css` (5.3.6)** end to end (1066 warnings from parsing the
232KB sheet alone as of M10-T2 — 870 as of M10-T1, 895 as of M9, before
`vw`/`vh`, `:nth-of-type`/`:nth-last-of-type` and width `@media` queries
gained real support, see below — every one categorized and pinned as a
golden — a complete, honest partition, not a sample), exposes it as
`Engine::bootstrap()` (a preset that stacks author-order before your own
`->stylesheet()` calls, see [Presets](#presets)), adds the two PDF primitives
a real striped/gradiented Bootstrap page actually needs — `PatternType 1`
tiling patterns (`/Pattern cs`/`/Pn scn` fills instead of per-tile `Do`
calls) and `ExtGState` **soft-mask** groups (a parallel grayscale shading as
`/SMask /Luminosity`, so a gradient color stop can finally carry real
alpha) — and closes the milestone with a from-scratch **Chrome-as-oracle
visual regression pipeline** (`tools/oracle/`: Playwright screenshots vs.
pliego's own Ghostscript raster, compared in pure PHP) that measures, rather
than asserts, how close the two renderers actually land — see
[Oracle](#oracle-chrome-as-ground-truth) for the full fidelity table. It is
still not a general-purpose renderer — `:hover`-style dynamic pseudo-classes
(meaningless in paged media), `@media`, pseudo-elements (`::before`/
`::after`), `position: sticky`, CSS columns, writing modes, `text-shadow`,
`border-image`, and the rest of tables/flex-to-spec are the milestones ahead
(see [Roadmap](#roadmap)).

### Supported as of M9

M9 doesn't widen the CSS/box-model surface the way M1-M7 did — it proves
that surface against a **real, unmodified, third-party stylesheet** instead
of the hand-picked "Bootstrap-flavored" CSS every earlier milestone's E2E
used, and adds the couple of PDF primitives that stylesheet actually needs.

- **`Engine::bootstrap()`** — a static-factory preset (alternative to
  `Engine::make()`) that vendors real Bootstrap 5.3.6
  (`resources/presets/bootstrap.min.css`, MIT) plus a small print addendum
  (`@page { margin: 15mm }` — real Bootstrap ships no print margins at all).
  Queued author-order **before** every `->stylesheet()` call, so your own
  same-specificity overrides win by cascade order alone, no `!important`
  needed. Full detail, including what it deliberately does **not** do (no
  JS, no sheet rewriting), in [Presets](#presets).
- **Honest capability audit**: parsing the vendored sheet alone produces
  1066 warnings (M10-T2; 870 as of M10-T1, 895 as of M9). M10-T1 removed 25
  of the old 895 (`vw`/`vh` viewport units and `:nth-of-type`/
  `:nth-last-of-type` gaining real support, css-values-4 §5.1.1 /
  Selectors-4 §14.4: 9 "Unsupported length" + 15 "Invalid calc() expression"
  for `vw`/`vh`, plus 1 "Pseudo-class not supported yet: :nth-of-type").
  M10-T2 ADDS 196 (real `min-width`/`max-width` evaluation against the
  page's own CSS-px width, `Css\MediaQueryEvaluator` — 30 of the sheet's
  108 non-print/all `@media` blocks now genuinely apply at A4 width instead
  of being uniformly skipped, e.g. Bootstrap's `.row-cols-md-3` responsive
  grid; those 30 blocks' own real declarations surface 196 genuinely new
  instances of already-documented gaps — `position: sticky`, `width: auto`,
  `z-index`, `transform`, `margin: auto` shorthand — not new KINDS of gaps,
  see `MediaQueryEvaluatorTest`/`BootstrapIngestionTest` for the exact
  per-category breakdown), every one bucketed by a
  regex-per-category classifier and pinned as a golden snapshot with a
  `'other'`-must-be-empty safety net (i.e. a *complete* partition, not a
  sample) — `@media` blocks that don't apply (skipped as a single aggregate
  warning, not one per block), unknown/unsupported pseudo-classes,
  unsupported properties/keywords/lengths/colors, invalid `calc()`
  expressions. Rendering an actual page pushes the count higher still (1100
  for the M9-T2 component showcase, 1240 for the full page used as oracle
  fixture 07) as more
  declarations get resolved against real elements (unresolved `var()`
  chains, atomic flex fragments taller than a page, …) — this is the *whole
  point*: pliego tells you exactly what it didn't understand instead of
  silently dropping it. (M10-T1 finding fix: a custom property set to the
  CSS-wide keyword `initial` — e.g. Bootstrap's own `.table` reset — now
  correctly engages the `var()` fallback chain instead of substituting the
  literal string `"initial"`, dropping 105/15 of those two counts
  respectively; see `Css\VarResolver`.)
- **`PatternType 1` tiling patterns** (ISO 32000-1 §8.7.3.3, `/PaintType 1`):
  `PdfWriter::registerTilingPattern()` registers a small tile as a pattern
  cell once; `PdfCanvas` then fills a border box with `/Pattern cs`/`/Pn
  scn` instead of stamping N individual `Do` XObject calls — the mechanism
  real Bootstrap's own tiled/repeated backgrounds need, decoupled from how
  many times the tile actually repeats across the box.
- **`ExtGState` soft-mask groups for gradient alpha** (ISO 32000-1 §11.6.5.2,
  `/SMask /Luminosity`): `PdfWriter::registerSoftMaskGroup()` builds a
  grayscale Form XObject shading **in parallel** with a gradient's own color
  shading — a gradient stop's alpha channel finally survives into the PDF
  (an `rgba()` color-stop used to be forced fully opaque with a warning,
  see M8; that warning is gone for stops the soft-mask path now handles).
- **Chrome-as-oracle visual regression** (`tools/oracle/`, not part of the
  Composer package — Playwright/Node live entirely under that directory
  with their own `package.json`): screenshots real Chromium's rendering of
  each fixture and compares it, pixel-by-pixel in pure PHP, against
  pliego's own Ghostscript rasterization. Opt-in locally (`composer
  oracle`), a separate, non-blocking CI job otherwise — see
  [Oracle](#oracle-chrome-as-ground-truth) for the full fidelity table and
  how to run it.
- **Housekeeping**: a document with a `<style>` element anywhere (`<head>`
  or `<body>`) now gets a one-time warning ("style tags are ignored; pass
  CSS via stylesheet()") instead of silently rendering unstyled — this
  engine's API is CSS and HTML as two separate strings
  (`->stylesheet($css)->render($html)`); auto-extracting and applying inline
  `<style>` content is a real feature, left for a future milestone.

### Supported as of M8

M8 rounds out the box model's **look** rather than widening it further —
every feature below composes with everything from M1-M7 (a rounded,
gradient-filled, shadowed `.card` still lays out through the exact same
`BlockFlowContext` as a plain `<div>`).

- **`border-radius`** (css-backgrounds-3 §5 subset): one circular radius per
  corner (`border-radius` shorthand, 1-4 values, **clockwise** tl/tr/br/bl
  order — different from the TRBL margin/padding shorthand, per spec — plus
  the 4 longhands); elliptical radii (`/` syntax) are rejected with a
  warning, negative values are rejected. Percentages resolve **against the
  box's own width** for all 4 corners (an M8 adjudication, not the spec's
  per-axis rule), then the whole set is proportionally clamped so no two
  adjacent corners can overlap (css-backgrounds-3 §5.5) — a `border-radius:
  999px` badge with a small height is auto-clamped down to exactly half
  that height, the real "pill" look, with zero special-case code.
  - Painted as real cubic Bézier paths (`PdfCanvas`'s `k=0.5522847498`
    circle-approximation constant), not a polygon approximation. A
    **uniform** border (all 4 sides equal width/style/color, and no side
    left unstyled adjacent to a rounded corner) paints as a single annular
    fill (`f*`, even-odd rule: one outer path, one inner path, no geometric
    subtraction) instead of 4 separate rects. **Mixed** border widths/
    styles/colors fall back to the flat 4-rect approximation **with a
    warning** ("mixed border widths with border-radius approximated") —
    the radius is silently dropped from the border itself (the background
    still rounds correctly).
  - `overflow: hidden` on a box with a non-zero radius clips through the
    same rounded Bézier path (`clipRoundedRect`), not a bounding rect.
  - A multi-slice inline box (a wrapped `<span>` with `border-radius`,
    box-decoration-break: slice) rounds correctly on **both** its first and
    last visual slice — the lateral side an inner slice suppresses to
    `None` is recognized as slicing bookkeeping, not real border
    heterogeneity, so it never trips the "mixed" fallback/warning.
- **Native PDF gradients** (css-images-3 §3.1 subset): `linear-gradient()`
  and `radial-gradient()` as a `background`/`background-image` value,
  painted as a real PDF `/Shading` object (`/ShadingType 2` axial /
  `/ShadingType 3` radial) rather than a rasterized bitmap — resolution-
  independent, tiny file cost regardless of the element's size.
  - **Linear**: full direction grammar (`<angle>`, `to <side>`, `to
    <corner>`), css-images-3 §3.4.1 stop-position distribution (implicit
    0%/100% endpoints, monotonic clamp, even split of unpositioned runs),
    2 stops → `/FunctionType 2`, N>2 stops → `/FunctionType 3` (exact
    `/Bounds`/`/Encode` stitching).
  - **Radial reduced to `circle at center`**: any other shape/position/
    extent (`ellipse`, an explicit `at <position>`, `closest-side`/
    `farthest-corner`/a bare length/a percentage-pair size, etc.) degrades
    to circle-at-center **with a warning** rather than being dropped —
    radius = farthest-corner distance.
  - **Noisy approximation**: a `to <corner>` direction (`to top right`,
    etc.) always uses a **fixed 45°/135°/225°/315°** angle — real CSS
    computes the angle from the box's own aspect ratio, so a non-square box
    diverges from a real browser here.
  - Alpha color stops are rejected (warning, forced fully opaque) and only
    the first declared `background`/`background-image` layer is used
    (multiple comma-separated backgrounds warn and drop everything past the
    first) — both are milestone-wide M8 restrictions, not gradient-specific.
  - An inline element's gradient is painted **per visual slice** (each
    wrapped line gets its own independent gradient rect) rather than one
    continuous gradient spanning every line — a documented divergence from
    a real browser's single continuous paint.
- **`box-shadow`** (css-backgrounds-3 §6 subset): one shadow (`offsetX
  offsetY [blurRadius] color`; a comma-separated list uses only the first,
  with a warning) painted **before** the background, following the same
  `border-radius` as the element.
  - **`blur-radius: 0`**: exactly one extra filled rect/rounded-rect,
    offset by `(offsetX, offsetY)` — free.
  - **Noisy approximation — blur is NOT a real Gaussian/box blur**:
    `blur-radius > 0` is approximated as **4 concentric layers**, each at
    1/4 the shadow color's alpha (sharing one PDF `ExtGState`), expanded by
    `-blur/2, -blur/6, +blur/6, +blur/2` from the base rect. This produces
    a soft-edged look at a glance but is not a true blur convolution —
    acceptable only for the small blur radii (≲10px) typical of a card/
    button shadow; a large blur radius will look banded, not smooth.
  - **Not supported, reported as a warning**: `inset` shadows (the whole
    declaration is dropped) and a declared 4th length (`spread-radius`,
    warned about but NOT dropped — the shadow is still built from the
    first 3 lengths, spread is simply ignored).
  - `box-shadow` on an inline element (`<span>`) is **not supported**: a
    one-time warning fires and the declaration is dropped entirely (M9+
    scope, per the milestone's own restriction list).
- **`border-style: dashed` / `dotted`** (CSS 2.2 §8.5.3 subset, ISO
  32000-1 §8.4.3.6 PDF dash arrays): a **uniform** dashed/dotted border (all
  4 sides equal) strokes a single continuous centerline path (rect or,
  with a radius, the same Bézier path border-radius already uses) with a
  real PDF dash array — dashed: `[3w w] 0 d` (w = the border's own half-pt
  width); dotted: `[0 2w] 0 d 1 J` (a zero-length dash + a round line cap
  draws actual round dots, not square ticks). **Heterogeneous** sides (any
  mix of style/width/color) fall back to 4 independently-stroked/filled
  segments — straight, unmitred joins at the corners (no diagonal miter
  negotiation between differently-styled adjacent sides, a **noisy,
  documented approximation** of real corner joins).
- **`letter-spacing` / `word-spacing` / `text-transform`** (css-text-3 §8
  subset, both spacing properties **inherit**): a run with non-zero
  spacing switches its PDF text-showing operator from a plain `<hex> Tj`
  to a per-glyph adjusted `[<g1> adj1 <g2> adj2 ...] TJ` (ISO 32000-1
  §9.4.3) — a run with **zero** spacing (the overwhelming majority of
  existing documents) stays byte-identical to the pre-M8 plain `Tj`, a
  verified regression guard. `word-spacing` only adjusts the space glyph;
  `letter-spacing` adjusts after every glyph, including the last.
  `text-transform: none|uppercase|lowercase|capitalize` rewrites the text
  run **before** measuring/painting (UTF-8/accent-safe); `capitalize`'s
  word boundary is "start of string or a run of space/tab" — a hyphen is
  **never** a boundary, a documented, deliberate divergence from some (not
  all) real browsers.
- **`background-image`** (css-backgrounds-3 §4/css-images-3 subset):
  `url(...)` (quoted or bare), decoded/loaded at **paint** time through the
  same `ImageLoader`/dedup pipeline `<img>` already uses (a background-
  image and an `<img>` sharing the same file produce exactly one XObject).
  `background-size: auto|cover|contain` (no lengths/percentages/2-value
  forms yet), `background-repeat: no-repeat|repeat` (repeat tiles at the
  image's own intrinsic size, ignoring `background-size` when both are
  declared together — repeat wins, a documented adjudication),
  `background-position: center|top left` (2 canonical keyword forms only).
  `cover` always centers (ignores `background-position`); `contain`
  honors it. Clipped to the element's own (rounded, if applicable)
  border-box. `repeat` + `center` together is an unsupported combination —
  warns once and tiles from top-left anyway.
- **`@font-face`** (css-fonts-4 §4 subset): `font-family` + a `src` fallback
  list — the first **local** `.ttf`/`.otf` in the list wins; `woff`/
  `woff2`/a remote (`http(s)://`) URL/`local()` are skipped with a warning
  in favor of the next candidate, not silently ignored. `font-weight`
  (`400`/`700`/`normal`/`bold`/any other number, mapped to the nearest of
  400/700 with a warning; a `"100 900"` range collapses to its first value)
  and `font-style` (`normal`/`italic`) route the parsed face into the exact
  same `FontCatalog` slots a built-in family uses. `unicode-range` is
  recognized but ignored (warning; the whole font file loads regardless — a
  documented, deliberate simplification, not real subrange loading).
  Family lookup is **case-insensitive end to end** (`@font-face
  { font-family: 'MiSerif' }` matches `font-family: miserif` and vice
  versa, including inside a fallback list) — the whole family/weight/style
  key space is normalized to lowercase at the single point it enters
  `FontCatalog`.
- **Excluded this milestone, reported as a warning rather than silently
  ignored or approximated** (per the M8 brief's own restriction list):
  `border-image`, inset `box-shadow`, `conic-gradient()`, multiple
  comma-separated backgrounds (only the first layer paints),
  `background-attachment`, `text-shadow` (a strong M9+ candidate, shares
  most of `box-shadow`'s machinery), `font-variant`, and `unicode-range`
  (the font still loads in full, see above).

### Supported as of M7

- **User-agent stylesheet** (replacing M0-M6's hardcoded tag-lists for
  bold/italic/underline/display defaults with real CSS rules, parsed
  through the same cascade as author CSS — an author rule can now win
  against a UA default with a *lower* specificity than before, since UA
  origin is compared before specificity, CSS 2.2 §6.4.1): `h1`-`h6` sizes
  and margins (`2/1.5/1.17/1/.83/.75em`, CSS 2.2 Appendix D-exact), `p`/
  `ul`/`ol`/`dl` (`margin: 1em 0`), `blockquote`/`figure` (`margin: 1em
  40px`), `pre` (`font-family: monospace; white-space: pre; margin: 1em
  0`), `code`/`kbd`/`samp` (monospace), `hr` (`border-top: 1px solid`;
  `margin: .5em auto` simplified to `.5em 0` — no `margin: auto`
  resolution exists yet, see below), `small` (`.83em`), and `th`
  (bold + centered, carried from M5).
  - **`white-space: pre`**: a `pre`-tagged text node is split on real
    newlines into hard `LineBreakRun`s (CRLF normalized) instead of the
    usual whitespace-collapsing; inside a single preformatted run there is
    no soft-wrap opportunity at all (overflow allowed, matching real
    browsers for an unbroken preformatted line).
  - **`font-family` as a real fallback list**: `font-family: "X", sans-
    serif` resolves each candidate against `FontCatalog` in order, falling
    back to the next name (and finally the generic keyword —
    `sans-serif`→`default`, `serif`→`serif`, `monospace`→`monospace`) —
    replacing M0-M6's "first name or bust" behavior. `monospace`/`serif`
    are now registered engine resources (bundled DejaVu Sans Mono/Serif,
    same license as the existing DejaVu Sans), not just `default`.
- **Lists** (css-lists-3 §3 subset): `display: list-item` (the UA default
  for `<li>`), `list-style-type: disc|circle|square|decimal|none`
  (inherited), with the classic two-level UA nesting rule (`ul` → disc,
  `ul ul` → circle, `ul ul ul` and deeper → square, never cycling back) and
  `ol` → decimal honoring a numeric `start` attribute. A marker is a
  synthetic glyph/`"N."` string sharing the font/baseline of the `<li>`'s
  **first** line of text (or a content-top+ascent fallback for an empty
  `<li>`), right-aligned 0.5em from the list's own padding band. A nested
  `<ol>`/`<ul>` restarts its own counter independently of any ancestor
  list, since it is itself a fresh `layout()` call.
  - `list-style-position` only recognizes `outside` (the only model
    implemented — no dedicated field exists for it in `ComputedStyle`);
    `inside` and any `list-style-image` are rejected with a warning.
- **Real inline boxes + `display: inline-block`** (css-inline-3 subset —
  THE M7 headline feature): an inline element (`span`, `strong`, custom
  `display: inline`, etc.) that declares a background, a visible border,
  or non-zero padding on any side now generates a real paintable box
  (`InlineBoxFragment`) that opens and closes mid-line, even mid-word,
  and correctly **slices across a wrap** (`box-decoration-break: slice`,
  the only mode implemented): lateral (left/right) border and padding
  paint only on the box's first/last visual slice, top/bottom paint on
  *every* slice. An inline element with **no** visible decoration still
  takes the pre-M7 "flattened to plain text runs" fast path — byte-
  identical geometry, zero regression for the overwhelming majority of
  existing documents/tests.
  - **`display: inline-block`**: laid out through the full block pipeline
    (recursing into `BlockFlowContext`) and placed as one atomic, unbreak-
    able item in the line — width is shrink-to-fit (`min(max-content,
    available)`) unless a `width` is declared, honoring `box-sizing`;
    baseline is approximated as the **bottom of its own margin box**
    (documented, standard-in-simplified-engines approximation, same
    criterion this engine already used for a plain `<img>`). An inline-
    block taller than the surrounding text grows the whole line's height
    (the "strut + item" model) without disturbing a normal, all-text line.
  - **Documented gaps**: `IntrinsicSizer` ignores an inline box's own
    horizontal padding when computing an ancestor's min/max-content (its
    *content* is still measured) — could under-estimate the needed width
    of, say, a table cell whose only content is one wide padded `span`. An
    inline-block's own box boundary is always treated as a valid wrap
    point (not the full UAX#14 "object" semantics for surrounding white-
    space). An `<img>` nested inside an inline element is still hoisted to
    block level with a warning (M3 behavior, unchanged) — it is *not* yet
    a real inline-level replaced box with its own line wrapping.
- **`min-width`/`max-width`/`min-height`/`max-height` + `overflow: hidden`**
  (CSS 2.2 §10.4/§10.7 subset): clamped in content-space before laying out
  children (max first, then min); `min-height` grows a box's own auto
  height (content anchored at the top), `max-height` caps it while content
  keeps overflowing **visibly** unless `overflow: hidden` is also declared,
  in which case the box clips its descendants with a real PDF clip path
  (`re W n`, ISO 32000-1 §8.5.4) around just its own children (its own
  background/border are never clipped). `overflow: scroll`/`auto` coerce
  to `hidden` with a warning (no scrolling in a print engine). A clipping
  box is treated as pagination-atomic (like a flex container), never split
  leaf-by-leaf across a page boundary.
  - **Gaps**: min/max-height are not applied to a replaced element
    (`<img>`) — only min/max-width, with the height re-derived from the
    (already clamped) width via the image's own ratio when the height
    itself is `auto`. `overflow: hidden` on a `<table>`/table cell has no
    clipping effect yet (the field is threaded through geometric
    reconstructions but no construction site sets it there).
- **Floats** (CSS 2.2 §9.5 subset): `float: left|right` removes a
  `BlockBox`/`<img>` from normal flow and places it against the nearest
  open band of its own block formatting context (BFC); `clear: left|
  right|both` jumps the next in-flow box below the tallest relevant float.
  A **line of text** queries the active float bands at the moment it
  starts and shortens/repositions itself around them, re-querying lower
  down if even its first word doesn't fit next to an intruding float. A
  new BFC is established by the document root and by any `overflow:
  hidden` box (CSS 2.2 §10.6.7's "clearfix": such a box's own auto-height
  then *does* grow to contain a float taller than its other content); any
  other box simply forwards its floats up to the BFC that actually owns
  them, exactly like a browser.
  - **Documented gap**: only **inline content (line boxes)** shortens
    around a float — a normal **block-level** sibling (a bordered `<div>`,
    say) is still laid out at its parent's full content width, potentially
    overlapping the float visually, since only `InlineFlowContext`
    consults the float bands; `BlockFlowContext`'s own per-child width
    resolution does not. Real CSS narrows both.
- **`position: relative` / `position: absolute`** (CSS 2.2 §9.4.3/§10.3.7
  subset; `fixed`/`sticky` fall back to `static`, `sticky` reported as an
  explicit warning): `relative`'s `top`/`right`/`bottom`/`left` offset is a
  pure **paint-only visual shift**, applied once the whole subtree already
  has its normal-flow geometry — a sibling's position and the container's
  own auto-height are computed from the **pre-shift** geometry, never
  leaking the offset into flow (verified end to end — see
  `BootstrapComponentsTest`). `absolute` removes the box from flow
  entirely, resolving against the nearest positioned ancestor's content
  box (or the page's own content box if there is none), with
  shrink-to-fit sizing when `width`/`height` is `auto` (not CSS 2.2's full
  10-case §10.3.7 resolution table).
  - **`top`/`bottom` are px-only** (a `%` value is rejected with a
    warning, like `height`) — `left`/`right` do accept `%`, resolved
    against the containing block's width.
  - **Documented gaps**: a `position: absolute` descendant that declares
    `bottom` (without `top`) against an ancestor whose own height isn't
    yet known falls back to the static-position cursor Y, with a warning.
    An absolute descendant nested inside a flex item or table cell always
    resolves against the page's root containing block, not a positioned
    ancestor in between (`FlexFormattingContext`/`TableFormattingContext`
    don't thread a containing-block parameter). An absolutely/relatively
    positioned box always paints as a child fragment of its *direct*
    parent (never bubbled up to the actual containing-block ancestor) —
    safe only because every `Rect` in this engine's fragment tree is
    already in whole-page absolute coordinates, never a local space.

### Supported as of M6

- **Selectors** (selectors-3 subset, replacing M0-M5's single-compound-only
  matching): a full selector is a chain of compound selectors joined by
  **combinators** — descendant (space), child (`>`), next-sibling (`+`),
  subsequent-sibling (`~`) — matched right-to-left, plus **specificity**
  (`Specificity`, an (a,b,c) value object with its own `compareTo()` — the
  cascade now orders by real specificity, not just source order, replacing
  M0-M5's `int`-returning `specificity()`).
  - **Attribute selectors**: `[attr]`, `[attr=val]`, `[attr~=val]`,
    `[attr^=val]`, `[attr$=val]`, `[attr*=val]`, `[attr|=val]`. The
    selectors-4 case-insensitivity flag (`[attr=val i]`) parses but always
    falls back to case-sensitive matching (one-time warning) — it is not
    actually honored.
  - **Pseudo-classes**: `:root`, `:first-child`, `:last-child`,
    `:nth-child(An+B|odd|even)`, `:nth-of-type(An+B|odd|even)`,
    `:nth-last-of-type(An+B|odd|even)` (M10-T1, Selectors-4 §14.4 — same
    An+B machinery as `:nth-child`, counting position only among siblings
    that share the element's own tag name), `:not(<single compound
    selector>)` (no nesting, no comma-separated compounds inside `:not()`).
  - **Not supported, reported as a warning rather than silently ignored**:
    `:hover`/`:focus`/`:active`/`:visited`/`:link` (parsed for specificity,
    but permanently excluded from matching — dynamic states have no
    meaning in paged/print media, not a "not implemented yet" gap),
    `:first-of-type`/`:last-of-type`/`:only-of-type`/`:only-child`/`:empty`/
    `:nth-last-child`, and any unknown pseudo-class. `::before`/`::after`
    and every other pseudo-*element* aren't parsed at all (they need to
    generate boxes of their own — M7).
- **Units** (css-values-3 §5-6, css-values-4 §5.1.1): `em` (relative to the
  element's **own** computed font-size — except in `font-size` itself,
  where css-values-3 §5.2 measures em/% against the **parent's** font-size
  to avoid a self-referential circularity) and `rem` (always relative to
  the **document root**'s computed font-size, however deeply nested the
  element is — never the nearest ancestor). Physical units `pt`/`cm`/`mm`/
  `in` fold to px at parse time (exact 96dpi factors). `vw`/`vh` (M10-T1)
  resolve against the **paper's own CSS-px size** (`Page\PaperSize`, e.g.
  794×1123 for A4) — not a content box, and not a browser viewport (a
  paginated engine has no such thing): the adjudicated equivalent, since a
  browser's print viewport IS the full page.
- **Custom properties and `calc()`** (css-variables-1, css-values-3 §8):
  `--name: value` declarations (inherited down the tree, resolved at
  compute time) and `var(--name, fallback)` with cycle detection (a cyclic
  reference is "invalid at computed-value time": falls back if a fallback
  exists, otherwise the whole declaration is dropped, with a warning).
  `calc()` supports `+ - * /` with correct precedence and parentheses,
  mixing `%`/`em`/`rem`/px/physical units and unitless numbers (including
  the Bootstrap spacer idiom `calc(var(--bs-spacing) * .5)` and bare
  leading-dot decimals like `.5rem`); a structurally invalid expression
  (e.g. `10px * 20px`, both sides carrying a unit) or a division by zero is
  a warning, the declaration dropped.
  - **Known gap**: a `calc()` whose result depends on a `%` component
    can't have its **sign** validated until Layout, when the containing
    block is finally known — unlike a plain `%` literal or an em/rem-only
    `calc()`, both of which get their negative-value check at parse/
    compute time. In practice this means `width: calc(-50% + 10px)` on a
    property that forbids negative values will not warn the way
    `width: -10px` would; it is simply used as computed. Not fixed until a
    used-value clamp lands (M7+).
- **Colors** (css-color-3/4 subset): hex, the **148 CSS named colors**
  (both `gray`/`grey` spellings, `rebeccapurple`; generated — not
  hand-typed — from the `color-name` npm package by
  `scripts/generate-named-colors.php`, see that script's own header for
  usage/regeneration/verification), `rgb()`/`rgba()`, `hsl()`/`hsla()`
  (**classic comma syntax only** — the css-color-4 space+`/`-alpha syntax,
  `rgb(255 0 0 / 50%)`, is not recognized and falls through to the generic
  unsupported-color warning), `transparent`, and `currentColor` (resolves
  against the element's own computed `color` for
  `background-color`/`border-*-color`; for `color` itself it resolves
  against the **inherited** value, since it can't refer to itself).
  `opacity` (a plain `0`-`1` number, silently clamped — not the `%` syntax)
  composes multiplicatively with a color's own alpha (`rgba(...,0.5)` with
  `opacity:0.5` → effective alpha 0.25). Alpha renders as a real PDF
  `ExtGState` (`/ca`/`/CA`, deduped by value) scoped to a `q`/`Q` block
  around just the op that needs it — a fully opaque color (the vast
  majority) costs zero extra bytes, byte-identical to pre-M6 output.
  - **Documented divergence**: `opacity` does **not** propagate to
    descendants as a real CSS/PDF transparency group would — each
    `BoxFragment`/`TextFragment`/`ImageFragment` only ever applies its
    *own* computed opacity at paint time. A semi-transparent parent
    container does not visually dim its children as a group; each child
    renders at whatever opacity *it itself* resolved to (1.0 by default,
    since `opacity` doesn't inherit). Full transparency-group compositing
    is out of scope until M7+.
  - No `color-mix()`, `lab()`/`lch()`/`oklab()`/`oklch()`, or any other
    css-color-4/5 function.

### Supported as of M5

- **Tables** (css-tables-3 §2 subset): `display: table` on `<table>` (and the
  matching UA defaults for `<thead>`/`<tbody>`/`<tr>`/`<td>`/`<th>` — no CSS
  needed to get real table semantics out of plain table markup) laid out by a
  standalone `TableFormattingContext` (not `FormattingContext` — a `TableBox`
  is a sibling of `BlockBox` in the box tree, not a specialization of it, the
  same adjudication `InlineFlowContext` already made).
  - **`<thead>`/`<tbody>`**: transparent row groups — they contribute no
    level of their own to the box tree, their `<tr>`s flatten directly into
    the table's row list in document order. `<thead>`'s rows are tagged (so
    a future repeating-header feature has the signal it needs) but **do not
    actually repeat per page** yet — see limitations below.
  - **Column width algorithms** (§17.5.2): **auto** (the default) sizes each
    column from the real min/max-content of every span-1 cell that falls in
    it (via `IntrinsicSizer`, the same collaborator M4 already used for flex
    sizing) — Σmax ≤ available distributes the surplus proportional to each
    column's max; Σmin ≥ available clamps every column to its min and warns;
    the interpolated middle case is linear between min and max. **Fixed**
    (`table-layout: fixed` **with** a declared table width — without one it
    warns and falls back to auto, per spec) is the fast path: the first
    row's own declared cell widths win outright, no `IntrinsicSizer` call at
    all, undeclared columns split the remainder equally.
  - **`colspan`**: a spanning cell's excess width (what its own content
    needs beyond the columns' already-accumulated single-span max) is
    distributed across the columns it spans, proportional to each column's
    own single-span max — equal shares when every spanned column is at 0.
  - **Separated borders model** (§17.6.1, the only model this engine
    implements — see `border-collapse` below): a single `border-spacing`
    value (both axes share it, a documented simplification of the two-value
    spec syntax) inserted before/between/after every column and every row;
    rows paint their own background but never a border of their own — only
    cells and the table's own outer border do.
  - **`vertical-align`**: `top` (default), `middle`, `bottom` on table cells
    only (this is **not** the general inline `vertical-align`, css-tables-3
    §3's cell-specific subset) — a shorter cell in a row stretches to the
    row's height (geometry-only, its content is never re-laid-out) and then,
    for `middle`/`bottom`, its content shifts down within that stretched box
    by half/all of the resulting delta.
  - **Nested tables**: a `<table>` inside a `<td>`/`<th>` is a completely
    ordinary case — cells reuse the exact same box-tree pipeline as any
    other block content (blocks/inline/images/nested tables), and a nested
    table now contributes its own real min/max-content to its host cell's
    column-width calculation (an `IntrinsicSizer` gap closed post-review: a
    cell whose only content was a nested table used to measure as 0-width,
    collapsing its column to zero and visually overlapping its sibling).
  - **Row-atomic pagination**: a `<tr>`'s `BoxFragment` is atomic (the same
    indivisible-unit mechanism M4 introduced for a flex container) — the
    table itself is **not** atomic, so `Paginator` descends into it freely
    and finds each row already indivisible, splitting the table exactly
    between rows with zero table-specific pagination code. A row taller
    than one page is kept unsplit, with the same warning M4's oversized
    atomic flex container already uses ("atomic fragment taller than page,
    kept unsplit") — no separate message for tables.
  - **Not supported, reported as a warning rather than silently ignored or
    approximated**: `border-collapse` (any value — the property isn't
    recognized at all, so it falls through to the generic "unsupported
    property" warning; separated borders is the only model implemented),
    `rowspan` (the attribute's mere presence warns and the cell is treated
    as if it weren't there — colspan-only grid, no vertical cell merging),
    a repeating `<thead>` per page (a `<thead>`'s rows are tagged but
    render exactly once, wherever they fall in document order — a
    multi-page table's header does **not** reappear on page 2+),
    `<caption>`/`<col>`/`<colgroup>`/`<tfoot>` (no dedicated handling — a
    `<caption>` as a direct child of `<table>` falls through the same
    "non-row element" anonymous-wrapping path any other stray element would,
    not a real caption placement), and `vertical-align: baseline` (or any
    value beyond top/middle/bottom).
  - **Loose content in a table** (bare text or a non-table-structure element
    found directly inside `<table>`/`<tr>`): `BoxTreeBuilder` *can* wrap it
    in an anonymous row+cell (a deliberately minimal subset of §17.2.1's full
    anonymous-box generation — it does not merge adjacent loose siblings
    into one shared anonymous box the way the complete algorithm would), but
    that code path is in practice unreachable through `HtmlParser::parse()`:
    `\Dom\HTMLDocument` already runs the full HTML5 tree-construction
    algorithm, which performs **foster parenting** itself — any stray text
    or non-table element written inside `<table>`/`<tr>` in source HTML is
    hoisted out to become a preceding sibling of the table *in the DOM
    itself*, before this engine ever sees it. The anonymous-wrapping code
    only fires for a non-HTML5 DOM source (XML/XHTML, or a hand-built DOM),
    verified empirically rather than a design choice — nothing in
    `BoxTreeBuilder` assumes its input always comes from `HtmlParser`.

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
- A background (or a visible border) that would visually cross a page
  boundary is pushed whole to the next page rather than split — it never
  paints twice or gets cut mid-box.
- **Borders**: `solid`/`none`/`dashed`/`dotted` (M8) — `double`/`groove`/
  `ridge`/`inset`/`outset` (CSS 2.2 §8.5.3) are still reported as warnings,
  not approximated. Corners are simple butt joints (each side is one filled
  rect, sized so the two horizontal sides span the full box width and the
  two vertical sides fit between them) — there is no real miter/45°-mitered
  corner; a dashed/dotted corner between two differently-styled sides is
  likewise an unmitred straight join (see "Supported as of M8" above).
  Different widths per side are supported, but two *thick*, *differently
  colored* adjacent sides will show that seam rather than a mitered
  diagonal.
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
- Floats and `position: relative`/`absolute` are implemented as of M7 — see
  "Supported as of M7" above for the reduced subset and its documented
  gaps. Still entirely unsupported, reported as a warning rather than
  silently ignored or approximated: `position: sticky`, a float's
  `shape-outside`, CSS columns (`column-*`), writing modes
  (`writing-mode`/`direction` beyond LTR/TTB), `::first-line`/
  `::first-letter`, and `list-style-image` — all M9+, alongside `@media`
  and the rest of `::before`/`::after`-style generated content. A `<table>`
  next to a float is a **further, honest instance** of the block-sibling
  gap already documented above: CSS 2.2 §9.5 forbids a table's border box
  from overlapping a float, but this engine's `TableFormattingContext`
  never consults the float bands (only `InlineFlowContext` does) — a
  table is laid out at its parent's full content width exactly like any
  other block-level box, so it can visually overlap an adjacent float
  instead of being narrowed around it, same gap and same fix scope (M9+)
  as the generic case. Tables are
  implemented as of M5 but only the subset above — see "Supported as of
  M5" for what's excluded (`border-collapse`, `rowspan`, a repeating
  `<thead>` per page, `<caption>`/`<col>`/`<colgroup>`/`<tfoot>`,
  `vertical-align: baseline`). Flexbox is implemented as of M4 but only the
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
- **Inline `style="..."` attributes are not supported**: only `<style>`
  stylesheets (and the engine's own UA stylesheet) are parsed for CSS — an
  element's own `style` attribute is ignored entirely, with a one-time
  warning the first time one is seen anywhere in the document (not once per
  element). Use a `<style>` block (or an external stylesheet passed to the
  engine) instead.
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
- **M8's noisy approximations, gathered in one place** (each is documented
  in full detail, with its reasoning, in "Supported as of M8" above — this
  is just the honest one-line summary of every place M8 diverges from a
  real browser rather than merely omitting a feature):
  - `box-shadow`'s `blur-radius > 0` is **4 flat concentric layers, not a
    real Gaussian/box blur** — looks soft at a glance, bands at a large
    blur radius.
  - `radial-gradient()` is **always reduced to `circle at center`** — every
    other shape/position/extent keyword degrades to that, with a warning.
  - A `linear-gradient(to <corner>, ...)` uses a **fixed 45°/135°/225°/
    315°** angle, never the box's own aspect-ratio-dependent angle real CSS
    computes.
  - A dashed/dotted corner between two differently-styled sides is a
    **straight, unmitred join** — no diagonal miter negotiation.
  - `box-shadow` and `background-image` are **not supported on inline
    elements** (a `<span>`) — both warn once and drop the declaration
    entirely; only block-level/inline-block boxes paint them.

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

## Presets

`Engine::bootstrap()` is an alternative entry point to `Engine::make()` that
ships with a vendored copy of real Bootstrap 5.3.6 (`bootstrap.min.css`, MIT —
see `resources/presets/LICENSE-bootstrap.txt`), so a document can use
`.btn`/`.card`/`.badge`/`.table`/etc. without pasting Bootstrap's CSS into
your own stylesheet first:

```php
use Pliego\Engine;

$html = <<<'HTML'
<body>
  <div class="card">
    <div class="card-body">
      <h5 class="card-title">Invoice #1042</h5>
      <p class="card-text">Rendered with the Bootstrap preset.</p>
      <a href="#" class="btn btn-primary">Pay now</a>
    </div>
  </div>
</body>
HTML;

$report = Engine::bootstrap()
    ->stylesheet('.btn-primary { background-color: purple }') // your own override
    ->render($html)
    ->save('invoice.pdf');
```

What it does:

- Queues the vendored sheet as the **first** author-origin stylesheet, before
  every `->stylesheet()` call that follows — a same-specificity rule of yours
  (`.btn-primary { background-color: purple }` above) wins purely by cascade
  order, no `!important` or extra specificity needed. This holds regardless
  of how many `->stylesheet()` calls you chain, or in what order they run.
- Also queues a small **print addendum** (`resources/presets/bootstrap-print.css`)
  right after it: real Bootstrap ships no `@page` rule at all (it's a
  screen-first sheet), so this addendum sets `@page { margin: 15mm }` —
  sane page margins out of the box instead of pliego's generic 48px default.
  A `@page` rule in your own `->stylesheet()` call completely replaces it
  (CSS's "last `@page` rule wins", not merged margin-by-margin).

What it does **not** do:

- It doesn't add Bootstrap's JS (no interactivity exists in a PDF) or any
  `:hover`/`:focus` behavior — dynamic pseudo-classes are permanently
  excluded (see the M9 warning audit) and `screen`-typed `@media` rules
  (and any feature this engine doesn't evaluate — `hover`,
  `prefers-reduced-motion`, `prefers-color-scheme`, …) are dropped. Width
  breakpoints (`min-width`/`max-width`/`width`, M10-T2) DO genuinely
  evaluate, though, against the page's own CSS-px width (`Css\
  MediaQueryEvaluator`) — the same width Chrome would use printing a page
  of that size, no viewport/device emulation involved.
- It doesn't rewrite or subset the vendored sheet — the file is the real,
  unmodified upstream release; unsupported constructs (inset `box-shadow`,
  `transform`, …) simply warn and get skipped, same as any CSS you'd write
  by hand. `Engine::make()` (no preset) is completely unaffected — never
  calling `->bootstrap()` renders byte-for-byte as before this feature
  existed.

## Tailwind

There is **no `Engine::tailwind()`/`Engine::tailwindPreflight()`** — unlike
Bootstrap, Tailwind gets no preset API at all, a deliberate M10-T4
adjudication (full reasoning in `.superpowers/sdd/m10-task-4-report.md`).
The short version: Bootstrap ships one authoritative `bootstrap.min.css`
that's the same for every project, so vendoring it once is genuinely
useful. Tailwind v4 doesn't work that way — every real build is
**per-project JIT output**, containing only the utility classes *your own*
HTML actually uses. There is no single canonical "Tailwind CSS" file to
vendor; a copy of "a" build would just be one arbitrary sample page's worth
of classes, useless for anyone else's markup.

### The workflow: bring your own build

1. Write HTML with Tailwind utility classes, same as any Tailwind project.
2. Generate the CSS locally with the standalone CLI, pointed at your HTML:
   ```
   npm install -D tailwindcss @tailwindcss/cli
   npx @tailwindcss/cli -i input.css -o output.css --content "path/to/your/*.html"
   ```
   (`input.css` needs only `@import "tailwindcss";` — Tailwind v4's entry
   point, replacing v3's `@tailwind base/components/utilities;`.)
3. Pass the generated CSS straight into `->stylesheet()`, same as any other
   hand-written or third-party sheet:
   ```php
   use Pliego\Engine;

   $css = file_get_contents('output.css');
   $report = Engine::make()->stylesheet($css)->render($html)->save('out.pdf');
   ```

pliego never shells out to the CLI itself and never vendors a copy of
Tailwind's CSS — no new runtime dependency, and nothing that can drift out
of sync with whatever Tailwind version your own build actually used.

### What genuinely works

M10-T3 ingested a **real, pinned Tailwind v4.3.2 CLI build**
(`tests/Fixtures/tailwind/tailwind-output.css`, MIT — generated once
against a representative utilities page and vendored as a golden fixture,
never re-run) end to end: 191 rules, 104 warnings across 16 categories, a
complete honest partition just like the Bootstrap audit.

- Static utility classes: spacing (`p-*`/`m-*`/`gap-*`), flexbox (`flex`,
  `flex-direction`, `flex-wrap`, `justify-*`, `items-*`), colors
  (`bg-*`/`text-*`), typography (`text-*`/`font-*`/`tracking-*`/
  `leading-*`), `rounded-*`, `shadow-*`, width/height.
- `oklch()` colors — Tailwind v4's entire default palette is defined in
  OKLCH, not sRGB hex; converted through the real css-color-4 matrix chain
  (`Color::oklchToSrgb()`), hand-verified against the spec and
  cross-checked with the `culori` npm library. **Not** the old Tailwind v3
  hex values for the same class names (v4 recomputed its whole palette
  directly in OKLCH for wider-gamut displays — only visually close to v3,
  never byte-identical).
- `@layer` (`theme`/`base`/`components`/`utilities`) — a real Tailwind v4
  build wraps virtually everything in `@layer`; unwrapped here in cascade
  rank order (css-cascade-5, reduced: no recursion into a nested `@layer`,
  and layered `!important` doesn't invert cross-layer precedence per
  §4.4 — one document-wide warning instead).
- Hand-written `:nth-child(odd)`/`:nth-child(even)` CSS pseudo-classes
  (used in the sample at line 208) and CSS custom properties/`calc()`
  (Tailwind's entire spacing scale is `calc(var(--spacing) * N)`). Note:
  Tailwind's own `odd:` and `even:` **utilities** do **not** work — they
  compile to CSS nesting which sabberworm mishandles (see below).

### What doesn't, and why

- **Variant classes never apply**: `hover:`, `sm:`, `md:`, `dark:`,
  `odd:`, `even:`, … all fail to match. Tailwind v4 compiles these to CSS
  nesting rules (e.g., `.odd\:bg-white { &:nth-child(odd) {...} }`), and
  sabberworm (the CSS parser) mishandles `&` nesting — it returns an empty
  block **and silently drops all subsequent rules in the stylesheet** (a
  verified regression, see M10-T4's report for details). The escaped colon
  in the class name (`.odd\:bg-white`) is secondary; the nesting
  mis-handling is the primary cause. Static (non-variant) utilities on the
  same element are unaffected.
- **Fraction utilities fail the exact same way**: `w-1/2`, `h-1/2`, …
  Tailwind escapes the `/` as `\/` in the class name for the identical
  reason above — the same backslash-escape gap, not a fraction-math
  problem.
- **No grid support**: `display: grid`, `grid-template-columns`,
  `grid-cols-*`, `col-span-*`, etc. all warn and are dropped — this engine
  only implements block/inline/flex/table layout, no grid formatting
  context (see [Roadmap](#roadmap)).
- **The all-sides `border` shorthand doesn't apply**: Tailwind's plain
  `.border`/`.border-{color}` utilities emit the all-sides `border-width`/
  `border-style`/`border-color` shorthand properties, which this engine
  doesn't recognize (`DeclarationParser::isBorderLonghand()` only matches
  the 4-part **per-side** form, `border-{side}-{width,style,color}`) — a
  pre-existing gap, not introduced by Tailwind ingestion.
- **Pseudo-elements aren't matched**: `::before`, `::after`,
  `::placeholder`, `::-webkit-*`, etc. — `SelectorParser` doesn't
  implement pseudo-elements at all yet, so any rule keyed on one
  (including most of Tailwind's own preflight reset, see below) falls
  into `invalid-selector`.
- `@property` rules (Tailwind emits dozens — one per animatable custom
  property) are dropped with a single aggregated warning; animation/
  transition support is out of scope for a paginated PDF regardless.

Most gaps surface as warnings, except nested variant rules: when
sabberworm mishandles `&` nesting (see the bullet above), it silently
drops all subsequent rules until the next top-level rule, with zero
warning emitted — a data loss risk tracked in the roadmap. All other gaps
above surface as real entries in `RenderReport::$warnings` (the same list
the playground's warnings panel renders), and the counts come from
ingesting the **entire** real build with zero exceptions carved out, not a
cherry-picked sample.

### Why no preflight preset

The milestone plan considered a narrower preset — just Tailwind's reset
(`@layer base`, the part of every build that normalizes `box-sizing`,
zeroes margins, sets font stacks), extracted the way `Engine::bootstrap()`
vendors the whole Bootstrap sheet. Adjudicated **against** it:

- The one rule in the preflight with real, non-duplicate value — `*,
  ::before, ::after { box-sizing: border-box; margin: 0; padding: 0 }` —
  is one line a user can write by hand if they need it. Everything else
  either duplicates pliego's own user-agent stylesheet
  (`Style\UserAgentStylesheet` already gives spec-compliant Appendix-D
  margins for headings/paragraphs/lists) or actively **conflicts** with
  it: Tailwind's preflight zeroes `h1`-`h6` font-size/margin, assuming its
  own typography utilities re-apply them — shipping that as a standalone
  preset would silently strip pliego's sane heading defaults for anyone
  who pastes it in without also writing utility classes, which is worse
  than doing nothing.
- Roughly half of preflight's own selectors target pseudo-elements this
  engine doesn't support (`::after`, `::before`, `::backdrop`,
  `::file-selector-button`, `::placeholder`, several `::-webkit-*`) — so
  "extract it clean" isn't actually clean: a first-party preset built out
  of rules that immediately warn `invalid-selector` would be a bad first
  impression for something billed as an official asset.
- Anyone following the bring-your-own-build workflow above already GETS
  the preflight for free — it's embedded in `@layer base` of every real
  Tailwind build, correctly version-matched to whatever Tailwind release
  generated their own utilities. A vendored copy would be redundant at
  best and version-drifted at worst, exactly the cost the milestone brief
  itself flagged.

### Playground

The playground (`index.php`) has an **"Ejemplo Tailwind"** sample button,
next to "Ejemplo" and "Ejemplo Bootstrap": a small invoice-style card and
table styled entirely with Tailwind utility classes (`bg-blue-500`,
`rounded-lg`, `shadow-md`, `flex`/`gap-4`, `text-*`/`font-bold`, …). Its
CSS is a slim, hand-curated slice of the real vendored v4.3.2 build above
— every selector/value copied verbatim and trimmed down to just the
classes the sample HTML uses (the sample deliberately avoids the
unsupported all-sides `border` shorthand, using `shadow-md`/background
color for visual definition instead — see the code comment in `index.php`
for the two specific gaps it works around). Nothing shells out to `npx`;
it's a faithful excerpt of real Tailwind CLI output, not a live build.

## Oracle: Chrome as ground truth

Warnings tell you what pliego *didn't understand*; they say nothing about
whether what it *did* paint actually **looks** right. `tools/oracle/`
answers that second question by rendering the same HTML/CSS through two
independent engines and measuring the disagreement in real pixels, instead
of asserting anything by hand:

1. **`render-chrome.mjs`** (Playwright) screenshots each fixture in headless
   Chromium at the CSS-px A4 page size (794×1123, `deviceScaleFactor: 2`) —
   real ground truth, the same engine your users' browsers use.
2. **`render-pliego.php`** runs the identical HTML/CSS through
   `Engine::make()`/`Engine::bootstrap()` and rasterizes the resulting PDF
   with Ghostscript at 192dpi (the same effective density as Chrome's 2×
   screenshot).
3. **`compare.php`** (`PliegoOracle\PixelDiff`, pure PHP — it reuses
   pliego's own PNG decoder, no GD/Imagick) computes the **% of pixels that
   genuinely differ**: `max(|ΔR|,|ΔG|,|ΔB|) > 24`, with an antialiasing mask
   that excludes any pixel sitting on a strong local edge in *either* image
   (two engines legitimately disagreeing about which side of an edge a
   half-covered pixel belongs to isn't a layout/paint bug). Every fixture
   gets an `NN-diff.png` visualization regardless of pass/fail.

Each fixture's max allowed diff% lives in `tools/oracle/thresholds.json`,
calibrated against a **real measured run**, never a round number picked in
advance — see that file's own `_comment` for the exact policy.

**Run it locally** (needs Node + a Chromium download + Ghostscript on
`PATH`; degrades to a no-op with a note if any are missing):

```
composer oracle
```

**In CI**: `.github/workflows/oracle.yml` is a **separate job** from the
main `pest`/PHPStan/deptrac workflow — it installs Node/Playwright/
Ghostscript (a real Chromium download, minutes not seconds) and is **never**
wired as a dependency of the PHP job, so a slow or momentarily-flaky oracle
run never blocks a merge. It uploads every screenshot + diff visualization
as a build artifact on both pass and fail. `tests/EndToEnd/
OracleFixturesSmokeTest.php` is the hermetic counterpart that *does* run in
the ordinary `pest` job — no pixel comparison, just "does every fixture
render through Engine without throwing, onto exactly one page".

### Fidelity table (radical transparency)

The real, measured numbers from the fixtures shipped today — not
aspirational targets:

| # | Fixture | Exercises | Diff % | Threshold | Root cause of the gap |
|---|---|---|---:|---:|---|
| 01 | Typography | Headings/paragraphs/lists, DejaVu Sans regular+bold+italic, numeric line-heights | 0.790% | 1.5% | Sub-pixel text metric rounding only; no structural gap |
| 02 | Table striped | `border-collapse`, `:nth-child(odd)` striping, auto column widths | 3.270% | 4.0% | `border-collapse` unsupported (separated-borders model always used) + the auto column-width algorithm's own rounding |
| 03 | Card, buttons, badges | `border-radius`, `box-shadow`, inline-block `.btn`/`.badge` | 1.529% | 2.5% | Approximated (non-Gaussian, 4-layer) `box-shadow` blur vs. Chrome's real blur convolution |
| 04 | Flex layout | `display:flex`, `gap`, `justify-content`/`align-items` | 0.089% | 0.5% | Near pixel-perfect since M10-T2's flex-item fix (was 1.471%/2.0% — two adjacent inline elements inside a flex container used to merge into ONE flex item instead of two, see fixture 07's own row below) |
| 05 | Blockquote / monospace | `blockquote`, `pre`/`code`, `white-space: pre` | 0.144% | 1.0% | Near pixel-perfect — smallest, simplest fixture |
| 06 | Gradients / shadows | Linear/radial `/Shading` gradients, `box-shadow` | 0.151% | 1.0% | Near pixel-perfect — native PDF shadings match Chrome's own gradient rendering closely |
| 07 | **Full Bootstrap page** | Real vendored `bootstrap.min.css` via `<link>`: navbar, grid of cards, buttons, badges, alerts, striped table, blockquote — `Engine::make()` + the same real sheet `Engine::bootstrap()` ships | 2.641% | 3.5% | **Was still the worst fixture through M10-T1; three real fixes later (M10-T2), it comfortably passes.** History: 4.665% (pre-M10-T1) → 5.654% (M10-T1's `vw`/`vh` fix, a genuine correctness win for headings that UNMASKED a pre-existing, unrelated navbar bug instead of fixing it — see M10-T1's own report) → 5.558% (M10-T2 PART 1, real `min-width`/`max-width` evaluation against the page's own 793.70px A4 width — `Css\MediaQueryEvaluator`, e.g. Bootstrap's `.row-cols-md-3` grid now genuinely applies) → 5.523% (M10-T2 PART 2's navbar fix — see below — barely moved 07's own number because a second, larger issue below the navbar dominated the diff mass by then) → **2.641%** (a line-height inheritance fix found while investigating why the navbar fix barely moved the number). Two real M10-T2 root causes, found via `tools/oracle/probe-*.mjs` (Playwright `getComputedStyle()` dumps, not part of the oracle pipeline) diffed against `Layout\FragmentDumper` dumps of pliego's own box tree: (1) `Box\BoxTreeBuilder`'s flex-item construction merged TWO adjacent `Display::Inline` children (`.navbar-brand`/`.navbar-text`, both real elements with their own Bootstrap padding) into ONE shared anonymous flex item instead of two separate ones (css-flexbox-1 §4 violation) — pliego's navbar rendered 40px tall against Chrome's real 56px, now hand-verified identical; (2) `Style\ComputedStyle`'s line-height inheritance carried an ancestor's ALREADY-RESOLVED px value straight down the tree instead of re-deriving a bare-NUMBER `line-height` (css-inline-3 §5.2 — e.g. Bootstrap's `body{line-height:1.5}`) against each descendant's OWN font-size: `.small`/`.card-text` (14px) inherited the body's 24px (16×1.5) unchanged instead of the correct 21px (14×1.5), a small per-line drift that compounded across every line below the card row into the fixture's dominant remaining diff mass. Both fixes are general (not fixture-07-specific): the flex-item fix alone dropped fixture 04 (a flex-layout fixture unrelated to Bootstrap) from 1.471% to 0.089%. |

Fixture 07 is deliberately kept to content that still fits **one** page
(`OracleFixturesSmokeTest.php` hard-requires every oracle fixture to render
to exactly one page, so `compare.php`'s "overlapping top-left region"
normalization stays meaningful) — `tests/EndToEnd/BootstrapPageTest.php` is
the *unbounded* companion E2E, a longer real page that's allowed (expected)
to paginate.

## Playground

`index.php` doubles as a runnable playground: a two-pane web UI (CodeMirror
HTML/CSS editors on the left, a live PDF preview on the right) backed by the
same `Engine` API described above, plus a warnings panel that surfaces every
unsupported CSS declaration the engine reported instead of silently
dropping it. The pre-loaded sample leads with a `:root { --brand: ...;
--stripe: rgba(...); ... }` custom-properties block, `calc(var(--gap) *
.75)` for a padding, and a "Riepilogo tappe" table striped via
`tbody tr:nth-child(odd) { background-color: var(--stripe) }` (M6) — plus,
as of M7, a real Bootstrap-style `<a class="btn">Prenota ora</a>`
(`display: inline-block`, its background/border/padding finally painting
in-line instead of flattening to plain text) and a small `<ul
class="packing-list">` rendered with real disc markers. As of M8, the
header banner paints a native `linear-gradient()` (a real PDF `/Shading`,
still built from the same `:root` vars), the price line carries a
`-10%` pill badge (`border-radius: 999px`, auto-clamped to a true pill by
the corner-overlap clamp — no special-casing), and both `.btn` and every
`.card` gained `border-radius` + a soft `box-shadow` — the full Bootstrap
look (rounded, shadowed, gradient-lit) renders correctly on the very first
click, no editing required. As of M9, a **"Bootstrap preset" checkbox**
next to the action buttons picks `Engine::bootstrap()` over `Engine::make()`
for the next render (a POST field, `$useBootstrapPreset ? Engine::bootstrap()
: Engine::make()` server-side) — and an **"Ejemplo Bootstrap"** button next
to the original **"Ejemplo"** one swaps both editors for a second sample
that has **no custom CSS at all** (`.btn`/`.card`/`.badge`/`.table`
markup only) and auto-checks the preset box for you, so the very first
click after loading it already shows the real Bootstrap look with zero
authored styles.

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
| **M4** | **Flexbox subset**: `display:flex`, `flex-direction` (row/column), `flex-wrap`, `gap`, `justify-content`, `align-items`, basic `flex-grow`/`flex-shrink`, `flex-basis`/`width`, atomic pagination | Authors write cards (photo + flexible text) assuming flex; M1–M4 together render the target document in full |
| **M5** | **Tables subset**: `table`/`thead`/`tbody`/`tr`/`td`/`th`, auto + fixed column-width algorithms, `colspan`, separated borders (`border-spacing`), cell `vertical-align`, nested tables, row-atomic pagination | Third-party/email-style HTML is built from `<table>`s, not flexbox — a classic email layout (photo cell + text cell, bordered data table inside) renders without rewriting it first |
| **M6** | **CSS core**: selector combinators + `:nth-child`/`:not` + specificity, `em`/`rem`/physical units, `:root` custom properties + `calc()`, full color syntax (`rgb()`/`hsl()`/148 named colors) + alpha via `ExtGState` | Real-world stylesheets (Bootstrap-flavored CSS especially) lean on `var()`/`calc()`, `rem`, and combinators/`:nth-child` for the exact "striped table" pattern used everywhere — none of that worked before M6 |
| **M7** | **Layout**: real user-agent stylesheet (`h1`-`h6`, list/blockquote/`pre` margins, monospace), list markers (`disc`/`circle`/`square`/`decimal`), real inline boxes + `display: inline-block` (THE `.btn`/`.badge` fix), `min`/`max-width`/`height` + `overflow: hidden` clipping, floats with line shortening, `position: relative`/`absolute` | A Bootstrap-derived `.btn`/`.badge`/`.card` finally paints in-line instead of flattening to plain text — the last big gap between this engine's box model and a real browser's |
| **M8** | **Visual polish**: `border-radius` (Bézier + annular ring + rounded clipping), native PDF gradients (`linear-gradient()`/`radial-gradient()` as real `/Shading` objects), approximated `box-shadow` (4-layer, non-Gaussian) + `dashed`/`dotted` borders, `letter-spacing`/`word-spacing`/`text-transform`, `background-image` (`cover`/`contain`/tiling), `@font-face` (local TTF, case-insensitive) | The last mile between "the boxes are in the right place" (M1-M7) and "it actually looks like a real Bootstrap-derived document" — a rounded, shadowed, gradient-filled `.card`/`.btn`/pill `.badge` is the exact look the target document's author would reach for |
| **M9** (this release) | **Real Bootstrap**: `Engine::bootstrap()` preset ingesting the real, unmodified, vendored `bootstrap.min.css` (5.3.6), `PatternType 1` tiling patterns + `ExtGState` soft-mask gradient alpha (the two PDF primitives that sheet needed), and a from-scratch **Chrome-as-oracle** visual regression pipeline (`tools/oracle/`) that measures fidelity in real pixels instead of asserting it | Proves the M1-M8 CSS/box-model subset against a real third-party stylesheet rather than a hand-picked one, and replaces "trust me, it looks right" with a measured, honestly-published fidelity table |
| **M10+** | Pseudo-elements (`::before`/`::after`) → `@media` (conditional inclusion, not just skip) → `position: sticky` → CSS columns → flex to spec (`order`, `align-self`, `stretch`) → grid → `text-shadow`/`border-image` | Generated content and responsive/print media queries round out the CSS core; flex/grid get completed to spec in their own milestones; the remaining visual properties M8 explicitly excluded |

## License

MIT. See [LICENSE](LICENSE).
