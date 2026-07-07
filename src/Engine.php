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
use Pliego\Image\ImagePathResolver;
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
use Pliego\Text\FontException;
use Pliego\Text\TtfFont;

final class Engine
{
    private string $css = '';
    private PaperSize $paper = PaperSize::A4;
    private Length $margin;
    private string $fontPath = __DIR__ . '/../resources/fonts/DejaVuSans.ttf';
    /** @var list<array{string, int, FontStyle, string}> registros ->font() adicionales, en orden */
    private array $extraFonts = [];
    private string $basePath;
    // M9-T4: flag, NOT the sheet's content -- Engine::bootstrap() (the static factory below,
    // alternative to make()) only marks intent on the fresh instance it returns; the actual css
    // is read and prepended in render() (see assembledCss()), so the preset ends up FIRST in the
    // parsed stylesheet no matter how many ->stylesheet() calls follow or in what relative order.
    private bool $bootstrapPreset = false;
    private const string BOOTSTRAP_PRESET_CSS_PATH = __DIR__ . '/../resources/presets/bootstrap.min.css';
    private const string BOOTSTRAP_PRESET_PRINT_ADDENDUM_PATH = __DIR__ . '/../resources/presets/bootstrap-print.css';

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

    /**
     * M9-T4: alternative entry point to make() (same "static factory returning a fresh Engine"
     * shape -- e.g. the playground's "Bootstrap preset" checkbox picks between the two: `$useIt ?
     * Engine::bootstrap() : Engine::make()`) that opts into the vendored Bootstrap preset
     * (resources/presets/bootstrap.min.css, MIT, v5.3.6 -- see LICENSE-bootstrap.txt alongside
     * it) plus pliego's own print addendum (resources/presets/bootstrap-print.css: sane @page
     * margins, since real Bootstrap ships none -- see that file's docblock). Both are queued as
     * the FIRST author-origin sheets, BEFORE every subsequent ->stylesheet() call -- author order
     * lets a same-specificity user rule win (css-cascade-4 §4.1's "declaration order" tiebreak),
     * the correct semantics for a "preset you then customize", without needing !important or
     * extra specificity on the user's side.
     *
     * A flag on the new instance, not an eager read+concat: assembledCss() (called from
     * render()) does the actual file_get_contents()+prepend, once, at render time -- so however
     * many ->stylesheet() calls follow, in whatever order relative to each other, the preset is
     * always what StylesheetParser sees first (this method itself never touches $this->css --
     * see that property's own docblock -- it only sets $bootstrapPreset on the fresh instance it
     * returns, exactly like make() sets nothing beyond the constructor's own defaults).
     */
    public static function bootstrap(): static
    {
        $engine = new self();
        $engine->bootstrapPreset = true;
        return $engine;
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
            // M10-T2 (css-mediaqueries-4, reduced): the paper's own CSS-px WIDTH threads into
            // StylesheetParser::parse() too, one line below where it already threads into
            // StyleResolver for vw/vh (M10-T1, see that call's own comment) -- (min-width: N)/
            // (max-width: N)/(width: N) media features evaluate against this SAME number
            // (Css\MediaQueryEvaluator), so a print of this page at $this->paper's size sees
            // exactly the breakpoints Chrome would apply printing a page of the same CSS-px width.
            $parseResult = (new StylesheetParser())->parse($this->assembledCss(), $this->paper->widthPx());
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
            // M9-T6 (controller addition, T5 review): a <style> element ANYWHERE in the parsed
            // document (head OR body -- getElementsByTagName() walks the whole tree, not just
            // $document->body, unlike Box\BoxTreeBuilder) is a real engine gap: its CSS is never
            // parsed nor applied (this codebase's API is CSS/HTML as two SEPARATE strings, see
            // every EndToEnd test's ->stylesheet()->render() shape) -- silently before this. One
            // warning per render (addWarningOnce), regardless of how many <style> elements exist.
            // Auto-extracting and applying it is a real feature (M10 candidate: needs its own
            // design for cascade ordering against ->stylesheet() calls) -- out of scope here.
            if ($document->getElementsByTagName('style')->length > 0) {
                $layoutWarnings->addWarningOnce(
                    'style-tag-ignored',
                    'style tags are ignored; pass CSS via stylesheet()',
                );
            }
            // M10-T1 (css-values-4 §5.1.1): the paper's own CSS-px size (Page\PaperSize, same
            // widthPx()/heightPx() the content-box math just below derives $contentWidth/Height
            // from) threads into StyleResolver so vw/vh resolve against the PAPER box, per the
            // adjudication documented on Css\Value\LengthUnit.
            $styles = (new StyleResolver([new CssStyleSource($parseResult)], $layoutWarnings, $this->paper->widthPx(), $this->paper->heightPx()))->resolve($document);
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
            // M8-T7 (css-fonts-4 §4 reducido): @font-face rules parsed above by StylesheetParser
            // -- registered into the SAME catalog, AFTER font()/fontFile() (so a stylesheet
            // @font-face can override a programmatic font() registration for the same family/
            // weight/style slot -- same "last wins" semantics FontCatalog::register() already has
            // between any two calls). $fontFaceRule->srcPath is resolved against $this->basePath
            // with the SAME convention as <img src> and background-image (Image\
            // ImagePathResolver, M8-T6). A missing or unparseable (corrupt/truncated) font file
            // is a WARNING, never fatal: the family/slot is simply left unregistered and
            // Layout\Text\FontFamilyResolver's normal fallback chain (M7-T2) takes it from there
            // at layout time. Deviation documented: this eagerly parses the TTF here just to
            // validate it (TtfFont::fromFile() throws on a missing/malformed file), then
            // FontCatalog parses it AGAIN lazily on first select() (its own fontCache is keyed by
            // path and has no way to receive an already-parsed instance via register()) -- a
            // double parse per REGISTERED @font-face family, not per glyph/page, so bounded and
            // acceptable for M8 scope.
            foreach ($parseResult->fontFaceRules as $fontFaceRule) {
                $resolvedPath = ImagePathResolver::resolve($this->basePath, $fontFaceRule->srcPath);
                try {
                    TtfFont::fromFile($resolvedPath);
                } catch (FontException $e) {
                    $layoutWarnings->addWarning(
                        "@font-face: could not load font for family '{$fontFaceRule->family}' ($resolvedPath): " . $e->getMessage(),
                    );
                    continue;
                }
                [$slotWeight, $weightWarning] = self::nearestFontFaceCatalogWeight($fontFaceRule->weight);
                if ($weightWarning !== null) {
                    $layoutWarnings->addWarning($weightWarning);
                }
                $catalog->register($fontFaceRule->family, $slotWeight, $fontFaceRule->italic, $resolvedPath);
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
            // M7-T6 (CSS 2.2 §9.4.3/§10.3.7, position:absolute): el "initial containing block" de
            // la página -- a diferencia del $containingBlock de layout() (altura INF, el
            // contenido puede desbordar y paginar libremente), este Rect SÍ lleva la altura REAL
            // del área de contenido de página, para que un `position:absolute` directamente bajo
            // la raíz (sin ningún ancestro position!=static) pueda resolver `bottom` con precisión
            // y para que el chequeo de "taller than page" (BlockFlowContext::layoutAbsoluteChild())
            // tenga una referencia real contra la que comparar.
            $rootFragment = (new BlockFlowContext($measurer, $catalog, $layoutWarnings))
                ->layout(
                    $boxTree,
                    new Rect(0.0, 0.0, $contentWidth, INF),
                    positionedCB: new Rect(0.0, 0.0, $contentWidth, $contentHeight),
                );

            $writer = new PdfWriter($stream);
            $writer->begin();
            $fonts = new FontRegistry($writer, $catalog);
            $images = new ImageRegistry($writer, $imageLoader);
            $canvas = new PdfCanvas($writer, $fonts, $images, $this->paper, $marginLeft, $marginTop);
            // M8-T2: comparte el MISMO WarningCollector que Style/Box/Layout (ver arriba) -- así
            // "mixed border widths with border-radius approximated" sale por el mismo
            // $layoutWarnings->drain() de más abajo, junto con cualquier otro warning del render.
            // M8-T6: $imageLoader (la MISMA instancia que BoxTreeBuilder ya usó para <img>, ver
            // arriba -- memoización compartida por path, dedup de decodificación) y
            // $this->basePath (el MISMO basePath que BoxTreeBuilder ya usó para resolver rutas
            // relativas) -- background-image se carga en tiempo de PINTADO, ver Paint\Painter::
            // paintBackgroundImage().
            $painter = new Painter($catalog, $imageLoader, $this->basePath, $layoutWarnings);
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

    /**
     * M9-T4: the css StylesheetParser actually parses -- $this->css (every ->stylesheet() call,
     * in their original relative order, untouched) prefixed by the vendored preset + its print
     * addendum when ->bootstrap() was called (see that method's docblock for why this is a
     * flag-then-assemble design rather than eager concatenation). Both preset files are package
     * assets that always ship with pliego (same guarantee as resources/fonts/DejaVuSans.ttf,
     * read unconditionally by $fontPath's default) -- a missing file here would mean a broken
     * install, not a user error, so file_get_contents() failing is treated as fatal (the
     * bool-false-on-failure branch below never fires in practice, but is handled rather than
     * silently feeding `false` to string concatenation, which PHP would coerce to `""` and
     * silently drop the whole preset instead of failing loudly).
     */
    private function assembledCss(): string
    {
        if (!$this->bootstrapPreset) {
            return $this->css;
        }
        $presetCss = file_get_contents(self::BOOTSTRAP_PRESET_CSS_PATH);
        $addendumCss = file_get_contents(self::BOOTSTRAP_PRESET_PRINT_ADDENDUM_PATH);
        if ($presetCss === false || $addendumCss === false) {
            throw new \RuntimeException('Engine::bootstrap(): missing vendored preset asset(s) under resources/presets/ -- broken pliego install.');
        }
        return $presetCss . "\n" . $addendumCss . "\n" . $this->css;
    }

    /**
     * M8-T7: Text\FontCatalog only supports two weight slots per family/style (400 regular, 700
     * bold — same two-weight model as its withDefaults() builtins, see FontCatalog's own
     * docblock). Any @font-face font-weight other than exactly 400/700 (e.g. 500 for a "Medium"
     * cut) is mapped to whichever of the two is numerically closer (500 -> 400, 600 -> 700, a tie
     * at 550 -> 400), with a warning — never silently dropped, never a third slot invented.
     *
     * @return array{0: int, 1: ?string}
     */
    private static function nearestFontFaceCatalogWeight(int $weight): array
    {
        if ($weight === 400 || $weight === 700) {
            return [$weight, null];
        }
        $nearest = abs($weight - 400) <= abs($weight - 700) ? 400 : 700;
        return [$nearest, "@font-face font-weight $weight approximated to $nearest (only 400/700 are supported per family/style)"];
    }
}
