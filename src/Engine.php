<?php

// src/Engine.php
declare(strict_types=1);

namespace Pliego;

use Pliego\Box\BoxTreeBuilder;
use Pliego\Css\StylesheetParser;
use Pliego\Css\Value\Length;
use Pliego\Dom\HtmlParser;
use Pliego\Layout\BlockFlowContext;
use Pliego\Layout\Geometry\Rect;
use Pliego\Layout\TextMeasurer;
use Pliego\Page\Paginator;
use Pliego\Page\PaperSize;
use Pliego\Paint\Painter;
use Pliego\Pdf\FontEmbedder;
use Pliego\Pdf\PdfCanvas;
use Pliego\Pdf\PdfWriter;
use Pliego\Style\CssStyleSource;
use Pliego\Style\StyleResolver;
use Pliego\Text\FontCatalog;

final class Engine
{
    private string $css = '';
    private PaperSize $paper = PaperSize::A4;
    private Length $margin;
    private string $fontPath = __DIR__ . '/../resources/fonts/DejaVuSans.ttf';

    private function __construct()
    {
        $this->margin = Length::px(48.0);
    }

    public static function make(): self
    {
        return new self();
    }

    public function stylesheet(string $css): self
    {
        $this->css .= "\n" . $css;
        return $this;
    }

    public function paper(PaperSize $paper): self
    {
        $this->paper = $paper;
        return $this;
    }

    public function margins(Length $margin): self
    {
        $this->margin = $margin;
        return $this;
    }

    public function fontFile(string $ttfPath): self
    {
        $this->fontPath = $ttfPath;
        return $this;
    }

    public function render(string $html): RenderResult
    {
        return new RenderResult(function (mixed $stream) use ($html): RenderReport {
            // Nota: llamadas envueltas en paréntesis `(new X())->metodo()` en vez del
            // encadenamiento directo de PHP 8.4 (`new X()->metodo()`, sintaxis válida y
            // semánticamente idéntica). Deviación documentada (mismo motivo que
            // Fragment::rect(), ver task-8-report.md): el parser interno de deptrac.phar
            // (nikic/php-parser v4.19.1) no reconoce el encadenamiento sin paréntesis y
            // marca el fichero entero "Syntax Error", dejando sin cubrir el grafo de
            // dependencias de Engine (`--fail-on-uncovered` deja de verificar nada aquí).
            $parseResult = (new StylesheetParser())->parse($this->css);
            $document = HtmlParser::parse($html);
            $styles = (new StyleResolver([new CssStyleSource($parseResult)]))->resolve($document);
            $boxTree = (new BoxTreeBuilder())->build($document, $styles);

            // fontFile() registra/sobreescribe la cara regular de la familia 'default'; el resto
            // de caras (bold/italic/bold-italic) siguen siendo las builtin de withDefaults().
            $catalog = FontCatalog::withDefaults();
            $catalog->register('default', 400, false, $this->fontPath);
            $measurer = new TextMeasurer();
            $margin = $this->margin->px;
            $contentWidth = $this->paper->widthPx() - 2 * $margin;
            $contentHeight = $this->paper->heightPx() - 2 * $margin;
            $rootFragment = (new BlockFlowContext($measurer, $catalog))
                ->layout($boxTree, new Rect(0.0, 0.0, $contentWidth, INF));

            $writer = new PdfWriter($stream);
            $writer->begin();
            // PdfCanvas/FontEmbedder siguen siendo de UNA sola cara (M1-T6): se embebe solo la
            // regular por defecto e IGNORAN faceKey; el texto en negrita se pinta temporalmente
            // con los glifos de la regular (M1-T9 completa el embedding multi-cara).
            $defaultFace = $catalog->select('default', 400, false);
            $embedder = new FontEmbedder($writer, $defaultFace->font, 'PliegoDefault');
            $canvas = new PdfCanvas($writer, $embedder, $this->paper, $margin, $margin);
            $painter = new Painter();
            $pageCount = 0;
            foreach ((new Paginator($contentHeight))->paginate($rootFragment) as $page) {
                $canvas->beginPage();
                $painter->paint($page, $canvas);
                $canvas->endPage();
                $pageCount++;
            }
            $embedder->flush();
            $writer->finish();
            return new RenderReport($parseResult->warnings, $pageCount);
        });
    }
}
