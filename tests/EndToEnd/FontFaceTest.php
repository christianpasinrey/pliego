<?php

// tests/EndToEnd/FontFaceTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * M8-T7 (css-fonts-4 §4 reducido): @font-face end to end -- StylesheetParser produces
 * Css\Value\FontFaceRule VOs (unit-tested in StylesheetParserTest.php), Engine registers each
 * into Text\FontCatalog (basePath-relative src, same Image\ImagePathResolver convention as
 * <img src>/background-image, M8-T6). All fixtures here reuse resources/fonts/DejaVuSerif*.ttf
 * (already shipped for the 'serif' generic family, M7-T2) instead of adding new binary fixtures.
 *
 * @return array{0: string, 1: \Pliego\RenderReport}
 */
function fontFaceRenderToPdfString(string $css, string $html, ?string $basePath = null): array
{
    $engine = Engine::make()->stylesheet($css)->basePath($basePath ?? __DIR__ . '/../../resources/fonts');
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = $engine->render($html)->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

/** @return list<string> distinct /BaseFont subset names (post `TAG+`) found in $pdf */
function fontFaceDistinctBaseFonts(string $pdf): array
{
    preg_match_all('/\/BaseFont \/[A-Z]{6}\+([\w-]+)/', $pdf, $m);
    return array_values(array_unique($m[1]));
}

it('embeds a custom @font-face family (MiSerif -> DejaVuSerif.ttf) used via font-family, subsetted', function () {
    $css = "@font-face { font-family: 'MiSerif'; src: url('DejaVuSerif.ttf') }\n"
        . "p { font-family: 'MiSerif'; }";
    [$pdf, $report] = fontFaceRenderToPdfString($css, '<body><p>Texto en MiSerif</p></body>');

    expect($report->warnings)->toBe([]);
    expect($pdf)->toStartWith('%PDF-1.7');
    $baseFonts = fontFaceDistinctBaseFonts($pdf);
    expect($baseFonts)->toContain('DejaVuSerif');
    // Genuinely subsetted (6-letter tag prefix), not the whole font program.
    expect($pdf)->toMatch('/\/BaseFont \/[A-Z]{6}\+DejaVuSerif\b/');
});

it('lands weight/style variants in the right catalog slots across 4 @font-face rules for one family', function () {
    $css = <<<'CSS'
        @font-face { font-family: 'MiSerif'; src: url('DejaVuSerif.ttf') }
        @font-face { font-family: 'MiSerif'; src: url('DejaVuSerif-Bold.ttf'); font-weight: bold }
        @font-face { font-family: 'MiSerif'; src: url('DejaVuSerif-Italic.ttf'); font-style: italic }
        @font-face { font-family: 'MiSerif'; src: url('DejaVuSerif-BoldItalic.ttf'); font-weight: bold; font-style: italic }
        p { font-family: 'MiSerif'; }
        CSS;
    $html = '<body><p>Regular <b>Bold</b> <i>Italic</i> <b><i>BoldItalic</i></b></p></body>';
    [$pdf, $report] = fontFaceRenderToPdfString($css, $html);

    expect($report->warnings)->toBe([]);
    $baseFonts = fontFaceDistinctBaseFonts($pdf);
    expect($baseFonts)->toContain('DejaVuSerif');
    expect($baseFonts)->toContain('DejaVuSerif-Bold');
    expect($baseFonts)->toContain('DejaVuSerif-Italic');
    expect($baseFonts)->toContain('DejaVuSerif-BoldItalic');
    expect(count($baseFonts))->toBe(4);
});

it('warns and falls back to the default family when the @font-face src file does not exist', function () {
    $css = "@font-face { font-family: 'Ghost'; src: url('does-not-exist.ttf') }\n"
        . "p { font-family: 'Ghost'; }";
    [$pdf, $report] = fontFaceRenderToPdfString($css, '<body><p>Texto</p></body>');

    expect($report->warnings)->not->toBeEmpty();
    // The text still renders -- fallback to 'default' (DejaVuSans), never a fatal error.
    expect($pdf)->toStartWith('%PDF-1.7');
    $baseFonts = fontFaceDistinctBaseFonts($pdf);
    expect($baseFonts)->toContain('DejaVuSans');
    expect($baseFonts)->not->toContain('Ghost');
});

it('warns and falls back to the default family when the @font-face src file is unparseable', function () {
    $garbagePath = sys_get_temp_dir() . '/pliego-fontface-garbage.ttf';
    file_put_contents($garbagePath, 'this is not a real font file');
    try {
        $css = "@font-face { font-family: 'Garbage'; src: url('" . basename($garbagePath) . "') }\n"
            . "p { font-family: 'Garbage'; }";
        [$pdf, $report] = fontFaceRenderToPdfString($css, '<body><p>Texto</p></body>', sys_get_temp_dir());

        expect($report->warnings)->not->toBeEmpty();
        expect($pdf)->toStartWith('%PDF-1.7');
        expect(fontFaceDistinctBaseFonts($pdf))->toContain('DejaVuSans');
    } finally {
        unlink($garbagePath);
    }
});

it('skips a woff src with a warning and falls back to the ttf src in the same rule', function () {
    $css = "@font-face { font-family: 'MiSerif'; src: url('nonexistent.woff') format('woff'), url('DejaVuSerif.ttf') format('truetype') }\n"
        . "p { font-family: 'MiSerif'; }";
    [$pdf, $report] = fontFaceRenderToPdfString($css, '<body><p>Texto</p></body>');

    expect($report->warnings)->not->toBeEmpty();
    expect(fontFaceDistinctBaseFonts($pdf))->toContain('DejaVuSerif');
});

it('skips a remote src with a warning; family falls back to default when it is the only src', function () {
    $css = "@font-face { font-family: 'Remote'; src: url('https://example.com/font.ttf') }\n"
        . "p { font-family: 'Remote'; }";
    [$pdf, $report] = fontFaceRenderToPdfString($css, '<body><p>Texto</p></body>');

    expect($report->warnings)->not->toBeEmpty();
    expect(fontFaceDistinctBaseFonts($pdf))->toContain('DejaVuSans');
});

it('collapses a font-weight range to its first value (parse warning), then maps it to the nearest supported slot (Engine warning)', function () {
    // "100 900" -> first value 100 (parser warning) -> nearest of 400/700 is 400 (Engine
    // warning) -> ends up in the SAME slot as a plain `font-weight: normal` face would.
    $css = "@font-face { font-family: 'MiSerif'; src: url('DejaVuSerif.ttf'); font-weight: 100 900 }\n"
        . "p { font-family: 'MiSerif'; }";
    [$pdf, $report] = fontFaceRenderToPdfString($css, '<body><p>Texto</p></body>');

    expect(count($report->warnings))->toBeGreaterThanOrEqual(2);
    expect(fontFaceDistinctBaseFonts($pdf))->toContain('DejaVuSerif');
});

it('maps an unsupported numeric font-weight to the nearest of 400/700, with a warning (500->400, 600->700)', function () {
    $css = <<<'CSS'
        @font-face { font-family: 'A'; src: url('DejaVuSerif.ttf'); font-weight: 500 }
        @font-face { font-family: 'A'; src: url('DejaVuSerif-Bold.ttf'); font-weight: 600 }
        p { font-family: 'A'; }
        b { font-family: 'A'; font-weight: bold; }
        CSS;
    [$pdf, $report] = fontFaceRenderToPdfString($css, '<body><p>Regular <b>Bold</b></p></body>');

    expect($report->warnings)->not->toBeEmpty();
    $baseFonts = fontFaceDistinctBaseFonts($pdf);
    expect($baseFonts)->toContain('DejaVuSerif');
    expect($baseFonts)->toContain('DejaVuSerif-Bold');
});

// --- Ghostscript smoke test: proves an @font-face-heavy PDF is well-formed enough to rasterize --

function fontFaceFindGhostscriptBinary(): ?string
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

$fontFaceGsBinary = fontFaceFindGhostscriptBinary();

it('renders an @font-face document that Ghostscript can rasterize without error (E2E render check)', function () use ($fontFaceGsBinary) {
    if ($fontFaceGsBinary === null) {
        return;
    }
    $gs = $fontFaceGsBinary;

    $css = "@font-face { font-family: 'MiSerif'; src: url('DejaVuSerif.ttf') }\n"
        . "@font-face { font-family: 'MiSerif'; src: url('DejaVuSerif-Bold.ttf'); font-weight: bold }\n"
        . "p { font-family: 'MiSerif'; } b { font-family: 'MiSerif'; font-weight: bold; }";
    $html = '<body><p>Custom @font-face <b>bold</b> paragraph.</p></body>';
    $pdfPath = sys_get_temp_dir() . '/pliego-fontface-e2e.pdf';
    $report = Engine::make()->stylesheet($css)->basePath(__DIR__ . '/../../resources/fonts')->render($html)->save($pdfPath);
    expect($report->warnings)->toBe([]);

    $renderedPage = sys_get_temp_dir() . '/pliego-fontface-e2e-page.png';
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
})->skip($fontFaceGsBinary === null, 'Ghostscript not found on PATH in this environment.');
