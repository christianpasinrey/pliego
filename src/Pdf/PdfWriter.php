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
    /**
     * M8-T3 (ISO 32000-1 §8.7.4.5, shadings): dedup POR CONTENIDO -- a diferencia de ExtGState
     * (clave = "milli-alpha" entero), la firma de dedup aquí es la cadena EXACTA que Pdf\PdfCanvas
     * calcula a partir del rect+Gradient (kind, /Coords ya redondeados a 2 decimales, y cada stop),
     * pasada por el llamador -- ver registerShading(). Dos elementos con el mismo rect Y el mismo
     * Gradient CSS producen la MISMA firma (mismo texto), así que comparten un único objeto
     * /Shading (y sus /Function subyacentes, nunca escritos dos veces -- ver el docblock de
     * registerShading()).
     *
     * @var array<string, int> firma => object id
     */
    private array $shadingIds = [];
    /** @var array<string, string> misma firma => nombre de recurso ("Sh1", "Sh2", ...), en orden de creación */
    private array $shadingNames = [];
    private int $nextShadingIndex = 1;
    /**
     * M9-T3 (ISO 32000-1 §8.7.3.1, PatternType 1 tiling patterns): dedup POR FIRMA -- igual
     * criterio que $shadingIds, ver registerTilingPattern(). La firma la calcula Pdf\PdfCanvas
     * (imageXObjectId + tamaño de tile + punto de anclaje, TODOS en pt) — dos llamadas con la
     * MISMA firma comparten un único objeto /Pattern (mismo /Matrix, así que es seguro compartir).
     *
     * @var array<string, int> firma => object id
     */
    private array $patternIds = [];
    /** @var array<string, string> misma firma => nombre de recurso ("P1", "P2", ...), en orden de creación */
    private array $patternNames = [];
    private int $nextPatternIndex = 1;
    /**
     * M9-T3 (ISO 32000-1 §11.6.5.2, luminosity soft masks): ExtGState { /SMask << /S /Luminosity
     * /G <form XObject> >> } para un gradiente con stops translúcidos -- ver
     * registerSoftMaskGroup(). Dedup por firma (misma firma que el par de shadings color/gris que
     * la originan, ver Pdf\PdfCanvas::paintGradient()). Comparte el MISMO contador de nombres
     * "GSn" que $extGStateIds (constant-alpha) -- ambos son ExtGState y conviven en el mismo
     * /Resources /ExtGState de la página (ver extGStatePageResources()), así que sus nombres nunca
     * pueden colisionar.
     *
     * @var array<string, int> firma => object id del ExtGState (NO del Form XObject -- ese no
     *      necesita nombre de recurso propio, solo se referencia por object id desde /SMask /G)
     */
    private array $softMaskGsIds = [];
    /** @var array<string, string> misma firma => nombre de recurso ("GS1", "GS2", ...) */
    private array $softMaskGsNames = [];

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
     * @param array<string, int> $shadingRefs resource name (e.g. "Sh1") => Shading object id
     *        (M8-T3 gradients — see registerShading()/shadingPageResources())
     * @param array<string, int> $patternRefs resource name (e.g. "P1") => Pattern object id
     *        (M9-T3 tiling backgrounds — see registerTilingPattern()/patternPageResources())
     */
    public function addPage(float $widthPt, float $heightPt, string $contentStream, array $fontRefs, array $xobjectRefs = [], array $extGStateRefs = [], array $shadingRefs = [], array $patternRefs = []): void
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
        $shadings = $this->refDict($shadingRefs);
        if ($shadings !== null) {
            $resourceParts[] = "/Shading << $shadings>>";
        }
        $patterns = $this->refDict($patternRefs);
        if ($patterns !== null) {
            $resourceParts[] = "/Pattern << $patterns>>";
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
     *
     * M9-T3: += los ExtGState /SMask de registerSoftMaskGroup() -- comparten el mismo contador de
     * nombres "GSn" que los de constant-alpha (ver el docblock de $softMaskGsIds), así que un
     * simple merge de ambos mapas no puede colisionar.
     */
    public function extGStatePageResources(): array
    {
        $resources = [];
        foreach ($this->extGStateNames as $milliAlpha => $name) {
            $resources[$name] = $this->extGStateIds[$milliAlpha];
        }
        foreach ($this->softMaskGsNames as $signature => $name) {
            $resources[$name] = $this->softMaskGsIds[$signature];
        }
        return $resources;
    }

    /** Clampa $ca a [0,1] y lo convierte a la clave de dedup entera (ver docblock de $extGStateIds). */
    private static function milliAlpha(float $ca): int
    {
        return (int) round(max(0.0, min(1.0, $ca)) * 1000.0);
    }

    /**
     * M8-T3: registra (dedup por VALOR de $signature, no por identidad de llamada -- mismo
     * criterio que registerExtGState()) el/los objeto(s) PDF que representan un shading
     * (ISO 32000-1 §8.7.4.5) -- típicamente el propio /Shading dict MÁS su(s) /Function
     * subyacente(s) (FunctionType 2/3, ISO 32000-1 §7.10.2/§7.10.3), todos escritos dentro de
     * $bodyBuilder. A diferencia de registerExtGState() (el body es una fórmula trivial de $ca),
     * un shading necesita objetos AUXILIARES (las funciones) que solo tiene sentido escribir la
     * PRIMERA vez que se ve esta firma -- por eso $bodyBuilder es un callable (invocado SOLO en
     * caché-miss, nunca en caché-hit): puede allocar+escribir esos objetos auxiliares (con
     * allocateObjectId()/writeObject() de ESTE MISMO writer, vía closure) antes de devolver el
     * cuerpo del propio /Shading dict, que los referencia por su object id.
     *
     * Devuelve el object id (ya escrito, o el de una llamada anterior con la misma firma).
     *
     * @param callable(): string $bodyBuilder
     */
    public function registerShading(string $signature, callable $bodyBuilder): int
    {
        if (isset($this->shadingIds[$signature])) {
            return $this->shadingIds[$signature];
        }
        $objectId = $this->allocateObjectId();
        $name = 'Sh' . $this->nextShadingIndex++;
        $this->shadingIds[$signature] = $objectId;
        $this->shadingNames[$signature] = $name;
        $this->writeObject($objectId, $bodyBuilder());
        return $objectId;
    }

    /**
     * Nombre de recurso ("Sh1", "Sh2", ...) del shading para $signature, registrándolo (dedup) si
     * hace falta -- mismo patrón que extGStateResourceName().
     *
     * @param callable(): string $bodyBuilder
     */
    public function shadingResourceName(string $signature, callable $bodyBuilder): string
    {
        $this->registerShading($signature, $bodyBuilder);
        return $this->shadingNames[$signature];
    }

    /**
     * @return array<string, int> resourceName ("Sh1", ...) => objectId de TODOS los shadings
     *         registrados hasta el momento -- mismo patrón "acumula globalmente, sobre-incluye en
     *         cada página" que FontRegistry/ImageRegistry/extGStatePageResources().
     */
    public function shadingPageResources(): array
    {
        $resources = [];
        foreach ($this->shadingNames as $signature => $name) {
            $resources[$name] = $this->shadingIds[$signature];
        }
        return $resources;
    }

    /**
     * M9-T3 (ISO 32000-1 §8.7.3.1, "Tiling Patterns", PatternType 1): registra (dedup por firma —
     * mismo criterio que registerShading(), ver el docblock de $patternIds) un patrón PaintType 1
     * (color, no sin color) que repite la imagen $imageXObjectId en una rejilla de tile
     * $tileWPt×$tileHPt, ANCLADA en ($originXPt, $originYPt) — el punto de la página en espacio
     * PDF por defecto (ISO 32000-1: /Matrix de un patrón mapea SIEMPRE al espacio por defecto del
     * content stream que lo usa, IGNORANDO cualquier `cm` previo al `scn` que lo pinte -- por eso
     * el anclaje debe viajar en el propio /Matrix del patrón, no puede depender de nada dinámico en
     * tiempo de pintado) donde Pdf\PdfCanvas ya calculó que debe empezar la esquina superior
     * izquierda del border-box (incluido el flip de Y, ver su docblock de paintTiledImage... /
     * fillImagePattern()).
     *
     * El patrón declara sus PROPIOS /Resources (un único /XObject /Im1 -> $imageXObjectId) —
     * autocontenido, igual criterio que defer() (Form XObjects de margin-box) declarando su propio
     * /Font: independiente de cómo se llame ese mismo XObject en el /Resources de la página.
     */
    public function registerTilingPattern(string $signature, int $imageXObjectId, float $tileWPt, float $tileHPt, float $originXPt, float $originYPt): int
    {
        if (isset($this->patternIds[$signature])) {
            return $this->patternIds[$signature];
        }
        $objectId = $this->allocateObjectId();
        $name = 'P' . $this->nextPatternIndex++;
        $this->patternIds[$signature] = $objectId;
        $this->patternNames[$signature] = $name;

        $bbox = sprintf('[0 0 %.2F %.2F]', $tileWPt, $tileHPt);
        $matrix = sprintf('[1 0 0 1 %.2F %.2F]', $originXPt, $originYPt);
        $ops = sprintf("%.2F 0 0 %.2F 0 0 cm /Im1 Do", $tileWPt, $tileHPt);
        $length = strlen($ops);
        $this->writeObject(
            $objectId,
            "<< /Type /Pattern /PatternType 1 /PaintType 1 /TilingType 1 /BBox $bbox "
                . sprintf('/XStep %.2F /YStep %.2F', $tileWPt, $tileHPt)
                . " /Resources << /XObject << /Im1 $imageXObjectId 0 R >> >> /Matrix $matrix /Length $length >>\nstream\n$ops\nendstream",
        );
        return $objectId;
    }

    /** Nombre de recurso ("P1", "P2", ...) del patrón para $signature, registrándolo (dedup) si hace falta. */
    public function tilingPatternResourceName(string $signature, int $imageXObjectId, float $tileWPt, float $tileHPt, float $originXPt, float $originYPt): string
    {
        $this->registerTilingPattern($signature, $imageXObjectId, $tileWPt, $tileHPt, $originXPt, $originYPt);
        return $this->patternNames[$signature];
    }

    /**
     * @return array<string, int> resourceName ("P1", ...) => objectId de TODOS los patrones
     *         registrados hasta el momento -- mismo patrón "acumula globalmente, sobre-incluye en
     *         cada página" que shadingPageResources().
     */
    public function patternPageResources(): array
    {
        $resources = [];
        foreach ($this->patternNames as $signature => $name) {
            $resources[$name] = $this->patternIds[$signature];
        }
        return $resources;
    }

    /**
     * M9-T3 (ISO 32000-1 §11.6.5.2, "Luminosity Masks"): escribe el Form XObject de grupo de
     * transparencia $formOps (DeviceGray, /Resources ya resuelto por el caller -- el único recurso
     * que necesita es la /Shading gris que pinta la máscara, referenciada dentro de $formOps con el
     * nombre local fijo "ShMask") y el ExtGState que lo envuelve como /SMask /Luminosity. Dedup por
     * firma (ver el docblock de $softMaskGsIds) -- $formOps/$bboxPt/$grayShadingObjectId son
     * deterministas a partir de (rect, Gradient), así que dos elementos con la MISMA firma producen
     * bytes idénticos y pueden compartir un único par (Form, ExtGState).
     *
     * Devuelve el object id del ExtGState (lo que un `gs` necesita referenciar) — el Form XObject
     * en sí nunca tiene nombre de recurso propio en la página, solo se apunta desde /SMask /G.
     */
    public function registerSoftMaskGroup(string $signature, string $bboxPt, string $formOps, int $grayShadingObjectId): int
    {
        if (isset($this->softMaskGsIds[$signature])) {
            return $this->softMaskGsIds[$signature];
        }
        $formObjectId = $this->allocateObjectId();
        $resources = "<< /Shading << /ShMask $grayShadingObjectId 0 R >> >>";
        $length = strlen($formOps);
        $this->writeObject(
            $formObjectId,
            "<< /Type /XObject /Subtype /Form /BBox $bboxPt /Group << /S /Transparency /CS /DeviceGray >> "
                . "/Resources $resources /Length $length >>\nstream\n$formOps\nendstream",
        );

        $gsObjectId = $this->allocateObjectId();
        $name = 'GS' . $this->nextExtGStateIndex++;
        $this->softMaskGsIds[$signature] = $gsObjectId;
        $this->softMaskGsNames[$signature] = $name;
        $this->writeObject($gsObjectId, "<< /Type /ExtGState /SMask << /Type /Mask /S /Luminosity /G $formObjectId 0 R >> >>");
        return $gsObjectId;
    }

    /** Nombre de recurso ("GS1", "GS2", ...) del ExtGState /SMask para $signature, registrándolo (dedup) si hace falta. */
    public function softMaskGroupResourceName(string $signature, string $bboxPt, string $formOps, int $grayShadingObjectId): string
    {
        $this->registerSoftMaskGroup($signature, $bboxPt, $formOps, $grayShadingObjectId);
        return $this->softMaskGsNames[$signature];
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
