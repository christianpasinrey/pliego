<?php

declare(strict_types=1);

namespace Pliego\Box;

use Pliego\Css\WarningCollector;
use Pliego\Image\ImageException;
use Pliego\Image\ImageLoader;
use Pliego\Style\ComputedStyle;
use Pliego\Style\Display;
use Pliego\Style\StyleMap;

final class BoxTreeBuilder
{
    private const array INLINE_TAGS = ['span', 'strong', 'em', 'b', 'i', 'a', 'small', 'code', 'u'];

    public function __construct(
        private readonly ImageLoader $imageLoader,
        private readonly WarningCollector $warnings,
        private readonly string $basePath,
    ) {}

    public function build(\Dom\HTMLDocument $document, StyleMap $styles): BlockBox
    {
        $body = $document->body ?? throw new \InvalidArgumentException('Document has no body');
        return $this->buildBlock($body, $styles);
    }

    private function buildBlock(\Dom\Element $element, StyleMap $styles): BlockBox
    {
        $style = $styles->get($element);
        $children = [];
        /**
         * @var list<TextRun|LineBreakRun|ImageBox> $pending secuencia inline pendiente de
         *     colapsar (M1-T4). Puede incluir ImageBox (M3-T2 defect fix): una <img> anidada
         *     dentro de un elemento inline se "hoistea" aquí como token de la secuencia — ver
         *     collectInline() — y collapse() la trata como separador de secuencia, igual que un
         *     LineBreakRun.
         */
        $pending = [];
        $flush = function () use (&$children, &$pending): void {
            foreach (self::collapse($pending) as $run) {
                $children[] = $run;
            }
            $pending = [];
        };
        foreach ($element->childNodes as $node) {
            if ($node instanceof \Dom\Text) {
                $pending[] = new TextRun(self::collapseInternalWhitespace($node->textContent ?? ''), $style);
                continue;
            }
            if (!$node instanceof \Dom\Element) {
                continue;
            }
            if ($styles->get($node)->display === Display::None) {
                continue;
            }
            $tag = strtolower($node->tagName);
            if ($tag === 'br') {
                $pending[] = new LineBreakRun();
                continue;
            }
            if ($tag === 'img') {
                $flush();
                $imageBox = $this->buildImage($node, $styles->get($node));
                if ($imageBox !== null) {
                    $children[] = $imageBox;
                }
                continue;
            }
            if (in_array($tag, self::INLINE_TAGS, true)) {
                $this->collectInline($node, $styles, $pending);
                continue;
            }
            $flush();
            $children[] = $this->buildBlock($node, $styles);
        }
        $flush();
        if ($style->display === Display::Flex) {
            $children = $this->wrapAnonymousFlexItems($children, $style);
        }
        return new BlockBox($style, $children, strtolower($element->tagName));
    }

    /**
     * css-flexbox-1 §4: cada hijo directo de un flex container se convierte en un "flex item".
     * En este punto $children ya es la secuencia final y aplanada de hijos directos del
     * contenedor (BlockBox/ImageBox directos + TextRun/LineBreakRun ya colapsados por
     * collapse(), incluida cualquier ImageBox "hoisteada" desde un inline — ver collectInline()).
     * Un tramo CONTIGUO de TextRun|LineBreakRun se envuelve en un ÚNICO BlockBox anónimo (tag
     * "anonymous") por tramo; BlockBox e ImageBox ya son items directos por sí mismos y NUNCA
     * entran en el anónimo — una ImageBox es un replaced box (css-images-3), que en flexbox es
     * su propio item directo igual que un BlockBox, así que corta el tramo de texto exactamente
     * igual que ya hace un LineBreakRun (nunca lo hace un anónimo distinto por LineBreakRun: ese
     * sigue siendo un separador DENTRO del tramo de texto, per brief M4-T2).
     *
     * El estilo del anónimo es ComputedStyle::compute([], $containerStyle, 'div'): sin
     * declaraciones propias, así que cae al initial value de todo salvo las propiedades
     * heredadas de CSS 2.2 §6.1 (color, font-*, line-height, text-align, underline...), que
     * toman el computed value de $containerStyle — nunca hereda las propiedades flex del
     * contenedor (M4-T1: ninguna de esas hereda), así que el anónimo nunca es él mismo un flex
     * container aunque su padre lo sea.
     *
     * @param list<BlockBox|TextRun|LineBreakRun|ImageBox> $children
     * @return list<BlockBox|ImageBox>
     */
    private function wrapAnonymousFlexItems(array $children, ComputedStyle $containerStyle): array
    {
        $items = [];
        /** @var list<TextRun|LineBreakRun> $run */
        $run = [];
        $flushRun = function () use (&$items, &$run, $containerStyle): void {
            if ($run === []) {
                return;
            }
            $anonymousStyle = ComputedStyle::compute([], $containerStyle, 'div');
            $items[] = new BlockBox($anonymousStyle, $run, 'anonymous');
            $run = [];
        };
        foreach ($children as $child) {
            if ($child instanceof TextRun || $child instanceof LineBreakRun) {
                $run[] = $child;
                continue;
            }
            $flushRun();
            $items[] = $child;
        }
        $flushRun();
        return $items;
    }

    /**
     * M3-T2: <img> es replaced block-level (nunca aporta a la secuencia inline de runs, brief
     * M3-T2). Errores suaves: src remoto (http/https), fichero ausente o formato no soportado
     * por Image\ImageLoader → warning en el WarningCollector compartido + null (la caja se omite,
     * nunca se lanza una excepción desde aquí). Los atributos HTML width/height solo se leen si
     * son puramente numéricos; cualquier otro valor (%, auto, vacío, ausente) se ignora en
     * silencio — no es un fallo, solo un atributo no soportado en M3.
     */
    private function buildImage(\Dom\Element $element, ComputedStyle $style): ?ImageBox
    {
        $src = $element->getAttribute('src');
        if ($src === null || $src === '') {
            $this->warnings->addWarning('Image missing src attribute');
            return null;
        }
        if (preg_match('#^https?://#i', $src) === 1) {
            $this->warnings->addWarning("remote images not supported yet: $src");
            return null;
        }
        $resolved = $this->resolvePath($src);
        try {
            $image = $this->imageLoader->load($resolved);
        } catch (ImageException $e) {
            $this->warnings->addWarning("Could not load image \"$src\": " . $e->getMessage());
            return null;
        }
        return new ImageBox(
            $style,
            $resolved,
            $image->widthPx(),
            $image->heightPx(),
            self::numericAttribute($element, 'width'),
            self::numericAttribute($element, 'height'),
        );
    }

    /** src relativo se resuelve contra basePath (Engine::basePath(), default getcwd()); un src ya
     * absoluto (unix "/..." o Windows "C:\..."/"C:/...") se usa tal cual. */
    private function resolvePath(string $src): string
    {
        $isAbsolute = str_starts_with($src, '/') || preg_match('#^[a-zA-Z]:[\\\\/]#', $src) === 1;
        return $isAbsolute ? $src : rtrim($this->basePath, '/\\') . '/' . $src;
    }

    /** Valores <= 0 se tratan como ausentes (null), igual que los navegadores ignoran un
     * width/height no positivo y recurren al tamaño intrínseco. */
    private static function numericAttribute(\Dom\Element $element, string $name): ?float
    {
        $value = $element->getAttribute($name);
        if ($value === null || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        return $number > 0 ? $number : null;
    }

    /**
     * M1-T4: recorre el subárbol de un elemento INLINE generando TextRun/LineBreakRun con el
     * ComputedStyle de CADA elemento inline propio (ya heredado del bloque vía StyleResolver),
     * en vez de aplanar a texto plano con el estilo del bloque (comportamiento M0). Cualquier
     * descendiente con display:none se poda (arregla el leak de M0). Los tags anidados no
     * necesitan estar en INLINE_TAGS: se recorren igualmente, con permisividad heredada de M0.
     *
     * M3-T2 defect fix — aproximación documentada: una <img> anidada dentro de un inline
     * (`<a href><img></a>`, `<span><img></span>`) NO tiene layout inline en M3 (los "replaced
     * inline boxes" con wrapping de línea llegarán más adelante). En vez de descartarla en
     * silencio (bug original: se recorría como elemento sin hijos y no producía nada), se
     * "hoistea" a nivel de bloque: se emite como token ImageBox dentro de la MISMA secuencia
     * `$pending` que los TextRun/LineBreakRun de alrededor (preservando el ORDEN relativo:
     * texto-antes → ImageBox → texto-después), reusando el mismo buildImage() con sus mismos
     * warnings de fallo suave (src remoto, fichero ausente, formato no soportado). `collapse()`
     * trata el token ImageBox como separador de secuencia (igual que un LineBreakRun): no se le
     * aplica el whitespace-collapsing propio de texto, ni genera espacio de frontera con el
     * texto adyacente. SIEMPRE se añade un warning adicional
     * (independiente del éxito/fallo de buildImage) para que la aproximación quede visible en
     * RenderReport — el consumidor del reporte necesita saber que el layout aquí no es fiel al
     * documento fuente.
     *
     * @param list<TextRun|LineBreakRun|ImageBox> $pending
     */
    private function collectInline(\Dom\Element $element, StyleMap $styles, array &$pending): void
    {
        $style = $styles->get($element);
        foreach ($element->childNodes as $node) {
            if ($node instanceof \Dom\Text) {
                $pending[] = new TextRun(self::collapseInternalWhitespace($node->textContent ?? ''), $style);
                continue;
            }
            if (!$node instanceof \Dom\Element) {
                continue;
            }
            if ($styles->get($node)->display === Display::None) {
                continue;
            }
            $tag = strtolower($node->tagName);
            if ($tag === 'br') {
                $pending[] = new LineBreakRun();
                continue;
            }
            if ($tag === 'img') {
                $src = $node->getAttribute('src') ?? '';
                // M5-T1 (housekeeping): un src vacío ya no arrastra un "): " colgante y feo al
                // final del mensaje -- el sufijo con el src solo se añade cuando hay algo que
                // mostrar.
                $message = 'inline image hoisted to block level (inline replaced boxes not supported yet)';
                $this->warnings->addWarning($src === '' ? $message : "$message: $src");
                $imageBox = $this->buildImage($node, $styles->get($node));
                if ($imageBox !== null) {
                    $pending[] = $imageBox;
                }
                continue;
            }
            $this->collectInline($node, $styles, $pending);
        }
    }

    private static function collapseInternalWhitespace(string $raw): string
    {
        return preg_replace('/\s+/', ' ', $raw) ?? '';
    }

    /**
     * Colapsa una secuencia completa de runs de un bloque (CSS 2.2 §16.6.1 simplificado):
     * el whitespace interno de cada chunk ya viene reducido a un único espacio
     * (collapseInternalWhitespace); aquí se recorta el espacio inicial/final de la secuencia
     * y se conserva EXACTAMENTE un espacio de frontera entre chunks adyacentes cuando alguno
     * de los dos lados aportaba whitespace. Convención (documentada en el brief M1-T4): el
     * espacio de frontera se adjunta SIEMPRE al final del run YA EMITIDO precedente, nunca
     * como prefijo del run siguiente — así "Hola <b>mundo</b>!" da "Hola ", "mundo", "!" y
     * "Hola <b> mundo</b>" (doble espacio de frontera) colapsa a "Hola ", "mundo". Un <br>
     * corta la secuencia: reinicia el recorte de inicio/fin igual que un límite de bloque.
     * Runs adyacentes con el mismo ComputedStyle (p.ej. texto partido por un display:none
     * podado en medio) se fusionan en un único TextRun.
     *
     * M3-T2 defect fix: un token ImageBox (imagen inline "hoisteada" por collectInline()) se
     * trata como separador de secuencia exactamente igual que un LineBreakRun — corta el
     * recorte de inicio/fin y no participa del whitespace-collapsing de texto — pero, a
     * diferencia de LineBreakRun, no es un marcador: es la propia caja de imagen, que queda
     * intercalada en el mismo orden en que apareció en el DOM entre el texto anterior y el
     * posterior.
     *
     * @param list<TextRun|LineBreakRun|ImageBox> $tokens
     * @return list<TextRun|LineBreakRun|ImageBox>
     */
    private static function collapse(array $tokens): array
    {
        $result = [];
        // Se muta el run precedente con array_pop()+push() (nunca por índice) para que
        // PHPStan siga viendo $result como list<...> de principio a fin.
        $lastText = null;
        $pendingSpace = false;
        foreach ($tokens as $token) {
            if ($token instanceof LineBreakRun || $token instanceof ImageBox) {
                $result[] = $token;
                $lastText = null;
                $pendingSpace = false;
                continue;
            }
            $text = $token->text;
            $leading = str_starts_with($text, ' ');
            $trailing = str_ends_with($text, ' ');
            $core = trim($text, ' ');
            $needsBoundarySpace = $pendingSpace || $leading;
            if ($core === '') {
                $pendingSpace = $pendingSpace || $leading || $trailing;
                continue;
            }
            if ($lastText instanceof TextRun && $lastText->style === $token->style) {
                array_pop($result);
                $lastText = new TextRun($lastText->text . ($needsBoundarySpace ? ' ' : '') . $core, $token->style);
                $result[] = $lastText;
            } else {
                if ($needsBoundarySpace && $lastText instanceof TextRun) {
                    array_pop($result);
                    $lastText = new TextRun($lastText->text . ' ', $lastText->style);
                    $result[] = $lastText;
                }
                $lastText = new TextRun($core, $token->style);
                $result[] = $lastText;
            }
            $pendingSpace = $trailing;
        }
        return $result;
    }
}
