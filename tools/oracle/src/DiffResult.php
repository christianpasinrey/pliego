<?php

// tools/oracle/src/DiffResult.php
declare(strict_types=1);

namespace PliegoOracle;

/**
 * M9-T5: result of PixelDiff::compare() -- the overlap region actually compared (top-left
 * aligned, min(widthA,widthB) x min(heightA,heightB) -- see PixelDiff's docblock for why), the
 * failure metric, and a per-pixel classification mask consumed by DiffPngEncoder to render
 * diff.png (tool-only visualization, never part of the pass/fail decision itself).
 */
final readonly class DiffResult
{
    /**
     * @param string $mask one byte per pixel, row-major over ($width, $height): "\x00" same
     *                     (within $deltaThreshold), "\x01" counted diff (contributes to
     *                     $diffPercent), "\x02" antialias-masked diff (exceeded $deltaThreshold
     *                     but sits in an edge zone in either source image -- excluded from the
     *                     metric, see PixelDiff::isEdgeZone()).
     * @param string $backgroundRgb image A's pixels, already cropped to ($width, $height) and
     *                              tightly row-major packed (3 bytes/pixel) -- handed straight to
     *                              DiffPngEncoder as the diff.png background, so callers never
     *                              need to redo the crop themselves.
     */
    public function __construct(
        public int $width,
        public int $height,
        public float $diffPercent,
        public string $mask,
        public string $backgroundRgb,
    ) {}
}
