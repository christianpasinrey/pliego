<?php

// tests/EndToEnd/InlineBoxAndInlineBlockTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * M7-T4 acceptance test (css-inline-3 reducido): "LA tarea del milestone — la que hace que
 * .btn de Bootstrap se vea". Dos caminos, per el brief:
 *   - display:inline-block (Bootstrap's `.btn`: padding + background + border + width
 *     shrink-to-fit) dentro de un párrafo con texto alrededor.
 *   - un `<span>` inline PLANO (sin inline-block) con background+padding (Bootstrap's `.badge`).
 * Ambos deben pintarse: rects de relleno reales en el PDF final, PDF válido, 0 warnings.
 *
 * Helper con nombre único (prefijo `inlineBox`) para poder ejecutarse en aislamiento -- mismo
 * motivo documentado en BootstrapLikeTest.php (funciones de nivel superior con el mismo nombre en
 * dos ficheros de test => fatal "cannot redeclare").
 *
 * @return array{0: string, 1: \Pliego\RenderReport}
 */
function inlineBoxRenderToPdfString(string $css, string $html): array
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $report = Engine::make()->stylesheet($css)->render($html)->toStream($stream);
    rewind($stream);
    return [(string) stream_get_contents($stream), $report];
}

const INLINE_BOX_ACCEPTANCE_CSS = <<<'CSS'
.btn {
  display: inline-block;
  padding: 6px 12px;
  background-color: #007bff;
  border: 1px solid #0056b3;
  color: #ffffff;
}
.badge {
  background-color: #cccccc;
  padding: 0 6px;
}
CSS;

const INLINE_BOX_ACCEPTANCE_HTML = <<<'HTML'
<body>
  <p>Click here: <a class="btn">Submit</a> to continue.</p>
  <p>Status: <span class="badge">42</span> unread.</p>
</body>
HTML;

it('THE .btn acceptance: an inline-block Bootstrap-like button paints its background+border, end to end, 0 warnings, valid PDF', function () {
    [$pdf, $report] = inlineBoxRenderToPdfString(INLINE_BOX_ACCEPTANCE_CSS, INLINE_BOX_ACCEPTANCE_HTML);

    expect($pdf)->toStartWith('%PDF-1.7');
    expect($report->warnings)->toBe([]);

    // .btn background-color: #007bff = rgb(0, 123, 255), painted as a filled rect (bg then border,
    // css-backgrounds-3 order — InlineFlowContext/Painter, ver su docblock de clase).
    $btnFill = sprintf('%.3F %.3F %.3F rg', 0 / 255, 123 / 255, 255 / 255);
    expect($pdf)->toContain($btnFill);

    // .btn border: 1px solid #0056b3 = rgb(0, 86, 179) -- 4 lados, mismo criterio de pintado que
    // un BoxFragment normal (Painter::paintBorders() generalizado en esta tarea).
    $btnBorderFill = sprintf('%.3F %.3F %.3F rg', 0 / 255, 86 / 255, 179 / 255);
    $btnBorderPattern = '/^' . preg_quote($btnBorderFill, '/') . ' [\d.]+ [\d.]+ [\d.]+ [\d.]+ re f$/m';
    expect(preg_match_all($btnBorderPattern, $pdf))->toBe(4);

    // .badge (plain inline span with background+padding, no inline-block) -- #cccccc = rgb(204,204,204).
    $badgeFill = sprintf('%.3F %.3F %.3F rg', 204 / 255, 204 / 255, 204 / 255);
    expect($pdf)->toContain($badgeFill);

    // "Submit" (the button's own label) and the surrounding paragraph text are both painted as
    // glyph-index Tj operators (this engine embeds subset TTFs and encodes text as hex glyph ids,
    // never literal ASCII strings -- see PdfCanvas::fillText()) -- at least one Tj op per line of
    // text is present, confirming text painting wasn't skipped.
    expect(preg_match_all('/<[0-9A-Fa-f]+> Tj/', $pdf))->toBeGreaterThanOrEqual(4);
});
