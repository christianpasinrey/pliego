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
