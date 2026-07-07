<?php

// tests/EndToEnd/TailwindPlaygroundSampleTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * M10-T4: two things this file proves, together.
 *
 * (1) The README's "Tailwind" section documents a bring-your-own-build workflow ("generate the
 * CSS locally with the standalone CLI, pass it to ->stylesheet()") instead of a preset API (the
 * adjudication -- see .superpowers/sdd/m10-task-4-report.md). TailwindRealComponentsTest.php
 * (M10-T3) already smoke-tests that workflow against the FULL real vendored build; this file
 * doesn't repeat that, it smoke-tests the SECOND artifact this task adds: the playground's new
 * "Ejemplo Tailwind" sample (index.php's $tailwindSampleHtml/$tailwindSampleCss).
 *
 * (2) index.php is a runnable script (CLI demo branch + web playground branch), not a library
 * file with an autoloadable class -- there's no clean way to `require` it and pull out two local
 * variables without executing its top-level side effects (headers, $_POST handling). Every other
 * playground-adjacent E2E test in this codebase (BootstrapPresetTest.php etc.) follows the same
 * convention instead: a SEPARATE, self-contained copy of the sample content, not a reference into
 * index.php. TAILWIND_PLAYGROUND_SAMPLE_HTML/_CSS below are kept content-identical (same
 * selectors/declarations; index.php's copy additionally carries an explanatory comment block this
 * one omits) to index.php's $tailwindSampleHtml/$tailwindSampleCss BY HAND -- if you touch one,
 * touch the other.
 *
 * ONE real, pre-existing engine gap the sample deliberately AVOIDS demoing broken (documented in
 * both the README and index.php's own comment): the all-sides `border-width`/`border-style`/
 * `border-color` shorthand (only the 4-part per-side longhands are supported) -- this test's
 * warning assertion below is the guardrail that catches it if that gap is ever reintroduced by
 * accident. (A SECOND gap used to live here too -- a bare-number `calc()` for `line-height` --
 * but that was FIXED by M10-T5's `CalcParser::parseNumberOrLength()`, see the README's "Tailwind"
 * section; this sample's --text-* vars just were never revisited to add the paired
 * --text-*--line-height companion back in, not because it would fail now.)
 */

const TAILWIND_PLAYGROUND_SAMPLE_HTML = <<<'HTML'
<body>
  <div class="p-6 mb-3 rounded-lg bg-slate-50 shadow-md">
    <p class="mb-2 text-sm font-bold tracking-wide text-slate-700">TAILWIND SAMPLE</p>
    <h1 class="mb-3 text-2xl font-bold text-slate-900">Invoice #1042</h1>
    <p class="text-base text-slate-700 leading-normal">Bring your own Tailwind build: this CSS is a slim, hand-curated slice of a real npx @tailwindcss/cli output, pasted straight into stylesheet(). No CLI runs inside pliego -- see the README's "Tailwind" section for the full workflow and its honest gaps. Variant classes (hover:/sm:/odd: etc.) don't apply (CSS nesting isn't supported); fractions like w-1/2 fail similarly (same escaped-character parsing gap); grid is unsupported; the all-sides border shorthand this sample deliberately avoids is unsupported too.</p>
    <div class="flex items-center justify-between gap-4 mb-2">
      <span class="p-4 rounded-md bg-blue-500 text-white font-bold text-sm">Pay now</span>
      <span class="p-4 rounded-md bg-slate-100 text-slate-700 text-sm">Details</span>
    </div>
  </div>

  <table class="w-full">
    <thead>
      <tr class="bg-slate-100">
        <th class="p-2 text-left text-sm font-bold text-slate-900">#</th>
        <th class="p-2 text-left text-sm font-bold text-slate-900">Item</th>
        <th class="p-2 text-left text-sm font-bold text-slate-900">Amount</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td class="p-2 text-sm text-slate-700">1</td>
        <td class="p-2 text-sm text-slate-700">Consulting</td>
        <td class="p-2 text-sm text-slate-700">296,33 &euro;</td>
      </tr>
      <tr class="bg-slate-50">
        <td class="p-2 text-sm text-slate-700">2</td>
        <td class="p-2 text-sm text-slate-700">Support plan</td>
        <td class="p-2 text-sm text-slate-700">120,00 &euro;</td>
      </tr>
    </tbody>
  </table>
</body>
HTML;

const TAILWIND_PLAYGROUND_SAMPLE_CSS = <<<'CSS'
@layer theme, utilities;
@layer theme {
  :root {
    --color-blue-500: oklch(62.3% 0.214 259.815);
    --color-slate-50: oklch(98.4% 0.003 247.858);
    --color-slate-100: oklch(96.8% 0.007 247.896);
    --color-slate-700: oklch(37.2% 0.044 257.287);
    --color-slate-900: oklch(20.8% 0.042 265.755);
    --color-white: #fff;
    --spacing: 0.25rem;
    --text-sm: 0.875rem;
    --text-base: 1rem;
    --text-2xl: 1.5rem;
    --font-weight-bold: 700;
    --tracking-wide: 0.025em;
    --leading-normal: 1.5;
    --radius-md: 0.375rem;
    --radius-lg: 0.5rem;
  }
}
@supports ((-webkit-hyphens: none) and (not (margin-trim: inline))) or ((-moz-orient: inline) and (not (color: rgb(from red r g b)))) {
  * {
    --tw-shadow: 0 0 #0000;
    --tw-shadow-color: initial;
    --tw-inset-shadow: 0 0 #0000;
    --tw-inset-ring-shadow: 0 0 #0000;
    --tw-ring-shadow: 0 0 #0000;
    --tw-ring-offset-shadow: 0 0 #0000;
  }
}
@layer utilities {
  .mb-2 { margin-bottom: calc(var(--spacing) * 2); }
  .mb-3 { margin-bottom: calc(var(--spacing) * 3); }
  .flex { display: flex; }
  .items-center { align-items: center; }
  .justify-between { justify-content: space-between; }
  .gap-4 { gap: calc(var(--spacing) * 4); }
  .w-full { width: 100%; }
  .rounded-md { border-radius: var(--radius-md); }
  .rounded-lg { border-radius: var(--radius-lg); }
  .bg-blue-500 { background-color: var(--color-blue-500); }
  .bg-slate-50 { background-color: var(--color-slate-50); }
  .bg-slate-100 { background-color: var(--color-slate-100); }
  .p-2 { padding: calc(var(--spacing) * 2); }
  .p-4 { padding: calc(var(--spacing) * 4); }
  .p-6 { padding: calc(var(--spacing) * 6); }
  .text-sm { font-size: var(--text-sm); }
  .text-base { font-size: var(--text-base); }
  .text-2xl { font-size: var(--text-2xl); }
  .text-left { text-align: left; }
  .font-bold { --tw-font-weight: var(--font-weight-bold); font-weight: var(--font-weight-bold); }
  .tracking-wide { --tw-tracking: var(--tracking-wide); letter-spacing: var(--tracking-wide); }
  .leading-normal { --tw-leading: var(--leading-normal); line-height: var(--leading-normal); }
  .text-white { color: var(--color-white); }
  .text-slate-700 { color: var(--color-slate-700); }
  .text-slate-900 { color: var(--color-slate-900); }
  .shadow-md {
    --tw-shadow: 0 4px 6px -1px var(--tw-shadow-color, rgb(0 0 0 / 0.1)), 0 2px 4px -2px var(--tw-shadow-color, rgb(0 0 0 / 0.1));
    box-shadow: var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow);
  }
}
CSS;

/** @return array{0: string, 1: \Pliego\RenderReport} */
function tailwindPlaygroundSampleRender(): array
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::make()->stylesheet(TAILWIND_PLAYGROUND_SAMPLE_CSS)->render(TAILWIND_PLAYGROUND_SAMPLE_HTML)->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

it('renders the playground\'s Tailwind sample as a single-page, valid PDF', function () {
    [$pdf, $report] = tailwindPlaygroundSampleRender();

    expect($pdf)->toStartWith('%PDF-1.7');
    expect($report->pageCount)->toBe(1);
});

/**
 * The ONLY warning this curated sample should produce is the well-known, pre-existing "multiple
 * box-shadow layers" limitation (Tailwind's real .shadow-md chains 5 var()-based layers, this
 * engine paints only the first) -- if the all-sides border shorthand gap documented in index.php's
 * own comment were reintroduced by editing the sample without re-checking against the engine, THIS
 * assertion is what catches it. (The sample's other gap, bare-number calc() for line-height, was
 * FIXED by M10-T5 -- see this file's class docblock and index.php's own comment.)
 */
it('produces exactly the one documented warning (multiple box-shadow layers), nothing else', function () {
    [, $report] = tailwindPlaygroundSampleRender();

    expect($report->warnings)->toHaveCount(1);
    expect($report->warnings[0])->toContain('Multiple box-shadows not supported');
});

/**
 * Same verified oklch()->sRGB conversion as TailwindRealComponentsTest.php (M10-T3): bg-blue-500
 * resolves through var(--color-blue-500) to oklch(62.3% .214 259.815) -> #2b7fff, hand-verified
 * against the css-color-4 matrices and the independent `culori` npm library -- NOT the old
 * Tailwind v3 hex (#3b82f6) for this same class name.
 */
it('paints bg-blue-500 (the "Pay now" button) with its real oklch()-converted color (#2b7fff)', function () {
    [$pdf, ] = tailwindPlaygroundSampleRender();
    $blue500Fill = sprintf('%.3F %.3F %.3F rg', 0x2b / 255, 0x7f / 255, 0xff / 255);
    expect($pdf)->toContain($blue500Fill);
});

it('paints rounded corners (border-radius) as real bezier curve operators', function () {
    [$pdf, ] = tailwindPlaygroundSampleRender();
    expect(preg_match_all('/^[\d.\s-]+ c$/m', $pdf))->toBeGreaterThan(0);
});

function tailwindPlaygroundSampleFindGhostscriptBinary(): ?string
{
    foreach (['gswin64c', 'gswin32c', 'gs'] as $candidate) {
        $which = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'where' : 'which';
        $output = [];
        $exitCode = 0;
        @exec("$which $candidate 2>NUL", $output, $exitCode);
        if ($exitCode === 0 && $output !== []) {
            return trim($output[0]);
        }
    }
    return null;
}

$gsBinary = tailwindPlaygroundSampleFindGhostscriptBinary();

it('renders a PDF Ghostscript can rasterize without error (E2E render check)', function () use ($gsBinary) {
    if ($gsBinary === null) {
        return;
    }
    $gs = $gsBinary;
    [$pdf, ] = tailwindPlaygroundSampleRender();

    $pdfPath = sys_get_temp_dir() . '/pliego-tailwind-playground-sample-e2e.pdf';
    file_put_contents($pdfPath, $pdf);

    $renderedPage = sys_get_temp_dir() . '/pliego-tailwind-playground-sample-e2e-page.png';
    $cmd = sprintf(
        '%s -dNOPAUSE -dBATCH -dSAFER -sDEVICE=png16m -r72 -sOutputFile=%s %s 2>&1',
        escapeshellarg($gs),
        escapeshellarg($renderedPage),
        escapeshellarg($pdfPath),
    );
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    expect($exitCode)->toBe(0);
    expect(file_exists($renderedPage))->toBeTrue();
    expect(filesize($renderedPage))->toBeGreaterThan(0);

    @unlink($renderedPage);
    @unlink($pdfPath);
})->skip($gsBinary === null, 'Ghostscript not found on PATH in this environment.');
