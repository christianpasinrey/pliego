<?php

declare(strict_types=1);

namespace Pliego\Page;

use Pliego\Layout\Fragment\BoxFragment;
use Pliego\Layout\Fragment\Fragment;
use Pliego\Layout\Fragment\TextFragment;
use Pliego\Layout\Geometry\Rect;

/**
 * Fragmentación M0 (css-break-3 mínimo): push-down de hojas que cruzan el
 * límite de página. Streaming real: cada página se emite en cuanto la
 * siguiente hoja pertenece a una página posterior.
 */
final readonly class Paginator
{
    public function __construct(private float $contentHeightPx) {}

    /** @return \Generator<int, Page> */
    public function paginate(BoxFragment $root): \Generator
    {
        $h = $this->contentHeightPx;
        $offset = 0.0;
        $pageIndex = 0;
        $current = [];
        foreach ($this->flatten($root) as $leaf) {
            $top = $leaf->rect()->y + $offset;
            $leafPage = (int) floor($top / $h);
            $bottom = $top + $leaf->rect()->height;
            if ($leaf->rect()->height <= $h && $bottom > ($leafPage + 1) * $h) {
                $pushDown = ($leafPage + 1) * $h - $top;
                $offset += $pushDown;
                $top += $pushDown;
                $leafPage++;
            }
            while ($leafPage > $pageIndex) {
                yield new Page($pageIndex + 1, $current);
                $current = [];
                $pageIndex++;
            }
            $current[] = $this->relocate($leaf, $top - $pageIndex * $h);
        }
        yield new Page($pageIndex + 1, $current);
    }

    /**
     * Hojas en orden de pintado: el fondo de un contenedor precede a sus hijos.
     * @return \Generator<int, Fragment>
     */
    private function flatten(BoxFragment $box): \Generator
    {
        if ($box->background !== null) {
            yield new BoxFragment($box->rect, $box->background, []);
        }
        foreach ($box->children as $child) {
            if ($child instanceof BoxFragment) {
                yield from $this->flatten($child);
            } else {
                yield $child;
            }
        }
    }

    private function relocate(Fragment $leaf, float $localY): Fragment
    {
        $rect = new Rect($leaf->rect()->x, $localY, $leaf->rect()->width, $leaf->rect()->height);
        return match (true) {
            $leaf instanceof TextFragment => new TextFragment(
                $rect,
                $leaf->text,
                $localY + ($leaf->baselineY - $leaf->rect->y),
                $leaf->fontSizePx,
                $leaf->color,
                $leaf->faceKey,
                $leaf->underline,
            ),
            $leaf instanceof BoxFragment => new BoxFragment($rect, $leaf->background, []),
            default => throw new \LogicException('Unknown fragment leaf: ' . $leaf::class),
        };
    }
}
