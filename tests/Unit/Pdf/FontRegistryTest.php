<?php

// tests/Unit/Pdf/FontRegistryTest.php
declare(strict_types=1);

use Pliego\Pdf\FontRegistry;
use Pliego\Pdf\PdfWriter;
use Pliego\Text\FontCatalog;

/** Escribe un documento de una página usando FontRegistry, en el orden real del Engine:
 *  resolver caras -> addPage(pageResources()) -> flushAll(). */
function renderRegistryPdf(FontCatalog $catalog, callable $draw): string
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $writer = new PdfWriter($stream);
    $writer->begin();
    $registry = new FontRegistry($writer, $catalog);
    $draw($registry);
    $writer->addPage(595.0, 842.0, '', $registry->pageResources());
    $registry->flushAll();
    $writer->finish();
    rewind($stream);
    return (string) stream_get_contents($stream);
}

it('emits a ToUnicode CMap with utf16be mappings', function (): void {
    $catalog = FontCatalog::withDefaults();
    $pdf = renderRegistryPdf($catalog, function (FontRegistry $registry): void {
        $registry->embedderFor('default:400:normal')->encode('ñ');
    });

    $gid = $catalog->faceByKey('default:400:normal')->font->glyphId(0xF1);
    $gidHex = sprintf('%04X', $gid);

    expect($pdf)->toContain('/ToUnicode')
        ->toContain('begincmap')->toContain('beginbfchar')->toContain('endbfchar')
        ->toContain("<$gidHex> <00F1>");
});

it('chunks the ToUnicode CMap into beginbfchar blocks of at most 100 entries', function (): void {
    // ISO 32000-1 §9.10.3 / CMap spec: cada bloque begin/endbfchar admite como mucho 100
    // pares — un documento con >100 glifos distintos usados debe partir el CMap en varios
    // bloques en vez de emitir uno solo con un header N > 100.
    $catalog = FontCatalog::withDefaults();
    $pdf = renderRegistryPdf($catalog, function (FontRegistry $registry): void {
        $embedder = $registry->embedderFor('default:400:normal');
        $text = '';
        for ($cp = 0x21; $cp <= 0x7E; $cp++) { // 94 codepoints ASCII imprimibles
            $text .= mb_chr($cp, 'UTF-8');
        }
        for ($cp = 0xA1; $cp <= 0xB0; $cp++) { // +16 Latin-1 Supplement -> 110 glifos distintos
            $text .= mb_chr($cp, 'UTF-8');
        }
        $embedder->encode($text);
    });

    preg_match_all('/(\d+) beginbfchar/', $pdf, $matches);
    $blockSizes = array_map('intval', $matches[1]);

    expect($blockSizes)->toHaveCount(2); // 110 entradas -> bloques de 100 + 10
    foreach ($blockSizes as $size) {
        expect($size)->toBeLessThanOrEqual(100);
    }
    expect(substr_count($pdf, 'beginbfchar'))->toBe(count($blockSizes));
    expect(substr_count($pdf, 'endbfchar'))->toBe(count($blockSizes));
});

it('embeds one font object set per used face', function (): void {
    $catalog = FontCatalog::withDefaults();
    $pdf = renderRegistryPdf($catalog, function (FontRegistry $registry): void {
        $registry->embedderFor('default:400:normal')->encode('A');
        $registry->embedderFor('default:700:normal')->encode('B');
    });

    expect(substr_count($pdf, '/Subtype /Type0'))->toBe(2);
    expect(substr_count($pdf, '/Subtype /CIDFontType2'))->toBe(2);
    expect(substr_count($pdf, '/FontFile2'))->toBe(2);
});

it('does not embed unused faces', function (): void {
    $catalog = FontCatalog::withDefaults(); // 4 caras registradas (regular/bold/italic/bold-italic)
    $pdf = renderRegistryPdf($catalog, function (FontRegistry $registry): void {
        $registry->embedderFor('default:400:normal')->encode('A'); // solo se usa una
    });

    expect(substr_count($pdf, '/FontFile2'))->toBe(1);
    expect(substr_count($pdf, '/Subtype /Type0'))->toBe(1);
});

it('prefixes subset tag in BaseFont', function (): void {
    $catalog = FontCatalog::withDefaults();
    $pdf = renderRegistryPdf($catalog, function (FontRegistry $registry): void {
        $registry->embedderFor('default:400:normal')->encode('A');
    });

    expect($pdf)->toMatch('/\/BaseFont \/[A-Z]{6}\+DejaVuSans\b/');
});

it('sanitizes non-PDF-name characters out of a BaseFont derived from a spaced filename', function (): void {
    // Un fichero de fuente con espacios (o delimitadores PDF) en el nombre no puede volcarse
    // tal cual en /BaseFont: un espacio termina el nombre de forma prematura y produce un
    // token de PDF inválido (Ghostscript repara el xref sustituyendo la fuente en vez de
    // fallar ruidosamente, lo que oculta el bug).
    $sourcePath = __DIR__ . '/../../../resources/fonts/DejaVuSans.ttf';
    $spacedPath = sys_get_temp_dir() . '/My Custom Font.ttf';
    copy($sourcePath, $spacedPath);
    try {
        $catalog = new FontCatalog();
        $catalog->register('spaced', 400, false, $spacedPath);
        $pdf = renderRegistryPdf($catalog, function (FontRegistry $registry): void {
            $registry->embedderFor('spaced:400:normal')->encode('A');
        });

        // Cada dict que lleva /BaseFont lo cierra con " /<siguienteClave>" (mismo dict, misma
        // línea — PdfWriter no pone una clave por línea): capturar hasta ahí de forma no-greedy
        // recupera el nombre COMPLETO tal y como quedó volcado, espacios incluidos si el bug
        // sigue presente, en vez de truncarlo en el primer espacio.
        $count = preg_match_all('/\/BaseFont \/(.*?) \//', $pdf, $matches);
        expect($count)->toBeGreaterThan(0);
        foreach ($matches[1] as $baseFontName) {
            expect($baseFontName)->toMatch('/^[A-Z]{6}\+[A-Za-z0-9_-]+$/');
        }
    } finally {
        unlink($spacedPath);
    }
});

it('gives each face its own resource name in creation order', function (): void {
    $catalog = FontCatalog::withDefaults();
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $registry = new FontRegistry(new PdfWriter($stream), $catalog);

    $regular = $registry->embedderFor('default:400:normal');
    $bold = $registry->embedderFor('default:700:normal');
    $regularAgain = $registry->embedderFor('default:400:normal');

    expect($regularAgain)->toBe($regular); // lazy + memoized por faceKey
    $resources = $registry->pageResources();
    expect($resources)->toHaveKey('F1');
    expect($resources)->toHaveKey('F2');
    expect($resources['F1'])->toBe($regular->objectId());
    expect($resources['F2'])->toBe($bold->objectId());
});
