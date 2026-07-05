<?php

// src/Pdf/PdfWriter.php
declare(strict_types=1);

namespace Pliego\Pdf;

/**
 * Streaming PDF writer (ISO 32000-1 §7.5). Objects are written to the output
 * stream as soon as they are complete; only object ids, byte offsets and page
 * references are retained, so memory stays O(page).
 */
final class PdfWriter
{
    private int $nextObjectId = 1;
    private int $bytesWritten = 0;
    /** @var array<int, int> object id => byte offset */
    private array $offsets = [];
    /** @var list<int> */
    private array $pageIds = [];
    private int $pagesTreeId = 0;

    /** @param resource $stream */
    public function __construct(private readonly mixed $stream) {}

    public function begin(): void
    {
        $this->emit("%PDF-1.7\n%\xE2\xE3\xCF\xD3\n");
        $this->pagesTreeId = $this->allocateObjectId();
    }

    public function allocateObjectId(): int
    {
        return $this->nextObjectId++;
    }

    public function writeObject(int $id, string $body): void
    {
        $this->offsets[$id] = $this->bytesWritten;
        $this->emit("$id 0 obj\n$body\nendobj\n");
    }

    /** @param array<string, int> $fontRefs resource name (e.g. "F1") => font object id */
    public function addPage(float $widthPt, float $heightPt, string $contentStream, array $fontRefs): void
    {
        $contentId = $this->allocateObjectId();
        $length = strlen($contentStream);
        $this->writeObject($contentId, "<< /Length $length >>\nstream\n$contentStream\nendstream");

        $fonts = '';
        foreach ($fontRefs as $name => $objectId) {
            $fonts .= "/$name $objectId 0 R ";
        }
        $resources = $fonts === '' ? '<< >>' : "<< /Font << $fonts>> >>";

        $pageId = $this->allocateObjectId();
        $mediaBox = sprintf('[0 0 %.2F %.2F]', $widthPt, $heightPt);
        $this->writeObject($pageId, "<< /Type /Page /Parent {$this->pagesTreeId} 0 R /MediaBox $mediaBox /Resources $resources /Contents $contentId 0 R >>");
        $this->pageIds[] = $pageId;
    }

    public function finish(): void
    {
        $kids = implode(' ', array_map(static fn(int $id): string => "$id 0 R", $this->pageIds));
        $count = count($this->pageIds);
        $this->writeObject($this->pagesTreeId, "<< /Type /Pages /Kids [$kids] /Count $count >>");

        $catalogId = $this->allocateObjectId();
        $this->writeObject($catalogId, "<< /Type /Catalog /Pages {$this->pagesTreeId} 0 R >>");

        $xrefOffset = $this->bytesWritten;
        $size = $this->nextObjectId;
        $xref = "xref\n0 $size\n0000000000 65535 f \n";
        for ($id = 1; $id < $size; $id++) {
            $xref .= sprintf("%010d 00000 n \n", $this->offsets[$id]);
        }
        $this->emit($xref);
        $this->emit("trailer\n<< /Size $size /Root $catalogId 0 R >>\nstartxref\n$xrefOffset\n%%EOF\n");
    }

    private function emit(string $bytes): void
    {
        fwrite($this->stream, $bytes);
        $this->bytesWritten += strlen($bytes);
    }
}
