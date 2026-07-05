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
