<?php

// src/Pdf/PdfWriter.php
declare(strict_types=1);

namespace Pliego\Pdf;

/**
 * Streaming PDF writer (ISO 32000-1 §7.5). Objects are written to the output
 * stream as soon as they are complete; only object ids, byte offsets and page
 * references are retained, so memory stays O(page).
 *
 * M2-T7 deferred XObjects — ORDERING CONTRACT (read before touching this class):
 * `defer()` queues a Form XObject (ISO 32000-1 §8.10.2) whose content stream can't be built yet
 * — typically because it needs `counter(pages)`, only known once every page has been laid out.
 * It returns a `DeferredXObject` (object id + resource name) immediately, so a page's own content
 * stream/`/Resources /XObject` dict can reference it right away, before the stream body exists.
 *
 * The REQUIRED call order, once every page has been added via addPage(), is:
 *
 *   1. `writeDeferred(int $totalPages)` — invokes every queued builder with the real page count
 *      and writes the resulting Form XObject objects. Builders typically call
 *      `FontEmbedder::encode()` while composing their text, which registers glyphs into that
 *      font's subset — so this step MUST run before...
 *   2. `FontRegistry::flushAll()` and `Image\ImageRegistry::flushAll()` (M3-T4) — write the font
 *      objects (subset now includes every glyph used by margin-box labels, because step 1 already
 *      ran) and the image XObjects (+ SMasks) for every image actually drawn. Neither depends on
 *      the other — they can run in either order relative to each other — but both must run before...
 *   3. `finish()` — writes the pages tree, catalog and xref. Assumes every other object (deferred
 *      XObjects and image XObjects included) is already written.
 *
 * `writeDeferred()` is only required when `defer()` was actually used — callers with no margin
 * boxes never call either and `finish()` behaves exactly as before T7 (backward compatible).
 * Misuse throws `LogicException` rather than silently producing a broken PDF: `defer()` after
 * `writeDeferred()`, `writeDeferred()` called twice, or `finish()` reached with a `defer()`-ed
 * XObject that `writeDeferred()` never resolved.
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
    /**
     * @var array<int, array{0: string, 1: float, 2: float, 3: array<string, int>, 4: callable(int): string}>
     *      object id => [name, widthPt, heightPt, fontRefs, opsBuilder], in defer() call order
     */
    private array $deferred = [];
    private bool $deferredWritten = false;
    /**
     * @var array<int, int> "milli-alpha" (round($ca*1000), entero 0-1000) => object id, dedup por
     *      valor (M6-T5) — clave ENTERA a propósito, no el string formateado "%.3F": un
     *      sprintf('%.3F', $ca) devuelve un `numeric-string` para PHPStan, y PHP convierte
     *      AUTOMÁTICAMENTE una clave de array que "parece" un entero canónico a int (p.ej.
     *      "123" => 123) — PHPStan no puede descartar esa posibilidad para un `numeric-string`
     *      genérico, así que infiere `array<int|string, int>` para la propiedad entera y el
     *      `array<string, int>` declarado deja de aceptarlo (assign.propertyType). Una clave int
     *      explícita elimina la ambigüedad de raíz, sin ensanchar el tipo real de la propiedad.
     */
    private array $extGStateIds = [];
    /** @var array<int, string> misma clave "milli-alpha" => nombre de recurso ("GS1", "GS2", ...), en orden de creación */
    private array $extGStateNames = [];
    private int $nextExtGStateIndex = 1;

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

    /**
     * @param array<string, int> $fontRefs resource name (e.g. "F1") => font object id
     * @param array<string, int> $xobjectRefs resource name (e.g. "XO1") => Form XObject object id
     *        (M2-T7 margin-box labels — see DeferredXObject/defer())
     * @param array<string, int> $extGStateRefs resource name (e.g. "GS1") => ExtGState object id
     *        (M6-T5 alpha — see registerExtGState()/extGStatePageResources())
     */
    public function addPage(float $widthPt, float $heightPt, string $contentStream, array $fontRefs, array $xobjectRefs = [], array $extGStateRefs = []): void
    {
        $contentId = $this->allocateObjectId();
        $length = strlen($contentStream);
        $this->writeObject($contentId, "<< /Length $length >>\nstream\n$contentStream\nendstream");

        $resourceParts = [];
        $fonts = $this->refDict($fontRefs);
        if ($fonts !== null) {
            $resourceParts[] = "/Font << $fonts>>";
        }
        $xobjects = $this->refDict($xobjectRefs);
        if ($xobjects !== null) {
            $resourceParts[] = "/XObject << $xobjects>>";
        }
        $extGStates = $this->refDict($extGStateRefs);
        if ($extGStates !== null) {
            $resourceParts[] = "/ExtGState << $extGStates>>";
        }
        $resources = $resourceParts === [] ? '<< >>' : '<< ' . implode(' ', $resourceParts) . ' >>';

        $pageId = $this->allocateObjectId();
        $mediaBox = sprintf('[0 0 %.2F %.2F]', $widthPt, $heightPt);
        $this->writeObject($pageId, "<< /Type /Page /Parent {$this->pagesTreeId} 0 R /MediaBox $mediaBox /Resources $resources /Contents $contentId 0 R >>");
        $this->pageIds[] = $pageId;
    }

    /**
     * Queues a Form XObject (ISO 32000-1 §8.10.2) whose stream is only known once every page has
     * been rendered (i.e., it needs the total page count) — see the ordering contract in this
     * class's docblock. Allocates the object id and resource name immediately so a page's content
     * stream can reference it (`/XOn Do`) before the stream body exists.
     *
     * @param float $widthPt BBox width, in pt — the XObject's own coordinate space starts at
     *        (0,0) bottom-left, same as any PDF page.
     * @param float $heightPt BBox height, in pt.
     * @param array<string, int> $fontRefs resource name => font object id, for the XObject's own
     *        /Resources /Font dict (the ids must already exist — see FontEmbedder::objectId(),
     *        allocated at construction, well before FontRegistry::flushAll() writes the object).
     * @param callable(int $totalPages): string $opsBuilder produces the raw content stream once
     *        $totalPages is known (invoked by writeDeferred(), not here).
     */
    public function defer(float $widthPt, float $heightPt, array $fontRefs, callable $opsBuilder): DeferredXObject
    {
        if ($this->deferredWritten) {
            throw new \LogicException('PdfWriter::defer() cannot be called after writeDeferred() has already run.');
        }
        $objectId = $this->allocateObjectId();
        $name = 'XO' . (count($this->deferred) + 1);
        $this->deferred[$objectId] = [$name, $widthPt, $heightPt, $fontRefs, $opsBuilder];
        return new DeferredXObject($objectId, $name);
    }

    /**
     * Resolves every XObject queued via defer(), now that $totalPages is known. Must run before
     * FontRegistry::flushAll() (builders call FontEmbedder::encode(), which registers glyphs into
     * the subset) and before finish() (which assumes every object is already written) — see this
     * class's docblock. A no-op when nothing was ever deferred, but still marks the "resolved"
     * state so a caller that calls it unconditionally (the common case) doesn't need to guard.
     *
     * Calling this more than once is a programming error and throws.
     */
    public function writeDeferred(int $totalPages): void
    {
        if ($this->deferredWritten) {
            throw new \LogicException('PdfWriter::writeDeferred() must be called at most once.');
        }
        $this->deferredWritten = true;
        foreach ($this->deferred as $objectId => [$name, $widthPt, $heightPt, $fontRefs, $opsBuilder]) {
            $ops = $opsBuilder($totalPages);
            $fonts = $this->refDict($fontRefs);
            $resources = $fonts === null ? '<< >>' : "<< /Font << $fonts>> >>";
            $bbox = sprintf('[0 0 %.2F %.2F]', $widthPt, $heightPt);
            $length = strlen($ops);
            $this->writeObject(
                $objectId,
                "<< /Type /XObject /Subtype /Form /BBox $bbox /Resources $resources /Length $length >>\nstream\n$ops\nendstream",
            );
        }
    }

    /** @param array<string, int> $refs */
    private function refDict(array $refs): ?string
    {
        if ($refs === []) {
            return null;
        }
        $entries = '';
        foreach ($refs as $name => $objectId) {
            $entries .= "/$name $objectId 0 R ";
        }
        return $entries;
    }

    /**
     * ISO 32000-1 §8.4.5: registra (dedup por VALOR, no por identidad de llamada) un ExtGState
     * con /ca y /CA fijados ambos a $ca (constant alpha para fill y stroke — M6-T5 no distingue
     * un alpha de fill de uno de stroke, así que ambas entradas comparten el mismo valor). A
     * diferencia de defer()/FontRegistry (que retrasan la escritura del objeto hasta que se
     * conoce algo que aún no existe), un ExtGState no depende de NADA que se resuelva más tarde
     * — se escribe INMEDIATAMENTE, en la primera llamada para cada valor distinto de $ca; no hay
     * ninguna ordering contract que respetar frente a esta clase (puede llamarse en cualquier
     * momento antes de finish()). Devuelve el object id (ya escrito).
     */
    public function registerExtGState(float $ca): int
    {
        $milliAlpha = self::milliAlpha($ca);
        if (isset($this->extGStateIds[$milliAlpha])) {
            return $this->extGStateIds[$milliAlpha];
        }
        $objectId = $this->allocateObjectId();
        $name = 'GS' . $this->nextExtGStateIndex++;
        $this->extGStateIds[$milliAlpha] = $objectId;
        $this->extGStateNames[$milliAlpha] = $name;
        $formatted = sprintf('%.3F', $milliAlpha / 1000.0);
        $this->writeObject($objectId, "<< /Type /ExtGState /ca $formatted /CA $formatted >>");
        return $objectId;
    }

    /** Nombre de recurso ("GS1", "GS2", ...) del ExtGState para $ca, registrándolo (dedup) si hace falta. */
    public function extGStateResourceName(float $ca): string
    {
        $this->registerExtGState($ca);
        return $this->extGStateNames[self::milliAlpha($ca)];
    }

    /**
     * @return array<string, int> resourceName ("GS1", ...) => objectId de TODOS los ExtGState
     *         registrados hasta el momento — mismo patrón "acumula globalmente, sobre-incluye en
     *         cada página" que FontRegistry::pageResources()/ImageRegistry::pageResources().
     */
    public function extGStatePageResources(): array
    {
        $resources = [];
        foreach ($this->extGStateNames as $milliAlpha => $name) {
            $resources[$name] = $this->extGStateIds[$milliAlpha];
        }
        return $resources;
    }

    /** Clampa $ca a [0,1] y lo convierte a la clave de dedup entera (ver docblock de $extGStateIds). */
    private static function milliAlpha(float $ca): int
    {
        return (int) round(max(0.0, min(1.0, $ca)) * 1000.0);
    }

    public function finish(): void
    {
        if ($this->deferred !== [] && !$this->deferredWritten) {
            throw new \LogicException('PdfWriter::finish() called with a deferred XObject still unwritten — call writeDeferred() first.');
        }

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
