<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/**
 * css-values-3 §5-6: reemplaza el parseo ad-hoc de longitudes con unidad en DeclarationParser.
 * Las unidades físicas (pt/cm/mm/in) se pliegan a píxeles EN TIEMPO DE PARSEO (factores exactos
 * a 96dpi, §5.2) — un CssLength con unit=Px es siempre un valor ya resuelto, sea cual sea la
 * unidad de origen. Em/Rem/Percent quedan SIMBÓLICOS (necesitan el font-size propio/del padre/
 * raíz, que no existe todavía en el parser) hasta que ComputedStyle::compute los resuelve — ahí
 * es donde las unidades simbólicas mueren, nunca antes (ver DeclarationParser::parseLength()/
 * parseLengthPercentage() y ComputedStyle::compute()).
 */
final readonly class CssLength
{
    /** M6-T4: visibilidad public — CalcParser reutiliza estos mismos factores exactos para plegar
     * físicos dentro de un calc() (evita duplicar los números y arriesgar un drift entre los dos
     * sitios que hacen el mismo plegado px<-físico). */
    public const float PX_PER_IN = 96.0;
    public const float PX_PER_PT = self::PX_PER_IN / 72.0;
    public const float PX_PER_CM = self::PX_PER_IN / 2.54;
    /** css-values-3 §5.2: 1mm = 96/25.4px, expresado como 9.6/2.54 (== 96/25.4) por fidelidad al brief. */
    public const float PX_PER_MM = 9.6 / 2.54;

    private function __construct(public float $value, public LengthUnit $unit) {}

    public static function of(float $value, LengthUnit $unit): self
    {
        return new self($value, $unit);
    }

    public static function zero(): self
    {
        return new self(0.0, LengthUnit::Px);
    }

    public static function fromCss(string $value): ?self
    {
        $value = strtolower(trim($value));
        if ($value === '0') {
            return self::zero();
        }
        if (preg_match('/^(-?\d+(?:\.\d+)?)(px|rem|em|pt|cm|mm|in|%)$/', $value, $m) !== 1) {
            return null;
        }
        $num = (float) $m[1];
        return match ($m[2]) {
            'px' => new self($num, LengthUnit::Px),
            'em' => new self($num, LengthUnit::Em),
            'rem' => new self($num, LengthUnit::Rem),
            '%' => new self($num, LengthUnit::Percent),
            // Físicos: plegados a Px aquí mismo, factores exactos css-values-3 §5.2.
            'pt' => new self($num * self::PX_PER_PT, LengthUnit::Px),
            'cm' => new self($num * self::PX_PER_CM, LengthUnit::Px),
            'mm' => new self($num * self::PX_PER_MM, LengthUnit::Px),
            'in' => new self($num * self::PX_PER_IN, LengthUnit::Px),
        };
    }
}
