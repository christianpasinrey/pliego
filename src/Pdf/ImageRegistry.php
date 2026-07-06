<?php

declare(strict_types=1);

namespace Pliego\Pdf;

use Pliego\Image\DecodedImage;
use Pliego\Image\ImageLoader;

/**
 * Mirrors FontRegistry's shape for images: creates (lazily, memoized by $imageKey) one image
 * XObject (ISO 32000-1 §8.9.5) per distinct image actually drawn during painting — the same
 * photo referenced from 6 `<img>` tags produces exactly one XObject, `Do`-ed 6 times. `$imageKey`
 * is ImageFragment's already-resolved-and-verified source path (see ImageFragment's docblock),
 * reused as-is as the dedup key.
 *
 * Ordering: xobjectFor() only allocates the object id and resource name and decodes the file (so
 * a page's content stream can reference `/ImN` right away); the actual XObject dict + stream body
 * is written later, by flushAll() — call once, after every page has been painted (same contract
 * as FontRegistry::flushAll(), see PdfWriter's ordering-contract docblock: both can run in either
 * order relative to each other, as long as both run before PdfWriter::finish()).
 */
final class ImageRegistry
{
    /** @var array<string, ImageXObjectRef> imageKey => ref, in first-use order */
    private array $refs = [];
    /** @var array<string, DecodedImage> imageKey => decoded image, kept for flushAll() */
    private array $decoded = [];
    private int $nextResourceIndex = 1;

    public function __construct(
        private readonly PdfWriter $writer,
        private readonly ImageLoader $loader,
    ) {}

    /** Devuelve (creando y decodificando si hace falta) el XObject de la imagen dada. */
    public function xobjectFor(string $imageKey): ImageXObjectRef
    {
        if (isset($this->refs[$imageKey])) {
            return $this->refs[$imageKey];
        }

        $this->decoded[$imageKey] = $this->loader->load($imageKey);
        $objectId = $this->writer->allocateObjectId();
        $name = 'Im' . $this->nextResourceIndex++;

        return $this->refs[$imageKey] = new ImageXObjectRef($objectId, $name);
    }

    /** @return array<string, int> resource name ("Im1", "Im2", ...) => objectId de TODAS las imágenes usadas hasta el momento */
    public function pageResources(): array
    {
        $resources = [];
        foreach ($this->refs as $ref) {
            $resources[$ref->name] = $ref->objectId;
        }
        return $resources;
    }

    /**
     * Escribe el XObject de cada imagen usada (+ su SMask, si tiene canal alfa). Llamar una única
     * vez, tras la última página — puede ejecutarse en cualquier orden relativo a
     * FontRegistry::flushAll(), ambos antes de PdfWriter::finish().
     */
    public function flushAll(): void
    {
        foreach ($this->refs as $imageKey => $ref) {
            $decoded = $this->decoded[$imageKey];
            $data = $decoded->pdfData();

            $smaskEntry = '';
            if ($data->smaskBytes !== null) {
                $smaskId = $this->writer->allocateObjectId();
                $this->writeImageObject(
                    $smaskId,
                    $decoded->widthPx(),
                    $decoded->heightPx(),
                    'DeviceGray',
                    8,
                    'FlateDecode',
                    $data->smaskBytes,
                    '',
                );
                $smaskEntry = " /SMask $smaskId 0 R";
            }

            $this->writeImageObject(
                $ref->objectId,
                $decoded->widthPx(),
                $decoded->heightPx(),
                $data->colorSpace,
                $data->bitsPerComponent,
                $data->filter,
                $data->bytes,
                $smaskEntry,
            );
        }
    }

    private function writeImageObject(
        int $objectId,
        int $widthPx,
        int $heightPx,
        string $colorSpace,
        int $bitsPerComponent,
        string $filter,
        string $bytes,
        string $extraEntries,
    ): void {
        $length = strlen($bytes);
        $dict = "<< /Type /XObject /Subtype /Image /Width $widthPx /Height $heightPx "
            . "/ColorSpace /$colorSpace /BitsPerComponent $bitsPerComponent /Filter /$filter"
            . "$extraEntries /Length $length >>";
        $this->writer->writeObject($objectId, "$dict\nstream\n$bytes\nendstream");
    }
}
