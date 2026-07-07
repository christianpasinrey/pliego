<?php

declare(strict_types=1);

namespace Pliego\Css;

use Pliego\Css\Value\FontFaceRule;
use Pliego\Css\Value\Length;
use Sabberworm\CSS\Parser as SabberwormParser;

/**
 * @page (T2): probado con un probe manual (ver report) que sabberworm/php-css-parser 8.9
 * MANGLA los at-rules anidados dentro de @page — el `content` de un `@top-center` termina
 * fusionado directamente en el AtRuleSet de "page" (perdiendo qué margin-box lo declaró), y un
 * segundo margin-box (p.ej. `@bottom-right`) aparece como AtRuleSet HERMANO de nivel superior en
 * vez de anidado. Por eso @page se extrae con un tokenizer-lite de brace-matching ANTES de pasar
 * el resto de la hoja a sabberworm (que sigue gestionando reglas normales sin problema).
 *
 * M8-T7: @font-face se extrae con el MISMO tokenizer-lite (misma justificación — un at-rule de
 * nivel superior con su propio cuerpo de declaraciones, sin selectores, que sabberworm no tiene
 * por qué tratar mejor que @page) vía extractAtRuleBlocks(), generalizado a partir de
 * extractAtPageBlocks() para que ambos at-rules compartan el brace-matching en vez de duplicarlo.
 *
 * M9-T2 (@media, css-conditional-3): probado a mano contra sabberworm/php-css-parser 8.9 —
 * Document::getAllDeclarationBlocks() RECURSA dentro de un AtRuleBlockList (la clase que sabberworm
 * usa para @media, ver CSSBlockList::allDeclarationBlocks()) SIN mirar nunca el nombre del at-rule
 * ni sus argumentos: hoy, un `@media (min-width: 768px) { .x { color: red } }` aplica SIEMPRE, como
 * si no existiera el @media -- exactamente el mismo "sabberworm mangla anidados" que @page (ver
 * arriba) pero peor: aquí ni siquiera hay pérdida de información, hay APLICACIÓN INCORRECTA de
 * reglas condicionadas a un medium (bootstrap.min.css real está lleno de breakpoints
 * min-width/max-width y de `@media (prefers-reduced-motion: reduce)` que nunca deberían alcanzar un
 * documento impreso). Se resuelve con el MISMO patrón de extractor propio + brace-matching que
 * @page/@font-face, pero @media NO puede compartir extractAtRuleBlocks() sin más: esos dos
 * descartan el bloque entero tras extraerlo (su contenido se interpreta aparte, nunca vuelve al
 * css principal); @media en cambio necesita DECIDIR si su contenido vuelve a quedar en el css que
 * sabberworm procesará (medium aplica) o se descarta (no aplica) -- ver resolveMediaBlocks().
 *
 * Adjudicado (RESTRICCIONES GLOBALES M9-T2): evaluación de media query -- 'print' y 'all'
 * (case-insensitive, ignorando espacios) APLICAN sus reglas al mismo nivel de autor que el resto
 * de la hoja (orden de documento preservado, ver más abajo); M10-T1 (css-mediaqueries-3 §2.3): un
 * prefijo 'only' (p.ej. 'only print'/'only all') se NORMALIZA fuera antes de esa comparación --
 * 'only' no aporta semántica de evaluación propia (solo existe para ocultar la query completa a
 * UAs pre-css3), así que 'only print'/'only all' aplican exactamente igual que 'print'/'all' sin
 * el prefijo, y 'only screen' sigue sin aplicar, igual que 'screen' a secas.
 * M10-T2 (css-mediaqueries-4, reducido): la evaluación en sí se EXTRAE a MediaQueryEvaluator (ya no
 * vive en este fichero, ver esa clase para la gramática completa) y deja de ser dos-valores-fijos --
 * `(min-width: N)`/`(max-width: N)`/`(width: N)` (px/rem/em) se comparan REALMENTE contra el ancho
 * de página en CSS px ($pageWidthPx, threadeado desde Engine::render() -> parse(), MISMO patrón que
 * vw/vh de M10-T1 -- ver parse()'s propio parámetro), y combinadores 'and'/listas separadas por
 * comas (OR) se resuelven de verdad. `screen` (sola o combinada) sigue sin aplicar NUNCA, y
 * cualquier feature desconocida (`hover`, `prefers-reduced-motion`, `prefers-color-scheme`, ...)
 * sigue siendo un DESCARTE conservador -- sin logging individual, solo UN warning agregado al
 * final de parse() con el total de bloques descartados (bootstrap real dispara 78 de estos tras
 * esta tarea, ver MediaQueryEvaluatorTest/BootstrapIngestionTest para el desglose exacto; antes de
 * esta tarea eran 108, todos los no-print/all). @media anidado hereda la decisión del @media
 * exterior: si el exterior no aplica, su cuerpo entero se descarta sin mirar dentro (ni siquiera
 * para contar anidados por separado -- "1 bloque descartado", no "1 + los que tuviera dentro"); si
 * el exterior SÍ aplica, cualquier @media anidado en su cuerpo se evalúa de forma independiente y
 * recursiva (puede perfectamente ser `@media print { @media (hover: hover) { ... } }`, descartándose
 * solo el anidado). "Aplican al mismo nivel de autor, orden preservado" se logra literalmente: el
 * cuerpo de un bloque que aplica se PEGA de vuelta en el css, en el mismo sitio donde estaba el
 * `@media print { ... }` -- así StylesheetParser::parse() ve un css COMPLETAMENTE PLANO (sin @media
 * supervivientes) en el mismo orden textual del fichero original, y el $order++ de siempre (ver
 * parse()) cae exactamente donde caería sin este paso.
 */
final class StylesheetParser
{
    /** css-page-3 §6.5.3: las 6 cajas de margen soportadas en M2 (de las 16 posibles). */
    private const array MARGIN_BOX_NAMES = [
        'top-left', 'top-center', 'top-right',
        'bottom-left', 'bottom-center', 'bottom-right',
    ];
    private const array PAGE_MARGIN_LONGHANDS = ['margin-top', 'margin-right', 'margin-bottom', 'margin-left'];

    /**
     * M10-T2 (css-mediaqueries-4, reduced): default page width for a parse() call that doesn't
     * thread one explicitly (mostly tests, plus UserAgentStylesheet::css() which has no @media at
     * all) — A4 in CSS px, SAME literal/formula StyleResolver's own DEFAULT_PAGE_WIDTH_PX and
     * Page\PaperSize::widthPx() use, duplicated here because Css cannot depend on Page (deptrac
     * boundary, see that class's own docblock for the identical reasoning). Engine::render()
     * threads the REAL configured paper width explicitly (see its own call site) — this default
     * only matters for callers that don't.
     */
    private const float DEFAULT_PAGE_WIDTH_PX = 210.0 / 25.4 * 96.0;

    private readonly MediaQueryEvaluator $mediaQueryEvaluator;

    public function __construct()
    {
        $this->mediaQueryEvaluator = new MediaQueryEvaluator();
    }

    public function parse(string $css, float $pageWidthPx = self::DEFAULT_PAGE_WIDTH_PX): ParseResult
    {
        $css = $this->stripComments($css);
        // M10-T3 (css-cascade-5, reduced -- see resolveLayers()'s own docblock for the full
        // algorithm): @layer resolved FIRST, before @media -- a real Tailwind v4 build's layers
        // (theme/base/components/utilities) never nest a @media inside a @layer in practice, but
        // if one did, flattening layers first means @media's own brace-matching (below) sees
        // perfectly ordinary top-level text, no special-casing needed on that side.
        [$css, $importantInLayer] = $this->resolveLayers($css);
        // M9-T2: @media resuelto ANTES de @font-face/@page (ver docblock de clase) -- un bloque
        // `@media print { @font-face { ... } }` (raro, pero legal) debe quedar aplanado a un
        // `@font-face` de nivel superior para que extractAtRuleBlocks() lo vea a continuación.
        [$css, $mediaSkippedCount] = $this->resolveMediaBlocks($css, $pageWidthPx);
        // M10-T3 (css-properties-values-api-1, out of scope): @property registers a custom
        // property's syntax/inheritance/initial-value for typed-custom-property animation and
        // validation -- none of which this static print engine implements (no animation, and a
        // custom property's VALUE already flows through unchanged regardless of its declared
        // syntax, see VarResolver). Extracted and dropped exactly like @font-face/@page (same
        // extractAtRuleBlocks() brace-matcher), but with ONE aggregated warning (like @media's
        // skip count) rather than one per rule -- a real Tailwind v4 build emits dozens of these
        // (one per animatable custom property, e.g. every `--tw-rotate-x`/`--tw-gradient-from`),
        // and they are all the same kind of gap, not 40 distinct ones worth reading individually.
        [$css, $propertyBodies] = $this->extractAtRuleBlocks($css, 'property');
        [$css, $fontFaceBodies] = $this->extractAtRuleBlocks($css, 'font-face');
        $fontFaceRules = [];
        $fontFaceWarnings = [];
        foreach ($fontFaceBodies as $body) {
            [$fontFaceRule, $warns] = $this->parseFontFaceBody($body);
            $fontFaceWarnings = [...$fontFaceWarnings, ...$warns];
            if ($fontFaceRule !== null) {
                $fontFaceRules[] = $fontFaceRule;
            }
        }

        [$css, $pageBodies] = $this->extractAtPageBlocks($css);
        $pageRule = null;
        $pageWarnings = [];
        foreach ($pageBodies as $body) {
            [$margins, $marginBoxes, $warnings] = $this->parsePageRuleBody($body);
            $pageWarnings = [...$pageWarnings, ...$warnings];
            // Última regla @page del documento gana (mismo criterio "last wins" que el resto
            // del cascade de este parser).
            $pageRule = new PageRuleData($margins, $marginBoxes);
        }

        $document = (new SabberwormParser($css))->parse();
        $declarationParser = new DeclarationParser();
        $selectorWarnings = new WarningCollector();
        $selectorParser = new SelectorParser($selectorWarnings);
        $rules = [];
        $warnings = [...$fontFaceWarnings, ...$pageWarnings];
        // M9-T2: UN único warning agregado (nunca uno por bloque, ver docblock de clase) -- el
        // conteo ya está cerrado en $mediaSkippedCount desde el paso de resolución, arriba. Texto
        // fijo (brief M9-T2, RESTRICCIONES GLOBALES): "N @media rule blocks skipped
        // (screen/interactive-only media)" -- "blocks" siempre en plural, incluso para N=1, para
        // que el mensaje sea grep-eable con un patrón estable independientemente del conteo.
        if ($mediaSkippedCount > 0) {
            $warnings[] = "$mediaSkippedCount @media rule blocks skipped (screen/interactive-only media)";
        }
        // M10-T3: same "one aggregated warning" shape as @media's skip count above -- see parse()'s
        // own docblock comment at the extraction call site.
        if ($propertyBodies !== []) {
            $propertySkippedCount = count($propertyBodies);
            $warnings[] = "$propertySkippedCount @property rule blocks skipped (not supported)";
        }
        // M10-T3 (css-cascade-5 §4.4, reduced -- see resolveLayers()'s docblock): a real cross-
        // layer !important INVERTS layer order (an !important in an earlier-declared layer beats
        // one in a later layer); this engine does NOT implement that inversion -- !important is
        // applied with its NORMAL (non-inverted) precedence regardless of which layer it came
        // from, same as StyleResolver already does for every other important-tier declaration.
        // ONE warning for the whole document (not one per !important-in-a-layer occurrence,
        // deliberately noisier-averse like the two aggregated warnings above) -- adjudicated exact
        // wording per the M10-T3 brief.
        if ($importantInLayer) {
            $warnings[] = 'layered !important uses simplified precedence (no cross-layer inversion, css-cascade-5 §4.4)';
        }
        $order = 0;
        foreach ($document->getAllDeclarationBlocks() as $block) {
            // M6 final-review fix (Finding 1, CSS 2.2 §6.4.2): un bloque puede mezclar
            // declaraciones !important y normales — se acumulan en DOS mapas separados (mismo
            // tipado/expansión de shorthand de siempre, ver mergeDeclaration()) para poder emitir
            // hasta DOS StyleRule por selector más abajo, uno por tier (ver StyleRule).
            $normalDeclarations = [];
            $importantDeclarations = [];
            foreach ($block->getRules() as $rule) {
                $property = trim($rule->getRule());
                $rawValue = trim((string) $rule->getValue());
                if ($rule->getIsImportant()) {
                    $importantDeclarations = $this->mergeDeclaration($importantDeclarations, $property, $rawValue, $declarationParser);
                } else {
                    $normalDeclarations = $this->mergeDeclaration($normalDeclarations, $property, $rawValue, $declarationParser);
                }
            }
            $warnings = [...$warnings, ...$declarationParser->drainWarnings()];
            foreach ($block->getSelectors() as $sabberwormSelector) {
                $selectorString = is_string($sabberwormSelector) ? $sabberwormSelector : $sabberwormSelector->getSelector();
                $selector = $selectorParser->parse($selectorString);
                $warnings = [...$warnings, ...$selectorWarnings->drain()];
                if ($selector === null) {
                    continue;
                }
                if ($normalDeclarations !== []) {
                    $rules[] = new StyleRule($selector, $normalDeclarations, $order++);
                }
                if ($importantDeclarations !== []) {
                    $rules[] = new StyleRule($selector, $importantDeclarations, $order++, important: true);
                }
            }
        }
        return new ParseResult($rules, $warnings, $pageRule, $fontFaceRules);
    }

    /**
     * Tipa una única declaración cruda (propiedad + valor, ya sin el sufijo "!important" — lo
     * despoja Sabberworm, ver Rule::parse()) y la fusiona en $target, con las MISMAS tres ramas
     * de siempre (custom property cruda / DeferredDeclaration si hay var() / DeclarationParser
     * normal) — solo que ahora $target es uno de los dos mapas (normal/important) que decide el
     * llamador según $rule->getIsImportant(), en vez del único mapa $declarations de antes de
     * esta tarea (fast path idéntico, cero regresión de tipado).
     *
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function mergeDeclaration(array $target, string $property, string $rawValue, DeclarationParser $declarationParser): array
    {
        // css-variables-1 §2: una custom property (--x) se captura CRUDA, sin tipar nunca (ni
        // siquiera cuando no contiene var()) — su valor final depende del elemento (herencia +
        // cascade), y css-variables-1 exige case-sensitivity real (--Sp !== --sp), así que NO se
        // pasa por strtolower() como el resto de propiedades (ver DeclarationParser::parse(),
        // que sí lo hace).
        if (str_starts_with($property, '--')) {
            $target[$property] = $rawValue;
            return $target;
        }
        // M6-T4: cualquier declaración cuyo valor contenga var(...) se difiere COMPLETA (valor
        // crudo, propiedad tal cual — shorthand sin expandir) porque su tipado definitivo
        // depende de las custom properties heredadas del elemento, que solo StyleResolver
        // conoce (compute-time, por elemento) — ver DeferredDeclaration. Las reglas SIN var()
        // siguen tipándose aquí mismo, en tiempo de parseo (fast path intacto, cero regresión
        // para el 99% de las hojas de estilo sin variables).
        if (str_contains($rawValue, 'var(')) {
            $target[strtolower($property)] = new DeferredDeclaration($rawValue);
            return $target;
        }
        foreach ($declarationParser->parse($property, $rawValue) as $parsedProperty => $value) {
            $target[$parsedProperty] = $value;
        }
        return $target;
    }

    /**
     * Extrae todos los bloques @page de nivel superior — delegado en extractAtRuleBlocks() (M8-T7,
     * generalizado a partir de esta misma implementación para que @font-face la comparta).
     *
     * @return array{0: string, 1: list<string>} [cssSinPage, bodies]
     */
    private function extractAtPageBlocks(string $css): array
    {
        return $this->extractAtRuleBlocks($css, 'page');
    }

    /**
     * M8 final-review Finding D (bundled Minor 4): strips CSS comments from the ENTIRE stylesheet
     * BEFORE either at-rule extraction (extractAtRuleBlocks(), see its own docblock) runs. Bug
     * this closes: extractAtRuleBlocks()'s regex used to scan the RAW css, so a comment merely
     * MENTIONING "@font-face"/"@page" (a comment whose text contains the literal substring
     * "@font-face", followed by an unrelated rule) matched that literal text inside the comment --
     * its `[^{]*` then greedily ate everything up to the NEXT real opening brace in the document
     * (the FOLLOWING rule's own `{`), so the brace-matcher captured that rule's declarations as if
     * they were the @font-face BODY, deleted the whole rule from the css handed to sabberworm, and
     * (that "body" having no family/src) dropped it with bogus descriptor warnings. Same latent
     * hijack existed for @page.
     *
     * A plain regex strip is UNSAFE here: a `url()`/quoted string can legitimately contain a
     * comment-OPEN-like substring with no matching comment-CLOSE nearby (see the "literal
     * slash-star inside url()" test) -- a naive stripper would treat that as a comment start and
     * eat everything up to some LATER, unrelated comment-close token elsewhere in the sheet. This
     * is instead a conservative character-by-character state machine: single/double-quoted
     * strings are copied VERBATIM (a comment-like substring inside one never triggers comment
     * mode); outside any string, a comment-open token enters comment mode until the matching
     * comment-close token (or end of string, if unterminated -- treated as "rest of the sheet is a
     * comment", same as a real CSS tokenizer). Each stripped comment is replaced by a single space
     * (never simply deleted), so two tokens that only had a comment between them don't
     * accidentally fuse into one. No backslash-escape handling inside strings -- out of scope for
     * this conservative fix, same "reduced" spirit as SelectorParser/DeclarationParser, neither of
     * which handles CSS escapes either.
     */
    private function stripComments(string $css): string
    {
        $result = '';
        $length = strlen($css);
        $quote = null;
        $i = 0;
        while ($i < $length) {
            $char = $css[$i];
            if ($quote !== null) {
                $result .= $char;
                if ($char === $quote) {
                    $quote = null;
                }
                $i++;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $result .= $char;
                $i++;
                continue;
            }
            if ($char === '/' && $i + 1 < $length && $css[$i + 1] === '*') {
                $close = strpos($css, '*/', $i + 2);
                $i = $close === false ? $length : $close + 2;
                $result .= ' ';
                continue;
            }
            $result .= $char;
            $i++;
        }
        return $result;
    }

    /**
     * M8-T7: generalización de la extracción de @page (brace-matching manual, ver docblock de
     * clase) a cualquier at-rule de nivel superior identificado por nombre — @font-face es el
     * segundo consumidor. Sustituye cada bloque encontrado por un espacio en el CSS que se le
     * pasa a sabberworm, exactamente igual que antes de esta tarea para @page.
     *
     * M8 final-review Finding D: $css llega aquí YA sin comentarios (stripComments(), llamado al
     * principio de parse() antes de la primera invocación de este método) -- este método en sí no
     * necesita saber nada de comentarios, el fix vive enteramente aguas arriba.
     *
     * @return array{0: string, 1: list<string>} [cssSinElAtRule, bodies]
     */
    private function extractAtRuleBlocks(string $css, string $atRuleName): array
    {
        $bodies = [];
        $offset = 0;
        $pattern = '/@' . preg_quote($atRuleName, '/') . '\b[^{]*\{/i';
        while (preg_match($pattern, $css, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $matchStart = (int) $m[0][1];
            $openBrace = $matchStart + strlen((string) $m[0][0]) - 1;
            $closeBrace = $this->findMatchingBrace($css, $openBrace);
            if ($closeBrace === null) {
                break;
            }
            $bodies[] = substr($css, $openBrace + 1, $closeBrace - $openBrace - 1);
            $css = substr($css, 0, $matchStart) . ' ' . substr($css, $closeBrace + 1);
            $offset = $matchStart;
        }
        return [$css, $bodies];
    }

    /**
     * M9-T2 (css-conditional-3, ver docblock de clase): recorre el css buscando `@media ... { ... }`
     * de nivel superior (brace-matching manual, MISMO findMatchingBrace() que @page/@font-face), y
     * para cada uno decide con mediaQueryApplies() si su cuerpo se PEGA de vuelta en el css (medio
     * print/all) o se descarta entero (cualquier otra cosa). No comparte extractAtRuleBlocks(): ese
     * método SIEMPRE saca el bloque del css principal (@page/@font-face se interpretan aparte); este
     * necesita, en el caso "aplica", REINSERTAR el cuerpo tal cual en el mismo sitio -- css
     * completamente plano en el orden textual original, para que StylesheetParser::parse() (más
     * abajo, después de este paso) asigne $order exactamente como si el @media nunca hubiera
     * existido.
     *
     * Un bloque que APLICA se resuelve RECURSIVAMENTE antes de reinsertarse (un `@media print` puede
     * a su vez envolver un `@media (hover: hover)` que sí debe evaluarse por su cuenta) -- el
     * resultado ya no contiene ningún `@media` sin resolver, así que reanudar el escaneo en
     * $matchStart (mismo patrón de offset que extractAtRuleBlocks()) nunca vuelve a encontrar un
     * @media ya resuelto ahí dentro, solo avanza hacia el resto del documento.
     *
     * Un bloque que NO aplica se descarta ENTERO (sustituido por un espacio, igual que
     * extractAtRuleBlocks()) SIN recursar dentro de su cuerpo -- "el @media exterior decide por el
     * anidado" (RESTRICCIONES GLOBALES M9-T2): un `@media screen { @media print { p{color:red} } }`
     * cuenta como UN solo bloque descartado, no dos, y el `p{color:red}` de dentro nunca se evalúa
     * ni se parsea (sería observable si tuviera una regla inválida que debiera generar warning
     * propio -- deliberadamente no lo hace, todo el subárbol es letra muerta bajo un medio que no
     * aplica).
     *
     * @return array{0: string, 1: int} [cssConMediaResuelto, bloquesDescartadosTotal]
     */
    private function resolveMediaBlocks(string $css, float $pageWidthPx): array
    {
        $skipped = 0;
        $offset = 0;
        while (preg_match('/@media\b([^{]*)\{/i', $css, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $matchStart = (int) $m[0][1];
            $query = trim((string) $m[1][0]);
            $openBrace = $matchStart + strlen((string) $m[0][0]) - 1;
            $closeBrace = $this->findMatchingBrace($css, $openBrace);
            if ($closeBrace === null) {
                break;
            }
            $body = substr($css, $openBrace + 1, $closeBrace - $openBrace - 1);
            if ($this->mediaQueryEvaluator->applies($query, $pageWidthPx)) {
                [$resolvedBody, $nestedSkipped] = $this->resolveMediaBlocks($body, $pageWidthPx);
                $skipped += $nestedSkipped;
                $css = substr($css, 0, $matchStart) . $resolvedBody . substr($css, $closeBrace + 1);
            } else {
                $skipped++;
                $css = substr($css, 0, $matchStart) . ' ' . substr($css, $closeBrace + 1);
            }
            $offset = $matchStart;
        }
        return [$css, $skipped];
    }

    /**
     * M10-T3 (css-cascade-5 §4.4, reduced -- "Tailwind solo necesita theme<base<components<
     * utilities", per the brief's own RESTRICCIONES GLOBALES): Tailwind v4's CLI output wraps
     * EVERYTHING (its custom-property theme, the preflight reset, and every utility class) in
     * named `@layer` blocks, plus two BARE declaration statements up front with no body at all
     * (`@layer properties;` then `@layer theme, base, components, utilities;`) that exist purely
     * to PIN the cascade order of those five layer names before any of them is ever given a body
     * -- css-cascade-5's actual rule: "the order of layers is the order in which the FIRST
     * `@layer` (block OR bare statement) for that name appears anywhere in the stylesheet",
     * completely independent of where each layer's declarations physically sit in the file
     * afterwards.
     *
     * Design (brief's own words: "simplest correct = reorder the extracted blocks' rules into the
     * output stream by layer rank, stable within layer"): a single top-level, offset-driven scan
     * (same brace-matching machinery as resolveMediaBlocks()/extractAtRuleBlocks()) walks the css
     * once, and for every `@layer` construct it finds:
     *  - a BARE statement (`@layer a, b, c;`, no body) registers each comma-separated name in
     *    $order (if not already present) and is removed from the css with no body to carry.
     *  - a NAMED block (`@layer name { ... }`) registers `name` in $order (if new) and APPENDS its
     *    body text to that name's bucket in $bodies -- "appends" because css-cascade-5 allows the
     *    SAME layer name to reopen with more than one block; every occurrence contributes to the
     *    SAME layer rank (the first one it ever saw), never a new one, so a later re-opening of an
     *    EARLIER-ranked layer does not "bump" it -- see StylesheetParserTest's multi-block case.
     *  - an ANONYMOUS block (`@layer { ... }`, no name) gets a synthetic, never-colliding key
     *    (`"\0anon0"`, `"\0anon1"`, ...) registered at ITS OWN textual position -- "its own rank
     *    slot" per the milestone interface note, since an anonymous layer can never be reopened by
     *    name (nothing else can ever reference it) so every occurrence is necessarily distinct.
     *
     * After the scan, $css itself (with every @layer construct removed, replaced by a single
     * space each) holds whatever text was NEVER inside any @layer at all -- concatenating the
     * ordered per-layer buckets FIRST and this un-layered remainder LAST is the entire mechanism
     * that makes "un-layered rules win at equal specificity" true: StylesheetParser::parse()'s own
     * `$order++` counter (below, in the caller) then assigns the un-layered rules the HIGHEST
     * $order values of the whole document, and StyleResolver already treats a higher $order as the
     * tie-breaking winner at equal specificity (same mechanism that makes "later rule in the same
     * file wins" true for ordinary un-layered CSS, completely unchanged by this task).
     *
     * Recursion note (documented divergence, brief-adjudicated "reduced"): this method does NOT
     * recurse into an already-extracted layer body to resolve a NESTED `@layer` inside it --
     * Tailwind v4's real generated output never nests one (verified against the vendored fixture,
     * see tests/Fixtures/tailwind/tailwind-output.css), so this is a deliberate scope narrowing,
     * not an oversight; a hand-authored stylesheet that nests `@layer` INSIDE another `@layer`
     * block would have that inner construct pass through unresolved into whatever consumes the
     * outer layer's body next (@media resolution, then sabberworm) — likely misinterpreted, same
     * "reduced, documented" spirit as every other explicitly-scoped-down at-rule in this class.
     *
     * !important note: this method ALSO detects (via a single case-insensitive substring scan per
     * collected body -- declarations are still raw text here, sabberworm has not tokenized them
     * yet) whether ANY declaration inside ANY named/anonymous layer body used `!important`, so the
     * caller (parse()) can raise the ONE adjudicated warning about simplified (non-inverted)
     * cross-layer !important precedence -- see that call site's own docblock for why the
     * PRECEDENCE ITSELF is not touched here, only the DETECTION.
     *
     * @return array{0: string, 1: bool} [cssConLayersResueltos, huboImportantDentroDeUnLayer]
     */
    private function resolveLayers(string $css): array
    {
        /** @var list<string> $order */
        $order = [];
        /** @var array<string, string> $bodies */
        $bodies = [];
        $importantInLayer = false;
        $anonCounter = 0;
        $offset = 0;
        while (preg_match('/@layer\b([^{;]*)([{;])/i', $css, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $matchStart = (int) $m[0][1];
            $namesPart = trim((string) $m[1][0]);
            $terminator = $m[2][0];
            if ($terminator === ';') {
                $statementEnd = $matchStart + strlen((string) $m[0][0]);
                foreach (explode(',', $namesPart) as $name) {
                    $name = trim($name);
                    if ($name !== '' && !in_array($name, $order, true)) {
                        $order[] = $name;
                    }
                }
                $css = substr($css, 0, $matchStart) . ' ' . substr($css, $statementEnd);
                $offset = $matchStart;
                continue;
            }
            $openBrace = $matchStart + strlen((string) $m[0][0]) - 1;
            $closeBrace = $this->findMatchingBrace($css, $openBrace);
            if ($closeBrace === null) {
                break;
            }
            $body = substr($css, $openBrace + 1, $closeBrace - $openBrace - 1);
            $key = $namesPart === '' ? "\0anon" . $anonCounter++ : $namesPart;
            if (!in_array($key, $order, true)) {
                $order[] = $key;
            }
            $bodies[$key] = ($bodies[$key] ?? '') . ' ' . $body;
            if (!$importantInLayer && preg_match('/!\s*important/i', $body) === 1) {
                $importantInLayer = true;
            }
            $css = substr($css, 0, $matchStart) . ' ' . substr($css, $closeBrace + 1);
            $offset = $matchStart;
        }
        $layered = '';
        foreach ($order as $key) {
            $layered .= ($bodies[$key] ?? '') . ' ';
        }
        return [$layered . $css, $importantInLayer];
    }

    /**
     * css-fonts-4 §4 reducido: family (comillas despojadas) + src (fallback list separada por
     * comas, ver parseFontFaceSrc() — solo TTF/OTF local en M8) + font-weight (400/700/normal/
     * bold/cualquier numérico 100-900; un rango "100 900" toma el PRIMER valor + warning, ver
     * parseFontFaceWeight()) + font-style (normal/italic, ver parseFontFaceStyle()). Cualquier
     * otro descriptor (unicode-range, font-stretch, font-display, ...) se ignora con warning —
     * nunca aborta la regla completa. Sin family, o sin un src usable, SÍ descarta la regla
     * entera (con warning): no tiene sentido registrar una cara sin nombre o sin fichero.
     *
     * @return array{0: ?FontFaceRule, 1: list<string>}
     */
    private function parseFontFaceBody(string $body): array
    {
        $warnings = [];
        $family = null;
        $srcValue = null;
        $weight = 400;
        $italic = false;
        foreach (array_filter(array_map('trim', explode(';', $body))) as $declaration) {
            if (!str_contains($declaration, ':')) {
                $warnings[] = "Unsupported @font-face descriptor: $declaration";
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $declaration, 2));
            $name = strtolower($name);
            if ($name === 'font-family') {
                $family = trim($value, "\"' \t\n\r\0\x0B");
                continue;
            }
            if ($name === 'src') {
                $srcValue = $value;
                continue;
            }
            if ($name === 'font-weight') {
                [$weight, $weightWarning] = $this->parseFontFaceWeight($value);
                if ($weightWarning !== null) {
                    $warnings[] = $weightWarning;
                }
                continue;
            }
            if ($name === 'font-style') {
                [$italic, $styleWarning] = $this->parseFontFaceStyle($value);
                if ($styleWarning !== null) {
                    $warnings[] = $styleWarning;
                }
                continue;
            }
            if ($name === 'unicode-range') {
                $warnings[] = "@font-face unicode-range is not supported; the whole font is loaded: $value";
                continue;
            }
            $warnings[] = "Unsupported @font-face descriptor: $name";
        }

        if ($family === null || $family === '') {
            $warnings[] = 'Missing font-family in @font-face rule; rule dropped';
            return [null, $warnings];
        }
        if ($srcValue === null) {
            $warnings[] = "Missing src in @font-face rule for family '$family'; rule dropped";
            return [null, $warnings];
        }

        [$path, $srcWarnings] = $this->parseFontFaceSrc($srcValue);
        $warnings = [...$warnings, ...$srcWarnings];
        if ($path === null) {
            $warnings[] = "No usable local TTF/OTF src for @font-face family '$family'; rule dropped";
            return [null, $warnings];
        }

        return [new FontFaceRule($family, $path, $weight, $italic), $warnings];
    }

    /**
     * css-fonts-4 §4 reducido: recorre la lista de `src` separada por comas EN ORDEN (fallback
     * list de CSS), devolviendo la ruta de la PRIMERA entrada usable — un url() local .ttf/.otf.
     * woff/woff2 y remotos (http/https) generan warning y se SALTAN (se prueba la siguiente
     * entrada); local() también se salta con warning (M8 no tiene acceso a fuentes de sistema).
     * Si ninguna entrada es usable, devuelve null (el llamador descarta la regla entera).
     *
     * @return array{0: ?string, 1: list<string>}
     */
    private function parseFontFaceSrc(string $value): array
    {
        $warnings = [];
        foreach (array_map('trim', explode(',', $value)) as $entry) {
            if ($entry === '') {
                continue;
            }
            if (preg_match('/^local\(/i', $entry) === 1) {
                $warnings[] = "@font-face local() is not supported (no system font access): $entry";
                continue;
            }
            if (preg_match('/^url\(\s*([\'"]?)(.*?)\1\s*\)/i', $entry, $m) !== 1) {
                $warnings[] = "Unsupported @font-face src entry: $entry";
                continue;
            }
            $path = $m[2];
            if (preg_match('#^https?://#i', $path) === 1) {
                $warnings[] = "@font-face remote src is not supported: $path";
                continue;
            }
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($extension === 'ttf' || $extension === 'otf') {
                return [$path, $warnings];
            }
            if ($extension === 'woff' || $extension === 'woff2') {
                $warnings[] = "@font-face woff/woff2 is not supported; convert to ttf/otf: $path";
                continue;
            }
            $warnings[] = "Unsupported @font-face src format: $path";
        }
        return [null, $warnings];
    }

    /**
     * css-fonts-4 §4 reducido: 'normal'/'bold' + cualquier numérico 100-900. Un RANGO de dos
     * valores ("100 900", variable font descriptor) no tiene sentido para un TTF/OTF estático de
     * cara única (M8 no soporta variable fonts) — se colapsa al PRIMER valor, con warning.
     *
     * @return array{0: int, 1: ?string}
     */
    private function parseFontFaceWeight(string $value): array
    {
        $trimmed = trim($value);
        $lower = strtolower($trimmed);
        if ($lower === 'normal') {
            return [400, null];
        }
        if ($lower === 'bold') {
            return [700, null];
        }
        $parts = preg_split('/\s+/', $trimmed) ?: [];
        if (count($parts) === 2 && ctype_digit($parts[0]) && ctype_digit($parts[1])) {
            return [(int) $parts[0], "@font-face font-weight range \"$trimmed\" is not supported; using the first value: {$parts[0]}"];
        }
        if (count($parts) === 1 && ctype_digit($parts[0])) {
            return [(int) $parts[0], null];
        }
        return [400, "Unsupported @font-face font-weight: $value"];
    }

    /** @return array{0: bool, 1: ?string} */
    private function parseFontFaceStyle(string $value): array
    {
        $keyword = strtolower(trim($value));
        if ($keyword === 'normal') {
            return [false, null];
        }
        if ($keyword === 'italic') {
            return [true, null];
        }
        return [false, "Unsupported @font-face font-style: $value"];
    }

    /**
     * Extrae los margin-box at-rules (@top-center, etc., reconocidos o no) del cuerpo de un
     * @page, dejando solo las declaraciones de nivel superior (margin/margin-{side}) en el resto.
     *
     * @return array{0: string, 1: list<array{0: string, 1: string}>} [restoDelBody, list<[nombre, cuerpo]>]
     */
    private function extractMarginBoxes(string $body): array
    {
        $boxes = [];
        $offset = 0;
        while (preg_match('/@([a-zA-Z-]+)\s*\{/', $body, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $name = strtolower((string) $m[1][0]);
            $matchStart = (int) $m[0][1];
            $openBrace = $matchStart + strlen((string) $m[0][0]) - 1;
            $closeBrace = $this->findMatchingBrace($body, $openBrace);
            if ($closeBrace === null) {
                break;
            }
            $boxes[] = [$name, substr($body, $openBrace + 1, $closeBrace - $openBrace - 1)];
            $body = substr($body, 0, $matchStart) . ' ' . substr($body, $closeBrace + 1);
            $offset = $matchStart;
        }
        return [$body, $boxes];
    }

    private function findMatchingBrace(string $text, int $openBraceIndex): ?int
    {
        $depth = 0;
        $length = strlen($text);
        for ($i = $openBraceIndex; $i < $length; $i++) {
            if ($text[$i] === '{') {
                $depth++;
            } elseif ($text[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * @return array{0: array<string, Length>, 1: array<string, list<string>>, 2: list<string>}
     */
    private function parsePageRuleBody(string $body): array
    {
        [$bodyWithoutBoxes, $boxes] = $this->extractMarginBoxes($body);
        [$margins, $warnings] = $this->parsePageDeclarations($bodyWithoutBoxes);
        $marginBoxes = [];
        foreach ($boxes as [$name, $innerBody]) {
            if (!in_array($name, self::MARGIN_BOX_NAMES, true)) {
                $warnings[] = "Unsupported margin box: @$name";
                continue;
            }
            $contentValue = $this->extractContentDeclaration($innerBody);
            if ($contentValue === null) {
                $warnings[] = "Missing content declaration in margin box @$name";
                continue;
            }
            $parts = $this->parseContentParts($contentValue);
            if ($parts === null) {
                $warnings[] = "Unsupported content for margin box @$name: $contentValue";
                continue;
            }
            $marginBoxes[$name] = $parts;
        }
        return [$margins, $marginBoxes, $warnings];
    }

    /** @return array{0: array<string, Length>, 1: list<string>} */
    private function parsePageDeclarations(string $body): array
    {
        /** @var array<string, Length> $margins */
        $margins = [];
        /** @var list<string> $warnings */
        $warnings = [];
        foreach (array_filter(array_map('trim', explode(';', $body))) as $declaration) {
            if (!str_contains($declaration, ':')) {
                $warnings[] = "Unsupported @page descriptor: $declaration";
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $declaration, 2));
            $name = strtolower($name);
            if ($name === 'margin') {
                $expanded = $this->expandPageMarginShorthand($value);
                if ($expanded === null) {
                    $warnings[] = "Unsupported @page margin shorthand: $value";
                    continue;
                }
                $margins = [...$margins, ...$expanded];
                continue;
            }
            if (in_array($name, self::PAGE_MARGIN_LONGHANDS, true)) {
                $length = Length::fromCss($value);
                if ($length === null) {
                    $warnings[] = "Unsupported @page $name: $value";
                    continue;
                }
                $margins[substr($name, strlen('margin-'))] = $length;
                continue;
            }
            $warnings[] = "Unsupported @page descriptor: $name";
        }
        return [$margins, $warnings];
    }

    /**
     * CSS 2.2 §8.3, aplicado a @page (solo Length, @page no admite % en M2).
     *
     * @return ?array<string, Length>
     */
    private function expandPageMarginShorthand(string $value): ?array
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        $lengths = array_map(Length::fromCss(...), $parts);
        if ($lengths === [] || in_array(null, $lengths, true)) {
            return null;
        }
        /** @var list<Length> $lengths */
        [$top, $right, $bottom, $left] = match (count($lengths)) {
            1 => [$lengths[0], $lengths[0], $lengths[0], $lengths[0]],
            2 => [$lengths[0], $lengths[1], $lengths[0], $lengths[1]],
            3 => [$lengths[0], $lengths[1], $lengths[2], $lengths[1]],
            default => [$lengths[0], $lengths[1], $lengths[2], $lengths[3]],
        };
        return ['top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left];
    }

    private function extractContentDeclaration(string $body): ?string
    {
        foreach (array_filter(array_map('trim', explode(';', $body))) as $declaration) {
            if (!str_contains($declaration, ':')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $declaration, 2));
            if (strtolower($name) === 'content') {
                return $value;
            }
        }
        return null;
    }

    /**
     * content: cadenas entre comillas + counter(page)/counter(pages), concatenados con espacios.
     * Cada elemento del resultado es un literal de cadena (comillas ya despojadas) o uno de los
     * sentinels 'counter(page)'/'counter(pages)' (T6 los convierte a CounterRef).
     *
     * @return ?list<string>
     */
    private function parseContentParts(string $value): ?array
    {
        $value = trim($value);
        $parts = [];
        $pos = 0;
        $length = strlen($value);
        while ($pos < $length) {
            while ($pos < $length && ctype_space($value[$pos])) {
                $pos++;
            }
            if ($pos >= $length) {
                break;
            }
            $char = $value[$pos];
            if ($char === '"' || $char === "'") {
                $end = strpos($value, $char, $pos + 1);
                if ($end === false) {
                    return null;
                }
                $parts[] = substr($value, $pos + 1, $end - $pos - 1);
                $pos = $end + 1;
                continue;
            }
            if (preg_match('/\Gcounter\(\s*(page|pages)\s*\)/i', $value, $m, 0, $pos) === 1) {
                $parts[] = 'counter(' . strtolower($m[1]) . ')';
                $pos += strlen($m[0]);
                continue;
            }
            return null;
        }
        return $parts;
    }
}
