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

/** @return array{0: PdfWriter, 1: resource} a begun writer + its backing stream (rewind()+read after finish()). */
function beginWriter(): array
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $writer = new PdfWriter($stream);
    $writer->begin();
    return [$writer, $stream];
}

/** @param resource $stream */
function readAll(mixed $stream): string
{
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

// M2-T7: deferred Form XObjects (margin-box page counters).

it('defer() allocates an object id immediately and names XObjects XO1..XOn in call order', function () {
    [$writer] = beginWriter();
    $first = $writer->defer(10.0, 10.0, [], fn(int $totalPages): string => '');
    $second = $writer->defer(10.0, 10.0, [], fn(int $totalPages): string => '');
    expect($first->name)->toBe('XO1');
    expect($second->name)->toBe('XO2');
    expect($second->objectId)->toBeGreaterThan($first->objectId);
});

it('writeDeferred() writes a Form XObject dict with BBox, font Resources, and the totalPages-aware stream', function () {
    [$writer, $stream] = beginWriter();
    $ref = $writer->defer(
        100.0,
        20.0,
        ['F1' => 99],
        fn(int $totalPages): string => "BT /F1 10 Tf ($totalPages) Tj ET\n",
    );
    $writer->addPage(595.28, 841.89, "q 1 0 0 1 10 10 cm /{$ref->name} Do Q\n", [], [$ref->name => $ref->objectId]);
    $writer->writeDeferred(3);
    $writer->finish();
    $pdf = readAll($stream);

    expect($pdf)->toContain('/Type /XObject')
        ->toContain('/Subtype /Form')
        ->toContain('/BBox [0 0 100.00 20.00]')
        ->toContain('/Font << /F1 99 0 R >>')
        ->toContain('(3) Tj')
        ->toContain("/XObject << /{$ref->name} {$ref->objectId} 0 R >>");
});

it('invokes each deferred builder exactly once, with the real total page count', function () {
    [$writer, $stream] = beginWriter();
    $calls = [];
    $writer->defer(10.0, 10.0, [], function (int $totalPages) use (&$calls): string {
        $calls[] = $totalPages;
        return '';
    });
    $writer->writeDeferred(7);
    $writer->finish();
    readAll($stream);

    expect($calls)->toBe([7]);
});

it('throws when defer() is called after writeDeferred() has already run', function () {
    [$writer] = beginWriter();
    $writer->defer(10.0, 10.0, [], fn(int $totalPages): string => '');
    $writer->writeDeferred(1);

    expect(fn() => $writer->defer(10.0, 10.0, [], fn(int $totalPages): string => ''))
        ->toThrow(LogicException::class);
});

it('throws when writeDeferred() is called more than once', function () {
    [$writer] = beginWriter();
    $writer->defer(10.0, 10.0, [], fn(int $totalPages): string => '');
    $writer->writeDeferred(1);

    expect(fn() => $writer->writeDeferred(2))->toThrow(LogicException::class);
});

it('throws when finish() runs with a deferred XObject still unwritten', function () {
    [$writer] = beginWriter();
    $writer->defer(10.0, 10.0, [], fn(int $totalPages): string => '');

    expect(fn() => $writer->finish())->toThrow(LogicException::class);
});

it('still allows finish() with no writeDeferred() call at all when nothing was ever deferred', function () {
    // Backward compatibility: documents with no @page margin boxes never call defer(), so
    // requiring writeDeferred() unconditionally would break every pre-T7 caller.
    [$writer, $stream] = beginWriter();
    $writer->addPage(595.28, 841.89, '', []);
    $writer->finish();

    expect(readAll($stream))->toStartWith('%PDF-1.7');
});
