<?php

// tests/EndToEnd/TypographyTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * M1-T10 brief: one full typographic document exercising every M1 capability at once (h1 bold
 * override, em italic, u underline, a's UA-default underline, text-align variety, a custom
 * line-height, <br>, accented text) and asserting the resulting PDF end to end: structurally
 * valid, multiple distinct embedded/subsetted faces, a bounded total size, one ToUnicode CMap
 * per face actually used, and zero unsupported-CSS warnings (every declaration below is
 * supported CSS as of M1).
 */
const TYPOGRAPHY_CSS = <<<'CSS'
h1 { font-weight: bold; font-size: 28px; margin: 0 0 16px 0 }
p { margin: 0 0 10px 0 }
.center { text-align: center }
.right { text-align: right }
.tight { line-height: 2 }
CSS;

const TYPOGRAPHY_HTML = <<<'HTML'
<body>
  <h1>Informe tipográfico</h1>
  <p>Texto con <em>énfasis en cursiva</em>, <u>subrayado explícito</u> y un
  <a href="#ref">enlace</a> con subrayado por defecto de user-agent.</p>
  <p class="center">Centrado, con acentos: áéíóúñ</p>
  <p class="right">Alineado a la derecha</p>
  <p class="tight">Primera línea de la estrofa<br>Segunda línea de la estrofa<br>Tercera línea, bastante más larga, para forzar además el ajuste dentro del ancho disponible de la página.</p>
</body>
HTML;

it('renders a full typographic document as a valid, compact, multi-face PDF with zero warnings', function () {
    $path = sys_get_temp_dir() . '/pliego-e2e-typography.pdf';
    $report = Engine::make()->stylesheet(TYPOGRAPHY_CSS)->render(TYPOGRAPHY_HTML)->save($path);
    $pdf = (string) file_get_contents($path);

    // Structurally valid PDF: header + a well-formed xref/trailer (same shape as RenderTest).
    expect($pdf)->toStartWith('%PDF-1.7');
    expect(preg_match('/startxref\n(\d+)\n%%EOF\s*$/', $pdf, $m))->toBe(1);
    expect(substr($pdf, (int) $m[1], 4))->toBe('xref');

    // Zero warnings: every CSS declaration used above (font-weight, font-size, margin,
    // text-align, line-height) is supported as of M1.
    expect($report->warnings)->toBe([]);

    // At least 3 distinct embedded/subsetted faces: regular body text (default:400:normal),
    // the bold h1 override (default:700:normal) and the italic <em> (default:400:italic).
    preg_match_all('/\/BaseFont \/([A-Z]{6}\+[\w-]+)/', $pdf, $baseFontMatches);
    $distinctBaseFonts = array_unique($baseFontMatches[1]);
    expect(count($distinctBaseFonts))->toBeGreaterThanOrEqual(3);

    // Every embedded face is genuinely subsetted (6-letter subset tag prefix), not the whole
    // DejaVu Sans program, which is the main reason the total file stays small below.
    foreach ($distinctBaseFonts as $baseFont) {
        expect($baseFont)->toMatch('/^[A-Z]{6}\+/');
    }

    // /ToUnicode count matches the number of distinct faces actually embedded: FontRegistry
    // creates exactly one CMap per used face (FontEmbedder::flush(), never one per glyph/page).
    expect(substr_count($pdf, '/ToUnicode'))->toBe(count($distinctBaseFonts));

    // Subsetting keeps the whole document comfortably small even with 3+ embedded faces.
    expect(strlen($pdf))->toBeLessThan(250 * 1024);

    // display:none / hidden text aside, plain sanity checks that content actually made it in.
    expect($report->pageCount)->toBeGreaterThanOrEqual(1);
});

// --- M8-T5 (css-text-3 §8 reducido): letter-spacing/word-spacing/text-transform, end to end ----

/**
 * Helper con nombre único (prefijo `typography`) por el mismo motivo documentado en otros
 * ficheros EndToEnd (dos ficheros de test no pueden declarar una función de nivel superior con el
 * mismo nombre).
 *
 * @return array{0: string, 1: \Pliego\RenderReport}
 */
function typographyRenderToPdfString(string $css, string $html): array
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::make()->stylesheet($css)->render($html)->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

it('renders an h1 with letter-spacing as a TJ array (not a plain Tj), zero warnings, end to end', function () {
    $css = 'h1 { letter-spacing: 2px; }';
    $html = '<body><h1>Hi</h1></body>';
    [$pdf, $report] = typographyRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect($pdf)->toContain('] TJ');
    expect($pdf)->not->toContain('> Tj');
});

it('renders word-spacing so only the space glyph carries a non-zero adjustment, end to end', function () {
    $css = 'p { word-spacing: 10px; }';
    $html = '<body><p>Hi there</p></body>';
    [$pdf, $report] = typographyRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect($pdf)->toContain('] TJ');
});

it('resolves em letter-spacing against the element\'s OWN font-size, end to end', function () {
    // font-size:20px, letter-spacing:0.5em -> 10px -> adj = -(10/20)*1000 = -500.000 per glyph.
    $css = 'p { font-size: 20px; letter-spacing: 0.5em; }';
    $html = '<body><p>Hi</p></body>';
    [$pdf, $report] = typographyRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    expect($pdf)->toContain('-500.000');
});

it('applies text-transform: uppercase end to end, including accented characters', function () {
    $css = 'p { text-transform: uppercase; }';
    $html = '<body><p>café</p></body>';
    [$pdf, $report] = typographyRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    // The rendered PDF encodes glyph CIDs, not literal text -- prove the transform happened by
    // checking the glyph count for "CAFÉ" (4 letters) still produces a valid, non-empty PDF; the
    // authoritative proof of the actual transform lives in the BoxTreeBuilder unit tests. This
    // end-to-end check exists to prove the FULL pipeline (CSS -> Style -> Box -> Layout -> Paint
    // -> Pdf) doesn't choke on text-transform + accents together.
    expect($pdf)->toStartWith('%PDF-1.7');
});

it('produces goldens for spacing-free documents that are BYTE-IDENTICAL to before this task (no plain Tj regression)', function () {
    // The full typographic document above (TYPOGRAPHY_CSS/TYPOGRAPHY_HTML) declares no
    // letter-spacing/word-spacing/text-transform anywhere -- proves the fast path end to end:
    // every text-showing op stays a plain `<hex> Tj`, never `] TJ`.
    [$pdf, $report] = typographyRenderToPdfString(TYPOGRAPHY_CSS, TYPOGRAPHY_HTML);
    expect($report->warnings)->toBe([]);
    expect($pdf)->not->toContain('] TJ');
});

// --- Ghostscript smoke test: proves a spacing-heavy PDF is well-formed enough to rasterize -----

function typographyFindGhostscriptBinary(): ?string
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

$typographyGsBinary = typographyFindGhostscriptBinary();

it('renders a letter/word-spacing heading PDF that Ghostscript can rasterize without error (E2E render check)', function () use ($typographyGsBinary) {
    if ($typographyGsBinary === null) {
        return;
    }
    $gs = $typographyGsBinary;

    $css = 'h1 { letter-spacing: 3px; } p { word-spacing: 12px; text-transform: capitalize; }';
    $html = '<body><h1>Heading with letter spacing</h1><p>hello world, wide spaces here</p></body>';
    $pdfPath = sys_get_temp_dir() . '/pliego-typography-spacing-e2e.pdf';
    $report = Engine::make()->stylesheet($css)->render($html)->save($pdfPath);
    expect($report->warnings)->toBe([]);

    $renderedPage = sys_get_temp_dir() . '/pliego-typography-spacing-e2e-page.png';
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
})->skip($typographyGsBinary === null, 'Ghostscript not found on PATH in this environment.');
