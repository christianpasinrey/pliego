<?php

// tests/EndToEnd/KitchenSinkTest.php
declare(strict_types=1);

use Pliego\Engine;
use Pliego\Text\TtfFont;

/**
 * Probe permanente de M0 (portado de la review final): un único documento que
 * combina, a la vez, todas las capacidades y límites documentados del engine:
 * fondos anidados, paginación por overflow, warnings de CSS no soportado,
 * display:none y texto acentuado. Sirve de regresión E2E de "todo a la vez".
 */
const KITCHEN_SINK_HIDDEN_TEXT = 'SECRETOOCULTO';

const KITCHEN_SINK_CSS = <<<'CSS'
.outer { background-color: #eee; padding: 20px }
.inner { background-color: #ccc; padding: 10px }
.hidden { display: none }
.narrow { width: 50vh }
p > span { color: red }
p { float: left; line-height: 1.5 }
CSS;
// NOTA M1-T6: `p { line-height: 1.5 }` ya se parseaba desde M1-T2 pero, hasta InlineFlowContext
// (M1-T6), el layout SIEMPRE usaba la fórmula fija 1.2×font-size ignorando el valor declarado
// (bug conocido, no documentado explícitamente hasta ahora). InlineFlowContext lo aplica de
// verdad (altura de línea = max(lineHeightPx declarado, 1.2×font-size)); con 140 <p> a 24px de
// alto de línea (antes 19.2px) el contenido total crece lo suficiente como para desbordar una
// página más: 3 -> 4. Verificado a mano: 140×24 + paddings ≈ 3458px de contenido / ≈1026.5px de
// alto de página ≈ 3.37 -> 4 páginas (antes 140×19.2 + paddings ≈ 2786px ≈ 2.71 -> 3 páginas).
//
// NOTA M7-T2: la hoja UA (Style\UserAgentStylesheet) añade márgenes reales a <h1>/<p> donde antes
// (M0-M6) no había ninguno -- este documento, con 140 <p> reales y un <h1>, es exactamente el
// "doc sin CSS de márgenes propio" que la tarea M7-T2 advierte que cambia de geometría. 4 -> 8
// páginas. Verificado a mano (BlockFlowContext real, sin PDF, ver script de la tarea):
//   h1: font-size 2em de 16px = 32px; línea 1.2×32 = 38.4px; margin UA .67em 0 (relativo a SU
//       PROPIO font-size, 32px) = .67×32 = 21.44px arriba Y abajo (no es el último hijo de
//       .inner) -> 21.44 + 38.4 + 21.44 = 81.28px.
//   .hidden: display:none -> 0px (fuera del árbol de caja por completo).
//   .narrow: width:50vh se descarta (warning) -> width:auto normal, 1 línea a 19.2px, sin margin
//       propio -> 19.2px.
//   139 <p> NO-últimos: margin-top UA 16px + línea (line-height:1.5×16=24px, gana al UA porque
//       el autor lo declara) + margin-bottom UA 16px = 56px cada uno -> 139×56 = 7784px.
//   El <p> #140 (ÚLTIMO hijo de .inner): margin-top 16px + línea 24px, SIN margin-bottom (CSS
//       2.2 §10.6.3: el margen del último hijo no aporta a la altura de contenido del padre,
//       mismo criterio que "excludes the last child margin-bottom..." en BlockFlowContextTest)
//       -> 40px.
//   Suma dentro de .inner: 81.28 + 19.2 + 7784 + 40 = 7924.48px.
//   .inner border-box: + su propio padding (10+10=20) = 7944.48px. .outer border-box: + su
//       propio padding (20+20=40) = 7984.48px = altura TOTAL de contenido del documento (body no
//       tiene margen/padding propio, hereda la altura de .outer, su único hijo).
//   7984.48 / 1026.5196850394 (alto de página A4 menos 2×48px de margen uniforme) ≈ 7.778 ->
//       ceil -> 8 páginas.


function kitchenSinkHtml(int $paragraphs): string
{
    $body = '<div class="outer"><div class="inner">'
        . '<h1>Título con acentos: áéíóúñ</h1>'
        . '<div class="hidden">' . KITCHEN_SINK_HIDDEN_TEXT . ' no debe aparecer jamás en el PDF</div>'
        . '<div class="narrow">Columna angosta con ancho no soportado</div>';
    for ($i = 0; $i < $paragraphs; $i++) {
        $body .= "<p>Línea de contenido número $i con acentos: áéíóúñ.</p>";
    }
    $body .= '</div></div>';
    return "<body>$body</body>";
}

/** Hex de CIDs (glyph ids) que produciría FontEmbedder::encode() para $text con la fuente por defecto. */
function expectedGlyphHex(string $text): string
{
    $font = TtfFont::fromFile(__DIR__ . '/../../resources/fonts/DejaVuSans.ttf');
    $hex = '';
    foreach (mb_str_split($text) as $char) {
        $hex .= sprintf('%04X', $font->glyphId(mb_ord($char)));
    }
    return $hex;
}

it('renders nested backgrounds, overflows to 8 pages, warns on exactly 2 unsupported declarations, hides display:none text and keeps accents', function () {
    $path = sys_get_temp_dir() . '/pliego-kitchen-sink.pdf';
    $report = Engine::make()->stylesheet(KITCHEN_SINK_CSS)->render(kitchenSinkHtml(140))->save($path);
    $pdf = (string) file_get_contents($path);

    expect($pdf)->toStartWith('%PDF-1.7');

    // Overflow a 8 páginas (subió de 3 a 4 en M1-T6, de 4 a 8 en M7-T2 — ver notas arriba).
    expect($report->pageCount)->toBe(8);

    // Exactamente 2 declaraciones CSS no soportadas: float y width:vh. "p > span" parsea Y matchea
    // de verdad desde M6-T2 (ya no genera warning), pero no hay ningún <span> en este documento —
    // no tiene ningún efecto observable aquí. line-height ya no genera warning: M1-T2 le da soporte
    // (ver p { line-height: 1.5 } arriba). M2-T2: width SÍ admite % ahora (LengthPercentage); M6-T3
    // añade em/rem/pt/cm/mm/in (CssLength) — "vh" (viewport height) sigue fuera de alcance del
    // motor (no hay noción de viewport en un motor de paginación), así que se usa aquí para seguir
    // demostrando el warning discipline sobre unidades no soportadas sin afectar la aritmética de
    // paginación de este test (declaración descartada igual que antes, sin efecto en el layout).
    expect($report->warnings)->toHaveCount(2);
    expect($report->warnings)->toContain('Unsupported property: float');
    expect($report->warnings)->toContain('Unsupported length for width: 50vh');

    // Fondos anidados: el gris claro de .outer y el gris oscuro de .inner aparecen.
    $outerFill = sprintf('%.3F %.3F %.3F rg', 0xee / 255, 0xee / 255, 0xee / 255);
    $innerFill = sprintf('%.3F %.3F %.3F rg', 0xcc / 255, 0xcc / 255, 0xcc / 255);
    expect($pdf)->toContain($outerFill);
    expect($pdf)->toContain($innerFill);

    // display:none: el texto oculto no debe aparecer codificado como glifos en el PDF.
    $hiddenHex = expectedGlyphHex(KITCHEN_SINK_HIDDEN_TEXT);
    expect($pdf)->not->toContain($hiddenHex);
    expect($pdf)->not->toContain(KITCHEN_SINK_HIDDEN_TEXT);

    // Texto acentuado: el glifo de una á minúscula sí debe estar presente.
    $accentedHex = expectedGlyphHex('á');
    expect($pdf)->toContain($accentedHex);
});
