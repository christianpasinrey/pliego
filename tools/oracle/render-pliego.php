<?php

// tools/oracle/render-pliego.php
declare(strict_types=1);

/**
 * M9-T5: renders every tools/oracle/fixtures/*.html through the REAL Engine (CSS parse -> style
 * -> layout -> paint -> PDF, the exact same pipeline every other pliego document goes through),
 * then rasterizes the resulting PDF with Ghostscript at 192dpi -- 2x 96dpi, matching
 * render-chrome.mjs's deviceScaleFactor:2 screenshot, so both rasters land at the same effective
 * pixel density (see that file's own docblock).
 *
 * Each fixture carries its OWN `@page { margin: 0; size: A4 }` rule (see fixtures/*.html) -- so
 * no ->paper()/->margins() call is needed here: Engine's own PageRuleFactory (M2-T6) already
 * overrides the uniform-margin default per side from that rule, the identical mechanism
 * BootstrapPresetTest.php exercises for the vendored preset's print addendum. ->basePath() is set
 * to the fixture's own directory so the fixture's `@font-face { src: url(../../../resources/
 * fonts/...) }` resolves via the SAME relative-path convention render-chrome.mjs's file:// URL
 * navigation uses (Image\ImagePathResolver, M8-T6) -- both engines see one canonical path syntax.
 *
 * Ghostscript's binary name differs by platform (`gs` on Linux/macOS -- what the CI workflow's
 * `apt-get install ghostscript` provides -- vs `gswin64c`/`gswin32c` on Windows, this repo's own
 * local dev environment); resolveGhostscriptBinary() tries each in turn via a `--version` probe
 * rather than assuming one.
 *
 * Deliberately a thin, unshared script (not a PliegoOracle\* class like PixelDiff) -- it has no
 * unit-testable "logic" of its own beyond "shell out to gs", and its actual correctness (does the
 * PDF this produces visually match Chrome's rendering) is exactly what compare.php exists to
 * measure; wrapping shell_exec() in a class for its own sake here would be indirection, not
 * testability.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Pliego\Engine;
use PliegoOracle\FixtureHtml;

const ORACLE_RASTER_DPI = 192; // 2x 96dpi, matching render-chrome.mjs's deviceScaleFactor: 2

function oracleResolveGhostscriptBinary(): string
{
    foreach (['gs', 'gswin64c', 'gswin32c'] as $candidate) {
        $probe = shell_exec(sprintf('%s --version 2>&1', escapeshellarg($candidate)));
        if ($probe !== null && trim($probe) !== '' && preg_match('/^\d+\.\d+/', trim($probe)) === 1) {
            return $candidate;
        }
    }
    throw new RuntimeException(
        'render-pliego: no working Ghostscript binary found (tried gs, gswin64c, gswin32c). '
        . 'Install Ghostscript and ensure it is on PATH.',
    );
}

/** @return list<string> absolute paths to tools/oracle/fixtures/*.html, sorted */
function oracleFixturePaths(): array
{
    $fixtures = glob(__DIR__ . '/fixtures/*.html');
    if ($fixtures === false) {
        throw new RuntimeException('render-pliego: glob() failed while listing fixtures.');
    }
    sort($fixtures);
    return $fixtures;
}

function oracleFixtureNumber(string $filename): string
{
    if (preg_match('/^(\d+)-/', $filename, $m) !== 1) {
        throw new RuntimeException("render-pliego: fixture filename does not start with a numeric prefix: $filename");
    }
    return $m[1];
}

function main(): void
{
    $outDir = __DIR__ . '/out';
    if (!is_dir($outDir) && !mkdir($outDir, recursive: true) && !is_dir($outDir)) {
        throw new RuntimeException("render-pliego: could not create output directory: $outDir");
    }

    $ghostscript = oracleResolveGhostscriptBinary();
    $fixturesDir = __DIR__ . '/fixtures';

    foreach (oracleFixturePaths() as $fixturePath) {
        $filename = basename($fixturePath);
        $number = oracleFixtureNumber($filename);
        $html = (string) file_get_contents($fixturePath);
        // FixtureHtml::extractInlineCss(): the fixture's own <style> block, the SAME text Chrome
        // parses natively from file:// -- Engine::render() has no auto-extraction of inline
        // <style> tags (see FixtureHtml's docblock for why this is required, not optional).
        $css = FixtureHtml::extractInlineCss($html);

        $pdfPath = $outDir . "/$number-pliego.pdf";
        $report = Engine::make()->basePath($fixturesDir)->stylesheet($css)->render($html)->save($pdfPath);

        if ($report->pageCount !== 1) {
            fwrite(
                STDERR,
                "render-pliego: WARNING: $filename rendered to {$report->pageCount} page(s), expected 1 "
                . "-- the oracle only compares page 1 against Chrome's single full-page screenshot.\n",
            );
        }
        if ($report->warnings !== []) {
            fwrite(STDERR, "render-pliego: $filename warnings: " . implode('; ', $report->warnings) . "\n");
        }

        // A literal filename (no `%d` page-number placeholder): Ghostscript only needs one when
        // a run could emit MULTIPLE files, which -dFirstPage=1 -dLastPage=1 rules out here (every
        // fixture is guarded to exactly 1 page by tests/EndToEnd/OracleFixturesSmokeTest.php).
        // `%d` was tried first and dropped: PHP's exec() shells out via cmd.exe on Windows, which
        // treats a lone `%d` on the command line as an (undefined, silently-blanked) batch
        // parameter reference -- `-o ...-%d.png` landed on disk as `...- d.png`, not
        // `...-1.png`, even though the exact same command line works verbatim in bash/CI. A fixed
        // filename sidesteps that platform-specific `%` handling entirely.
        $pngPath = $outDir . "/$number-pliego-1.png";
        $cmd = sprintf(
            '%s -q -dNOPAUSE -dBATCH -sDEVICE=png16m -r%d -dFirstPage=1 -dLastPage=1 -o %s %s 2>&1',
            escapeshellarg($ghostscript),
            ORACLE_RASTER_DPI,
            escapeshellarg($pngPath),
            escapeshellarg($pdfPath),
        );
        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException("render-pliego: Ghostscript failed for $filename (exit $exitCode): " . implode("\n", $output));
        }

        echo "render-pliego: $filename -> " . basename($pdfPath) . ' + ' . basename($pngPath) . "\n";
    }
}

main();
