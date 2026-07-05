<?php // index.php — playground: php index.php && abrir out.pdf
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pliego\Css\Value\Length;
use Pliego\Engine;

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

$report = Engine::make()->stylesheet($css)->margins(Length::px(60))->render($html)->save('out.pdf');
echo "out.pdf generado — {$report->pageCount} página(s), " . count($report->warnings) . " warning(s)\n";
