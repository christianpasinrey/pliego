<?php

declare(strict_types=1);

namespace Pliego\Style;

use Pliego\Css\DeclarationParser;
use Pliego\Css\Value\BorderRadius;
use Pliego\Css\Value\BorderSide;
use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\BoxShadow;
use Pliego\Css\Value\CalcExpr;
use Pliego\Css\Value\CalcValue;
use Pliego\Css\Value\Color;
use Pliego\Css\Value\CssLength;
use Pliego\Css\Value\Gradient;
use Pliego\Css\Value\Length;
use Pliego\Css\Value\LengthPercentage;
use Pliego\Css\Value\LengthUnit;
use Pliego\Css\WarningCollector;

final readonly class ComputedStyle
{
    /**
     * M7-T2 housekeeping: HIDDEN_BY_DEFAULT/BOLD_BY_DEFAULT/ITALIC_BY_DEFAULT/
     * UNDERLINE_BY_DEFAULT/CENTER_ALIGN_BY_DEFAULT (constantes de tag-por-tag que vivían aquí
     * desde M1/M5) MIGRARON a Style\UserAgentStylesheet como reglas CSS reales (display:none;
     * font-weight:bold; font-style:italic; text-decoration:underline; text-align:center) —
     * StyleResolver las antepone SIEMPRE al cascade con origen UA (ver esa clase), así que
     * $declarations, para cuando llega aquí, YA contiene el valor ganador (UA o autor, quien
     * gane) para estos cinco casos: display/font-weight/font-style/text-decoration/text-align se
     * resuelven abajo con la MISMA lógica de herencia/initial-value que cualquier otra propiedad
     * CSS normal, sin ninguna rama especial por $tag. Ganancia real (no solo limpieza): un autor
     * ahora SÍ puede pisar estos defaults con su propia regla (p.ej. `head { display: block }`),
     * algo que el hardcoding anterior no permitía — divergencia de CSS real que esta migración
     * corrige de paso.
     *
     * TABLE_DISPLAY_BY_TAG es la EXCEPCIÓN deliberada: se queda hardcoded aquí (ver docblock de
     * Style\UserAgentStylesheet para el porqué — observacionalmente sería un no-op migrarla, y
     * la extensa batería de goldens de tabla M5/M6 depende de esta ruta exacta).
     */
    private const array TABLE_DISPLAY_BY_TAG = [
        'table' => Display::Table,
        'tr' => Display::TableRow,
        'td' => Display::TableCell,
        'th' => Display::TableCell,
        'thead' => Display::TableHeaderGroup,
        'tbody' => Display::TableRowGroup,
    ];

    /**
     * M10-T1 (css-values-4 §5.1.1, vw/vh in paged media -- ADJUDICATED against the PAPER box, not
     * the content box, see LengthUnit's docblock): defaults for callers that don't thread a real
     * page size (mostly synthetic ComputedStyle::compute() calls with no declarations at all --
     * Box\BoxTreeBuilder's anonymous table row/cell wrappers -- where no vw/vh can possibly be
     * present anyway) or unit tests exercising compute() directly against a bare 4-arg call.
     * Style: [Css, Vendor] in deptrac.yaml -- this layer cannot import Page\PaperSize, so the A4
     * formula is duplicated here VERBATIM (210mm/297mm at 96dpi) rather than referenced; the real
     * pipeline (Style\StyleResolver, threaded from Engine::render() via $this->paper->widthPx()/
     * heightPx()) always overrides these with the ACTUAL configured paper size.
     */
    private const float DEFAULT_PAGE_WIDTH_PX = 210.0 / 25.4 * 96.0;
    private const float DEFAULT_PAGE_HEIGHT_PX = 297.0 / 25.4 * 96.0;

    public function __construct(
        public Display $display,
        public LengthPercentage $marginTop,
        public LengthPercentage $marginRight,
        public LengthPercentage $marginBottom,
        public LengthPercentage $marginLeft,
        public LengthPercentage $paddingTop,
        public LengthPercentage $paddingRight,
        public LengthPercentage $paddingBottom,
        public LengthPercentage $paddingLeft,
        public ?LengthPercentage $width,
        // M3-T3: a diferencia de width, height NO admite % en M3 (LENGTH_PROPERTIES en
        // DeclarationParser, no LENGTH_PERCENTAGE_PROPERTIES) — CSS 2.2 §10.5 resolvería un % de
        // height contra la altura del containing block, que este motor no rastrea; un valor como
        // "height: 50%" ya se rechaza en el parser ("Unsupported length for height: 50%") y aquí
        // simplemente no llega, dejando el eje en null (auto), warning incluido — la adjudicación
        // "% height -> warning + auto" del brief M3-T3 queda satisfecha sin código extra aquí.
        public ?Length $height,
        public ?Color $backgroundColor,
        public Color $color,
        public float $fontSizePx,
        // M7-T2 (css-fonts-3 §5.3.1): deja de ser un único nombre de familia (string) para ser la
        // LISTA CRUDA de fallback, ya troceada/limpiada por DeclarationParser::parseFontFamily()
        // pero SIN resolver contra ningún catálogo de fuentes — Style no puede depender de Text
        // (deptrac: Style: [Css, Vendor]), así que la resolución final (¿qué nombre de la lista
        // está REGISTRADO, o es un genérico como sans-serif/serif/monospace?) vive en Layout,
        // contra FontCatalog (ver Layout\Text\FontFamilyResolver, consumido por
        // InlineFlowContext::faceFor()/IntrinsicSizer::faceFor()). ['default'] es el initial
        // value sintético de este motor (nunca vacío: ver compute(), que cae a la lista heredada
        // del padre cuando la propia declaración produce una lista vacía).
        /** @var list<string> */
        public array $fontFamily,
        public int $fontWeight,
        public FontStyle $fontStyle,
        public ?float $lineHeightPx,
        // M10-T2 (navbar/card investigation, CSS 2.2 §10.8.1 / css-inline-3 §5.2): a `line-height`
        // declared as a bare <number> (e.g. Bootstrap's `body{line-height:1.5}`) inherits AS THE
        // NUMBER, not as its resolved px value at the declaring element -- css-inline-3 §5.2 is
        // explicit: "if the property value is a <number>... the used value... is inherited [as
        // that number]". null here means the CURRENT $lineHeightPx is an ABSOLUTE value (declared
        // with a Length/%/em/rem/vw/vh/calc()/'normal', or inherited from an ancestor whose own
        // line-height was itself absolute) that inherits UNCHANGED; a non-null float is the
        // pending multiplier a descendant with a DIFFERENT own font-size must reapply against
        // ITS OWN $fontSizePx, never blindly reuse the ancestor's already-resolved px (see
        // compute()'s own line-height block for where this bit is set/consumed).
        public ?float $lineHeightMultiplier,
        public TextAlign $textAlign,
        public bool $underline,
        // M7-T2 (CSS 2.2 §16.6, reducido a normal|pre): SÍ hereda (a diferencia de la mayoría de
        // propiedades de este constructor) — un <code> anidado dentro de un <pre> (patrón HTML
        // habitual) debe conservar white-space:pre sin que el autor tenga que redeclararlo. Ver
        // BoxTreeBuilder::textRunTokensFor()/collapse() (colapso de whitespace + \n -> LineBreakRun)
        // e InlineFlowContext::layout() (wrapping desactivado para runs 'pre').
        public string $whiteSpace,
        public BorderSide $borderTop,
        public BorderSide $borderRight,
        public BorderSide $borderBottom,
        public BorderSide $borderLeft,
        public string $boxSizing,
        // M4-T1: propiedades flex — ninguna hereda (css-flexbox-1: display:flex/flex-direction/
        // flex-wrap/gap/justify-content/align-items son propiedades del CONTENEDOR, flex-grow/
        // shrink/basis son propiedades del ITEM; ninguna de las dos categorías está en la lista de
        // propiedades heredadas de CSS 2.2 §6.1 ni de css-flexbox-1). Los defaults son los initial
        // values del spec, no los del padre.
        public FlexDirection $flexDirection,
        public FlexWrap $flexWrap,
        public float $rowGapPx,
        public float $columnGapPx,
        public JustifyContent $justifyContent,
        public AlignItems $alignItems,
        public float $flexGrow,
        public float $flexShrink,
        public ?LengthPercentage $flexBasis,
        // M5-T2: border-spacing SÍ hereda (CSS 2.2 §17.6.1: "This property... is inherited"),
        // a diferencia de casi todas las demás propiedades de caja — de ahí que caiga a
        // $parent->borderSpacingPx en compute() en vez de al initial value 0 cuando no hay
        // declaración propia. table-layout y vertical-align, en cambio, NO heredan (CSS 2.2
        // §17.5.2 y §10.8.1 respectivamente): cada uno parte siempre del initial value del
        // spec. vertical-align diverge del spec real (initial value = baseline, no soportado
        // en M5) usando Top como default — ver VerticalAlign.
        public float $borderSpacingPx,
        public string $tableLayout,
        public VerticalAlign $verticalAlign,
        // M7-T3 (css-lists-3 §3): list-style-type SÍ hereda (a diferencia de vertical-align justo
        // arriba, y de la mayoría de propiedades de este constructor) — ver ListStyleType para el
        // razonamiento completo y el initial value real ('disc').
        public ListStyleType $listStyleType,
        // M7-T5 (CSS 2.2 §10.4): min-width/max-width comparten el mismo tipo y la misma
        // resolución diferida a Layout que width (% contra el ancho del containing block, ver
        // BlockFlowContext::layout()) -- ninguna de las 2 hereda (igual que width). null = "sin
        // mínimo/máximo" (initial value real: 'auto'/'none' respectivamente, ver
        // DeclarationParser -- ambos colapsan al mismo null que "propiedad no declarada").
        public ?LengthPercentage $minWidth,
        public ?LengthPercentage $maxWidth,
        // M7-T5 (CSS 2.2 §10.7): a diferencia de min/max-width, min-height/max-height son PX-ONLY
        // -- este motor no rastrea la altura del containing block (mismo gap documentado que
        // $height arriba), así que un % en estas 2 propiedades se rechaza YA en
        // DeclarationParser (warning + valor descartado) y nunca llega aquí. NO heredan.
        public ?Length $minHeight,
        public ?Length $maxHeight,
        // M7-T5 (css-overflow-3, reducido a visible|hidden): NO hereda -- cada elemento parte
        // SIEMPRE del initial value 'visible' cuando no hay declaración propia, nunca de
        // $parent->overflow (mismo patrón que box-sizing/opacity). 'scroll'/'auto' ya llegan
        // coercionados a 'hidden' desde DeclarationParser (con warning) -- esta propiedad solo ve
        // 'visible'|'hidden'.
        public string $overflow,
        // M7-T6 (CSS 2.2 §9.5.1): NO hereda -- cada elemento parte SIEMPRE de "sin float" (null)
        // cuando no hay declaración propia, nunca de $parent->float (mismo patrón que
        // box-sizing/overflow/opacity: cada caja decide su PROPIO float de forma independiente).
        public ?FloatSide $float,
        // M7-T6 (CSS 2.2 §9.5.2): NO hereda -- initial 'none' siempre que no haya declaración
        // propia. DeclarationParser ya solo produce 'left'|'right'|'both'|'none' aquí.
        public string $clear,
        // M7-T6 (CSS 2.2 §9.4.3/§10, css-position-3 reducido): NO hereda -- initial Static
        // siempre que no haya declaración propia (ver Style\Position, incluye el fallback de
        // 'sticky'/'fixed' -> warning + Static, ya resuelto en DeclarationParser).
        public Position $position,
        // M7-T6: top/right/bottom/left -- NINGUNO de los 4 hereda (CSS 2.2 §9.3.1 no los lista
        // entre las propiedades heredadas). top/bottom nunca traen componente de % (rechazado ya
        // en DeclarationParser, mismo criterio que height -- ver LengthPercentage::px() más abajo
        // en compute(), que los envuelve SIN posibilidad de percent real); left/right SÍ pueden
        // traer % (resuelto contra el ancho del containing block en Layout, igual que width).
        public ?LengthPercentage $top,
        public ?LengthPercentage $right,
        public ?LengthPercentage $bottom,
        public ?LengthPercentage $left,
        // M8-T2 (css-backgrounds-3 §5): NO hereda -- initial value real es 0 en las 4 esquinas
        // (Css\Value\BorderRadius::zero()) cuando no hay declaración propia, nunca
        // $parent->borderRadius (mismo patrón que box-sizing/overflow/opacity/top-right-bottom-
        // left: cada elemento decide su PROPIO radio, un <div> dentro de un padre con
        // border-radius no hereda nada). % SIGUE SIMBÓLICO aquí (LengthPercentage sin resolver,
        // igual que width/margin/padding) -- Layout\Fragment\BorderRadius::fromCss() lo resuelve
        // contra el border-box (adjudicación M8: % siempre contra el ANCHO) y aplica el clamp de
        // solapes §5.5 una vez conocido el tamaño final de la caja (ver BlockFlowContext/
        // FlexFormattingContext/TableFormattingContext/InlineFlowContext).
        public BorderRadius $borderRadius,
        // M6-T5 (css-color-3 opacity / CSS Compositing §5): opacity NO hereda — cada elemento
        // parte SIEMPRE del initial value 1.0 (opaco) cuando no hay declaración propia, nunca de
        // $parent->opacity (a diferencia de $color, que sí hereda). Se aplica multiplicativamente
        // sobre los colores PROPIOS del elemento (fondo/texto/bordes/subrayado/imagen) en el
        // punto de pintado (ver Color::withOpacity(), Layout\*FormattingContext y Paint\Painter) —
        // NUNCA se hornea aquí en $color/$backgroundColor/border-*-color, porque esos SÍ pueden
        // heredar o servir de currentColor para un hijo, y ese hijo NO debe heredar la opacity de
        // este elemento (divergencia M6 documentada: cada elemento solo pinta con su PROPIA
        // opacity, sin semántica real de "transparency group" que afecte también a los
        // descendientes como grupo — eso es M7+).
        public float $opacity = 1.0,
        // M6-T4 (css-variables-1 §2-3): custom properties SÍ heredan siempre (a diferencia de
        // casi todas las demás propiedades de este constructor, que no heredan por defecto) — es
        // el mecanismo de herencia el que hace que var(--bs-primary) funcione en cualquier
        // descendiente de :root sin que cada regla tenga que redeclarar la variable. Valor CRUDO
        // (string, puede contener var() anidado sin resolver todavía si vino de un padre cuyo
        // propio --x también depende de otro — VarResolver resuelve la cadena completa on-demand
        // en cada uso, ver StyleResolver::resolveDeferred()).
        /** @var array<string, string> */
        public array $customProperties = [],
        // M8-T3 (css-images-3 §3.1 reducido): NO hereda -- initial value real es "none" (sin
        // gradiente) siempre que no haya declaración propia, nunca $parent->backgroundGradient
        // (mismo patrón que $backgroundColor, que tampoco hereda -- un <div> dentro de un padre
        // con gradiente no "hereda" el fondo del padre, cada caja pinta el SUYO). Raw VO sin
        // resolver contra ninguna caja (los stops YA traen su posición final 0-100, ver
        // Css\Value\GradientStop, pero /Coords se calculan en Pdf\PdfCanvas::paintGradient(), en
        // tiempo de pintado, contra el border-box final del fragmento -- igual división de
        // responsabilidades Css-vs-Layout/Pdf que BorderRadius). Pinta POR ENCIMA de
        // $backgroundColor (ambos pueden coexistir: el color sirve de fallback visible en los
        // huecos de un gradiente con alpha -- soportado vía /SMask desde M9-T3, ver
        // Pdf\PdfCanvas::paintGradient() -- y de fondo tras un gradiente translúcido -- ver
        // Paint\Painter::paintBackground()).
        public ?Gradient $backgroundGradient = null,
        // M8-T4 (css-backgrounds-3 §6 reducido): NO hereda -- initial value real es "none" (sin
        // sombra) siempre que no haya declaración propia, nunca $parent->boxShadow (mismo patrón
        // que $backgroundGradient/$borderRadius justo arriba: cada elemento pinta la SUYA propia).
        // A diferencia de $borderRadius (que guarda LengthPercentage sin resolver, % diferido a
        // Layout), este VO YA llega con offsetX/offsetY/blurRadius resueltos a PX -- ver el
        // docblock de Css\Value\BoxShadow para el porqué (ninguno de los 3 admite % en CSS real).
        public ?BoxShadow $boxShadow = null,
        // M8-T5 (css-text-3 §8 reducido): letter-spacing/word-spacing EN PX -- AMBAS heredan (a
        // diferencia de $borderRadius/$opacity/$backgroundGradient/$boxShadow justo arriba, que NO
        // heredan). 0.0 es el initial value real ('normal') Y TAMBIÉN el sentinel "sin efecto" que
        // Layout\TextMeasurer::widthOf()/Pdf\PdfCanvas::fillText() usan como fast path (ver sus
        // docblocks): un documento sin NINGUNA declaración de letter-spacing/word-spacing produce
        // ComputedStyle idénticos a antes de esta tarea salvo por estos 2 campos nuevos, que nunca
        // se leen fuera de Layout/Pdf.
        public float $letterSpacingPx = 0.0,
        public float $wordSpacingPx = 0.0,
        // M8-T5 (css-text-3 §8 reducido): text-transform -- SÍ hereda (igual criterio que
        // $textAlign/$whiteSpace/$listStyleType arriba). Aplicado al TEXTO de los runs en
        // Box\BoxTreeBuilder ANTES de medir (ver textRunTokensFor()) -- ComputedStyle solo
        // transporta el valor computado, nunca transforma nada por sí mismo.
        public TextTransform $textTransform = TextTransform::None,
        // M8-T6 (css-backgrounds-3 §4 reducido): NO hereda -- initial value real es "none" (sin
        // imagen) siempre que no haya declaración propia, nunca $parent->backgroundImagePath
        // (mismo patrón que $backgroundGradient/$borderRadius: cada caja pinta la SUYA propia).
        // RAW string sin resolver contra ningún basePath -- Style no tiene basePath y no debería
        // necesitarlo (arquitectura M8-T6: la resolución de ruta + la carga del fichero ocurren
        // en tiempo de PINTADO, ver Paint\Painter::paintBackgroundImage()/Image\ImagePathResolver,
        // el MISMO momento/mecanismo que <img> usa en Box\BoxTreeBuilder — así ambos caminos
        // producen el mismo string de ruta resuelta para el mismo fichero, condición necesaria
        // para el dedup de Pdf\ImageRegistry entre un <img> y un background-image que compartan
        // src). DeclarationParser::parseBackgroundImageValue()/parseBackgroundShorthand() ya
        // producen este valor listo para usar (string|null, nunca otro tipo).
        public ?string $backgroundImagePath = null,
        // M8-T6: NO hereda -- initial value real 'auto' (sin escalado, tamaño intrínseco) siempre
        // que no haya declaración propia, nunca $parent->backgroundSize (mismo patrón que
        // $backgroundImagePath justo arriba).
        public BackgroundSize $backgroundSize = BackgroundSize::Auto,
        // M8-T6: NO hereda -- initial value real 'no-repeat' (false) siempre que no haya
        // declaración propia, nunca $parent->backgroundRepeat.
        public bool $backgroundRepeat = false,
        // M8-T6: NO hereda -- initial value real 'top left' (equivalente a '0% 0%') siempre que no
        // haya declaración propia, nunca $parent->backgroundPosition.
        public BackgroundPosition $backgroundPosition = BackgroundPosition::TopLeft,
    ) {}

    /**
     * M6-T3 (css-values-3 §5.2): resolución de font-size — la ÚNICA propiedad donde em/%
     * se miden contra el font-size del PADRE (nunca el propio, evita la circularidad
     * "relativo a sí mismo"); rem contra $remBase. Extraído a un método público estático
     * porque StyleResolver::resolveRoot() necesita este MISMO cálculo para derivar el remBase
     * del árbol a partir del font-size del documentElement, sin duplicar la lógica ni caer en
     * el bug de recalcular dos veces con un remBase distinto cada vez (ver StyleResolver).
     */
    public static function resolveFontSizePx(
        mixed $fontSizeValue,
        float $parentFontSizePx,
        float $remBase,
        float $pageWidthPx = self::DEFAULT_PAGE_WIDTH_PX,
        float $pageHeightPx = self::DEFAULT_PAGE_HEIGHT_PX,
    ): float {
        if ($fontSizeValue instanceof Length) {
            return $fontSizeValue->px;
        }
        if ($fontSizeValue instanceof CssLength) {
            return match ($fontSizeValue->unit) {
                LengthUnit::Percent => ($fontSizeValue->value / 100.0) * $parentFontSizePx,
                LengthUnit::Em => $fontSizeValue->value * $parentFontSizePx,
                LengthUnit::Rem => $fontSizeValue->value * $remBase,
                // M10-T1: font-size:Xvw/Xvh -- against the page's own CSS-px size, same
                // adjudication as any other vw/vh use (see LengthUnit's docblock).
                LengthUnit::Vw => ($fontSizeValue->value / 100.0) * $pageWidthPx,
                LengthUnit::Vh => ($fontSizeValue->value / 100.0) * $pageHeightPx,
                default => $parentFontSizePx,
            };
        }
        // M6-T4 (css-values-3 §8): font-size es la única propiedad donde % TAMBIÉN se resuelve
        // ya mismo (contra el font-size del padre, igual que em) — nunca se difiere a Layout
        // como en width/margin/padding — así que $percentBase se pasa igual que $emBase
        // (foldCalcWithOwnBase asume ambos iguales, ver su docblock).
        if ($fontSizeValue instanceof CalcExpr) {
            return self::foldCalcWithOwnBase($fontSizeValue, $parentFontSizePx, $remBase, $pageWidthPx, $pageHeightPx);
        }
        return $parentFontSizePx;
    }

    /**
     * M6-T4: plegado de un calc() en un contexto donde % NUNCA se difiere a Layout — font-size
     * (% y em contra el font-size del padre) y line-height (% y em contra el font-size PROPIO,
     * ya resuelto) comparten esta forma: un único $ownBase sirve de base tanto para em como para
     * %, así que fold() SIEMPRE devuelve un float puro aquí (nunca un CalcValue diferido) — el
     * fallback a $ownBase solo satisface el tipo CalcValue|float de fold() ante PHPStan, nunca se
     * alcanza en la práctica.
     *
     * M10-T1: $pageWidthPx/$pageHeightPx simplemente se reenvían a CalcExpr::fold() — vw/vh dentro
     * de un calc() de font-size/line-height se resuelven contra el PAPER box exactamente igual que
     * fuera de un calc(), nunca contra $ownBase (a diferencia de %/em, que SÍ comparten base con
     * font-size — vw/vh son un eje completamente distinto, el tamaño de página, no el tipográfico).
     */
    private static function foldCalcWithOwnBase(CalcExpr $expr, float $ownBase, float $remBase, float $pageWidthPx = self::DEFAULT_PAGE_WIDTH_PX, float $pageHeightPx = self::DEFAULT_PAGE_HEIGHT_PX): float
    {
        $folded = $expr->fold($ownBase, $remBase, $ownBase, $pageWidthPx, $pageHeightPx);
        return is_float($folded) ? $folded : $ownBase;
    }

    public static function root(): self
    {
        $zero = LengthPercentage::zero();
        $rootColor = new Color(0, 0, 0);
        // Sin borde declarado en la raíz: color=currentColor (=$rootColor), igual que en
        // compute() — invariante del árbol: BorderSide::$color nunca es null en ComputedStyle.
        $noBorder = new BorderSide(0.0, BorderStyle::None, $rootColor);
        return new self(
            Display::Block,
            $zero,
            $zero,
            $zero,
            $zero,
            $zero,
            $zero,
            $zero,
            $zero,
            null,
            null,
            null,
            $rootColor,
            16.0,
            ['default'],
            400,
            FontStyle::Normal,
            null,
            null,
            TextAlign::Left,
            false,
            'normal',
            $noBorder,
            $noBorder,
            $noBorder,
            $noBorder,
            'content-box',
            FlexDirection::Row,
            FlexWrap::NoWrap,
            0.0,
            0.0,
            JustifyContent::FlexStart,
            AlignItems::Stretch,
            0.0,
            1.0,
            null,
            0.0,
            'auto',
            VerticalAlign::Top,
            // css-lists-3 §3: initial value real de list-style-type es 'disc' — ver docblock del
            // nuevo parámetro del constructor.
            ListStyleType::Disc,
            // M7-T5: sin min/max-width/height (null = sin mínimo/máximo) ni overflow declarado
            // (initial value real 'visible') en la raíz.
            null,
            null,
            null,
            null,
            'visible',
            // M7-T6: sin float/clear/position/offsets declarados en la raíz (initial values:
            // sin float, 'none', Static, sin ningún offset).
            null,
            'none',
            Position::Static,
            null,
            null,
            null,
            null,
            BorderRadius::zero(),
        );
    }

    /**
     * CSS 2.2 §6.1-6.2: propiedades heredadas toman el computed value del padre;
     * el resto parte del initial value. Las declaraciones ganadoras sobrescriben.
     *
     * M6-T3 (css-values-3 §5-6): $remBase es el font-size computado del elemento raíz
     * (documentElement), capturado y threadeado por StyleResolver — 1rem se resuelve contra
     * ESTE valor en todo el árbol, nunca contra el padre inmediato. Es donde las unidades
     * simbólicas (Em/Rem/Percent-en-font-size/line-height, ver CssLength) mueren: cualquier
     * CssLength que sobreviva hasta aquí se resuelve a Length/LengthPercentage (solo px|%) antes
     * de construir el ComputedStyle — ningún consumidor fuera de Style\ ve jamás un CssLength.
     *
     * M6-T4: $customProperties es el mapa YA FUSIONADO (heredado del padre + propio del cascade,
     * propio gana) que StyleResolver calculó para este elemento — se limita a threadearse hasta
     * el nuevo campo homónimo (para que los hijos lo hereden a su vez); la sustitución de var()
     * en las declaraciones normales YA ocurrió antes de llegar aquí (ver
     * StyleResolver::resolveDeferred()), así que $declarations nunca contiene var() sin resolver
     * a estas alturas. $warnings (opcional, igual patrón que Layout\*FormattingContext) recibe los
     * warnings de plegado de calc() que solo pueden detectarse aquí (depende del font-size propio/
     * raíz de ESTE elemento): % en una propiedad que no la admite (height/gap/border-width/
     * border-spacing) y — en tareas futuras — signo inválido.
     *
     * M10-T1 (css-values-4 §5.1.1): $pageWidthPx/$pageHeightPx thread through EXACTLY like
     * $remBase (StyleResolver captures them once from Engine's configured Page\PaperSize and
     * passes them to every compute() call for the whole tree) -- where any vw/vh symbolic
     * CssLength/CalcExpr component finally dies, resolved against the PAPER box (adjudicated,
     * see LengthUnit's docblock), never the content box.
     *
     * @param array<string, mixed> $declarations
     * @param array<string, string> $customProperties
     */
    public static function compute(
        array $declarations,
        self $parent,
        string $tagName,
        float $remBase,
        array $customProperties = [],
        ?WarningCollector $warnings = null,
        float $pageWidthPx = self::DEFAULT_PAGE_WIDTH_PX,
        float $pageHeightPx = self::DEFAULT_PAGE_HEIGHT_PX,
    ): self {
        $zero = LengthPercentage::zero();
        $tag = strtolower($tagName);
        $displayValue = $declarations['display'] ?? null;
        // M7-T2: el default "oculto" (head/script/style/title/meta/link) YA NO vive aquí como
        // lista de tags — es una regla UA real (Style\UserAgentStylesheet, display:none) que
        // llega mezclada en $declarations['display'] como cualquier otro 'none' de autor, cubierta
        // por el match de $displayValue de abajo. TABLE_DISPLAY_BY_TAG es la única tabla de
        // defaults-por-tag que sigue viviendo aquí (ver su docblock para el porqué).
        $display = match (true) {
            array_key_exists($tag, self::TABLE_DISPLAY_BY_TAG) => self::TABLE_DISPLAY_BY_TAG[$tag],
            default => Display::Block,
        };
        $display = match ($displayValue) {
            'none' => Display::None,
            // css-flexbox-1 §2: sigue siendo un block-level box en el flujo normal — M4-T4
            // introduce FlexFormattingContext; hasta entonces fluye como Block (ver Display::Flex).
            'flex' => Display::Flex,
            'block' => Display::Block,
            // css-tables-3 §2: las cinco display values de tabla — M5-T3/T4 construyen
            // TableBox/TableFormattingContext a partir de estos; hasta entonces BoxTreeBuilder
            // sigue generando un BlockBox plano (ver Display::Table y comentario del case).
            'table' => Display::Table,
            'table-row' => Display::TableRow,
            'table-cell' => Display::TableCell,
            'table-header-group' => Display::TableHeaderGroup,
            'table-row-group' => Display::TableRowGroup,
            // M7-T3 (css-lists-3 §3): <li> UA default (Style\UserAgentStylesheet) — ver
            // Display::ListItem para por qué no necesita ninguna tabla de defaults-por-tag aquí
            // (a diferencia de TABLE_DISPLAY_BY_TAG, que sí sigue siendo hardcoded).
            'list-item' => Display::ListItem,
            // M7-T4 (css-inline-3 reducido): 'inline' es ahora el default UA real de span/strong/
            // em/... (ver Display::Inline) -- un autor puede declararlo/pisarlo igual que cualquier
            // otro valor de este match. 'inline-block' no tiene ningún default de tag en esta hoja
            // UA (puramente autor, p.ej. Bootstrap `.btn { display: inline-block }`).
            'inline' => Display::Inline,
            'inline-block' => Display::InlineBlock,
            default => $display,
        };
        // M6-T3: font-size se resuelve ANTES que cualquier otra propiedad porque su resultado
        // ($fontSizePx) es la base "own font-size" que usan em/% en TODAS las demás propiedades
        // de este elemento (ver $resolveCssLength más abajo). font-size es la ÚNICA propiedad
        // donde em/% se miden contra el font-size del PADRE, no el propio (css-values-3 §5.2 /
        // CSS 2.2 §10.8.1: evita la circularidad "font-size relativo a sí mismo"); rem siempre
        // contra $remBase, igual que en cualquier otra propiedad.
        $fontSizeValue = $declarations['font-size'] ?? null;
        $fontSizePx = self::resolveFontSizePx($fontSizeValue, $parent->fontSizePx, $remBase, $pageWidthPx, $pageHeightPx);

        // Resolución genérica de CssLength simbólico para TODAS las demás propiedades (margin/
        // padding/width/height/row-gap/column-gap/border-width/border-spacing/flex-basis): em
        // contra el font-size PROPIO ($fontSizePx, ya resuelto arriba), rem contra $remBase. Px
        // (incluye pt/cm/mm/in, ya plegados en CssLength::fromCss) pasa el valor tal cual.
        // M10-T1: vw/vh -- contra el PAPER box (pageWidthPx/pageHeightPx), la misma adjudicación
        // que en cualquier otro punto de resolución de esta clase (ver LengthUnit).
        $resolveCssLength = static fn(CssLength $css): float => match ($css->unit) {
            LengthUnit::Em => $css->value * $fontSizePx,
            LengthUnit::Rem => $css->value * $remBase,
            LengthUnit::Vw => ($css->value / 100.0) * $pageWidthPx,
            LengthUnit::Vh => ($css->value / 100.0) * $pageHeightPx,
            default => $css->value,
        };
        // M6-T4 (css-values-3 §8): plegado de un CalcExpr en un contexto de longitud PURA (sin
        // %, height/row-gap/column-gap/border-*-width/border-spacing) — $percentBase=null en
        // fold(): si el árbol contenía %, el resultado es un CalcValue (no un float) y eso es
        // justo la señal de "% no soportado aquí", igual que el rechazo ya existente de "50%"
        // literal en esas mismas propiedades (ver DeclarationParser::LENGTH_PROPERTIES) — mismo
        // resultado observable (warning + valor descartado), vía un camino distinto.
        //
        // M6-T4 fix (Finding 2, parte 2): un CalcExpr con em/rem (sin %) no tenía signo conocible
        // en DeclarationParser::rawValueOf() (dependía del font-size propio) — AHORA que
        // $fontSizePx/$remBase existen, se re-chequea aquí con el MISMO
        // DeclarationParser::NON_NEGATIVE_PROPERTIES que usaría un literal, ej. `calc(-1em)` en
        // padding a font-size 16 → -16px → warning + valor descartado (mismo resultado observable
        // que `padding-left: -16px` a secas). $label son siempre nombres de propiedad reales
        // (height/row-gap/column-gap/border-$side-width/border-spacing, todas SIEMPRE no-negativas
        // en esta rama), así que el chequeo nunca es un falso "always true/false" para PHPStan.
        $resolveCalcPure = static function (CalcExpr $expr, string $label, float $default) use ($fontSizePx, $remBase, $warnings, $pageWidthPx, $pageHeightPx): float {
            $folded = $expr->fold($fontSizePx, $remBase, null, $pageWidthPx, $pageHeightPx);
            if ($folded instanceof CalcValue) {
                $warnings?->addWarning("calc() with % not supported for $label (percentage discarded)");
                return $default;
            }
            if ($folded < 0.0 && in_array($label, DeclarationParser::NON_NEGATIVE_PROPERTIES, true)) {
                $warnings?->addWarning("Negative value not allowed for $label (calc() resolved to $folded at compute time)");
                return $default;
            }
            return $folded;
        };
        // Contraparte para longitud+porcentaje (margin/padding/width/flex-basis): % SÍ se admite,
        // pero se difiere a Layout igual que un "50%" literal — LengthPercentage::calc() envuelve
        // el CalcValue diferido, resolve($containingBlockPx) ya sabe interpretarlo (ver esa clase).
        // M6-T4 fix (Finding 2, parte 2): mismo re-chequeo que $resolveCalcPure arriba, pero SOLO
        // cuando el plegado dio un float definitivo (sin %) — si hay % el signo sigue sin poder
        // conocerse hasta Layout (gap documentado, ver el reporte de M6-T4 §4), así que NO se
        // chequea aquí. margin-* nunca está en NON_NEGATIVE_PROPERTIES (los márgenes SÍ admiten
        // negativo, CSS 2.2 §8.3), así que este mismo código los deja pasar sin warning.
        $resolveCalcLengthPercentage = static function (CalcExpr $expr, string $label) use ($fontSizePx, $remBase, $warnings, $pageWidthPx, $pageHeightPx): LengthPercentage {
            $folded = $expr->fold($fontSizePx, $remBase, null, $pageWidthPx, $pageHeightPx);
            if ($folded instanceof CalcValue) {
                return LengthPercentage::calc($folded);
            }
            if ($folded < 0.0 && in_array($label, DeclarationParser::NON_NEGATIVE_PROPERTIES, true)) {
                $warnings?->addWarning("Negative value not allowed for $label (calc() resolved to $folded at compute time)");
                return LengthPercentage::zero();
            }
            return LengthPercentage::px($folded);
        };
        $length = static function (string $key) use ($declarations, $resolveCssLength, $resolveCalcPure): ?Length {
            $v = $declarations[$key] ?? null;
            return match (true) {
                $v instanceof Length => $v,
                $v instanceof CssLength => Length::px($resolveCssLength($v)),
                $v instanceof CalcExpr => Length::px($resolveCalcPure($v, $key, 0.0)),
                default => null,
            };
        };
        $lengthPercentage = static function (string $key) use ($declarations, $resolveCssLength, $resolveCalcLengthPercentage, $zero): LengthPercentage {
            $v = $declarations[$key] ?? null;
            return match (true) {
                $v instanceof LengthPercentage => $v,
                $v instanceof CssLength => LengthPercentage::px($resolveCssLength($v)),
                $v instanceof CalcExpr => $resolveCalcLengthPercentage($v, $key),
                default => $zero,
            };
        };
        $hasLengthPercentage = static function (string $key) use ($declarations): bool {
            $v = $declarations[$key] ?? null;
            return $v instanceof LengthPercentage || $v instanceof CssLength || $v instanceof CalcExpr;
        };

        // M7-T2: el default por tag (b/strong/th → bold) MIGRÓ a Style\UserAgentStylesheet —
        // $declarations['font-weight'] ya trae el valor ganador del cascade (UA o autor), así que
        // esta rama vuelve a ser herencia CSS pura (int declarado > heredado del padre), sin
        // ningún chequeo de $tag.
        $fontWeightValue = $declarations['font-weight'] ?? null;
        $fontWeight = is_int($fontWeightValue) ? $fontWeightValue : $parent->fontWeight;

        // M7-T2: idem para i/em → italic (antes ITALIC_BY_DEFAULT) — ya viene resuelto en
        // $declarations vía la regla UA real.
        $fontStyleValue = $declarations['font-style'] ?? null;
        $fontStyle = match ($fontStyleValue) {
            'italic' => FontStyle::Italic,
            'normal' => FontStyle::Normal,
            default => $parent->fontStyle,
        };

        // M7-T2: idem para th → center (antes CENTER_ALIGN_BY_DEFAULT).
        $textAlignValue = $declarations['text-align'] ?? null;
        $textAlign = match ($textAlignValue) {
            'left' => TextAlign::Left,
            'center' => TextAlign::Center,
            'right' => TextAlign::Right,
            default => $parent->textAlign,
        };

        /**
         * text-decoration/underline no hereda en CSS real (es una propiedad de decoración que se
         * aplica al elemento, no vía herencia formal). M1 la simplifica tratándola como heredada
         * porque el pipeline de texto todavía no soporta islas de decoración independientes de la
         * herencia tipográfica; T3+ revisará esto si la precisión total resulta necesaria.
         *
         * M7-T2: el default por tag (a/u → underline, antes UNDERLINE_BY_DEFAULT) MIGRÓ a
         * Style\UserAgentStylesheet — ya llega resuelto en $declarations['text-decoration'].
         */
        $underline = array_key_exists('text-decoration', $declarations)
            ? (bool) $declarations['text-decoration']
            : $parent->underline;

        // M7-T2 (CSS 2.2 §16.6, reducido a normal|pre): hereda del padre cuando no hay
        // declaración propia — mismo patrón que $textAlign/$underline arriba.
        $whiteSpaceValue = $declarations['white-space'] ?? null;
        $whiteSpace = match ($whiteSpaceValue) {
            'normal' => 'normal',
            'pre' => 'pre',
            default => $parent->whiteSpace,
        };

        // M8-T5 (css-text-3 §8 reducido): letter-spacing/word-spacing -- ambas heredan (mismo
        // patrón "array_key_exists + null explícito = reset" que $lineHeightPx justo abajo):
        // 'normal' declarado explícitamente (DeclarationParser::parseSpacing() -> null) SIEMPRE
        // resetea a 0.0, cortando cualquier herencia de un ancestro; ausencia total de declaración
        // hereda $parent->letterSpacingPx/$parent->wordSpacingPx tal cual; un <length>
        // (Length/CssLength/CalcExpr) resuelve exactamente igual que cualquier otra longitud pura
        // de este método (mismos closures $resolveCssLength/$resolveCalcPure) -- SIN el chequeo de
        // NON_NEGATIVE_PROPERTIES (ninguna de las 2 propiedades figura en esa lista: CSS 2.2
        // §16.3.1/§16.4 admiten valores negativos).
        $letterSpacingPx = $parent->letterSpacingPx;
        if (array_key_exists('letter-spacing', $declarations)) {
            $letterSpacingValue = $declarations['letter-spacing'];
            $letterSpacingPx = match (true) {
                $letterSpacingValue === null => 0.0,
                $letterSpacingValue instanceof Length => $letterSpacingValue->px,
                $letterSpacingValue instanceof CssLength => $resolveCssLength($letterSpacingValue),
                $letterSpacingValue instanceof CalcExpr => $resolveCalcPure($letterSpacingValue, 'letter-spacing', 0.0),
                default => 0.0,
            };
        }
        $wordSpacingPx = $parent->wordSpacingPx;
        if (array_key_exists('word-spacing', $declarations)) {
            $wordSpacingValue = $declarations['word-spacing'];
            $wordSpacingPx = match (true) {
                $wordSpacingValue === null => 0.0,
                $wordSpacingValue instanceof Length => $wordSpacingValue->px,
                $wordSpacingValue instanceof CssLength => $resolveCssLength($wordSpacingValue),
                $wordSpacingValue instanceof CalcExpr => $resolveCalcPure($wordSpacingValue, 'word-spacing', 0.0),
                default => 0.0,
            };
        }

        // M8-T5: text-transform -- hereda (mismo patrón match-con-default-al-padre que $textAlign/
        // $whiteSpace arriba).
        $textTransformValue = $declarations['text-transform'] ?? null;
        $textTransform = match ($textTransformValue) {
            'none' => TextTransform::None,
            'uppercase' => TextTransform::Uppercase,
            'lowercase' => TextTransform::Lowercase,
            'capitalize' => TextTransform::Capitalize,
            default => $parent->textTransform,
        };

        // M10-T2 fix (root cause found investigating fixture 07's card-row height, oracle task):
        // when THIS element has no own `line-height` declaration, inheritance must reproduce
        // css-inline-3 §5.2 exactly -- if the nearest ancestor's line-height was declared as a
        // bare NUMBER (multiplier), that NUMBER (not its px, resolved against the ANCESTOR's own
        // font-size) is what inherits, and it must be reapplied against THIS element's OWN
        // $fontSizePx. Before this fix, a plain `$lineHeightPx = $parent->lineHeightPx` here
        // carried the ancestor's ALREADY-RESOLVED px straight down regardless of a smaller/larger
        // own font-size -- e.g. Bootstrap's `body{line-height:1.5}` (16px root -> 24px) leaking
        // unchanged onto `.small`/`.card-text` (14px own font-size, should recompute to 21px, not
        // stay at 24px) any time the descendant left line-height undeclared, which is the common
        // case (only a handful of Bootstrap rules -- headings, .card-title, .badge -- declare
        // their own line-height at all).
        $lineHeightMultiplier = $parent->lineHeightMultiplier;
        $lineHeightPx = $lineHeightMultiplier !== null
            ? $lineHeightMultiplier * $fontSizePx
            : $parent->lineHeightPx;
        if (array_key_exists('line-height', $declarations)) {
            $lineHeightValue = $declarations['line-height'];
            // Only a bare <number> re-normalizes against a DESCENDANT's own font-size on further
            // inheritance (see the property's own docblock) -- every other branch below resolves
            // to an ABSOLUTE px (or null, for 'normal') at THIS element, so the multiplier resets
            // to null for all of them, same as a declared Length/%/em/rem/vw/vh/calc() always has.
            $lineHeightMultiplier = is_float($lineHeightValue) ? $lineHeightValue : null;
            $lineHeightPx = match (true) {
                $lineHeightValue === null => null,
                $lineHeightValue instanceof Length => $lineHeightValue->px,
                is_float($lineHeightValue) => $lineHeightValue * $fontSizePx,
                // M6-T3: %/em en line-height se miden contra el font-size PROPIO (ya resuelto
                // arriba, igual que el multiplicador unitless de la rama anterior); rem contra
                // $remBase, igual que en cualquier otra propiedad no-font-size.
                $lineHeightValue instanceof CssLength => match ($lineHeightValue->unit) {
                    LengthUnit::Percent => ($lineHeightValue->value / 100.0) * $fontSizePx,
                    LengthUnit::Em => $lineHeightValue->value * $fontSizePx,
                    LengthUnit::Rem => $lineHeightValue->value * $remBase,
                    // M10-T1: line-height:Xvw/Xvh -- against the page's own CSS-px size, NOT
                    // $fontSizePx (vw/vh are a different axis entirely, see
                    // foldCalcWithOwnBase()'s own M10-T1 note just below for the calc() case).
                    LengthUnit::Vw => ($lineHeightValue->value / 100.0) * $pageWidthPx,
                    LengthUnit::Vh => ($lineHeightValue->value / 100.0) * $pageHeightPx,
                    default => null,
                },
                // M6-T4: igual que font-size, % en line-height se resuelve YA (contra el propio
                // font-size, no diferido a Layout) — comparte foldCalcWithOwnBase con font-size.
                $lineHeightValue instanceof CalcExpr => self::foldCalcWithOwnBase($lineHeightValue, $fontSizePx, $remBase, $pageWidthPx, $pageHeightPx),
                default => null,
            };
        }

        // color se computa antes de ensamblar los bordes: border-{side}-color por defecto
        // es currentColor (CSS 2.2 §8.5.3), es decir, el color computado de este elemento.
        //
        // M6-T5 (css-color-3 §4.4): 'color: currentColor' es el ÚNICO caso donde el sentinel NO
        // puede resolverse contra el propio $color (circular — todavía no existe) — CSS lo fija
        // al valor HEREDADO del padre, exactamente igual que si 'color' no se hubiera declarado en
        // absoluto (de ahí que ambas ramas del match converjan al mismo $parent->color).
        $declaredColor = $declarations['color'] ?? null;
        $color = match (true) {
            $declaredColor instanceof Color && $declaredColor->isCurrentColor => $parent->color,
            $declaredColor instanceof Color => $declaredColor,
            default => $parent->color,
        };

        // M6-T5: helper compartido por background-color y border-*-color — 'currentColor' (o
        // ausencia total de declaración, ver $borderSide más abajo) resuelve al $color YA
        // computado arriba (nunca al $parent->color: aquí currentColor SÍ puede referirse al color
        // propio de ESTE elemento, porque ya existe).
        $resolveCurrentColor = static fn(mixed $declared): ?Color => match (true) {
            $declared instanceof Color && $declared->isCurrentColor => $color,
            $declared instanceof Color => $declared,
            default => null,
        };

        $borderSide = static function (string $side) use ($declarations, $color, $resolveCurrentColor, $resolveCssLength, $resolveCalcPure): BorderSide {
            $width = $declarations["border-$side-width"] ?? null;
            $style = $declarations["border-$side-style"] ?? null;
            $sideColor = $declarations["border-$side-color"] ?? null;
            $resolvedStyle = $style instanceof BorderStyle ? $style : BorderStyle::None;
            // CSS 2.2 §8.5.3: "if the value of the border-style property is none... the
            // computed value of the border width is 0" — el ancho USADO se calcula aquí, en
            // origen, para que ningún consumidor (BlockFlowContext, Painter) pueda leer
            // ->widthPx sin pasar por esta regla.
            // M8-T4 (css-backgrounds-3 §4.3): Dashed/Dotted RESERVAN el mismo ancho que Solid --
            // solo BorderStyle::None colapsa el ancho USADO a 0 (CSS 2.2 §8.5.3: "if the value of
            // border-style is none... the computed value of the border width is 0"; ese texto
            // nunca mencionó "solid", así que dashed/dotted (M8) también aplican la fórmula
            // normal de abajo, igual que Solid ya hacía). Antes de esta tarea, dashed/dotted
            // jamás llegaban aquí (DeclarationParser los rechazaba con warning), así que este
            // cambio de `!== Solid` a `=== None` es observacionalmente un no-op para todo el
            // comportamiento M2-M7 (Solid/None eran los únicos dos valores posibles).
            $widthPx = match (true) {
                $resolvedStyle === BorderStyle::None => 0.0,
                $width instanceof Length => $width->px,
                $width instanceof CssLength => $resolveCssLength($width),
                $width instanceof CalcExpr => $resolveCalcPure($width, "border-$side-width", 0.0),
                default => 0.0,
            };
            return new BorderSide(
                $widthPx,
                $resolvedStyle,
                $resolveCurrentColor($sideColor) ?? $color,
            );
        };

        $boxSizingValue = $declarations['box-sizing'] ?? null;
        // box-sizing NO hereda (CSS Box Sizing L3 §2): el initial value es siempre content-box,
        // independientemente de $parent->boxSizing.
        $boxSizing = $boxSizingValue === 'border-box' ? 'border-box' : 'content-box';

        // M4-T1: ninguna propiedad flex hereda (ver comentario del constructor) — cada rama cae
        // directamente al initial value del spec cuando no hay declaración propia, nunca a
        // $parent->....
        $flexDirection = ($declarations['flex-direction'] ?? null) === 'column'
            ? FlexDirection::Column
            : FlexDirection::Row;
        $flexWrap = ($declarations['flex-wrap'] ?? null) === 'wrap' ? FlexWrap::Wrap : FlexWrap::NoWrap;
        $justifyContent = match ($declarations['justify-content'] ?? null) {
            'center' => JustifyContent::Center,
            'flex-end' => JustifyContent::FlexEnd,
            'space-between' => JustifyContent::SpaceBetween,
            default => JustifyContent::FlexStart,
        };
        $alignItems = match ($declarations['align-items'] ?? null) {
            'flex-start' => AlignItems::FlexStart,
            'center' => AlignItems::Center,
            'flex-end' => AlignItems::FlexEnd,
            default => AlignItems::Stretch,
        };
        $rowGapValue = $declarations['row-gap'] ?? null;
        $rowGapPx = match (true) {
            $rowGapValue instanceof Length => $rowGapValue->px,
            $rowGapValue instanceof CssLength => $resolveCssLength($rowGapValue),
            $rowGapValue instanceof CalcExpr => $resolveCalcPure($rowGapValue, 'row-gap', 0.0),
            default => 0.0,
        };
        $columnGapValue = $declarations['column-gap'] ?? null;
        $columnGapPx = match (true) {
            $columnGapValue instanceof Length => $columnGapValue->px,
            $columnGapValue instanceof CssLength => $resolveCssLength($columnGapValue),
            $columnGapValue instanceof CalcExpr => $resolveCalcPure($columnGapValue, 'column-gap', 0.0),
            default => 0.0,
        };
        $flexGrowValue = $declarations['flex-grow'] ?? null;
        $flexGrow = is_float($flexGrowValue) ? $flexGrowValue : 0.0;
        $flexShrinkValue = $declarations['flex-shrink'] ?? null;
        $flexShrink = is_float($flexShrinkValue) ? $flexShrinkValue : 1.0;
        // flex-basis: la longhand/shorthand emiten 'auto' (string), un LengthPercentage o un
        // CssLength simbólico (em/rem, M6-T3, resuelto contra el font-size propio/raíz igual que
        // el resto de longitudes no-font-size); el sentinel 'auto' y la ausencia de declaración
        // colapsan al mismo null (= auto), igual que el resto de propiedades opcionales de este
        // método.
        $flexBasisValue = $declarations['flex-basis'] ?? null;
        $flexBasis = match (true) {
            $flexBasisValue instanceof LengthPercentage => $flexBasisValue,
            $flexBasisValue instanceof CssLength => LengthPercentage::px($resolveCssLength($flexBasisValue)),
            $flexBasisValue instanceof CalcExpr => $resolveCalcLengthPercentage($flexBasisValue, 'flex-basis'),
            default => null,
        };

        // M5-T2: border-spacing SÍ hereda (CSS 2.2 §17.6.1) — a diferencia de todo lo demás en
        // esta sección, el fallback sin declaración propia es $parent->borderSpacingPx, no 0.0.
        $borderSpacingValue = $declarations['border-spacing'] ?? null;
        $borderSpacingPx = match (true) {
            $borderSpacingValue instanceof Length => $borderSpacingValue->px,
            $borderSpacingValue instanceof CssLength => $resolveCssLength($borderSpacingValue),
            $borderSpacingValue instanceof CalcExpr => $resolveCalcPure($borderSpacingValue, 'border-spacing', $parent->borderSpacingPx),
            default => $parent->borderSpacingPx,
        };

        // table-layout NO hereda (CSS 2.2 §17.5.2): initial value 'auto' siempre que no haya
        // declaración propia, nunca $parent->tableLayout.
        $tableLayout = ($declarations['table-layout'] ?? null) === 'fixed' ? 'fixed' : 'auto';

        // vertical-align NO hereda en CSS real (CSS 2.2 §10.8.1 no lo lista entre las
        // propiedades heredadas de §6.1) — cada elemento parte de VerticalAlign::Top (divergencia
        // documentada del initial value real "baseline", ver VerticalAlign) cuando no hay
        // declaración propia, nunca de $parent->verticalAlign.
        $verticalAlign = match ($declarations['vertical-align'] ?? null) {
            'middle' => VerticalAlign::Middle,
            'bottom' => VerticalAlign::Bottom,
            'top' => VerticalAlign::Top,
            default => VerticalAlign::Top,
        };

        // M7-T3 (css-lists-3 §3): list-style-type SÍ hereda — cae a $parent->listStyleType
        // (nunca al initial value directamente) cuando no hay declaración propia, mismo patrón
        // que $textAlign/$underline/$whiteSpace más arriba (todas heredadas). El keyword ya llega
        // validado por DeclarationParser::parseListStyleType()/parseListStyleShorthand() — un
        // valor ausente (más común: ningún selector UA/autor coincidió, p.ej. un <li> huérfano
        // sin <ul>/<ol> ancestro) simplemente hereda, resolviendo en última instancia al initial
        // value 'disc' fijado en ComputedStyle::root().
        $listStyleType = match ($declarations['list-style-type'] ?? null) {
            'disc' => ListStyleType::Disc,
            'circle' => ListStyleType::Circle,
            'square' => ListStyleType::Square,
            'decimal' => ListStyleType::Decimal,
            'none' => ListStyleType::None,
            default => $parent->listStyleType,
        };

        // M7-T5 (CSS 2.2 §10.4): min-width/max-width, igual criterio que width (arriba) --
        // LengthPercentage sin resolver (% diferido a Layout), null cuando no hay declaración
        // (initial 'auto'/'none', ver DeclarationParser). NO heredan (nunca $parent->minWidth).
        $minWidth = $hasLengthPercentage('min-width') ? $lengthPercentage('min-width') : null;
        $maxWidth = $hasLengthPercentage('max-width') ? $lengthPercentage('max-width') : null;
        // M7-T5 (CSS 2.2 §10.7): min-height/max-height, px-only (igual criterio que $height
        // arriba) -- $length() ya devuelve null cuando no hay declaración.
        $minHeight = $length('min-height');
        $maxHeight = $length('max-height');

        // M7-T5 (css-overflow-3): NO hereda -- initial 'visible' siempre que no haya declaración
        // propia, nunca $parent->overflow. DeclarationParser ya solo produce 'visible'|'hidden'
        // aquí (scroll/auto coercionados con warning antes de llegar).
        $overflowValue = $declarations['overflow'] ?? null;
        $overflow = $overflowValue === 'hidden' ? 'hidden' : 'visible';

        // M7-T6 (CSS 2.2 §9.5.1): NO hereda -- 'none' (ausencia de declaración, o el keyword
        // literal 'none') colapsa SIEMPRE a null, nunca a $parent->float.
        $floatValue = $declarations['float'] ?? null;
        $float = match ($floatValue) {
            'left' => FloatSide::Left,
            'right' => FloatSide::Right,
            default => null,
        };
        // M7-T6 (CSS 2.2 §9.5.2): NO hereda -- initial 'none' siempre que no haya declaración
        // propia. DeclarationParser ya solo produce estos 4 literales.
        $clearValue = $declarations['clear'] ?? null;
        $clear = in_array($clearValue, ['left', 'right', 'both'], true) ? $clearValue : 'none';
        // M7-T6 (CSS 2.2 §9.4.3): NO hereda -- 'sticky'/'fixed' ya nunca llegan aquí como valor
        // reconocido (DeclarationParser los rechaza con warning, ver KEYWORD_PROPERTIES['position']),
        // así que el default 'sin declaración reconocida' == Static cubre ese caso sin rama extra.
        $positionValue = $declarations['position'] ?? null;
        $position = match ($positionValue) {
            'relative' => Position::Relative,
            'absolute' => Position::Absolute,
            default => Position::Static,
        };
        // M7-T6 (CSS 2.2 §9.4.3): top/bottom -- px-only (mismo camino que height/min-height/
        // max-height, ver $length() arriba), envueltos en LengthPercentage::px() para compartir
        // TIPO con left/right (ver docblock del constructor) aunque nunca lleven componente de %
        // real. left/right -- LengthPercentage con % posible (mismo camino que width, ver
        // $lengthPercentage()/$hasLengthPercentage()). Ninguno de los 4 hereda: ausencia de
        // declaración = null siempre, nunca $parent->top/etc.
        $topLength = $length('top');
        $top = $topLength !== null ? LengthPercentage::px($topLength->px) : null;
        $bottomLength = $length('bottom');
        $bottom = $bottomLength !== null ? LengthPercentage::px($bottomLength->px) : null;
        $right = $hasLengthPercentage('right') ? $lengthPercentage('right') : null;
        $left = $hasLengthPercentage('left') ? $lengthPercentage('left') : null;

        // M8-T2 (css-backgrounds-3 §5): NO hereda -- $lengthPercentage() ya cae a $zero cuando la
        // longhand no está en $declarations (mismo mecanismo que padding/margin, nunca
        // $parent->borderRadius), así que "sin border-radius declarado" produce exactamente
        // BorderRadius::zero() por construcción, sin rama especial. % sigue simbólico (resuelto
        // en Layout, ver el docblock del constructor).
        $borderRadius = new BorderRadius(
            $lengthPercentage('border-top-left-radius'),
            $lengthPercentage('border-top-right-radius'),
            $lengthPercentage('border-bottom-right-radius'),
            $lengthPercentage('border-bottom-left-radius'),
        );

        // M6-T5: opacity NO hereda (ver docblock del constructor) — initial value 1.0 siempre que
        // no haya declaración propia, nunca $parent->opacity. DeclarationParser ya clampa a
        // [0,1] en tiempo de parseo; el clamp de aquí es puramente defensivo (por si algún día
        // opacity llega por un camino distinto a DeclarationParser, ej. una expansión futura de
        // shorthand) y no debería activarse nunca desde el pipeline real.
        $opacityValue = $declarations['opacity'] ?? null;
        $opacity = is_float($opacityValue) ? max(0.0, min(1.0, $opacityValue)) : 1.0;

        // M7-T2: font-family ya no es un string único — DeclarationParser::parseFontFamily()
        // produce list<string> (posiblemente vacía, p.ej. "font-family: ,,"). Una lista vacía (o
        // con elementos no-string, defensivo, nunca ocurre desde el parser real) hereda del
        // padre, igual que el resto de propiedades tipográficas sin declaración propia.
        // array_filter(..., 'is_string') + array_values() es el idiom que PHPStan estrecha a
        // list<string> sin necesidad de @var/assert (ver instrucciones del gate: prohibidos).
        $fontFamilyValue = $declarations['font-family'] ?? null;
        $fontFamily = is_array($fontFamilyValue) ? array_values(array_filter($fontFamilyValue, 'is_string')) : [];
        if ($fontFamily === []) {
            $fontFamily = $parent->fontFamily;
        }

        // M8-T3 (css-images-3 §3.1 reducido): NO hereda (ver docblock del constructor) -- initial
        // "none" (null) siempre que no haya declaración propia, nunca $parent->backgroundGradient.
        // DeclarationParser::parseBackgroundShorthand()/parseBackgroundImageValue() ya producen
        // este valor listo para usar (Gradient|nada, nunca otro tipo).
        $backgroundGradientValue = $declarations['background-gradient'] ?? null;
        $backgroundGradient = $backgroundGradientValue instanceof Gradient ? $backgroundGradientValue : null;

        // M8-T4 (css-backgrounds-3 §6 reducido): NO hereda (ver docblock del constructor) --
        // initial "none" (null) siempre que no haya declaración propia, nunca $parent->boxShadow.
        // DeclarationParser::parseBoxShadowValue() deja el raw en $declarations['box-shadow'] como
        // un array ['offsetX'=>.., 'offsetY'=>.., 'blur'=>.., 'color'=>Color] (Length|CssLength|
        // CalcExpr para las 3 longitudes, igual tipo que cualquier otra longitud de este método) --
        // se resuelve aquí con LOS MISMOS closures $resolveCssLength/$resolveCalcPure que border-*-
        // width, offsetX/offsetY con signo (box-shadow admite desplazamientos negativos, a
        // diferencia de border-width) y blur re-clampado a >=0 defensivamente (ya validado no-
        // negativo en el parser para el caso literal; un calc(1em) que resolviera negativo aquí es
        // el mismo gap documentado que el resto del motor tiene para calc()+unidad simbólica, ver
        // NON_NEGATIVE_PROPERTIES). El color, si no se declaró, llega como el sentinel
        // Color::currentColor() (ver parseBoxShadowValue()) -- se resuelve exactamente igual que
        // border-*-color/background-color, contra el $color YA computado de ESTE elemento.
        // M8-T6 (css-backgrounds-3 §4 reducido): NO hereda (ver docblock del constructor) --
        // initial "none" (null) siempre que no haya declaración propia, nunca
        // $parent->backgroundImagePath. DeclarationParser::parseBackgroundImageValue()/
        // parseBackgroundShorthand() ya producen este valor listo para usar (string, sin resolver
        // contra basePath -- eso ocurre en tiempo de pintado, ver el docblock del campo).
        $backgroundImagePathValue = $declarations['background-image'] ?? null;
        $backgroundImagePath = is_string($backgroundImagePathValue) ? $backgroundImagePathValue : null;

        // M8-T6: NO hereda -- initial 'auto' siempre que no haya declaración propia.
        // DeclarationParser::parseBackgroundSize() ya solo produce 'auto'|'cover'|'contain' aquí.
        $backgroundSize = match ($declarations['background-size'] ?? null) {
            'cover' => BackgroundSize::Cover,
            'contain' => BackgroundSize::Contain,
            default => BackgroundSize::Auto,
        };

        // M8-T6: NO hereda -- initial 'no-repeat' (false) siempre que no haya declaración propia.
        // DeclarationParser::parseBackgroundRepeat() ya solo produce bool aquí.
        $backgroundRepeatValue = $declarations['background-repeat'] ?? null;
        $backgroundRepeat = is_bool($backgroundRepeatValue) && $backgroundRepeatValue;

        // M8-T6: NO hereda -- initial 'top left' siempre que no haya declaración propia.
        // DeclarationParser::parseBackgroundPositionValue() ya solo produce 'center'|'top left'
        // aquí (ambas grafías 'top left'/'left top' ya colapsadas a 'top left' en el parser).
        $backgroundPosition = ($declarations['background-position'] ?? null) === 'center'
            ? BackgroundPosition::Center
            : BackgroundPosition::TopLeft;

        $boxShadowValue = $declarations['box-shadow'] ?? null;
        $boxShadow = null;
        if (is_array($boxShadowValue)) {
            $resolveShadowLength = static function (mixed $v) use ($resolveCssLength, $resolveCalcPure): float {
                return match (true) {
                    $v instanceof Length => $v->px,
                    $v instanceof CssLength => $resolveCssLength($v),
                    $v instanceof CalcExpr => $resolveCalcPure($v, 'box-shadow', 0.0),
                    default => 0.0,
                };
            };
            $shadowColorRaw = $boxShadowValue['color'] ?? null;
            $shadowColor = $resolveCurrentColor($shadowColorRaw instanceof Color ? $shadowColorRaw : null) ?? $color;
            $boxShadow = new BoxShadow(
                $resolveShadowLength($boxShadowValue['offsetX']),
                $resolveShadowLength($boxShadowValue['offsetY']),
                max(0.0, $resolveShadowLength($boxShadowValue['blur'])),
                $shadowColor,
            );
        }

        return new self(
            $display,
            $lengthPercentage('margin-top'),
            $lengthPercentage('margin-right'),
            $lengthPercentage('margin-bottom'),
            $lengthPercentage('margin-left'),
            $lengthPercentage('padding-top'),
            $lengthPercentage('padding-right'),
            $lengthPercentage('padding-bottom'),
            $lengthPercentage('padding-left'),
            $hasLengthPercentage('width') ? $lengthPercentage('width') : null,
            // height NO hereda (igual que width): siempre parte de las propias declaraciones del
            // elemento, nunca del padre.
            $length('height'),
            $resolveCurrentColor($declarations['background-color'] ?? null),
            $color,
            $fontSizePx,
            $fontFamily,
            $fontWeight,
            $fontStyle,
            $lineHeightPx,
            $lineHeightMultiplier,
            $textAlign,
            $underline,
            $whiteSpace,
            $borderSide('top'),
            $borderSide('right'),
            $borderSide('bottom'),
            $borderSide('left'),
            $boxSizing,
            $flexDirection,
            $flexWrap,
            $rowGapPx,
            $columnGapPx,
            $justifyContent,
            $alignItems,
            $flexGrow,
            $flexShrink,
            $flexBasis,
            $borderSpacingPx,
            $tableLayout,
            $verticalAlign,
            $listStyleType,
            $minWidth,
            $maxWidth,
            $minHeight,
            $maxHeight,
            $overflow,
            $float,
            $clear,
            $position,
            $top,
            $right,
            $bottom,
            $left,
            $borderRadius,
            $opacity,
            $customProperties,
            $backgroundGradient,
            $boxShadow,
            $letterSpacingPx,
            $wordSpacingPx,
            $textTransform,
            $backgroundImagePath,
            $backgroundSize,
            $backgroundRepeat,
            $backgroundPosition,
        );
    }
}
