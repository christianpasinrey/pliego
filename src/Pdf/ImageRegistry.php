<?php

declare(strict_types=1);

namespace Pliego\Pdf;

use Pliego\Image\DecodedImage;
use Pliego\Image\ImageLoader;

/**
 * Mirrors FontRegistry's shape for images: creates (lazily, memoized by $imageKey) one image
 * XObject (ISO 32000-1 §8.9.5) per distinct image actually drawn during painting — the same
 * photo referenced from 6 `<img>` tags produces exactly one XObject, `Do`-ed 6 times. `$imageKey`
 * is ImageFragment's already-resolved-and-verified source path (see ImageFragment's docblock).
 *
 * M5-T1 (housekeeping, deferred from M3): dedup/lookup uses TWO keys per distinct image: the raw
 * $imageKey exactly as the caller passed it, and its `realpath($imageKey)` (when it differs from
 * the raw string). Lookup order is raw first, then realpath — raw is what closes the documented
 * build/paint TOCTOU (see reviewer-found regression below), realpath is what preserves the dedup
 * property: two DIFFERENT source strings that resolve to the SAME file on disk ('tiny.jpg' vs
 * './tiny.jpg', two relative paths reaching the same target through a different basePath, ...)
 * still share a single XObject/resource name instead of two, even though ImageFragment's own
 * $imageKey (unchanged) still carries whichever un-normalized string BoxTreeBuilder resolved it
 * to.
 *
 * Reviewer-found regression (fixed here): with a SINGLE realpath-only key, an image reached via a
 * path that resolves through a symlink/junction was stored only under its realpath. If the
 * target file was deleted before a later xobjectFor() call with the same raw $imageKey,
 * realpath() failed and the code fell back to the raw key — a key that was NEVER stored — causing
 * a cache miss and a re-decode attempt (ImageException) instead of serving the cached XObject.
 * Storing under both keys at creation time removes that window: the raw key is always present
 * for the same caller, independent of what happens to realpath() afterwards.
 *
 * Iteration note: `$refs`/`$decoded` may hold two keys pointing at the very same ImageXObjectRef
 * instance (raw + realpath); `$order` tracks each DISTINCT image exactly once, in first-use
 * order, so pageResources()/flushAll() never double-count or double-write an XObject.
 *
 * Ordering: xobjectFor() only allocates the object id and resource name and decodes the file (so
 * a page's content stream can reference `/ImN` right away); the actual XObject dict + stream body
 * is written later, by flushAll() — call once, after every page has been painted (same contract
 * as FontRegistry::flushAll(), see PdfWriter's ordering-contract docblock: both can run in either
 * order relative to each other, as long as both run before PdfWriter::finish()).
 */
final class ImageRegistry
{
    /** @var array<string, ImageXObjectRef> dedup key: raw imageKey AND/OR realpath (ver docblock de clase) => ref */
    private array $refs = [];
    /** @var array<string, DecodedImage> misma clave de dedup => decoded image, kept for flushAll() */
    private array $decoded = [];
    /** @var list<string> primary (raw) dedup key of each DISTINCT image, in first-use order — see "Iteration note" in the class docblock */
    private array $order = [];
    private int $nextResourceIndex = 1;

    public function __construct(
        private readonly PdfWriter $writer,
        private readonly ImageLoader $loader,
    ) {}

    /**
     * Devuelve (creando y decodificando si hace falta) el XObject de la imagen dada.
     *
     * M5-T1: lookup por $imageKey crudo primero (estable frente a un realpath() que deje de
     * resolver tras un borrado — ver docblock de clase), luego por realpath($imageKey) si difiere
     * del crudo. Si realpath() falla (fichero inexistente en este momento, symlink roto, etc. —
     * devuelve `false`) o coincide con el propio $imageKey, no se registra una segunda clave.
     * ImageLoader::load() (llamado más abajo con el $imageKey ORIGINAL, sin normalizar) sigue
     * siendo quien decide si el fichero es utilizable o lanza ImageException.
     */
    public function xobjectFor(string $imageKey): ImageXObjectRef
    {
        if (isset($this->refs[$imageKey])) {
            return $this->refs[$imageKey];
        }
        $real = realpath($imageKey);
        if ($real !== false && $real !== $imageKey && isset($this->refs[$real])) {
            return $this->refs[$real];
        }

        $decoded = $this->loader->load($imageKey);
        $objectId = $this->writer->allocateObjectId();
        $name = 'Im' . $this->nextResourceIndex++;
        $ref = new ImageXObjectRef($objectId, $name);

        $this->refs[$imageKey] = $ref;
        $this->decoded[$imageKey] = $decoded;
        $this->order[] = $imageKey;
        if ($real !== false && $real !== $imageKey) {
            $this->refs[$real] = $ref;
            $this->decoded[$real] = $decoded;
        }

        return $ref;
    }

    /** @return array<string, int> resource name ("Im1", "Im2", ...) => objectId de TODAS las imágenes usadas hasta el momento */
    public function pageResources(): array
    {
        $resources = [];
        foreach ($this->order as $imageKey) {
            $ref = $this->refs[$imageKey];
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
        foreach ($this->order as $imageKey) {
            $ref = $this->refs[$imageKey];
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
