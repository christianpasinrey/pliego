<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

final readonly class Length
{
    private function __construct(public float $px) {}

    public static function px(float $px): self
    {
        return new self($px);
    }

    public static function zero(): self
    {
        return new self(0.0);
    }

    /**
     * M6 final-review fix (Finding 3, css-values-3 §5.2): this class's only caller is
     * StylesheetParser's @page path (margin/margin-{side}) — until now it was px-only, while
     * element margins have accepted pt/cm/mm/in since M6-T3 (CssLength::fromCss). Physical units
     * fold to px here using CssLength's own exact factors (PX_PER_PT/CM/MM/IN, `public` for this
     * reuse — no duplicated constants, no drift risk between the two sites). em/rem/% are
     * deliberately NOT accepted: @page has no font-size or containing-block context in M6 (no
     * cascade/inheritance reaches a page margin box), so those still return null here and the
     * caller (StylesheetParser::parsePageDeclarations/expandPageMarginShorthand) emits its usual
     * "Unsupported @page ..." warning and keeps the Engine default margin for that side —
     * documented gap, same shape as the existing %-in-height gap.
     */
    public static function fromCss(string $value): ?self
    {
        $value = strtolower(trim($value));
        if ($value === '0') {
            return self::zero();
        }
        // Same optional-leading-digit grammar as CssLength::fromCss/CalcParser::tokenize (".5cm"
        // is valid, not just "0.5cm") — kept in sync to avoid a drift between the sites that
        // parse the same <number-token> grammar.
        if (preg_match('/^(-?(?:\d+(?:\.\d+)?|\.\d+))(px|pt|cm|mm|in)$/', $value, $m) !== 1) {
            return null;
        }
        $num = (float) $m[1];
        return match ($m[2]) {
            'px' => new self($num),
            'pt' => new self($num * CssLength::PX_PER_PT),
            'cm' => new self($num * CssLength::PX_PER_CM),
            'mm' => new self($num * CssLength::PX_PER_MM),
            default => new self($num * CssLength::PX_PER_IN), // 'in' — only remaining alternative matched by the regex above.
        };
    }
}
