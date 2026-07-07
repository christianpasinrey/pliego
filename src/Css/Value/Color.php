<?php

declare(strict_types=1);

namespace Pliego\Css\Value;

/**
 * M6-T5 (css-color-3 §4): además de #hex y los nombres, ahora también rgb()/rgba() (sintaxis
 * clásica con comas — la sintaxis moderna "espacio + /alpha" de css-color-4, ej.
 * `rgb(255 0 0 / 50%)`, NO se soporta: cae al warning genérico de fromCss() === null, igual que
 * cualquier otro valor no reconocido, sin mensaje especial), hsl()/hsla() (conversión EXACTA del
 * algoritmo de css-color-3 §4.2.4), 'transparent' (alpha 0, rgb negro por convención del spec) y
 * 'currentcolor' (sentinel — $isCurrentColor, resuelto contra el color computado del propio
 * elemento en ComputedStyle::compute(), igual patrón que ya usaba border-*-color sin declarar).
 *
 * $alpha es ?float: null significa "opaco" (el 99% de los casos — hex/named/rgb() sin cuarto
 * argumento), NUNCA 1.0 literal, para que dos colores opacos construidos por caminos distintos
 * (ej. `new Color(255,0,0)` en un test vs. `Color::fromCss('red')`) sigan comparando IGUAL con
 * `toEqual()`/`==` sin depender de que ambos caminos normalicen a 1.0 explícito.
 */
final readonly class Color
{
    /**
     * css-color-4 §6.1: los 148 nombres de color CSS (incluye ambas grafías gray/grey y
     * rebeccapurple). GENERADO — no tecleado a mano — desde la tabla canónica del paquete
     * `color-name` (colorjs/color-name, MIT), vía un script de una sola pasada (ver
     * .superpowers/sdd/m6-task-5-report.md para el script y la fuente exacta usados). No incluye
     * 'transparent' ni 'currentcolor': ambos son keywords CSS aparte, con semántica propia
     * (alpha 0 / sentinel), gestionados directamente en fromCss() antes de consultar esta tabla.
     *
     * @var array<string, array{int, int, int}>
     */
    private const array KEYWORDS = [
        'aliceblue' => [240, 248, 255],
        'antiquewhite' => [250, 235, 215],
        'aqua' => [0, 255, 255],
        'aquamarine' => [127, 255, 212],
        'azure' => [240, 255, 255],
        'beige' => [245, 245, 220],
        'bisque' => [255, 228, 196],
        'black' => [0, 0, 0],
        'blanchedalmond' => [255, 235, 205],
        'blue' => [0, 0, 255],
        'blueviolet' => [138, 43, 226],
        'brown' => [165, 42, 42],
        'burlywood' => [222, 184, 135],
        'cadetblue' => [95, 158, 160],
        'chartreuse' => [127, 255, 0],
        'chocolate' => [210, 105, 30],
        'coral' => [255, 127, 80],
        'cornflowerblue' => [100, 149, 237],
        'cornsilk' => [255, 248, 220],
        'crimson' => [220, 20, 60],
        'cyan' => [0, 255, 255],
        'darkblue' => [0, 0, 139],
        'darkcyan' => [0, 139, 139],
        'darkgoldenrod' => [184, 134, 11],
        'darkgray' => [169, 169, 169],
        'darkgreen' => [0, 100, 0],
        'darkgrey' => [169, 169, 169],
        'darkkhaki' => [189, 183, 107],
        'darkmagenta' => [139, 0, 139],
        'darkolivegreen' => [85, 107, 47],
        'darkorange' => [255, 140, 0],
        'darkorchid' => [153, 50, 204],
        'darkred' => [139, 0, 0],
        'darksalmon' => [233, 150, 122],
        'darkseagreen' => [143, 188, 143],
        'darkslateblue' => [72, 61, 139],
        'darkslategray' => [47, 79, 79],
        'darkslategrey' => [47, 79, 79],
        'darkturquoise' => [0, 206, 209],
        'darkviolet' => [148, 0, 211],
        'deeppink' => [255, 20, 147],
        'deepskyblue' => [0, 191, 255],
        'dimgray' => [105, 105, 105],
        'dimgrey' => [105, 105, 105],
        'dodgerblue' => [30, 144, 255],
        'firebrick' => [178, 34, 34],
        'floralwhite' => [255, 250, 240],
        'forestgreen' => [34, 139, 34],
        'fuchsia' => [255, 0, 255],
        'gainsboro' => [220, 220, 220],
        'ghostwhite' => [248, 248, 255],
        'gold' => [255, 215, 0],
        'goldenrod' => [218, 165, 32],
        'gray' => [128, 128, 128],
        'green' => [0, 128, 0],
        'greenyellow' => [173, 255, 47],
        'grey' => [128, 128, 128],
        'honeydew' => [240, 255, 240],
        'hotpink' => [255, 105, 180],
        'indianred' => [205, 92, 92],
        'indigo' => [75, 0, 130],
        'ivory' => [255, 255, 240],
        'khaki' => [240, 230, 140],
        'lavender' => [230, 230, 250],
        'lavenderblush' => [255, 240, 245],
        'lawngreen' => [124, 252, 0],
        'lemonchiffon' => [255, 250, 205],
        'lightblue' => [173, 216, 230],
        'lightcoral' => [240, 128, 128],
        'lightcyan' => [224, 255, 255],
        'lightgoldenrodyellow' => [250, 250, 210],
        'lightgray' => [211, 211, 211],
        'lightgreen' => [144, 238, 144],
        'lightgrey' => [211, 211, 211],
        'lightpink' => [255, 182, 193],
        'lightsalmon' => [255, 160, 122],
        'lightseagreen' => [32, 178, 170],
        'lightskyblue' => [135, 206, 250],
        'lightslategray' => [119, 136, 153],
        'lightslategrey' => [119, 136, 153],
        'lightsteelblue' => [176, 196, 222],
        'lightyellow' => [255, 255, 224],
        'lime' => [0, 255, 0],
        'limegreen' => [50, 205, 50],
        'linen' => [250, 240, 230],
        'magenta' => [255, 0, 255],
        'maroon' => [128, 0, 0],
        'mediumaquamarine' => [102, 205, 170],
        'mediumblue' => [0, 0, 205],
        'mediumorchid' => [186, 85, 211],
        'mediumpurple' => [147, 112, 219],
        'mediumseagreen' => [60, 179, 113],
        'mediumslateblue' => [123, 104, 238],
        'mediumspringgreen' => [0, 250, 154],
        'mediumturquoise' => [72, 209, 204],
        'mediumvioletred' => [199, 21, 133],
        'midnightblue' => [25, 25, 112],
        'mintcream' => [245, 255, 250],
        'mistyrose' => [255, 228, 225],
        'moccasin' => [255, 228, 181],
        'navajowhite' => [255, 222, 173],
        'navy' => [0, 0, 128],
        'oldlace' => [253, 245, 230],
        'olive' => [128, 128, 0],
        'olivedrab' => [107, 142, 35],
        'orange' => [255, 165, 0],
        'orangered' => [255, 69, 0],
        'orchid' => [218, 112, 214],
        'palegoldenrod' => [238, 232, 170],
        'palegreen' => [152, 251, 152],
        'paleturquoise' => [175, 238, 238],
        'palevioletred' => [219, 112, 147],
        'papayawhip' => [255, 239, 213],
        'peachpuff' => [255, 218, 185],
        'peru' => [205, 133, 63],
        'pink' => [255, 192, 203],
        'plum' => [221, 160, 221],
        'powderblue' => [176, 224, 230],
        'purple' => [128, 0, 128],
        'rebeccapurple' => [102, 51, 153],
        'red' => [255, 0, 0],
        'rosybrown' => [188, 143, 143],
        'royalblue' => [65, 105, 225],
        'saddlebrown' => [139, 69, 19],
        'salmon' => [250, 128, 114],
        'sandybrown' => [244, 164, 96],
        'seagreen' => [46, 139, 87],
        'seashell' => [255, 245, 238],
        'sienna' => [160, 82, 45],
        'silver' => [192, 192, 192],
        'skyblue' => [135, 206, 235],
        'slateblue' => [106, 90, 205],
        'slategray' => [112, 128, 144],
        'slategrey' => [112, 128, 144],
        'snow' => [255, 250, 250],
        'springgreen' => [0, 255, 127],
        'steelblue' => [70, 130, 180],
        'tan' => [210, 180, 140],
        'teal' => [0, 128, 128],
        'thistle' => [216, 191, 216],
        'tomato' => [255, 99, 71],
        'turquoise' => [64, 224, 208],
        'violet' => [238, 130, 238],
        'wheat' => [245, 222, 179],
        'white' => [255, 255, 255],
        'whitesmoke' => [245, 245, 245],
        'yellow' => [255, 255, 0],
        'yellowgreen' => [154, 205, 50],
    ];

    public function __construct(
        public int $r,
        public int $g,
        public int $b,
        public ?float $alpha = null,
        // M6-T5: sentinel de 'currentcolor' — r/g/b son un placeholder sin significado (0,0,0)
        // cuando isCurrentColor es true; el valor REAL solo se conoce en ComputedStyle::compute(),
        // que lo resuelve contra el color computado del propio elemento (para 'color' mismo, eso
        // es el color HEREDADO del padre — CSS 2.2/css-color-3: currentColor en la propiedad color
        // computa al valor heredado, no puede referirse a sí misma).
        public bool $isCurrentColor = false,
    ) {}

    /** Sentinel 'currentcolor' — ver el docblock de $isCurrentColor. */
    public static function currentColor(): self
    {
        return new self(0, 0, 0, null, true);
    }

    /**
     * M6-T5: combina el alpha PROPIO de este color con la opacity (0-1, ya clampada) del
     * elemento que lo pinta — multiplicativo (css-backgrounds-3-ish/css-color-3 §4.3: alpha
     * compuesto = alpha1 × alpha2), ej. rgba(...,0.5) con opacity:0.5 → alpha efectivo 0.25. Un
     * $opacity >= 1.0 (el caso común: la inmensa mayoría de los elementos no declaran opacity)
     * es un no-op — devuelve $this tal cual, sin asignar un alpha explícito 1.0 que rompería la
     * comparación con un color construido sin pasar por aquí (ver docblock de clase).
     */
    public function withOpacity(float $opacity): self
    {
        if ($opacity >= 1.0) {
            return $this;
        }
        $effectiveAlpha = ($this->alpha ?? 1.0) * $opacity;
        return new self($this->r, $this->g, $this->b, $effectiveAlpha, $this->isCurrentColor);
    }

    public static function fromCss(string $value): ?self
    {
        $value = strtolower(trim($value));
        if ($value === 'currentcolor') {
            return self::currentColor();
        }
        if ($value === 'transparent') {
            // css-color-3 §4.1: "transparent" es un atajo de rgba(0,0,0,0) — rgb negro por
            // convención del spec, invisible por su alpha 0 (ver PdfCanvas: alpha<=0 no emite
            // ningún operador de pintado).
            return new self(0, 0, 0, 0.0);
        }
        if (isset(self::KEYWORDS[$value])) {
            [$r, $g, $b] = self::KEYWORDS[$value];
            return new self($r, $g, $b);
        }
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value, $m) === 1) {
            $hex = $m[1];
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            return new self((int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2)));
        }
        if (str_starts_with($value, 'rgb')) {
            return self::parseRgbFunction($value);
        }
        if (str_starts_with($value, 'hsl')) {
            return self::parseHslFunction($value);
        }
        // M10-T3 (css-color-4 §10, Tailwind v4's default color function -- every generated
        // theme color, e.g. `--color-blue-500: oklch(62.3% 0.214 259.815)`, is emitted as oklch()):
        // see parseOklchFunction()/oklchToLinearSrgb() for the full conversion pipeline.
        if (str_starts_with($value, 'oklch(')) {
            return self::parseOklchFunction($value);
        }
        // M10-T3 (css-color-4 §16, reduced): color-mix() is NOT actually mixed -- adjudicated
        // (RESTRICCIONES GLOBALES M10, brief M10-T3) to fall back to its FIRST color argument
        // verbatim, with a warning raised by the caller (DeclarationParser::parse(), which is the
        // only place with a WarningCollector -- Color itself has none, see class docblock). This
        // branch only RESOLVES the fallback color; it never warns by itself, so calling
        // Color::fromCss('color-mix(...)') directly (e.g. from a unit test) is silent by design.
        if (str_starts_with($value, 'color-mix(')) {
            return self::parseColorMixFirstColor($value);
        }
        return null;
    }

    /**
     * css-color-4 §16: `color-mix(in <color-space> [<hue-method> hue]?, <color> <percent>?, <color>
     * <percent>?)`. Reduced support (brief M10-T3): no actual channel mixing math, no color-space
     * conversion, no hue-interpolation handling -- just extracts the FIRST `<color>` argument
     * (percentage suffix stripped, if present) and parses THAT through the normal fromCss()
     * dispatch. A depth-aware comma split (splitTopLevelCommas()) is used instead of a naive
     * explode(',', ...) because a color argument can itself be a comma-syntax function
     * (`color-mix(in srgb, rgb(0, 0, 0) 50%, white)`) -- exploding on every comma would cut
     * `rgb(0` off from its own arguments.
     */
    private static function parseColorMixFirstColor(string $value): ?self
    {
        if (preg_match('/^color-mix\(\s*in\s+[a-z0-9-]+(?:\s+[a-z]+\s+hue)?\s*,\s*(.*)\)$/is', $value, $m) !== 1) {
            return null;
        }
        $args = self::splitTopLevelCommas($m[1]);
        if ($args === [] || $args[0] === '') {
            return null;
        }
        // Strip a trailing "<percentage>" mix-weight off the first color argument, e.g.
        // "currentcolor 50%" -> "currentcolor". A color-mix() argument can ALSO lead with the
        // percentage ("50% currentcolor", css-color-4 §16.1) -- reduced scope does not support
        // that ordering, it simply fails to strip and the subsequent fromCss() call rejects the
        // still-percentage-prefixed token (falls through to the generic "Unsupported color"
        // warning at the call site, same honest degradation as any other unparseable value).
        $first = preg_replace('/\s+-?\d+(?:\.\d+)?%$/', '', $args[0]) ?? $args[0];
        return self::fromCss(trim($first));
    }

    /**
     * Paren-depth-aware top-level comma split, shared by parseColorMixFirstColor() -- a plain
     * explode(',', ...) would incorrectly split INSIDE a nested color function's own comma-syntax
     * arguments (e.g. `rgb(0, 0, 0)` inside a color-mix() argument list).
     *
     * @return list<string>
     */
    private static function splitTopLevelCommas(string $value): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }
            if ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = trim($current);
        return $parts;
    }

    /**
     * css-color-4 §10 (oklch()): `L C H [/ A]` -- per the M10-T3 brief's reduced contract, L
     * accepts either a percentage (0%-100%) or a bare number (0-1, both map to the same [0,1]
     * lightness range), C is a bare number only (NOT a percentage of the 0.4 reference range that
     * the full spec also allows -- Tailwind's generated output never uses %-chroma, so this is a
     * deliberate narrowing, not an oversight), H is a number in degrees (an optional trailing
     * "deg" is accepted and ignored -- bare numbers are degrees by css-values-4 §6.1's <angle>
     * default for this context). Alpha, if present after '/', follows the same % or 0-1 rule as
     * every other color function in this class.
     */
    private static function parseOklchFunction(string $value): ?self
    {
        if (preg_match(
            '/^oklch\(\s*([\d.]+)(%)?\s+([\d.]+)\s+([\d.]+)(?:deg)?\s*(?:\/\s*([\d.]+)(%)?\s*)?\)$/i',
            $value,
            $m,
        ) !== 1) {
            return null;
        }
        $lightness = (float) $m[1];
        if ($m[2] === '%') {
            $lightness /= 100.0;
        }
        $chroma = (float) $m[3];
        $hueDegrees = (float) $m[4];
        $alpha = null;
        if (($m[5] ?? '') !== '') {
            $alphaRaw = (float) $m[5];
            $alpha = max(0.0, min(1.0, ($m[6] ?? '') === '%' ? $alphaRaw / 100.0 : $alphaRaw));
        }
        [$r, $g, $b] = self::oklchToSrgb($lightness, $chroma, $hueDegrees);
        return new self($r, $g, $b, $alpha);
    }

    /**
     * css-color-4 §10-12, the FULL conversion pipeline (OKLCH -> OKLab -> LMS' -> LMS -> linear
     * sRGB -> gamma-encoded sRGB -> clamped 8-bit), matrices copied verbatim from the spec's own
     * sample conversion code (Björn Ottosson's original OKLab reference matrices, the same ones
     * colorjs.io/culori use — cross-checked by hand against the `culori` npm package, MIT, for
     * every value in ColorTest.php's oklch() table before pinning: e.g. oklch(62.3% 0.214
     * 259.815), Tailwind v4's OWN `--color-blue-500`, converts to #2b7fff, NOT the old Tailwind v3
     * hex #3b82f6 the M10-T3 brief assumed -- v4's palette was recomputed directly in OKLCH, it is
     * only VISUALLY close to v3's sRGB hex table, not byte-identical; see the M10-T3 report for
     * the full by-hand matrix derivation that caught this and the independent-library
     * cross-check).
     *
     * Gamut clamp (brief's adjudicated "simple channel clamp, documented vs proper gamut
     * mapping"): an out-of-sRGB-gamut OKLCH color (high chroma) is clamped by the FINAL
     * round()+max()/min() to [0,255] per channel independently -- NOT the spec's alternative
     * binary-search-in-OKLCH-chroma "CSS gamut mapping algorithm" (css-color-4 §13.2), which
     * preserves hue/lightness more faithfully for way-out-of-gamut colors at the cost of a
     * per-color iterative search. A plain channel clamp can shift perceived hue for extreme
     * inputs; Tailwind's own palette (verified against every scale referenced by the fixture)
     * never produces one, so this simplification is invisible for the actual M10-T3 use case.
     *
     * @return array{int, int, int}
     */
    private static function oklchToSrgb(float $lightness, float $chroma, float $hueDegrees): array
    {
        $hueRadians = deg2rad($hueDegrees);
        $a = $chroma * cos($hueRadians);
        $b = $chroma * sin($hueRadians);

        $lPrime = $lightness + 0.3963377774 * $a + 0.2158037573 * $b;
        $mPrime = $lightness - 0.1055613458 * $a - 0.0638541728 * $b;
        $sPrime = $lightness - 0.0894841775 * $a - 1.2914855480 * $b;

        $l = $lPrime ** 3;
        $m = $mPrime ** 3;
        $s = $sPrime ** 3;

        $rLinear = 4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s;
        $gLinear = -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s;
        $bLinear = -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s;

        return [
            self::clampGammaChannel($rLinear),
            self::clampGammaChannel($gLinear),
            self::clampGammaChannel($bLinear),
        ];
    }

    /**
     * css-color-4 §12.2 (sRGB transfer function, the standard piecewise gamma encoding), applied
     * to ONE linear-light channel and immediately quantized to a clamped 8-bit int -- the "simple
     * channel clamp" gamut strategy documented on oklchToSrgb() happens exactly HERE, at the
     * max(0, min(255, ...)) boundary.
     */
    private static function clampGammaChannel(float $linear): int
    {
        $sign = $linear < 0.0 ? -1.0 : 1.0;
        $magnitude = abs($linear);
        $encoded = $magnitude <= 0.0031308
            ? 12.92 * $magnitude
            : 1.055 * ($magnitude ** (1.0 / 2.4)) - 0.055;
        return max(0, min(255, (int) round($sign * $encoded * 255.0)));
    }

    /**
     * css-color-3 §4.2.1: rgb()/rgba() — SOLO la sintaxis clásica de comas,
     * `rgb(R, G, B)`/`rgba(R, G, B, A)`, con R/G/B enteros 0-255 o porcentajes 0%-100%. La
     * sintaxis moderna de css-color-4 (espacios + `/` para alpha, ej. `rgb(255 0 0 / 50%)`) NO
     * está en el contrato de M6 — un valor así falla el split-por-comas de más abajo (produce un
     * único "argumento" con espacios internos, que parseColorComponent() rechaza) y cae de forma
     * natural al `return null` genérico, con el warning estándar de fromCss() === null en
     * DeclarationParser (ningún mensaje especial: mismo tratamiento que cualquier otro valor no
     * reconocido).
     */
    private static function parseRgbFunction(string $value): ?self
    {
        if (preg_match('/^rgba?\(([^)]*)\)$/i', $value, $m) !== 1) {
            return null;
        }
        $args = array_map(trim(...), explode(',', $m[1]));
        if (count($args) !== 3 && count($args) !== 4) {
            return null;
        }
        $r = self::parseColorComponent($args[0]);
        $g = self::parseColorComponent($args[1]);
        $b = self::parseColorComponent($args[2]);
        if ($r === null || $g === null || $b === null) {
            return null;
        }
        if (count($args) === 4) {
            $alpha = self::parseAlphaComponent($args[3]);
            return $alpha === null ? null : new self($r, $g, $b, $alpha);
        }
        return new self($r, $g, $b);
    }

    /**
     * css-color-3 §4.2.4: hsl()/hsla() — misma restricción de sintaxis clásica de comas que
     * rgb()/rgba() (ver docblock de parseRgbFunction). H es un número (grados, opcionalmente
     * sufijado "deg" — css-color-4 §4.2, soporte mínimo; turn/rad quedan fuera de M6) SIN
     * restricción de rango (css-color-3: se normaliza mod 360 dentro de hslToRgb()); S/L son
     * SIEMPRE porcentajes (a diferencia de H), clampadas a [0,100] antes de convertir.
     */
    private static function parseHslFunction(string $value): ?self
    {
        if (preg_match('/^hsla?\(([^)]*)\)$/i', $value, $m) !== 1) {
            return null;
        }
        $args = array_map(trim(...), explode(',', $m[1]));
        if (count($args) !== 3 && count($args) !== 4) {
            return null;
        }
        $hue = self::parseHueComponent($args[0]);
        $saturation = self::parsePercentComponent($args[1]);
        $lightness = self::parsePercentComponent($args[2]);
        if ($hue === null || $saturation === null || $lightness === null) {
            return null;
        }
        $alpha = null;
        if (count($args) === 4) {
            $alpha = self::parseAlphaComponent($args[3]);
            if ($alpha === null) {
                return null;
            }
        }
        [$r, $g, $b] = self::hslToRgb($hue, $saturation / 100.0, $lightness / 100.0);
        return new self($r, $g, $b, $alpha);
    }

    private static function parseColorComponent(string $token): ?int
    {
        if (preg_match('/^-?\d+$/', $token) === 1) {
            return max(0, min(255, (int) $token));
        }
        if (preg_match('/^-?\d+(?:\.\d+)?%$/', $token) === 1) {
            $percent = max(0.0, min(100.0, (float) rtrim($token, '%')));
            return (int) round($percent / 100.0 * 255.0);
        }
        return null;
    }

    private static function parseAlphaComponent(string $token): ?float
    {
        if (preg_match('/^-?\d+(?:\.\d+)?%$/', $token) === 1) {
            return max(0.0, min(1.0, (float) rtrim($token, '%') / 100.0));
        }
        if (preg_match('/^-?(?:\d+\.?\d*|\.\d+)$/', $token) === 1) {
            return max(0.0, min(1.0, (float) $token));
        }
        return null;
    }

    private static function parseHueComponent(string $token): ?float
    {
        if (preg_match('/^(-?(?:\d+\.?\d*|\.\d+))(deg)?$/i', $token, $m) === 1) {
            return (float) $m[1];
        }
        return null;
    }

    private static function parsePercentComponent(string $token): ?float
    {
        if (preg_match('/^-?\d+(?:\.\d+)?%$/', $token) === 1) {
            return max(0.0, min(100.0, (float) rtrim($token, '%')));
        }
        return null;
    }

    /**
     * css-color-3 §4.2.4, algoritmo HUE→RGB citado literalmente del spec (nombres de variable
     * m1/m2 conservados a propósito para poder comparar línea a línea con el texto del spec):
     *
     *   HOW TO RETURN hsl.to.rgb(h, s, l):
     *     SELECT: m2 = l<=0.5 ? l*(s+1) : l+s-l*s
     *             m1 = l*2-m2
     *     RETURN (hue-to-rgb(m1, m2, h+1/3), hue-to-rgb(m1, m2, h), hue-to-rgb(m1, m2, h-1/3))
     *
     * $hue llega en GRADOS (sin normalizar); se pasa a [0,1) aquí (mod 360, luego /360) antes de
     * aplicar el algoritmo, que opera siempre en el espacio [0,1]. $saturation/$lightness llegan
     * YA normalizadas a [0,1] (el caller divide el porcentaje /100).
     *
     * @return array{int, int, int}
     */
    private static function hslToRgb(float $hue, float $saturation, float $lightness): array
    {
        $h = fmod($hue, 360.0) / 360.0;
        if ($h < 0.0) {
            $h += 1.0;
        }
        $s = max(0.0, min(1.0, $saturation));
        $l = max(0.0, min(1.0, $lightness));

        $m2 = $l <= 0.5 ? $l * ($s + 1.0) : $l + $s - $l * $s;
        $m1 = $l * 2.0 - $m2;

        return [
            (int) round(self::hueToRgb($m1, $m2, $h + 1.0 / 3.0) * 255.0),
            (int) round(self::hueToRgb($m1, $m2, $h) * 255.0),
            (int) round(self::hueToRgb($m1, $m2, $h - 1.0 / 3.0) * 255.0),
        ];
    }

    /**
     * css-color-3 §4.2.4, citado literalmente:
     *
     *   HOW TO RETURN hue-to-rgb(m1, m2, h):
     *     IF h<0: PUT h+1 IN h
     *     IF h>1: PUT h-1 IN h
     *     IF h*6<1: RETURN m1+(m2-m1)*h*6
     *     IF h*2<1: RETURN m2
     *     IF h*3<2: RETURN m1+(m2-m1)*(2/3-h)*6
     *     RETURN m1
     */
    private static function hueToRgb(float $m1, float $m2, float $h): float
    {
        if ($h < 0.0) {
            $h += 1.0;
        }
        if ($h > 1.0) {
            $h -= 1.0;
        }
        if ($h * 6.0 < 1.0) {
            return $m1 + ($m2 - $m1) * $h * 6.0;
        }
        if ($h * 2.0 < 1.0) {
            return $m2;
        }
        if ($h * 3.0 < 2.0) {
            return $m1 + ($m2 - $m1) * (2.0 / 3.0 - $h) * 6.0;
        }
        return $m1;
    }
}
