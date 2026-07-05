<?php

// tests/Unit/Pdf/PdfWriterTest.php
declare(strict_types=1);

use Pliego\Pdf\PdfWriter;

function renderEmptyPdf(): string
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $writer = new PdfWriter($stream);
    $writer->begin();
    $writer->addPage(595.28, 841.89, "1 0 0 RG\n", []);
    $writer->finish();
    rewind($stream);
    return (string) stream_get_contents($stream);
}

it('starts with the PDF 1.7 header', fn() => expect(renderEmptyPdf())->toStartWith('%PDF-1.7'));

it('contains catalog, page tree and one page', function () {
    $pdf = renderEmptyPdf();
    expect($pdf)->toContain('/Type /Catalog')
        ->toContain('/Type /Pages')->toContain('/Count 1')
        ->toContain('/Type /Page')->toContain('/MediaBox [0 0 595.28 841.89]');
});

it('writes a startxref offset that points at the xref table', function () {
    $pdf = renderEmptyPdf();
    expect(preg_match('/startxref\n(\d+)\n%%EOF\s*$/', $pdf, $m))->toBe(1);
    expect(substr($pdf, (int) $m[1], 4))->toBe('xref');
});

it('registers every object offset correctly in the xref', function () {
    $pdf = renderEmptyPdf();
    preg_match('/startxref\n(\d+)/', $pdf, $m);
    $xref = substr($pdf, (int) $m[1]);
    preg_match('/xref\n0 (\d+)\n/', $xref, $head);
    $count = (int) $head[1];
    preg_match_all('/^(\d{10}) \d{5} n ?$/m', $xref, $entries);
    expect($entries[1])->toHaveCount($count - 1);
    foreach ($entries[1] as $i => $offset) {
        $objectNumber = $i + 1;
        expect(substr($pdf, (int) $offset, strlen("$objectNumber 0 obj")))->toBe("$objectNumber 0 obj");
    }
});
