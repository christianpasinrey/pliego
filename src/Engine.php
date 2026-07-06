<?php

// src/Engine.php
declare(strict_types=1);

namespace Pliego;

use Pliego\Box\BoxTreeBuilder;
use Pliego\Css\StylesheetParser;
use Pliego\Css\Value\Length;
use Pliego\Css\WarningCollector;
use Pliego\Dom\HtmlParser;
use Pliego\Image\ImageLoader;
use Pliego\Layout\BlockFlowContext;
use Pliego\Layout\Geometry\Rect;
use Pliego\Layout\TextMeasurer;
use Pliego\Page\PageRuleFactory;
use Pliego\Page\Paginator;
use Pliego\Page\PaperSize;
use Pliego\Paint\Painter;
use Pliego\Pdf\FontRegistry;
use Pliego\Pdf\ImageRegistry;
use Pliego\Pdf\MarginBoxPainter;
use Pliego\Pdf\PdfCanvas;
use Pliego\Pdf\PdfWriter;
use Pliego\Style\CssStyleSource;
use Pliego\Style\FontStyle;
use Pliego\Style\StyleResolver;
use Pliego\Text\FontCatalog;

final class Engine
{
    private string $css = '';
    private PaperSize $paper = PaperSize::A4;
    private Length $margin;
    private string $fontPath = __DIR__ . '/../resources/fonts/DejaVuSans.ttf';
    /** @var list<array{string, int, FontStyle, string}> registros ->font() adicionales, en orden */
    private array $extraFonts = [];
    private string $basePath;

    private function __construct()
    {
        $this->margin = Length::px(48.0);
        $this->basePath = getcwd() ?: '.';
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

    /** Registra una cara (family/weight/style) adicional para embedding multi-cara. */
    public function font(string $family, int $weight, FontStyle $style, string $ttfPath): self
    {
        $this->extraFonts[] = [$family, $weight, $style, $ttfPath];
        return $this;
    }

    /** M3-T2: directorio base contra el que se resuelven los <img src="..."> relativos. */
    public function basePath(string $basePath): self
    {
        $this->basePath = $basePath;
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
            // M5-T1 (housekeeping) + M6-T4: un ÚNICO WarningCollector, compartido entre
            // StyleResolver (var()/calc(), M6-T4), BoxTreeBuilder (imágenes),
            // BlockFlowContext/FlexFormattingContext (layout) y Paginator (paginación) — antes de
            // M6-T4 solo cubría imágenes/layout/paginación; ahora RenderReport también refleja
            // limitaciones de resolución de estilos (ver los docblocks de esas clases). Se drena
            // al FINAL (ver el `$warnings = [...]` justo antes del `return`), después de consumir
            // el generador de Paginator::paginate(), para no perder los warnings que solo se
            // emiten DURANTE esa iteración.
            $layoutWarnings = new WarningCollector();
            $styles = (new StyleResolver([new CssStyleSource($parseResult)], $layoutWarnings))->resolve($document);
            $imageLoader = new ImageLoader();
            $boxTree = (new BoxTreeBuilder($imageLoader, $layoutWarnings, $this->basePath))->build($document, $styles);

            // fontFile() registra/sobreescribe la cara regular de la familia 'default'; el resto
            // de caras (bold/italic/bold-italic) siguen siendo las builtin de withDefaults().
            // font() añade caras extra (otras familias, u otros pesos/estilos de 'default').
            $catalog = FontCatalog::withDefaults();
            $catalog->register('default', 400, false, $this->fontPath);
            foreach ($this->extraFonts as [$family, $weight, $style, $ttfPath]) {
                $catalog->register($family, $weight, $style === FontStyle::Italic, $ttfPath);
            }
            $measurer = new TextMeasurer();

            // M2-T6: @page margin (Css\PageRuleData, crudo) -> Page\PageRule; sus márgenes, si
            // se declaran, OVERRIDEAN Engine::margins() lado a lado (los lados no declarados
            // conservan el margin uniforme del Engine). Los margin boxes de $pageRule quedan sin
            // pintar por ahora (T7); esta tarea solo necesita que sus márgenes fluyan hasta la
            // geometría del área de contenido y el canvas.
            $pageRuleFactory = new PageRuleFactory();
            $pageRule = $pageRuleFactory->fromCssData($parseResult->pageRule);

            $uniformMargin = $this->margin->px;
            // Nullsafe + ?? en la misma expresión dispara un falso positivo de PHPStan (ver
            // BlockFlowContext::layout()); se separa en dos sentencias como allí, por lado.
            $pageMarginTop = $pageRule?->marginTop;
            $marginTop = $pageMarginTop !== null ? $pageMarginTop->px : $uniformMargin;
            $pageMarginRight = $pageRule?->marginRight;
            $marginRight = $pageMarginRight !== null ? $pageMarginRight->px : $uniformMargin;
            $pageMarginBottom = $pageRule?->marginBottom;
            $marginBottom = $pageMarginBottom !== null ? $pageMarginBottom->px : $uniformMargin;
            $pageMarginLeft = $pageRule?->marginLeft;
            $marginLeft = $pageMarginLeft !== null ? $pageMarginLeft->px : $uniformMargin;

            $contentWidth = $this->paper->widthPx() - $marginLeft - $marginRight;
            $contentHeight = $this->paper->heightPx() - $marginTop - $marginBottom;
            $rootFragment = (new BlockFlowContext($measurer, $catalog, $layoutWarnings))
                ->layout($boxTree, new Rect(0.0, 0.0, $contentWidth, INF));

            $writer = new PdfWriter($stream);
            $writer->begin();
            $fonts = new FontRegistry($writer, $catalog);
            $images = new ImageRegistry($writer, $imageLoader);
            $canvas = new PdfCanvas($writer, $fonts, $images, $this->paper, $marginLeft, $marginTop);
            $painter = new Painter($catalog);
            // M2-T7: margin boxes with counter(pages) can't be painted while streaming (the total
            // page count is only known once every page is laid out) — see MarginBoxPainter's
            // docblock for the deferred-XObject design and PdfWriter's for the ordering contract
            // this loop must respect (writeDeferred() BEFORE flushAll() BEFORE finish()).
            $marginBoxPainter = new MarginBoxPainter(
                $writer,
                $fonts,
                $catalog,
                $measurer,
                $this->paper,
                $marginTop,
                $marginRight,
                $marginBottom,
                $marginLeft,
            );
            $pageCount = 0;
            foreach ((new Paginator($contentHeight, $layoutWarnings))->paginate($rootFragment) as $page) {
                $canvas->beginPage();
                $painter->paint($page, $canvas);
                if ($pageRule !== null) {
                    $marginBoxPainter->paintPage($pageRule, $canvas, $page->number);
                }
                $canvas->endPage();
                $pageCount++;
            }
            $writer->writeDeferred($pageCount);
            // M3-T4: images' flushAll() can run in either order relative to fonts' — neither
            // depends on the other, both just need to finish before finish() (see PdfWriter's
            // ordering-contract docblock, extended for ImageRegistry).
            $images->flushAll();
            $fonts->flushAll();
            $writer->finish();
            // M5-T1: $layoutWarnings se drena aquí, AL FINAL — el generador de Paginator::paginate()
            // ya se consumió por completo en el foreach de arriba, así que cualquier warning
            // emitido durante la paginación (además de los de BoxTreeBuilder/BlockFlowContext,
            // acumulados antes) ya está presente en el colector compartido.
            $warnings = [...$parseResult->warnings, ...$pageRuleFactory->drainWarnings(), ...$layoutWarnings->drain()];
            return new RenderReport($warnings, $pageCount);
        });
    }
}
