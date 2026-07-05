<?php

declare(strict_types=1);

namespace Pliego\Pdf;

/**
 * Handle returned by PdfWriter::defer(): identifies a Form XObject (ISO 32000-1 §8.10.2) whose
 * stream body isn't known yet — it's produced later by PdfWriter::writeDeferred(), once the
 * total page count is known. `$objectId` is already allocated at defer() time (so a page's
 * `/Resources /XObject` dict can reference it immediately, before the stream itself exists);
 * `$name` ("XO1".."XOn", in defer() call order) is the resource name a page uses in its content
 * stream (e.g. `/XO1 Do`).
 */
final readonly class DeferredXObject
{
    public function __construct(
        public int $objectId,
        public string $name,
    ) {}
}
