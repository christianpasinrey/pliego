<?php

// tests/EndToEnd/PlaygroundSamplesSmokeTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * M10-T6 (README, playground, cierre): closes a real gap found while writing the milestone's
 * closing report -- index.php ships THREE playground samples ("Ejemplo" / "Ejemplo Bootstrap" /
 * "Ejemplo Tailwind"), but only the Tailwind one had a dedicated E2E smoke test
 * (TailwindPlaygroundSampleTest.php, M10-T4). This file closes the other two, so all three of the
 * playground's own render paths are proven "0-warnings-or-documented" by a real test, not by eye
 * in a browser: the default sample (hand-styled CSS, `Engine::make()`) must render with genuinely
 * ZERO warnings; the Bootstrap sample (`Engine::bootstrap()`, no author CSS at all) ingests the
 * ENTIRE real vendored Bootstrap 5.3.6 sheet against this page's small markup -- same shape as
 * BootstrapPageTest.php/BootstrapRealComponentsTest.php, just a THIRD real document exercising a
 * different mix of classes -- so its warnings are audited into the SAME categories those two files
 * already established (copied classifier, per this codebase's own "one process, unique-per-file
 * helpers" convention -- see either of their docblocks), with the same 'other'-must-be-empty
 * safety net (a complete partition, not a sample) rather than asserted as zero.
 *
 * index.php is a runnable script (CLI demo branch + web playground branch), not a library file
 * with an autoloadable class -- same reasoning as TailwindPlaygroundSampleTest.php's own docblock:
 * PLAYGROUND_DEFAULT_SAMPLE_HTML/_CSS and PLAYGROUND_BOOTSTRAP_SAMPLE_HTML below are separate,
 * self-contained copies of index.php's `$sampleHtml`/`$sampleCss`/`$bootstrapSampleHtml`, kept
 * content-identical BY HAND -- if you touch one, touch the other.
 *
 * Helper functions prefixed `playgroundSamples` (unique-per-file convention).
 */

const PLAYGROUND_DEFAULT_SAMPLE_HTML = <<<'HTML'
<body>
  <div class="header">pliego · playground</div>
  <h1>Cammino francese da Sarria</h1>
  <p class="meta">Prenotazione n. 136961 — Cliente: Livia</p>

  <div class="price">Prezzo a persona: 296,33 € <span class="badge-pill">-10%</span></div>
  <a class="btn">Prenota ora</a>

  <div class="band">Cosa portare</div>
  <ul class="packing-list">
    <li>Zaino da 30-40 litri</li>
    <li>Scarpe da trekking già rodate</li>
    <li>Borraccia e crema solare</li>
  </ul>

  <div class="band">Itinerario</div>

  <table class="summary">
    <thead>
      <tr><th class="dt-cell">Giorno</th><th class="dt-cell">Tappa</th><th class="dt-cell">Km</th></tr>
    </thead>
    <tbody>
      <tr><td class="dt-cell">1</td><td class="dt-cell">Sarria &rarr; Portomarín</td><td class="dt-cell">22,2</td></tr>
      <tr><td class="dt-cell">2</td><td class="dt-cell">Portomarín &rarr; Palas de Rei</td><td class="dt-cell">24,8</td></tr>
      <tr><td class="dt-cell">3</td><td class="dt-cell">Palas de Rei &rarr; Arzúa</td><td class="dt-cell">28,5</td></tr>
      <tr><td class="dt-cell">4</td><td class="dt-cell">Arzúa &rarr; Santiago</td><td class="dt-cell">39,3</td></tr>
    </tbody>
  </table>

  <div class="card photo-card">
    <img src="playground-assets/sample-photo.jpg" width="120">
    <div class="info">
      <p class="day"><strong>Sarria</strong> — <em>Giorno 1</em></p>
      <table class="data-table">
        <thead>
          <tr><th class="dt-cell">Data</th><th class="dt-cell">Km</th><th class="dt-cell">Pernottamento</th></tr>
        </thead>
        <tbody>
          <tr><td class="dt-cell">19/09/2026</td><td class="dt-cell">—</td><td class="dt-cell">Sarria</td></tr>
        </tbody>
      </table>
      <p>Una volta arrivato a Sarria, ti consigliamo di visitare la città e di goderti
      i suoi monumenti e le sue strade, dove si respira già l'atmosfera del Cammino.
      Puoi <strong>prenotare online</strong> o scriverci — <em>siamo qui per aiutarti</em>.</p>
    </div>
  </div>

  <div class="card">
    <p class="day"><strong>Da Sarria a Portomarín</strong> — Giorno 2</p>
    <table class="data-table">
      <thead>
        <tr><th class="dt-cell">Data</th><th class="dt-cell">Km</th><th class="dt-cell">Pernottamento</th></tr>
      </thead>
      <tbody>
        <tr><td class="dt-cell">20/09/2026</td><td class="dt-cell">22,2</td><td class="dt-cell">Portomarín</td></tr>
      </tbody>
    </table>
    <p>Il tuo itinerario inizierà con questo tratto che si snoda lungo sentieri
    tranquilli tra questi secolari e granai storici.</p>
  </div>
</body>
HTML;

const PLAYGROUND_DEFAULT_SAMPLE_CSS = <<<'CSS'
:root {
  --brand: #163a6b;
  --accent: #ffd500;
  --stripe: rgba(22, 58, 107, .06);
  --gap: 1rem;
}
body { font-size: 14px; color: #222222 }
.header {
  background: linear-gradient(to right, var(--brand), #2a5298);
  color: white;
  padding: calc(var(--gap) * .75);
  font-size: 16px;
}
h1 { font-size: 24px; margin: 16px 0 4px 0 }
.meta { color: #666666; margin: 0 0 12px 0 }
.price { background-color: var(--accent); padding: 14px; font-size: 20px; margin: 0 0 14px 0 }
.badge-pill {
  display: inline;
  border-radius: 999px;
  background-color: #163a6b;
  color: white;
  padding: 2px 10px;
  font-size: 12px;
  font-weight: bold;
}
.btn {
  display: inline-block;
  background-color: var(--brand);
  color: white;
  padding: calc(var(--gap) * .375) calc(var(--gap) * .75);
  border: 1px solid var(--brand);
  border-radius: 6px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, .35);
  margin: 0 0 14px 0;
}
.packing-list { margin: 0 0 14px 0 }
.band { background-color: var(--accent); padding: 10px; font-size: 18px; margin: 0 0 10px 0 }
.card {
  background-color: #f4f4f4;
  padding: 12px;
  margin: 0 0 10px 0;
  border-radius: 8px;
  box-shadow: 0 3px 8px rgba(0, 0, 0, .18);
}
.photo-card { display: flex; gap: 12px }
.info { flex: 1 }
.day { margin: 0 0 4px 0; font-size: 16px }
.data-table { border: 1px solid #999999; border-spacing: 2px; margin: 0 0 6px 0; font-size: 12px; color: #555555 }
.dt-cell { border: 1px solid #cccccc; padding: 3px 6px }
.summary { border: 1px solid #999999; border-spacing: 2px; margin: 0 0 14px 0; font-size: 12px; color: #555555; width: 100% }
.summary tbody tr:nth-child(odd) { background-color: var(--stripe) }
.price { text-align: right }
p { line-height: 1.45 }
@page {
  margin: 48px;
  @bottom-left { content: "tubuencamino.com"; }
  @bottom-right { content: "Pagina " counter(page) " de " counter(pages); }
}
CSS;

const PLAYGROUND_BOOTSTRAP_SAMPLE_HTML = <<<'HTML'
<body>
  <h1 class="display-6 mb-2">Bootstrap preset sample</h1>
  <p class="lead">No custom CSS anywhere in this sample — every look below comes from the vendored Bootstrap 5.3.6 preset via <code>Engine::bootstrap()</code>. Check "Bootstrap preset" to render it.</p>

  <div class="card mb-3">
    <div class="card-body">
      <h5 class="card-title">Invoice #1042</h5>
      <p class="card-text">Rendered entirely with Bootstrap's own classes, no author stylesheet.</p>
      <a href="#" class="btn btn-primary">Pay now</a>
      <a href="#" class="btn btn-outline-secondary">Details</a>
    </div>
  </div>

  <p>
    <span class="badge bg-primary">New</span>
    <span class="badge bg-success rounded-pill">Active</span>
    <span class="badge bg-danger">Overdue</span>
  </p>

  <table class="table table-striped">
    <thead>
      <tr><th>#</th><th>Item</th><th>Amount</th></tr>
    </thead>
    <tbody>
      <tr><td>1</td><td>Consulting</td><td>296,33 &euro;</td></tr>
      <tr><td>2</td><td>Support plan</td><td>120,00 &euro;</td></tr>
      <tr><td>3</td><td>Onboarding</td><td>80,00 &euro;</td></tr>
    </tbody>
  </table>

  <blockquote class="blockquote">
    <p>Rounded corners, real gradients and soft shadows, straight from the preset.</p>
    <footer class="blockquote-footer">pliego <cite title="Source Title">playground</cite></footer>
  </blockquote>
</body>
HTML;

const PLAYGROUND_BOOTSTRAP_SAMPLE_CSS = '';

/** @return array{0: string, 1: \Pliego\RenderReport} */
function playgroundSamplesRenderDefault(): array
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::make()->stylesheet(PLAYGROUND_DEFAULT_SAMPLE_CSS)->render(PLAYGROUND_DEFAULT_SAMPLE_HTML)->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

/** @return array{0: string, 1: \Pliego\RenderReport} */
function playgroundSamplesRenderBootstrap(): array
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::bootstrap()->stylesheet(PLAYGROUND_BOOTSTRAP_SAMPLE_CSS)->render(PLAYGROUND_BOOTSTRAP_SAMPLE_HTML)->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

// --- "Ejemplo" (default, Engine::make()) ---------------------------------------------------------

it('renders the playground\'s default sample as a valid single-page PDF', function () {
    [$pdf, $report] = playgroundSamplesRenderDefault();
    expect($pdf)->toStartWith('%PDF-1.7');
    expect($report->pageCount)->toBe(1);
});

it('produces GENUINELY ZERO warnings for the default sample (hand-styled CSS, every property used is fully supported)', function () {
    [, $report] = playgroundSamplesRenderDefault();
    expect($report->warnings)->toBe([]);
});

// --- "Ejemplo Bootstrap" (Engine::bootstrap(), no author CSS) -------------------------------------

/** Same classifier as BootstrapIngestionTest.php/BootstrapPageTest.php's own (copied, not shared,
 * per this codebase's convention) -- plus M10-T6's own new 'container-skipped' category (the
 * @container aggregated-warning fix this task added, see StylesheetParser::parse()), unused by
 * real Bootstrap 5.3.6 (it predates container queries) but kept here for parity with the other
 * two copies of this classifier so all three stay byte-for-byte the same shape. */
function playgroundSamplesCategorizeWarning(string $warning): string
{
    return match (true) {
        (bool) preg_match('/@media rule blocks skipped/', $warning) => 'media-skipped',
        (bool) preg_match('/@container rule blocks skipped/', $warning) => 'container-skipped',
        (bool) preg_match('/^Dynamic pseudo-class has no effect in paged media/', $warning) => 'pseudo-dynamic-permanent-exclusion',
        (bool) preg_match('/^Unknown pseudo-class:/', $warning) => 'pseudo-unknown',
        (bool) preg_match('/^Pseudo-class not supported yet:/', $warning) => 'pseudo-not-yet-supported',
        (bool) preg_match('/^:not\(\)/', $warning) => 'selector-not-argument-unsupported',
        (bool) preg_match('/^Invalid selector syntax:/', $warning) => 'invalid-selector',
        $warning === 'Unsupported property: transform' => 'unsupported-property-transform',
        (bool) preg_match('/^Unsupported property:/', $warning) => 'unsupported-property-other',
        (bool) preg_match('/^Unsupported keyword for/', $warning) => 'unsupported-keyword',
        (bool) preg_match('/^Unsupported length for/', $warning) => 'unsupported-length',
        (bool) preg_match('/^Unsupported color for/', $warning) => 'unsupported-color',
        (bool) preg_match('/^Invalid calc\(\) expression:/', $warning) => 'invalid-calc',
        (bool) preg_match('/box-shadow/i', $warning) => 'box-shadow-limitation',
        (bool) preg_match('/^Unsupported background/', $warning) => 'unsupported-background-value',
        (bool) preg_match('/^Unsupported border component/', $warning) => 'unsupported-border-component',
        (bool) preg_match('/^Unsupported shorthand for/', $warning) => 'unsupported-shorthand',
        (bool) preg_match('/^Unsupported text-decoration:/', $warning) => 'unsupported-text-decoration',
        (bool) preg_match('/^Unsupported overflow /', $warning) => 'overflow-approximated',
        (bool) preg_match('/^Unsupported font-weight:/', $warning) => 'unsupported-font-weight',
        (bool) preg_match('/^Unsupported vertical-align:/', $warning) => 'unsupported-vertical-align',
        (bool) preg_match('/^Unsupported text-align:/', $warning) => 'unsupported-text-align',
        (bool) preg_match('/^Unsupported line-height:/', $warning) => 'unsupported-line-height',
        (bool) preg_match('/^Unknown custom property:/', $warning) => 'unresolved-custom-property',
        (bool) preg_match('/^Invalid value for .+ \(unresolved var\(\)\):/', $warning) => 'invalid-value-unresolved-var',
        $warning === 'atomic fragment taller than page, kept unsplit' => 'atomic-fragment-oversized',
        default => 'other',
    };
}

/**
 * @param list<string> $warnings
 * @return array<string, int>
 */
function playgroundSamplesAuditWarnings(array $warnings): array
{
    $counts = [];
    foreach ($warnings as $warning) {
        $counts[playgroundSamplesCategorizeWarning($warning)] = ($counts[playgroundSamplesCategorizeWarning($warning)] ?? 0) + 1;
    }
    ksort($counts);
    return $counts;
}

it('renders the playground\'s Bootstrap sample as a valid single-page PDF', function () {
    [$pdf, $report] = playgroundSamplesRenderBootstrap();
    expect($pdf)->toStartWith('%PDF-1.7');
    expect($report->pageCount)->toBe(1);
});

/**
 * The Bootstrap sample has NO author CSS at all (see PLAYGROUND_BOOTSTRAP_SAMPLE_CSS above) --
 * Engine::bootstrap() alone means the ENTIRE real, unmodified, vendored bootstrap.min.css (5.3.6)
 * gets ingested against this small page, same as BootstrapPageTest.php/BootstrapRealComponentsTest.
 * php's own full-sheet ingestion. Pinned count (1093) observed from a real run, same "not guessed"
 * convention as those two files -- a regression here means either the vendored sheet or this
 * sample's markup changed. The load-bearing assertion is the SECOND one: every single warning
 * lands in a KNOWN, already-documented category (README's "Supported as of M9"/"Tailwind" audit
 * sections) -- an 'other' bucket appearing would mean some genuinely NEW, undocumented warning
 * shape snuck in, which is exactly what "0-warnings-or-documented" is meant to catch.
 */
it('produces ONLY already-documented warning categories for the Bootstrap sample (complete partition, no "other" bucket)', function () {
    [, $report] = playgroundSamplesRenderBootstrap();
    expect($report->warnings)->toHaveCount(1093);
    $counts = playgroundSamplesAuditWarnings($report->warnings);
    expect($counts)->not->toHaveKey('other');
    expect(array_sum($counts))->toBe(1093);
});

function playgroundSamplesFindGhostscriptBinary(): ?string
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

$gsBinary = playgroundSamplesFindGhostscriptBinary();

it('renders both the default and Bootstrap samples\' PDFs through Ghostscript without error (E2E render check)', function () use ($gsBinary) {
    if ($gsBinary === null) {
        return;
    }
    foreach (['default' => playgroundSamplesRenderDefault(), 'bootstrap' => playgroundSamplesRenderBootstrap()] as $label => [$pdf, $report]) {
        $pdfPath = sys_get_temp_dir() . "/pliego-playground-sample-$label-e2e.pdf";
        file_put_contents($pdfPath, $pdf);
        $renderedPage = sys_get_temp_dir() . "/pliego-playground-sample-$label-e2e-page.png";
        $cmd = sprintf(
            '%s -dNOPAUSE -dBATCH -dSAFER -sDEVICE=png16m -r72 -sOutputFile=%s %s 2>&1',
            escapeshellarg($gsBinary),
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
    }
})->skip($gsBinary === null, 'Ghostscript not found on PATH in this environment.');
