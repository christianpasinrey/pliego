<?php

declare(strict_types=1);

namespace Pliego\Css;

use Sabberworm\CSS\Parser as SabberwormParser;

final class StylesheetParser
{
    public function parse(string $css): ParseResult
    {
        $document = (new SabberwormParser($css))->parse();
        $declarationParser = new DeclarationParser();
        $rules = [];
        $warnings = [];
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
        return new ParseResult($rules, $warnings);
    }
}
