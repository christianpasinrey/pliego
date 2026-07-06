<?php

declare(strict_types=1);

namespace Pliego\Pdf;

/**
 * Handle returned by ImageRegistry::xobjectFor(): identifies an image XObject (ISO 32000-1
 * §8.9.5) whose stream body isn't written yet — it's produced later by
 * ImageRegistry::flushAll(), once every page has been painted. `$objectId` is already allocated
 * at xobjectFor() time (so a page's `/Resources /XObject` dict, or PdfCanvas's own content
 * stream `/ImN Do`, can reference it immediately, before the stream itself exists); `$name`
 * ("Im1".."Imn", in first-use order) is the resource name a page uses in its content stream.
 */
final readonly class ImageXObjectRef
{
    public function __construct(
        public int $objectId,
        public string $name,
    ) {}
}
