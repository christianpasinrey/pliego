<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pliego\Css\Value\Length;
use Pliego\Engine;

// ─── Modo CLI: demo clásica (php index.php → out.pdf) ─────────────────────────
if (PHP_SAPI === 'cli') {
    $html = <<<'HTML'
    <body>
      <h1>pliego — esqueleto andante</h1>
      <p class="box">Motor HTML/CSS a PDF en PHP puro. Esta página salió del pipeline completo:
      DOM, cascade, box tree, block flow, paginación en streaming y writer PDF propio.</p>
      <p>Texto con <strong>inline aplanado</strong> y acentos: años, señal, corazón.</p>
    </body>
    HTML;
    $css = 'h1 { font-size: 28px; color: #8b5e34; margin: 0 0 16px 0 }
    p { margin: 0 0 10px 0 } .box { background-color: #eee; padding: 14px }';
    $report = Engine::make()->basePath(__DIR__)->stylesheet($css)->margins(Length::px(60))->render($html)->save('out.pdf');
    echo "out.pdf generado — {$report->pageCount} página(s), " . count($report->warnings) . " warning(s)\n";
    exit(0);
}

// ─── Modo web: playground ──────────────────────────────────────────────────────
$action = $_POST['action'] ?? null;

if ($action === 'pdf' || $action === 'download' || $action === 'report') {
    $html = (string) ($_POST['html'] ?? '');
    $css = (string) ($_POST['css'] ?? '');
    try {
        $engine = Engine::make()->basePath(__DIR__)->stylesheet($css)->margins(Length::px(48));
        $start = microtime(true);
        $stream = fopen('php://temp', 'r+b');
        assert($stream !== false);
        $report = $engine->render($html)->toStream($stream);
        $ms = (int) round((microtime(true) - $start) * 1000);
        $bytes = ftell($stream);
        rewind($stream);

        if ($action === 'report') {
            fclose($stream);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'pages' => $report->pageCount,
                'warnings' => $report->warnings,
                'ms' => $ms,
                'bytes' => $bytes,
            ], JSON_UNESCAPED_UNICODE);
            exit(0);
        }

        header('Content-Type: application/pdf');
        $disposition = $action === 'download' ? 'attachment' : 'inline';
        header("Content-Disposition: $disposition; filename=\"pliego.pdf\"");
        header('Content-Length: ' . (string) $bytes);
        fpassthru($stream);
        fclose($stream);
        exit(0);
    } catch (\Throwable $e) {
        if ($action === 'report') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'type' => $e::class], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/plain; charset=utf-8', true, 500);
            echo "Error de render (" . $e::class . "):\n" . $e->getMessage();
        }
        exit(1);
    }
}

$sampleHtml = <<<'HTML'
<body>
  <div class="header">pliego · playground</div>
  <h1>Cammino francese da Sarria</h1>
  <p class="meta">Prenotazione n. 136961 — Cliente: Livia</p>

  <div class="price">Prezzo a persona: 296,33 € <span class="badge-pill">-10%</span></div>
  <a class="btn">Prenota ora</a>

  <div class="band">Cosa portare</div>
  <ul class="packing-list">
    <li>Zaino da 30-40 litri</li>
    <li>Scarpe da trekking già rodate</li>
    <li>Borraccia e crema solare</li>
  </ul>

  <div class="band">Itinerario</div>

  <table class="summary">
    <thead>
      <tr><th class="dt-cell">Giorno</th><th class="dt-cell">Tappa</th><th class="dt-cell">Km</th></tr>
    </thead>
    <tbody>
      <tr><td class="dt-cell">1</td><td class="dt-cell">Sarria &rarr; Portomarín</td><td class="dt-cell">22,2</td></tr>
      <tr><td class="dt-cell">2</td><td class="dt-cell">Portomarín &rarr; Palas de Rei</td><td class="dt-cell">24,8</td></tr>
      <tr><td class="dt-cell">3</td><td class="dt-cell">Palas de Rei &rarr; Arzúa</td><td class="dt-cell">28,5</td></tr>
      <tr><td class="dt-cell">4</td><td class="dt-cell">Arzúa &rarr; Santiago</td><td class="dt-cell">39,3</td></tr>
    </tbody>
  </table>

  <div class="card photo-card">
    <img src="playground-assets/sample-photo.jpg" width="120">
    <div class="info">
      <p class="day"><strong>Sarria</strong> — <em>Giorno 1</em></p>
      <table class="data-table">
        <thead>
          <tr><th class="dt-cell">Data</th><th class="dt-cell">Km</th><th class="dt-cell">Pernottamento</th></tr>
        </thead>
        <tbody>
          <tr><td class="dt-cell">19/09/2026</td><td class="dt-cell">—</td><td class="dt-cell">Sarria</td></tr>
        </tbody>
      </table>
      <p>Una volta arrivato a Sarria, ti consigliamo di visitare la città e di goderti
      i suoi monumenti e le sue strade, dove si respira già l'atmosfera del Cammino.
      Puoi <strong>prenotare online</strong> o scriverci — <em>siamo qui per aiutarti</em>.</p>
    </div>
  </div>

  <div class="card">
    <p class="day"><strong>Da Sarria a Portomarín</strong> — Giorno 2</p>
    <table class="data-table">
      <thead>
        <tr><th class="dt-cell">Data</th><th class="dt-cell">Km</th><th class="dt-cell">Pernottamento</th></tr>
      </thead>
      <tbody>
        <tr><td class="dt-cell">20/09/2026</td><td class="dt-cell">22,2</td><td class="dt-cell">Portomarín</td></tr>
      </tbody>
    </table>
    <p>Il tuo itinerario inizierà con questo tratto che si snoda lungo sentieri
    tranquilli tra questi secolari e granai storici.</p>
  </div>
</body>
HTML;

$sampleCss = <<<'CSS'
/* M6: :root custom properties — reused via var() below, resolved at compute time. */
:root {
  --brand: #163a6b;
  --accent: #ffd500;
  --stripe: rgba(22, 58, 107, .06);
  --gap: 1rem;
}
body { font-size: 14px; color: #222222 }
/* M8: the header banner gains a native PDF gradient (a real /Shading object, not a bitmap) --
 * still built entirely from :root var()s, same Bootstrap-friendly idiom as the rest of this sheet. */
.header {
  background: linear-gradient(to right, var(--brand), #2a5298);
  color: white;
  padding: calc(var(--gap) * .75);
  font-size: 16px;
}
h1 { font-size: 24px; margin: 16px 0 4px 0 }
.meta { color: #666666; margin: 0 0 12px 0 }
.price { background-color: var(--accent); padding: 14px; font-size: 20px; margin: 0 0 14px 0 }
/* M8: a real Bootstrap ".badge.rounded-pill" -- border-radius: 999px is auto-clamped by the
 * engine's corner-overlap clamp (css-backgrounds-3 §5.5) down to exactly half this badge's own
 * height, no special-case code needed to get the true pill shape. */
.badge-pill {
  display: inline;
  border-radius: 999px;
  background-color: #163a6b;
  color: white;
  padding: 2px 10px;
  font-size: 12px;
  font-weight: bold;
}
/* M7: display:inline-block finally paints its own bg+border+padding IN LINE (real inline boxes,
 * M7-T4) -- the exact Bootstrap ".btn" pattern that used to flatten to plain text before M7.
 * M8: + border-radius and a soft box-shadow -- the last mile from "boxed" to "looks real". */
.btn {
  display: inline-block;
  background-color: var(--brand);
  color: white;
  padding: calc(var(--gap) * .375) calc(var(--gap) * .75);
  border: 1px solid var(--brand);
  border-radius: 6px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, .35);
  margin: 0 0 14px 0;
}
.packing-list { margin: 0 0 14px 0 }
.band { background-color: var(--accent); padding: 10px; font-size: 18px; margin: 0 0 10px 0 }
/* M8: rounded corners + a soft shadow -- the real "Bootstrap .card" look, on top of M1-M7's plain
 * bordered/padded box (both features compose with the existing flex photo-card below, unchanged). */
.card {
  background-color: #f4f4f4;
  padding: 12px;
  margin: 0 0 10px 0;
  border-radius: 8px;
  box-shadow: 0 3px 8px rgba(0, 0, 0, .18);
}
.photo-card { display: flex; gap: 12px }
.info { flex: 1 }
.day { margin: 0 0 4px 0; font-size: 16px }
.data-table { border: 1px solid #999999; border-spacing: 2px; margin: 0 0 6px 0; font-size: 12px; color: #555555 }
.dt-cell { border: 1px solid #cccccc; padding: 3px 6px }
/* M6: a striped table — nth-child(odd) painting an rgba() accent behind alternating rows. */
.summary { border: 1px solid #999999; border-spacing: 2px; margin: 0 0 14px 0; font-size: 12px; color: #555555; width: 100% }
.summary tbody tr:nth-child(odd) { background-color: var(--stripe) }
.price { text-align: right }
p { line-height: 1.45 }
@page {
  margin: 48px;
  @bottom-left { content: "tubuencamino.com"; }
  @bottom-right { content: "Pagina " counter(page) " de " counter(pages); }
}
CSS;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>pliego · playground</title>
<link rel="stylesheet" href="playground-assets/codemirror.min.css">
<link rel="stylesheet" href="playground-assets/theme-material-darker.min.css">
<style>
  :root { --bg:#14161a; --panel:#1c1f24; --border:#2c3038; --fg:#e6e3de; --accent:#d8a878; --warn:#e0b050 }
  * { box-sizing:border-box }
  body { margin:0; height:100vh; display:flex; flex-direction:column;
         font:14px/1.5 system-ui,'Segoe UI',sans-serif; background:var(--bg); color:var(--fg) }
  header { display:flex; align-items:center; gap:1rem; padding:.6rem 1rem;
           background:var(--panel); border-bottom:1px solid var(--border) }
  header h1 { font-size:1rem; margin:0; color:var(--accent) }
  header .hint { color:#9aa4af; font-size:.8rem }
  main { flex:1; display:grid; grid-template-columns: 1fr 1fr; min-height:0 }
  .left { display:flex; flex-direction:column; border-right:1px solid var(--border); min-width:0 }
  .tabs { display:flex; gap:.25rem; padding:.4rem .6rem 0; background:var(--panel) }
  .tabs button { background:none; border:1px solid var(--border); border-bottom:none; color:var(--fg);
                 padding:.35rem .9rem; border-radius:6px 6px 0 0; cursor:pointer; font-size:.85rem }
  .tabs button.active { background:#212121; color:var(--accent); font-weight:600 }
  .editors { flex:1; min-height:0; position:relative }
  .editor-wrap { position:absolute; inset:0; display:none }
  .editor-wrap.active { display:block }
  .CodeMirror { height:100%; font-size:13px }
  .actions { display:flex; gap:.6rem; align-items:center; padding:.55rem .8rem;
             background:var(--panel); border-top:1px solid var(--border) }
  .actions button { background:var(--accent); color:#14161a; border:none; padding:.5rem 1.1rem;
                    border-radius:6px; font-weight:700; cursor:pointer; font-size:.9rem }
  .actions button.secondary { background:none; color:var(--fg); border:1px solid var(--border) }
  .actions .stats { margin-left:auto; color:#9aa4af; font-size:.8rem }
  .right { display:flex; flex-direction:column; min-width:0 }
  .right iframe { flex:1; border:none; background:#3a3d42 }
  .warnings { max-height:30%; overflow:auto; border-top:1px solid var(--border);
              background:var(--panel); padding:.5rem .8rem; font-size:.8rem }
  .warnings h2 { font-size:.8rem; margin:.1rem 0 .35rem; color:var(--warn) }
  .warnings ul { margin:0; padding-left:1.2rem }
  .warnings li { color:#c9b17c; margin:.15rem 0; font-family:ui-monospace,Consolas,monospace }
  .warnings .none { color:#7fb08a }
  .warnings .error { color:#e07070; font-family:ui-monospace,Consolas,monospace; white-space:pre-wrap }
</style>
</head>
<body>
<header>
  <h1>pliego · playground</h1>
  <span class="hint">Pega tu HTML y CSS, pulsa Generar. Los warnings te dicen qué CSS no soporta el motor todavía.</span>
</header>
<main>
  <div class="left">
    <div class="tabs">
      <button id="tab-html" class="active" type="button">HTML</button>
      <button id="tab-css" type="button">CSS</button>
    </div>
    <div class="editors">
      <div class="editor-wrap active" id="wrap-html"><textarea id="src-html"><?= htmlspecialchars($sampleHtml) ?></textarea></div>
      <div class="editor-wrap" id="wrap-css"><textarea id="src-css"><?= htmlspecialchars($sampleCss) ?></textarea></div>
    </div>
    <div class="actions">
      <button id="btn-generate" type="button">Generar PDF</button>
      <button id="btn-download" class="secondary" type="button">Descargar</button>
      <span class="stats" id="stats"></span>
    </div>
  </div>
  <div class="right">
    <iframe id="preview" name="preview" title="PDF"></iframe>
    <div class="warnings" id="warnings"><h2>Warnings</h2><p class="none">Genera un PDF para ver el informe.</p></div>
  </div>
</main>

<form id="pdf-form" method="post" target="preview" style="display:none">
  <input type="hidden" name="action" value="pdf">
  <input type="hidden" name="html" id="form-html">
  <input type="hidden" name="css" id="form-css">
</form>

<script src="playground-assets/codemirror.min.js"></script>
<script src="playground-assets/mode-xml.min.js"></script>
<script src="playground-assets/mode-css.min.js"></script>
<script src="playground-assets/mode-htmlmixed.min.js"></script>
<script>
  const cmOpts = { lineNumbers: true, theme: 'material-darker', lineWrapping: true, tabSize: 2 };
  const cmHtml = CodeMirror.fromTextArea(document.getElementById('src-html'), { ...cmOpts, mode: 'htmlmixed' });
  const cmCss = CodeMirror.fromTextArea(document.getElementById('src-css'), { ...cmOpts, mode: 'css' });

  const tabs = { html: document.getElementById('tab-html'), css: document.getElementById('tab-css') };
  const wraps = { html: document.getElementById('wrap-html'), css: document.getElementById('wrap-css') };
  function showTab(which) {
    for (const key of ['html', 'css']) {
      tabs[key].classList.toggle('active', key === which);
      wraps[key].classList.toggle('active', key === which);
    }
    (which === 'html' ? cmHtml : cmCss).refresh();
  }
  tabs.html.addEventListener('click', () => showTab('html'));
  tabs.css.addEventListener('click', () => showTab('css'));

  async function generate(download) {
    const html = cmHtml.getValue();
    const css = cmCss.getValue();
    document.getElementById('form-html').value = html;
    document.getElementById('form-css').value = css;
    const form = document.getElementById('pdf-form');
    form.action.value = download ? 'download' : 'pdf';
    form.submit();

    const body = new URLSearchParams({ action: 'report', html, css });
    const box = document.getElementById('warnings');
    try {
      const res = await fetch('', { method: 'POST', body });
      const data = await res.json();
      if (!data.ok) {
        box.innerHTML = '<h2>Error</h2><p class="error">' + escapeHtml(data.type + ': ' + data.error) + '</p>';
        document.getElementById('stats').textContent = '';
        return;
      }
      document.getElementById('stats').textContent =
        data.pages + ' pág · ' + (data.bytes / 1024).toFixed(0) + ' KB · ' + data.ms + ' ms';
      box.innerHTML = '<h2>Warnings (' + data.warnings.length + ')</h2>' +
        (data.warnings.length === 0
          ? '<p class="none">Ninguno — todo el CSS fue entendido.</p>'
          : '<ul>' + data.warnings.map(w => '<li>' + escapeHtml(w) + '</li>').join('') + '</ul>');
    } catch (e) {
      box.innerHTML = '<h2>Error</h2><p class="error">' + escapeHtml(String(e)) + '</p>';
    }
  }
  function escapeHtml(s) {
    return s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  document.getElementById('btn-generate').addEventListener('click', () => generate(false));
  document.getElementById('btn-download').addEventListener('click', () => generate(true));
  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); generate(false); }
  });
</script>
</body>
</html>
