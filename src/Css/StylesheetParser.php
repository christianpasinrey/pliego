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
 */
final class StylesheetParser
{
    /** css-page-3 §6.5.3: las 6 cajas de margen soportadas en M2 (de las 16 posibles). */
    private const array MARGIN_BOX_NAMES = [
        'top-left', 'top-center', 'top-right',
        'bottom-left', 'bottom-center', 'bottom-right',
    ];
    private const array PAGE_MARGIN_LONGHANDS = ['margin-top', 'margin-right', 'margin-bottom', 'margin-left'];

    public function parse(string $css): ParseResult
    {
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
     * M8-T7: generalización de la extracción de @page (brace-matching manual, ver docblock de
     * clase) a cualquier at-rule de nivel superior identificado por nombre — @font-face es el
     * segundo consumidor. Sustituye cada bloque encontrado por un espacio en el CSS que se le
     * pasa a sabberworm, exactamente igual que antes de esta tarea para @page.
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
