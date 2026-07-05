<?php

declare(strict_types=1);

namespace Pliego\Css;

use Pliego\Css\Value\Length;
use Sabberworm\CSS\Parser as SabberwormParser;

/**
 * @page (T2): probado con un probe manual (ver report) que sabberworm/php-css-parser 8.9
 * MANGLA los at-rules anidados dentro de @page — el `content` de un `@top-center` termina
 * fusionado directamente en el AtRuleSet de "page" (perdiendo qué margin-box lo declaró), y un
 * segundo margin-box (p.ej. `@bottom-right`) aparece como AtRuleSet HERMANO de nivel superior en
 * vez de anidado. Por eso @page se extrae con un tokenizer-lite de brace-matching ANTES de pasar
 * el resto de la hoja a sabberworm (que sigue gestionando reglas normales sin problema).
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
        $rules = [];
        $warnings = $pageWarnings;
        $order = 0;
        foreach ($document->getAllDeclarationBlocks() as $block) {
            $declarations = [];
            foreach ($block->getRules() as $rule) {
                foreach ($declarationParser->parse($rule->getRule(), (string) $rule->getValue()) as $property => $value) {
                    $declarations[$property] = $value;
                }
            }
            $warnings = [...$warnings, ...$declarationParser->drainWarnings()];
            foreach ($block->getSelectors() as $sabberwormSelector) {
                $selectorString = is_string($sabberwormSelector) ? $sabberwormSelector : $sabberwormSelector->getSelector();
                $selector = Selector::fromString($selectorString);
                if ($selector === null) {
                    $warnings[] = 'Unsupported selector in M0: ' . $selectorString;
                    continue;
                }
                if ($declarations !== []) {
                    $rules[] = new StyleRule($selector, $declarations, $order++);
                }
            }
        }
        return new ParseResult($rules, $warnings, $pageRule);
    }

    /**
     * Extrae todos los bloques @page de nivel superior (brace-matching manual, ver docblock de
     * clase) y los sustituye por un espacio en el CSS que se le pasa a sabberworm.
     *
     * @return array{0: string, 1: list<string>} [cssSinPage, bodies]
     */
    private function extractAtPageBlocks(string $css): array
    {
        $bodies = [];
        $offset = 0;
        while (preg_match('/@page\b[^{]*\{/i', $css, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
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
