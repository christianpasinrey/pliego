# pliego

Pure-PHP HTML/CSS to PDF rendering engine. No binaries, no Node, no headless
browser — the full pipeline (HTML parsing, CSS cascade, box tree, block
layout, pagination, PDF writing) runs as plain PHP code.

> **Not published to Packagist yet.** This is an early milestone (M0); the
> package is not installable via Composer from a registry at this point.

## Status: M0 — walking skeleton

M0 proves the pipeline end to end (DOM → cascade → box tree → block flow →
paginated PDF) on a deliberately small, honestly-documented subset of
HTML/CSS. It is not a general-purpose renderer yet.

### Supported in M0

- **HTML**: a `<body>` with block elements and a flat set of inline tags
  (`span`, `strong`, `em`, `b`, `i`, `a`, `small`, `code`) that are flattened
  into the surrounding block's text — no independent inline layout yet.
- **Selectors**: type (`p`), class (`.note`), id (`#total`), and a single
  compound of the three (`p.note`). No combinators (no descendant, child,
  sibling selectors).
- **Properties**: `display: block|none`, `margin`/`padding` (shorthand and
  longhands, 1/2/3/4-value expansion), `width`, `color`,
  `background-color`, `font-size`, `font-family`.
- **Box model**: block formatting context per CSS 2.2 §9.4.1, with inherited
  `color`/`font-size`/`font-family` down the tree.
- **Text**: greedy line breaking on spaces (UAX #14 line breaking is M1),
  measured against a real embedded TrueType font.
- **Fonts**: one TTF embedded whole as a Type0/CIDFontType2 composite font
  with Identity-H encoding (no subsetting, no ToUnicode — both M1).
- **Pagination**: streaming, page-by-page fragmentation that pushes a leaf
  down to the next page when it would otherwise be split.

### Explicitly deferred (not bugs — documented M0 simplifications)

- No margin collapsing.
- No selector combinators beyond a single compound selector.
- A background that would visually cross a page boundary stays entirely on
  its starting page.
- Fonts are embedded in full (subsetting is M1).
- No `ToUnicode` CMap (M1) — text is extractable by tools that understand
  Identity-H + embedded glyph IDs, but not yet copy-pasteable as Unicode.
- Unsupported CSS is reported as non-fatal warnings, not rendering failures.

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
`->fontFile(string $ttfPath)` (default: a bundled DejaVu Sans). Instead of
`->save($path)`, `RenderResult::toStream($resource)` writes to any open
stream resource.

See `index.php` in the repository root for a runnable playground
(`php index.php`).

## Roadmap

- **M1**: UAX #14 line breaking, font subsetting, ToUnicode CMaps.
- **M2**: streaming layout (O(page) memory for the box/fragment tree itself,
  not just the PDF output), CSS selector combinators, stylesheet-from-path
  sugar.
- **M3–M8**: inline formatting context, floats, tables, images, links/
  bookmarks, and the remaining CSS box/paint model needed for real-world
  documents.

## License

MIT. See [LICENSE](LICENSE).
