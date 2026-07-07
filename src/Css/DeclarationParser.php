<?php

declare(strict_types=1);

namespace Pliego\Css;

use Pliego\Css\Value\BorderStyle;
use Pliego\Css\Value\CalcExpr;
use Pliego\Css\Value\CalcParser;
use Pliego\Css\Value\Color;
use Pliego\Css\Value\CssLength;
use Pliego\Css\Value\Gradient;
use Pliego\Css\Value\GradientKind;
use Pliego\Css\Value\GradientStop;
use Pliego\Css\Value\Length;
use Pliego\Css\Value\LengthPercentage;
use Pliego\Css\Value\LengthUnit;

final class DeclarationParser
{
    /** height/row-gap/column-gap NO admiten % (height no está en el contrato T2; row-gap/
     * column-gap son px-only en M4, css-flexbox-1 §8.1 nota "% fuera de alcance aquí" —
     * parseLength() ya rechaza % de forma natural, generando el warning). font-size vive aparte
     * (ver parseFontSize, M6-T3): SÍ admite % (contra el font-size del padre), así que no puede
     * compartir esta lista de "solo longitud pura, nunca %". */
    /** M7-T5: min-height/max-height se unen aquí (igual criterio que height, ver su docblock: sin
     * containing height rastreada, % no tiene interpretación válida — parseLength() ya lo rechaza
     * de forma natural, produciendo el warning genérico "Unsupported length for min-height/
     * max-height: 50%" documentado en el brief). M7-T6: top/bottom TAMBIÉN se unen aquí (CSS 2.2
     * §9.4.3) — mismo motivo de fondo (la altura del containing block es, en general,
     * indeterminada en este motor, ver ComputedStyle::$height), así que un % en top/bottom cae al
     * mismo warning genérico + valor descartado que height. A diferencia de los otros 4 miembros,
     * top/bottom SÍ admiten negativo (ver el chequeo de signo condicionado a
     * NON_NEGATIVE_PROPERTIES más abajo, en vez de incondicional). */
    private const array LENGTH_PROPERTIES = ['height', 'row-gap', 'column-gap', 'min-height', 'max-height', 'top', 'bottom'];
    /** CSS 2.2 §10: width, margin-{side} y padding-{side} sí admiten %, resuelto en used-value (T4).
     * M7-T5 (CSS 2.2 §10.4): min-width/max-width comparten el MISMO tratamiento que width — %
     * resuelto contra el ancho del containing block en Layout (ComputedStyle::compute() los deja
     * en LengthPercentage sin resolver, igual que width/margin/padding). min-height/max-height NO
     * están aquí (ver LENGTH_PROPERTIES, px-only — el motor no rastrea la altura del containing
     * block, ver el docblock de ComputedStyle::$height/$minHeight/$maxHeight). */
    /** M7-T6 (CSS 2.2 §9.4.3): left/right se UNEN aquí — a diferencia de top/bottom (arriba, en
     * LENGTH_PROPERTIES, px-only), un % en left/right SÍ tiene interpretación clara (siempre se
     * resuelve contra el ANCHO del containing block, que este motor SIEMPRE conoce, ver el brief
     * de esta tarea) — igual tratamiento que width/margin-*. Tampoco están en
     * NON_NEGATIVE_PROPERTIES (un offset de posicionamiento admite negativo, CSS 2.2 §9.4.3), así
     * que el chequeo de signo de la rama de abajo los deja pasar sin más. */
    private const array LENGTH_PERCENTAGE_PROPERTIES = [
        'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'width', 'min-width', 'max-width',
        'left', 'right',
    ];
    /**
     * CSS 2.2 §8.4/§10.2/§10.5/§15.7: negativos inválidos en LENGTH_PERCENTAGE_PROPERTIES;
     * margin-* es la única excepción ahí. font-size (parseFontSize(), aparte -- ver su propio
     * docblock arriba, "font-size vive aparte") tiene su PROPIO chequeo incondicional, ajeno a esta
     * constante, así que no necesita figurar aquí (evita el "always true" que detecta PHPStan al
     * estrechar el tipo) -- height, en cambio, SÍ figura (ver más abajo, M8-T1 housekeeping).
     *
     * M6-T4 fix (Finding 2): visibilidad `public` y lista AMPLIADA a la lista COMPLETA de
     * propiedades no-negativas del motor — antes solo cubría las 5 gateadas explícitamente aquí
     * mismo (líneas más abajo, chequeo de literales en LENGTH_PERCENTAGE_PROPERTIES); en aquel
     * momento height/row-gap/column-gap/border-*-width/border-spacing/flex-basis YA eran
     * no-negativas siempre en sus propios sitios de parseo (chequeo incondicional, sin consultar
     * esta constante) — añadirlas aquí no cambiaba ESE comportamiento (seguían rechazándose igual),
     * solo hacía la lista consultable desde `ComputedStyle::compute()`, que necesita el mismo
     * criterio para re-chequear el signo de un CalcExpr con em/rem UNA VEZ conocido el font-size
     * propio (ver rawValueOf() más abajo y ComputedStyle::compute() — el signo de un calc() con %
     * sigue sin poder conocerse hasta Layout, gap documentado, ver el reporte de M6-T4 §4).
     *
     * M8-T1 housekeeping (stale comment fix): la frase de arriba -- "chequeo incondicional, sin
     * consultar esta constante" -- describía la rama LENGTH_PROPERTIES tal como era ANTES de
     * M7-T6, pero dejó de ser cierta para sus 5 miembros preexistentes (height/row-gap/column-gap/
     * min-height/max-height) en cuanto top/bottom se UNIERON a esa misma lista: el chequeo de signo
     * de esa rama (más abajo, en parse()) pasó de incondicional a `in_array(...,
     * NON_NEGATIVE_PROPERTIES, true)` PARA TODOS sus miembros, no solo top/bottom -- necesario para
     * que top/bottom (que SÍ admiten negativo) queden excluidos sin duplicar la rama. height SÍ
     * consulta esta constante hoy (por eso figura en la lista de abajo, línea "'height', 'row-gap',
     * ..."), simplemente su membresía ahí siempre evalúa a "no-negativo" -- ningún comportamiento
     * observable cambió, solo el mecanismo por el que se llega a él (ver el docblock de la rama
     * LENGTH_PROPERTIES en parse() para el detalle completo).
     */
    public const array NON_NEGATIVE_PROPERTIES = [
        'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'width',
        'height', 'row-gap', 'column-gap',
        'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
        'border-spacing', 'flex-basis',
        // M7-T5 (CSS 2.2 §10.4/§10.7): negativo no tiene interpretación válida para ninguna de las
        // 4 -- mismo criterio de signo que width/height, reutilizando la guarda existente.
        'min-width', 'max-width', 'min-height', 'max-height',
        // M8-T2 (css-backgrounds-3 §5): un radio negativo no tiene interpretación válida -- mismo
        // criterio que border-*-width. Necesario aquí (y no solo en el chequeo literal ad-hoc de
        // parseBorderRadiusLonghand()/expandBorderRadiusShorthand()) para que ComputedStyle::
        // compute() re-chequee el signo de un calc(em/rem) una vez conocido el font-size propio,
        // igual que el resto de propiedades de esta lista (ver $resolveCalcLengthPercentage).
        'border-top-left-radius', 'border-top-right-radius',
        'border-bottom-right-radius', 'border-bottom-left-radius',
    ];
    private const array COLOR_PROPERTIES = ['color', 'background-color'];
    private const array KEYWORD_PROPERTIES = [
        // css-tables-3 §2: los 5 display values de tabla soportados en M5 (grep OBLIGATORIO
        // hecho en ComputedStyle/BoxTreeBuilder antes de añadirlos aquí — ver Display::Table).
        'display' => [
            'block', 'none', 'flex',
            'table', 'table-row', 'table-cell', 'table-header-group', 'table-row-group',
            // M7-T3 (css-lists-3 §3): Display::ListItem (ver su docblock) -- UserAgentStylesheet
            // lo usa vía `li { display: list-item }`, un autor puede declararlo/pisarlo igual que
            // cualquier otro valor de esta lista.
            'list-item',
            // M7-T4 (css-inline-3 reducido): Display::Inline/InlineBlock -- 'inline' es ahora el
            // default UA de span/strong/em/... (ver UserAgentStylesheet, migrado desde el
            // INLINE_TAGS hardcoded de BoxTreeBuilder); 'inline-block' es puramente autor (ningún
            // tag lo declara por defecto en esta hoja UA), el caso ".btn"/".badge" del brief.
            'inline', 'inline-block',
        ],
        'box-sizing' => ['content-box', 'border-box'],
        // css-flexbox-1 §5.1/§5.2/§8.2/§8.3: *-reverse, wrap-reverse, space-around/evenly y
        // baseline son válidos en CSS pero fuera de alcance en M4 — al no estar en la lista
        // "allowed" caen al warning genérico de KEYWORD_PROPERTIES, igual que box-sizing:padding-box.
        'flex-direction' => ['row', 'column'],
        'flex-wrap' => ['nowrap', 'wrap'],
        'justify-content' => ['flex-start', 'center', 'flex-end', 'space-between'],
        'align-items' => ['stretch', 'flex-start', 'center', 'flex-end'],
        // CSS 2.2 §17.5.2: 'auto' (initial) y 'fixed' — M5-T4 consume $tableLayout, aquí solo
        // se valida y parsea el keyword.
        'table-layout' => ['auto', 'fixed'],
        // M7-T2 (CSS 2.2 §16.6, reducido): 'normal' (initial, colapsa whitespace/envuelve línea,
        // comportamiento de siempre) y 'pre' (no colapsa, \n fuerza salto de línea, sin wrap —
        // ver BoxTreeBuilder::textRunTokensFor()/collapse() e InlineFlowContext::layout()).
        // 'nowrap'/'pre-wrap'/'pre-line' son válidos en CSS pero fuera de alcance M7 -- caen al
        // warning genérico de KEYWORD_PROPERTIES, igual que cualquier otro keyword no soportado.
        'white-space' => ['normal', 'pre'],
        // M7-T3 (css-lists-3 §3): los 5 valores soportados — ver Style\ListStyleType. Reutilizado
        // (mismos 5 literales) en parseListStyleShorthand() más abajo; no se referencia esta
        // constante desde ahí para no acoplar el shorthand a la forma exacta de este mapa.
        'list-style-type' => ['disc', 'circle', 'square', 'decimal', 'none'],
        // M7-T3 (css-lists-3 §3, reducido): 'outside' es el ÚNICO valor soportado -- y, a
        // diferencia de cualquier otro miembro de este mapa, NO produce ninguna propiedad
        // consumible en ComputedStyle (no existe un campo $listStylePosition: M7 solo implementa
        // el comportamiento "outside", hardcoded en BlockFlowContext, así que no hay nada que
        // diferenciar en tiempo de layout). Se valida/acepta aquí de todas formas (en vez de
        // ignorar la propiedad entera) para que 'inside' -- fuera de alcance M7, ver RESTRICCIONES
        // GLOBALES -- caiga al warning genérico de este mismo bloque ("Unsupported keyword for
        // list-style-position: inside"), cumpliendo la disciplina de warnings del milestone
        // (TODO lo excluido avisa) sin necesitar una rama dedicada.
        'list-style-position' => ['outside'],
        // M7-T6 (CSS 2.2 §9.5.1): 'none' (initial, el caso común) colapsa a
        // ComputedStyle::$float === null -- ver Style\FloatSide, "shape-outside"/float con
        // recorte no rectangular quedan fuera de alcance (RESTRICCIONES GLOBALES).
        'float' => ['left', 'right', 'none'],
        // M7-T6 (CSS 2.2 §9.5.2): los 4 valores completos del spec -- ninguno queda fuera de
        // alcance, a diferencia de 'position' justo abajo.
        'clear' => ['left', 'right', 'both', 'none'],
        // M7-T6 (CSS 2.2 §9.4.3 / css-position-3): 'sticky' y 'fixed' NO están en esta lista
        // deliberadamente -- caen al warning genérico de "Unsupported keyword for position" de
        // más abajo (mismo mecanismo que cualquier otro keyword no soportado de este mapa) y la
        // propiedad simplemente no se establece, colapsando a Position::Static en
        // ComputedStyle::compute() (el initial value real, ver Style\Position) -- exactamente el
        // fallback documentado en el brief del milestone para 'sticky' ("warning, treated as
        // static") y para 'fixed' (fuera de alcance M7, M8+).
        'position' => ['static', 'relative', 'absolute'],
    ];

    /** css-flexbox-1 §7.1.1: N sin unidad en la forma "flex: N ..." nunca admite signo (grow y
     * shrink son siempre >= 0); un negativo cae al warning genérico del shorthand/longhand. */
    private const string FLEX_NUMBER_RE = '/^\d+(?:\.\d+)?$/';

    private const array BORDER_SIDES = ['top', 'right', 'bottom', 'left'];
    private const array BORDER_WIDTH_KEYWORDS = ['thin' => 1.0, 'medium' => 3.0, 'thick' => 5.0];
    /** M8-T2 (css-backgrounds-3 §5, reducido): orden CLOCKWISE del shorthand -- tl, tr, br, bl --
     * a diferencia de margin/padding (TRBL, ver expandBoxShorthand()), border-radius empieza en la
     * esquina superior-izquierda y va en sentido horario (spec real, no una elección de este
     * motor). */
    private const array BORDER_RADIUS_LONGHANDS = [
        'border-top-left-radius', 'border-top-right-radius',
        'border-bottom-right-radius', 'border-bottom-left-radius',
    ];

    /** @var list<string> */
    private array $warnings = [];

    /** @return array<string, mixed> */
    public function parse(string $property, string $value): array
    {
        $property = strtolower(trim($property));
        $value = trim($value);
        // M7-T5 (CSS 2.2 §10.4): 'auto' es el initial value real de min-width/min-height ("sin
        // mínimo"), 'none' el de max-width/max-height ("sin máximo") -- ambos colapsan al MISMO
        // null que "propiedad no declarada en absoluto" en ComputedStyle::compute() (ver
        // $lengthPercentage()/$length() ahí: ausencia de clave => null), así que aceptarlos aquí
        // como "sin declaración" (array vacío, sin warning) es observacionalmente idéntico a
        // rechazarlos con éxito -- pero SIN el warning espurio que produciría intentar parsearlos
        // como longitud (spec keywords válidos, no un error de autor).
        if (($property === 'min-width' || $property === 'min-height') && strtolower(trim($value)) === 'auto') {
            return [];
        }
        if (($property === 'max-width' || $property === 'max-height') && strtolower(trim($value)) === 'none') {
            return [];
        }
        if ($property === 'margin' || $property === 'padding') {
            return $this->expandBoxShorthand($property, $value);
        }
        if ($property === 'gap') {
            return $this->expandGapShorthand($value);
        }
        if ($property === 'flex') {
            return $this->parseFlexShorthand($value);
        }
        if ($property === 'border' || in_array($property, ['border-top', 'border-right', 'border-bottom', 'border-left'], true)) {
            return $this->expandBorderShorthand($property, $value);
        }
        if ($property === 'border-radius') {
            return $this->expandBorderRadiusShorthand($value);
        }
        if (in_array($property, self::BORDER_RADIUS_LONGHANDS, true)) {
            return $this->parseBorderRadiusLonghand($property, $value);
        }
        if ($property === 'list-style') {
            return $this->parseListStyleShorthand($value);
        }
        // M8-T3 (css-images-3 §3.1 reducido): 'background' (shorthand) y 'background-image'
        // (longhand) -- ambos detectan linear-gradient()/radial-gradient() (color O gradiente O
        // imagen url(), "detectar" per el brief); url() todavía no está soportado (background-image
        // real de M8-T6, un milestone posterior) así que cae a warning + valor descartado, igual
        // disciplina "todo lo excluido avisa" que el resto de este parser.
        if ($property === 'background') {
            return $this->parseBackgroundShorthand($value);
        }
        if ($property === 'background-image') {
            return $this->parseBackgroundImageValue($value);
        }
        // M8-T4 (css-backgrounds-3 §6 reducido): box-shadow -- ver parseBoxShadowValue() para la
        // gramática soportada (sin inset/spread/multi-shadow reales, ver su docblock).
        if ($property === 'box-shadow') {
            return $this->parseBoxShadowValue($value);
        }
        if ($this->isBorderLonghand($property, 'width')) {
            return $this->parseBorderWidth($property, $value);
        }
        if ($this->isBorderLonghand($property, 'style')) {
            return $this->parseBorderStyle($property, $value);
        }
        if ($this->isBorderLonghand($property, 'color')) {
            $color = Color::fromCss($value);
            if ($color === null) {
                return $this->warn("Unsupported color for $property: $value");
            }
            return [$property => $color];
        }
        if (in_array($property, self::LENGTH_PERCENTAGE_PROPERTIES, true)) {
            $lengthPercentage = $this->parseLengthPercentage($value);
            if ($lengthPercentage === null) {
                return $this->warn("Unsupported length for $property: $value");
            }
            if (self::rawValueOf($lengthPercentage) < 0.0 && in_array($property, self::NON_NEGATIVE_PROPERTIES, true)) {
                return $this->warn("Negative value not allowed for $property: $value");
            }
            return [$property => $lengthPercentage];
        }
        if (in_array($property, self::LENGTH_PROPERTIES, true)) {
            $length = $this->parseLength($value);
            if ($length === null) {
                return $this->warn("Unsupported length for $property: $value");
            }
            // height/row-gap/column-gap/min-height/max-height son siempre no-negativos (los 5
            // están en NON_NEGATIVE_PROPERTIES). M7-T6: top/bottom se UNIERON a LENGTH_PROPERTIES
            // (arriba) pero NO a NON_NEGATIVE_PROPERTIES -- un offset de posicionamiento SÍ admite
            // negativo (CSS 2.2 §9.4.3, p.ej. `top: -10px` para desplazar hacia arriba) -- de ahí
            // que este chequeo, antes incondicional, ahora consulte la misma lista que ya usa la
            // rama LENGTH_PERCENTAGE_PROPERTIES de más arriba, sin cambiar el resultado para
            // ninguno de los 5 miembros preexistentes.
            if (self::rawValueOf($length) < 0.0 && in_array($property, self::NON_NEGATIVE_PROPERTIES, true)) {
                return $this->warn("Negative value not allowed for $property: $value");
            }
            return [$property => $length];
        }
        if ($property === 'font-size') {
            return $this->parseFontSize($value);
        }
        if ($property === 'font-family') {
            return $this->parseFontFamily($value);
        }
        if (in_array($property, self::COLOR_PROPERTIES, true)) {
            $color = Color::fromCss($value);
            if ($color === null) {
                return $this->warn("Unsupported color for $property: $value");
            }
            return [$property => $color];
        }
        if (array_key_exists($property, self::KEYWORD_PROPERTIES)) {
            // M7-T2: 'font-family' -- el único miembro con allowed=null ("cualquier string") --
            // se sacó de este mapa a su propia rama (parseFontFamily(), ver arriba), así que
            // TODOS los miembros restantes tienen ahora una lista real: el chequeo "$allowed !==
            // null" quedaría siempre-verdadero (PHPStan lo marca), se retira.
            $allowed = self::KEYWORD_PROPERTIES[$property];
            $keyword = strtolower($value);
            if (!in_array($keyword, $allowed, true)) {
                return $this->warn("Unsupported keyword for $property: $value");
            }
            return [$property => $keyword];
        }
        if ($property === 'font-weight') {
            return $this->parseFontWeight($value);
        }
        if ($property === 'font-style') {
            return $this->parseFontStyle($value);
        }
        if ($property === 'line-height') {
            return $this->parseLineHeight($value);
        }
        if ($property === 'text-align') {
            return $this->parseTextAlign($value);
        }
        if ($property === 'text-decoration') {
            return $this->parseTextDecoration($value);
        }
        if ($property === 'flex-grow' || $property === 'flex-shrink') {
            return $this->parseFlexNumber($property, $value);
        }
        if ($property === 'flex-basis') {
            return $this->parseFlexBasis($value);
        }
        if ($property === 'border-spacing') {
            return $this->parseBorderSpacing($value);
        }
        if ($property === 'vertical-align') {
            return $this->parseVerticalAlign($value);
        }
        if ($property === 'opacity') {
            return $this->parseOpacity($value);
        }
        if ($property === 'overflow') {
            return $this->parseOverflow($value);
        }
        return $this->warn("Unsupported property: $property");
    }

    private function isBorderLonghand(string $property, string $suffix): bool
    {
        foreach (self::BORDER_SIDES as $side) {
            if ($property === "border-$side-$suffix") {
                return true;
            }
        }
        return false;
    }

    /**
     * M6-T3 (css-values-3 §5-6): longitud PURA, sin %  — height, row-gap, column-gap,
     * border-{side}-width, border-spacing, el componente de longitud de line-height y los
     * tokens del shorthand `gap`. px/pt/cm/mm/in ya llegan resueltos a píxeles desde
     * CssLength::fromCss (Px), así que se envuelven directo en Length; em/rem quedan en el
     * CssLength simbólico tal cual, para que ComputedStyle::compute los resuelva contra el
     * font-size propio/raíz. % no tiene interpretación en una longitud pura (null, igual que el
     * comportamiento pre-M6-T3), salvo en las propiedades con manejo dedicado (font-size,
     * line-height) que llaman a CssLength::fromCss directamente en vez de a este método.
     */
    private function parseLength(string $value): Length|CssLength|CalcExpr|null
    {
        if ($this->looksLikeCalc($value)) {
            return $this->tryParseCalc($value);
        }
        $css = CssLength::fromCss($value);
        if ($css === null) {
            return null;
        }
        return match ($css->unit) {
            LengthUnit::Px => Length::px($css->value),
            LengthUnit::Em, LengthUnit::Rem => $css,
            default => null,
        };
    }

    /**
     * M6-T3: longitud+porcentaje — margin-*, padding-*, width, flex-basis y los componentes del
     * shorthand margin/padding. Percent sigue diferido a LengthPercentage (resuelto contra el
     * containing block en Layout, sin cambios respecto a M2); em/rem quedan en CssLength
     * simbólico para ComputedStyle::compute (resueltos contra el font-size propio/raíz, nunca
     * contra el containing block).
     */
    private function parseLengthPercentage(string $value): LengthPercentage|CssLength|CalcExpr|null
    {
        if ($this->looksLikeCalc($value)) {
            return $this->tryParseCalc($value);
        }
        $css = CssLength::fromCss($value);
        if ($css === null) {
            return null;
        }
        return match ($css->unit) {
            LengthUnit::Px => LengthPercentage::px($css->value),
            LengthUnit::Percent => LengthPercentage::percent($css->value),
            LengthUnit::Em, LengthUnit::Rem => $css,
            default => null,
        };
    }

    /** css-values-3 §8: true si, una vez recortado el valor, empieza literalmente por "calc(" —
     * a partir de ahí el parseo se COMPROMETE con la rama calc() (éxito -> CalcExpr, fallo ->
     * null con warning ya emitido dentro de tryParseCalc()), sin intentar CssLength::fromCss()
     * como fallback (evitaría un segundo warning confuso sobre el mismo valor). */
    private function looksLikeCalc(string $value): bool
    {
        return stripos(trim($value), 'calc(') === 0;
    }

    /** Extrae el cuerpo entre el "calc(" inicial y su paréntesis de cierre (que debe coincidir
     * exactamente con el final de la cadena — cualquier resto tras el cierre es sintaxis
     * inválida) y delega en CalcParser. Warnings de CalcParser se funden en $this->warnings, igual
     * que cualquier otro warning de este parser. */
    private function tryParseCalc(string $value): ?CalcExpr
    {
        $trimmed = trim($value);
        // looksLikeCalc() (el único llamador) ya garantizó que $trimmed empieza EXACTAMENTE por
        // "calc(" (5 caracteres) — el paréntesis de apertura está siempre en el índice 4, sin
        // necesidad de buscarlo (evita un stripos() que PHPStan tipa como int<0,max>|false).
        $openParen = 4;
        $closeParen = $this->matchingParen($trimmed, $openParen);
        if ($closeParen === null || $closeParen !== strlen($trimmed) - 1) {
            $this->warnings[] = "Invalid calc() expression: $value";
            return null;
        }
        $inner = substr($trimmed, $openParen + 1, $closeParen - $openParen - 1);
        $calcParser = new CalcParser();
        $expr = $calcParser->parse($inner);
        $this->warnings = [...$this->warnings, ...$calcParser->drainWarnings()];
        return $expr;
    }

    private function matchingParen(string $text, int $openIndex): ?int
    {
        $depth = 0;
        $length = strlen($text);
        for ($i = $openIndex; $i < $length; $i++) {
            if ($text[$i] === '(') {
                $depth++;
            } elseif ($text[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * M6-T4: divide por espacios en el NIVEL SUPERIOR de paréntesis — un token de shorthand
     * (margin/padding/gap/border/flex) puede ser "calc(1em + 4px)", que contiene espacios
     * INTERNOS; un preg_split('/\s+/') ingenuo (el comportamiento pre-M6-T4) lo fragmentaría en
     * 3 tokens espurios. Los espacios dentro de cualquier paréntesis (incluido un var() anidado
     * dentro de un calc(), o viceversa) se preservan como parte del mismo token.
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $value): array
    {
        $tokens = [];
        $current = '';
        $depth = 0;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '(') {
                $depth++;
                $current .= $char;
                continue;
            }
            if ($char === ')') {
                $depth--;
                $current .= $char;
                continue;
            }
            if ($depth === 0 && ctype_space($char)) {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }
                continue;
            }
            $current .= $char;
        }
        if ($current !== '') {
            $tokens[] = $current;
        }
        return $tokens;
    }

    /**
     * css-values-3 §5-6 + adjudicación M6-T3: font-size es la única propiedad de longitud pura
     * que SÍ admite % (relativo al font-size COMPUTADO DEL PADRE — CSS 2.2 §10.8.1 /
     * css-values-3, no del propio elemento, a diferencia de em en cualquier otra propiedad).
     * Todas las unidades pasan por aquí; solo Px resuelve ya mismo (Length), el resto
     * (Em/Rem/Percent) queda en CssLength simbólico hasta ComputedStyle::compute.
     *
     * @return array<string, mixed>
     */
    private function parseFontSize(string $value): array
    {
        if ($this->looksLikeCalc($value)) {
            $calc = $this->tryParseCalc($value);
            if ($calc === null) {
                return [];
            }
            // M6 final-review fix (Finding 2): a calc() with no em/rem/% is a definite px value
            // already knowable at parse time (see CalcExpr::isDefinite(), same fold already
            // applied to padding/width/etc. in DeclarationParser::rawValueOf() since M6-T4) —
            // font-size/line-height were left out of that fix (see the removed comment above);
            // this closes the gap: calc(-5px) is rejected exactly like the literal "-5px" would
            // be. A calc() WITH em/rem/% still has no knowable sign until ComputedStyle::compute
            // (depends on the parent's own font-size) — left untouched, same as before.
            if ($calc->isDefinite() && $calc->pxOffset < 0.0) {
                return $this->warn("Negative value not allowed for font-size: $value");
            }
            return ['font-size' => $calc];
        }
        $css = CssLength::fromCss($value);
        if ($css === null) {
            return $this->warn("Unsupported length for font-size: $value");
        }
        if ($css->value < 0.0) {
            return $this->warn("Negative value not allowed for font-size: $value");
        }
        return ['font-size' => $css->unit === LengthUnit::Px ? Length::px($css->value) : $css];
    }

    /** Valor crudo (sin resolver unidad simbólica) usado solo para el chequeo de negativos —
     * Length usa ->px, LengthPercentage/CssLength usan ->value. M6-T4 fix (Finding 2): un CalcExpr
     * SIN componente em/rem/% es un px DEFINITIVO ya conocido en tiempo de parseo (ver
     * CalcExpr::isDefinite()) — se pliega aquí para que el chequeo de negativos del llamador
     * (idéntico al de un literal) atrape `calc(-5px)` en padding/width/height/gap/border-width/
     * border-spacing/flex-basis exactamente igual que `-5px` a secas. Un calc() CON em/rem/% no
     * tiene signo conocible todavía (depende del font-size propio, solo disponible en
     * ComputedStyle::compute(), o del containing block, solo disponible en Layout) — se trata como
     * no negativo AQUÍ para no rechazarlo de forma prematura/incorrecta; el caso em/rem se
     * re-chequea en ComputedStyle::compute() en cuanto se conoce el font-size (mismo
     * NON_NEGATIVE_PROPERTIES, ahora exportado); el caso % queda como gap documentado (depende del
     * containing block, solo en Layout — ver el reporte de M6-T4 §4). */
    private static function rawValueOf(Length|LengthPercentage|CssLength|CalcExpr $value): float
    {
        if ($value instanceof CalcExpr) {
            return $value->isDefinite() ? $value->pxOffset : 0.0;
        }
        return $value instanceof Length ? $value->px : $value->value;
    }

    /** @return array<string, mixed> */
    private function parseBorderWidth(string $property, string $value): array
    {
        $length = $this->borderWidthFromToken($value);
        if ($length === null) {
            return $this->warn("Unsupported border width for $property: $value");
        }
        if (self::rawValueOf($length) < 0.0) {
            return $this->warn("Negative value not allowed for $property: $value");
        }
        return [$property => $length];
    }

    /** @return array<string, mixed> */
    private function parseBorderStyle(string $property, string $value): array
    {
        $style = $this->borderStyleFromToken($value);
        if ($style === null) {
            return $this->warn("Unsupported border style for $property: $value (only solid|none|dashed|dotted supported)");
        }
        return [$property => $style];
    }

    private function borderWidthFromToken(string $token): Length|CssLength|CalcExpr|null
    {
        $keyword = strtolower($token);
        if (array_key_exists($keyword, self::BORDER_WIDTH_KEYWORDS)) {
            return Length::px(self::BORDER_WIDTH_KEYWORDS[$keyword]);
        }
        return $this->parseLength($token);
    }

    private function borderStyleFromToken(string $token): ?BorderStyle
    {
        return match (strtolower($token)) {
            'solid' => BorderStyle::Solid,
            'none' => BorderStyle::None,
            // M8-T4 (css-backgrounds-3 §4.3): double/groove/ridge/inset/outset siguen fuera de
            // alcance -- caen al `null` genérico (warning "only solid|none|dashed|dotted
            // supported"), igual que antes de esta tarea.
            'dashed' => BorderStyle::Dashed,
            'dotted' => BorderStyle::Dotted,
            default => null,
        };
    }

    /**
     * CSS 2.2 §8.5.4: los 3 componentes (width, style, color) son opcionales y pueden
     * aparecer en cualquier orden dentro del shorthand.
     *
     * @return array<string, mixed>
     */
    private function expandBorderShorthand(string $property, string $value): array
    {
        $sides = $property === 'border' ? self::BORDER_SIDES : [substr($property, strlen('border-'))];
        $tokens = self::splitTopLevel($value);
        if ($tokens === []) {
            return $this->warn("Unsupported shorthand for $property: $value");
        }
        $width = null;
        $style = null;
        $color = null;
        foreach ($tokens as $token) {
            $tokenWidth = $this->borderWidthFromToken($token);
            if ($tokenWidth !== null) {
                if ($width !== null) {
                    return $this->warn("Duplicate border width component for $property: $value");
                }
                if (self::rawValueOf($tokenWidth) < 0.0) {
                    return $this->warn("Negative value not allowed for $property: $value");
                }
                $width = $tokenWidth;
                continue;
            }
            $tokenStyle = $this->borderStyleFromToken($token);
            if ($tokenStyle !== null) {
                if ($style !== null) {
                    return $this->warn("Duplicate border style component for $property: $value");
                }
                $style = $tokenStyle;
                continue;
            }
            $tokenColor = Color::fromCss($token);
            if ($tokenColor !== null) {
                if ($color !== null) {
                    return $this->warn("Duplicate border color component for $property: $value");
                }
                $color = $tokenColor;
                continue;
            }
            return $this->warn("Unsupported border component for $property: $token");
        }
        $result = [];
        foreach ($sides as $side) {
            if ($width !== null) {
                $result["border-$side-width"] = $width;
            }
            if ($style !== null) {
                $result["border-$side-style"] = $style;
            }
            if ($color !== null) {
                $result["border-$side-color"] = $color;
            }
        }
        return $result;
    }

    /**
     * M8-T2 (css-backgrounds-3 §5, reducido): `border-radius: <length-percentage>{1,4}` -- 1-4
     * valores, orden CLOCKWISE tl/tr/br/bl (ver BORDER_RADIUS_LONGHANDS), mismo patrón de
     * expansión 1/2/3/4 que expandBoxShorthand() salvo por el orden (TRBL ahí, TL-TR-BR-BL aquí --
     * spec real, no elección de este motor). Un '/' en cualquier parte del valor es la sintaxis
     * elíptica completa (`border-radius: <h>{1,4} / <v>{1,4}`, radios horizontal/vertical
     * distintos por esquina) -- fuera de alcance M8 (solo se soporta el radio circular, ver
     * Css\Value\BorderRadius), así que se rechaza ENTERO con un único warning antes de tokenizar
     * nada más (splitTopLevel() no sabe de '/', trataría "/ 20px" como tokens propios y produciría
     * un error de shorthand confuso en vez de este mensaje específico).
     *
     * @return array<string, mixed>
     */
    private function expandBorderRadiusShorthand(string $value): array
    {
        if (str_contains($value, '/')) {
            return $this->warn("Elliptical border-radius not supported: $value");
        }
        $parts = self::splitTopLevel(trim($value));
        $lengths = array_map($this->parseLengthPercentage(...), $parts);
        if ($parts === [] || in_array(null, $lengths, true) || count($lengths) > 4) {
            return $this->warn("Unsupported shorthand for border-radius: $value");
        }
        /** @var list<LengthPercentage|CssLength|CalcExpr> $lengths */
        foreach ($lengths as $length) {
            if (self::rawValueOf($length) < 0.0) {
                return $this->warn("Negative value not allowed for border-radius: $value");
            }
        }
        [$tl, $tr, $br, $bl] = match (count($lengths)) {
            1 => [$lengths[0], $lengths[0], $lengths[0], $lengths[0]],
            2 => [$lengths[0], $lengths[1], $lengths[0], $lengths[1]],
            3 => [$lengths[0], $lengths[1], $lengths[2], $lengths[1]],
            default => [$lengths[0], $lengths[1], $lengths[2], $lengths[3]],
        };
        return [
            'border-top-left-radius' => $tl, 'border-top-right-radius' => $tr,
            'border-bottom-right-radius' => $br, 'border-bottom-left-radius' => $bl,
        ];
    }

    /**
     * M8-T2: longhand individual -- UN solo valor (radio circular). Dos valores separados por
     * espacio (`border-top-left-radius: 10px 20px`, la forma elíptica del longhand, css-
     * backgrounds-3 §5) caen al mismo warning "elliptical... not supported" que el '/' del
     * shorthand, en vez del warning genérico de shorthand -- mensaje distinto a propósito (no hay
     * ningún shorthand aquí, un autor viendo "Unsupported shorthand" en un longhand sería confuso).
     *
     * @return array<string, mixed>
     */
    private function parseBorderRadiusLonghand(string $property, string $value): array
    {
        $parts = self::splitTopLevel(trim($value));
        if (count($parts) === 2) {
            return $this->warn("Elliptical border-radius not supported for $property: $value");
        }
        if (count($parts) !== 1) {
            return $this->warn("Unsupported $property: $value");
        }
        $length = $this->parseLengthPercentage($parts[0]);
        if ($length === null) {
            return $this->warn("Unsupported length for $property: $value");
        }
        if (self::rawValueOf($length) < 0.0) {
            return $this->warn("Negative value not allowed for $property: $value");
        }
        return [$property => $length];
    }

    /**
     * RESTRICCIONES GLOBALES M8 ("multiple backgrounds (primera capa se usa + warning)"): tanto
     * 'background' como 'background-image' admiten una LISTA de capas separadas por comas de
     * NIVEL SUPERIOR (css-backgrounds-3 §3.4 -- `background-image: linear-gradient(...), url(...)`
     * apila varias imágenes) -- fuera de alcance M8 entero, no solo de esta tarea. splitTopLevelCommas()
     * (reutilizado de la gramática de linear-gradient()/radial-gradient()) distingue correctamente
     * un ÚNICO linear-gradient(a, b) -- cuyas comas internas viven DENTRO de un paréntesis, así que
     * el split de nivel superior devuelve un solo segmento -- de una lista real de 2+ capas (comas
     * FUERA de cualquier paréntesis). Con 2+ segmentos, se avisa UNA vez y se seccion solo la
     * PRIMERA capa (recortada) para el resto del método -- exactamente "primera capa se usa +
     * warning" del brief.
     */
    private function firstBackgroundLayer(string $value): string
    {
        $layers = self::splitTopLevelCommas(trim($value));
        if (count($layers) > 1) {
            $this->warnings[] = "Multiple backgrounds not supported (using the first layer only): $value";
        }
        return $layers[0] ?? '';
    }

    /**
     * M8-T3 (css-images-3 §3.1 reducido): `background: <color> | <gradient-function> | url(...)`
     * -- forma MUY reducida del shorthand real (ningún position/size/repeat/attachment: quedan
     * fuera de alcance M8, ver RESTRICCIONES GLOBALES "multiple backgrounds"/"background-
     * attachment"). "Detectar" (per el brief): un valor que empieza por linear-gradient(/
     * radial-gradient( se parsea como gradiente; si no, un color válido gana; si no, url(...) cae
     * al warning de "no soportado todavía" (M8-T6); cualquier otra cosa, warning genérico.
     *
     * @return array<string, mixed>
     */
    private function parseBackgroundShorthand(string $value): array
    {
        $trimmed = $this->firstBackgroundLayer($value);
        if ($this->isGradientFunction($trimmed)) {
            $gradient = $this->parseGradientFunction($trimmed);
            if ($gradient === null) {
                return [];
            }
            // code review Finding 1 (css-backgrounds-3 §5): `background` is a SHORTHAND -- every
            // use of it resets ALL the sub-properties it covers, not just the one it declares a
            // value for (CSS 2.2 §12.5.3). This reduced grammar never carries a color AND a
            // gradient in the SAME shorthand value (see this method's docblock above), so a
            // gradient value always resets background-color to its initial 'transparent' --
            // represented here as an explicit `null` (not simply omitting the key), which is what
            // lets it win over a background-color a LESS-specific cascaded rule already set for
            // the same element (StyleResolver::matchedDeclarationsAndCustomProperties() merges
            // rule declarations key-by-key, in cascade order -- omitting the key here would let
            // that earlier value silently survive; `$declarations[$k] ?? null` in
            // ComputedStyle::compute() treats an explicit null exactly like "never declared").
            return ['background-gradient' => $gradient, 'background-color' => null];
        }
        if (stripos($trimmed, 'url(') === 0) {
            return $this->warn("Unsupported background (url() images land in a later milestone): $value");
        }
        $color = Color::fromCss($trimmed);
        if ($color !== null) {
            // code review Finding 1 (symmetric case): a color value resets background-gradient to
            // its initial 'none' the same way -- see the docblock above for why an explicit null
            // (not an omitted key) is required for the reset to actually win the cascade merge.
            return ['background-color' => $color, 'background-gradient' => null];
        }
        return $this->warn("Unsupported background shorthand: $value");
    }

    /**
     * M8-T3: `background-image: <gradient-function> | url(...) | none` -- misma detección que el
     * shorthand de arriba (ver su docblock), sin la rama de color (background-image nunca acepta
     * un color liso).
     *
     * @return array<string, mixed>
     */
    private function parseBackgroundImageValue(string $value): array
    {
        if (strtolower(trim($value)) === 'none') {
            return ['background-gradient' => null];
        }
        $trimmed = $this->firstBackgroundLayer($value);
        if ($this->isGradientFunction($trimmed)) {
            $gradient = $this->parseGradientFunction($trimmed);
            return $gradient === null ? [] : ['background-gradient' => $gradient];
        }
        if (stripos($trimmed, 'url(') === 0) {
            return $this->warn("Unsupported background-image (url() images land in a later milestone): $value");
        }
        return $this->warn("Unsupported background-image: $value");
    }

    /**
     * M8-T4 (css-backgrounds-3 §6 reducido): `box-shadow: none | <shadow>#` -- SOLO UNA sombra
     * (comma-multiple -> primera capa + warning, mismo criterio "primera capa + warning" que
     * firstBackgroundLayer(); no se reutiliza ese método porque su mensaje/vocabulario es de
     * "background", no de "box-shadow"). Dentro de esa primera sombra:
     *   - `inset` -> warning + declaración ENTERA descartada (sin sombra M8, ver RESTRICCIONES
     *     GLOBALES/brief de esta tarea: "sin inset M8 -> warning").
     *   - 2 longitudes -> offset-x/offset-y (blur=0 implícito).
     *   - 3 longitudes -> + blur-radius (debe ser >= 0, igual criterio de signo que border-width).
     *   - 4 longitudes -> + spread -- warning ("no soportado, ignorado") pero la sombra SÍ se
     *     construye con las 3 primeras (adjudicación del brief: "spread -> warning + ignorada",
     *     a diferencia de inset que tira la declaración entera).
     *   - <1 o >4 longitudes -> warning + declaración descartada.
     *   - color OPCIONAL, en cualquier posición entre las longitudes (igual que
     *     expandBorderShorthand()) -- ausente, el raw value lleva el sentinel
     *     Color::currentColor() (resuelto contra el color computado propio en
     *     ComputedStyle::compute(), igual patrón que border-*-color/background-color).
     *
     * El raw value bajo la clave 'box-shadow' NO es todavía el VO final Css\Value\BoxShadow (ese
     * exige floats YA resueltos em/rem/calc()-aware, solo posible una vez conocido el font-size
     * propio del elemento) -- es un array asociativo con las 3 longitudes SIN resolver
     * (Length|CssLength|CalcExpr, igual tipo que cualquier otra longitud de este parser) + el
     * Color (concreto o el sentinel currentColor), que ComputedStyle::compute() ensambla en el VO
     * final exactamente como ya hace con border-*-width/color.
     *
     * @return array<string, mixed>
     */
    private function parseBoxShadowValue(string $value): array
    {
        $layers = self::splitTopLevelCommas(trim($value));
        if (count($layers) > 1) {
            $this->warnings[] = "Multiple box-shadows not supported (using the first only): $value";
        }
        $first = trim($layers[0] ?? '');
        if ($first === '' || strtolower($first) === 'none') {
            // 'none' es un reset EXPLÍCITO (igual criterio que background-image: none) -- se
            // devuelve la clave con valor `null`, no un array vacío, para que SÍ gane el cascade
            // sobre un box-shadow menos específico ya puesto por una regla anterior.
            return ['box-shadow' => null];
        }
        $tokens = self::splitTopLevel($first);
        if ($tokens === []) {
            return $this->warn("Unsupported box-shadow: $value");
        }
        $inset = false;
        $color = null;
        /** @var list<Length|CssLength|CalcExpr> $lengths */
        $lengths = [];
        foreach ($tokens as $token) {
            if (strtolower($token) === 'inset') {
                $inset = true;
                continue;
            }
            $tokenColor = Color::fromCss($token);
            if ($tokenColor !== null) {
                if ($color !== null) {
                    return $this->warn("Duplicate box-shadow color component: $value");
                }
                $color = $tokenColor;
                continue;
            }
            $tokenLength = $this->parseLength($token);
            if ($tokenLength === null) {
                return $this->warn("Unsupported box-shadow component \"$token\": $value");
            }
            $lengths[] = $tokenLength;
        }
        if ($inset) {
            return $this->warn("Unsupported box-shadow (inset not supported in M8): $value");
        }
        if (count($lengths) < 2 || count($lengths) > 4) {
            return $this->warn("Unsupported box-shadow (needs offset-x/offset-y, optionally blur-radius/spread): $value");
        }
        if (isset($lengths[2]) && self::rawValueOf($lengths[2]) < 0.0) {
            return $this->warn("Negative blur radius not allowed for box-shadow: $value");
        }
        if (isset($lengths[3])) {
            $this->warnings[] = "box-shadow spread not supported (ignored): $value";
        }
        return ['box-shadow' => [
            'offsetX' => $lengths[0],
            'offsetY' => $lengths[1],
            'blur' => $lengths[2] ?? Length::px(0.0),
            'color' => $color ?? Color::currentColor(),
        ]];
    }

    /**
     * M8-T3: true cuando $trimmed empieza literalmente por "linear-gradient(" o "radial-gradient("
     * -- a partir de ahí el llamador se COMPROMETE con parseGradientFunction() (éxito -> Gradient,
     * fallo -> null con el warning específico ya emitido dentro), sin intentar la rama de color/
     * url() como fallback (evitaría un segundo warning confuso sobre el mismo valor) — mismo
     * patrón que looksLikeCalc()/tryParseCalc() para calc().
     */
    private function isGradientFunction(string $trimmed): bool
    {
        $lower = strtolower($trimmed);
        return str_starts_with($lower, 'linear-gradient(') || str_starts_with($lower, 'radial-gradient(');
    }

    /**
     * M8-T3 (css-images-3 §3.1 reducido): dispatcher linear-gradient()/radial-gradient() --
     * conic-gradient() y cualquier otra función no reconocida caen al `null` genérico del llamador
     * (tryParseGradientPrefix() ya garantizó el prefijo correcto antes de llegar aquí, así que este
     * método SIEMPRE reconoce cuál de los dos es).
     */
    private function parseGradientFunction(string $value): ?Gradient
    {
        $inner = $this->functionArgs($value);
        if ($inner === null) {
            return $this->warnGradientNull("Invalid gradient syntax (unbalanced parentheses): $value");
        }
        return str_starts_with(strtolower($value), 'linear-gradient(')
            ? $this->parseLinearGradient($inner, $value)
            : $this->parseRadialGradient($inner, $value);
    }

    /** Extrae el contenido entre el primer '(' y su paréntesis de cierre (que debe coincidir
     *  exactamente con el final de la cadena) -- mismo criterio que tryParseCalc(). */
    private function functionArgs(string $value): ?string
    {
        $open = strpos($value, '(');
        if ($open === false) {
            return null;
        }
        $close = $this->matchingParen($value, $open);
        if ($close === null || $close !== strlen($value) - 1) {
            return null;
        }
        return substr($value, $open + 1, $close - $open - 1);
    }

    /**
     * css-images-3 §3.4.1: `linear-gradient([<angle> | to <side-or-corner>]? , <color-stop-list>)`.
     * Sin dirección declarada, el default real del spec es 180deg ("to bottom").
     */
    private function parseLinearGradient(string $inner, string $original): ?Gradient
    {
        $args = self::splitTopLevelCommas($inner);
        if (count($args) < 2) {
            return $this->warnGradientNull("linear-gradient() needs a direction and/or at least 2 color stops: $original");
        }
        $direction = $this->parseGradientDirection($args[0]);
        $stopArgs = $direction !== null ? array_slice($args, 1) : $args;
        $angleDeg = $direction ?? 180.0;
        if (count($stopArgs) < 2) {
            return $this->warnGradientNull("linear-gradient() needs at least 2 color stops: $original");
        }
        $stops = $this->parseGradientStops($stopArgs, $original);
        if ($stops === null) {
            return null;
        }
        return new Gradient(GradientKind::Linear, $angleDeg, $stops);
    }

    /**
     * css-images-3 §3.1 reducido: SOLO `radial-gradient([circle at center]?, <color-stop-list>)`
     * -- cualquier forma más rica (ellipse, tamaños con keyword/longitud, `at <posición>` distinta
     * de center) cae a un warning + aproximación "circle at center" (primera capa del brief),
     * nunca rechaza el gradiente entero.
     */
    private function parseRadialGradient(string $inner, string $original): ?Gradient
    {
        $args = self::splitTopLevelCommas($inner);
        if ($args === []) {
            return $this->warnGradientNull("radial-gradient() needs at least 2 color stops: $original");
        }
        $first = strtolower(trim($args[0]));
        $stopArgs = $args;
        if ($this->looksLikeRadialPrefix($first)) {
            $stopArgs = array_slice($args, 1);
            if ($first !== 'circle' && $first !== 'circle at center' && $first !== 'at center') {
                $this->warnings[] = "Unsupported radial-gradient() shape/position (approximated as circle at center): $original";
            }
        }
        if (count($stopArgs) < 2) {
            return $this->warnGradientNull("radial-gradient() needs at least 2 color stops: $original");
        }
        $stops = $this->parseGradientStops($stopArgs, $original);
        if ($stops === null) {
            return null;
        }
        return new Gradient(GradientKind::Radial, 0.0, $stops);
    }

    /**
     * True cuando el primer argumento de radial-gradient() es una descripción de forma/posición
     * (en vez de ya ser el primer color-stop) -- cualquier token que no sea un color válido y no
     * empiece por un color-function reconocible se asume que pertenece a esta categoría (shape/
     * size/position), consistente con "todo lo que no es un stop es geometría" del spec real.
     */
    private function looksLikeRadialPrefix(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        // Un primer argumento que YA parsea como color (con o sin posición -- pero un color-stop
        // NUNCA empieza por 'circle'/'ellipse'/'at', que son las únicas palabras clave de forma/
        // posición reducidas de este milestone) es casi siempre un stop -- pero un token compuesto
        // como "red 10%" tampoco es una forma; basta comprobar si CONTIENE alguna de las 3 keywords
        // reservadas (nunca aparecen en un nombre de color ni en una función rgb()/hsl()).
        if (str_contains($token, 'circle') || str_contains($token, 'ellipse') || str_starts_with($token, 'at ')) {
            return true;
        }
        // code review Finding 2 (css-images-3 §3.1): las 3 formas anteriores no cubrían el resto
        // de la gramática real de tamaño/extensión de radial-gradient() -- las 4 extent keywords
        // (closest-side/closest-corner/farthest-side/farthest-corner), un tamaño explícito de UNA
        // longitud (`radial-gradient(50px, ...)`, radio circular) o de DOS longitudes/porcentajes
        // (`radial-gradient(50% 50%, ...)`, ejes de una elipse) -- ninguna de las 3 formas es un
        // color-stop válido, pero antes de este fix se colaban como el primer argumento de
        // parseGradientStops(), que Color::fromCss() rechazaba, tirando el gradiente ENTERO con un
        // warning de "stop color" engañoso en vez de degradar por el fallback circle-at-center de
        // más abajo (mismo mecanismo que 'ellipse'/'at'). Sin colisión posible con los 148 nombres
        // de color (Color::KEYWORDS): ninguno contiene un guion, y ninguno parsea como CssLength
        // (exige un dígito inicial) -- un stop real como "red"/"red 10%" falla el chequeo de
        // CssLength::fromCss() en su primer token y esta función sigue devolviendo `false` para él,
        // exactamente como antes (ver "parses a bare radial-gradient(red, blue)... zero warnings").
        $lower = strtolower(trim($token));
        if (in_array($lower, ['closest-side', 'closest-corner', 'farthest-side', 'farthest-corner'], true)) {
            return true;
        }
        $sizeParts = self::splitTopLevel($lower);
        if ($sizeParts === [] || count($sizeParts) > 2) {
            return false;
        }
        foreach ($sizeParts as $sizePart) {
            if (CssLength::fromCss($sizePart) === null) {
                return false;
            }
        }
        return true;
    }

    /**
     * css-images-3 §3.4.2: `<angle>` o `to <side-or-corner>` -- ninguno de los dos aparece nunca
     * como color-stop válido (un color no empieza por un número seguido de "deg" ni por "to "), así
     * que "no reconocido como dirección" (null) es la señal correcta para que el llamador trate
     * $args[0] como el primer color-stop en su lugar.
     *
     * Esquinas (`to top right`, etc.): aproximación de CAJA CUADRADA -- 45/135/225/315deg fijos,
     * en vez del cálculo real dependiente del aspect-ratio de la caja (css-images-3 §3.4.2) -- ver
     * el docblock de Css\Value\Gradient::$angleDeg para el razonamiento completo. Hand-verificable
     * contra el ángulo numérico `45deg` en una caja CUADRADA (mismo /Coords, ver PdfCanvasTest).
     */
    private function parseGradientDirection(string $token): ?float
    {
        $t = strtolower(trim($token));
        if (preg_match('/^(-?\d+(?:\.\d+)?)deg$/', $t, $m) === 1) {
            return (float) $m[1];
        }
        if (!str_starts_with($t, 'to ')) {
            return null;
        }
        $sides = preg_split('/\s+/', trim(substr($t, 3)));
        if ($sides === false || $sides === [] || count($sides) > 2) {
            return null;
        }
        sort($sides);
        return match (implode(' ', $sides)) {
            'top' => 0.0,
            'right' => 90.0,
            'bottom' => 180.0,
            'left' => 270.0,
            'right top' => 45.0,
            'bottom right' => 135.0,
            'bottom left' => 225.0,
            'left top' => 315.0,
            default => null,
        };
    }

    /**
     * css-images-3 §3.4.1: cada elemento de $stopArgs es "<color> <percentage>?" (posición
     * OPCIONAL) -- separados por espacio de nivel superior (splitTopLevel(), reutilizado de los
     * shorthands existentes: respeta paréntesis, así que "rgba(0,0,0,.5) 50%" trocea en
     * ["rgba(0,0,0,.5)", "50%"] y no se rompe por las comas internas de rgba()).
     *
     * @param list<string> $stopArgs
     * @return list<GradientStop>|null
     */
    private function parseGradientStops(array $stopArgs, string $original): ?array
    {
        /** @var list<array{0: Color, 1: ?float}> $rawStops */
        $rawStops = [];
        foreach ($stopArgs as $stopArg) {
            $parts = self::splitTopLevel(trim($stopArg));
            if (count($parts) !== 1 && count($parts) !== 2) {
                $this->warnings[] = "Unsupported gradient color-stop \"$stopArg\": $original";
                return null;
            }
            $color = Color::fromCss($parts[0]);
            if ($color === null) {
                $this->warnings[] = "Unsupported gradient stop color \"{$parts[0]}\": $original";
                return null;
            }
            $position = null;
            if (count($parts) === 2) {
                if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $parts[1], $m) !== 1) {
                    $this->warnings[] = "Unsupported gradient stop position \"{$parts[1]}\": $original";
                    return null;
                }
                $position = max(0.0, min(100.0, (float) $m[1]));
            }
            // M8-T3 (RESTRICCIONES GLOBALES M8, shadings con alpha -> M9): un stop con alpha
            // declarado (rgba()/hsla() con canal alpha<1) no puede representarse en un
            // /FunctionType 2/3 de PDF (RGB puro, sin canal alfa) sin un soft mask -- se avisa y
            // se pinta OPACO (mismo color RGB, alpha descartado) en vez de fallar el gradiente
            // entero.
            if ($color->alpha !== null && $color->alpha < 1.0) {
                $this->warnings[] = "Gradient color-stop alpha not supported (rendered opaque; soft masks are a later milestone): $stopArg";
                $color = new Color($color->r, $color->g, $color->b);
            }
            $rawStops[] = [$color, $position];
        }
        return self::distributeStopPositions($rawStops);
    }

    /**
     * css-images-3 §3.4.1 ("Determining the actual position of a color stop"), reducido: rellena
     * las posiciones ausentes --
     *   1. el PRIMER stop sin posición colapsa a 0%, el ÚLTIMO sin posición a 100% (endpoints
     *      implícitos del brief).
     *   2. cualquier posición EXPLÍCITA menor que la máxima ya vista se sube hasta esa máxima
     *      (monotonía del spec: una lista de posiciones nunca puede "retroceder").
     *   3. cada tramo de stops SIN posición entre dos stops YA resueltos se reparte uniformemente
     *      entre ambos (regla "simple" del brief -- 3 stops sin posición → 0/50/100).
     *
     * @param list<array{0: Color, 1: ?float}> $rawStops
     * @return list<GradientStop>
     */
    private static function distributeStopPositions(array $rawStops): array
    {
        $n = count($rawStops);
        $positions = array_map(static fn(array $s): ?float => $s[1], $rawStops);
        if ($positions[0] === null) {
            $positions[0] = 0.0;
        }
        if ($positions[$n - 1] === null) {
            $positions[$n - 1] = 100.0;
        }
        $runningMax = $positions[0];
        for ($i = 1; $i < $n; $i++) {
            if ($positions[$i] !== null) {
                if ($positions[$i] < $runningMax) {
                    $positions[$i] = $runningMax;
                }
                $runningMax = $positions[$i];
            }
        }
        $i = 0;
        while ($i < $n) {
            if ($positions[$i] !== null) {
                $i++;
                continue;
            }
            $runStart = $i - 1;
            $j = $i;
            while ($positions[$j] === null) {
                $j++;
            }
            $startPos = $positions[$runStart];
            $endPos = $positions[$j];
            $steps = $j - $runStart;
            for ($k = $runStart + 1; $k < $j; $k++) {
                $positions[$k] = $startPos + ($endPos - $startPos) * ($k - $runStart) / $steps;
            }
            $i = $j;
        }
        $stops = [];
        foreach ($rawStops as $index => $rawStop) {
            $stops[] = new GradientStop($rawStop[0], $positions[$index]);
        }
        return $stops;
    }

    /** @return list<string> */
    private static function splitTopLevelCommas(string $value): array
    {
        $tokens = [];
        $current = '';
        $depth = 0;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '(') {
                $depth++;
                $current .= $char;
                continue;
            }
            if ($char === ')') {
                $depth--;
                $current .= $char;
                continue;
            }
            if ($depth === 0 && $char === ',') {
                $tokens[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $tokens[] = trim($current);
        return $tokens;
    }

    /** warn() devuelve `array<string, mixed>` (el contrato de parse()) -- los helpers de gradiente
     *  necesitan devolver `null` en su lugar (el tipo de retorno ?Gradient de sus llamadores, que
     *  aceptan perfectamente este `null` literal), así que no pueden reutilizar warn() directamente. */
    private function warnGradientNull(string $message): null
    {
        $this->warnings[] = $message;
        return null;
    }

    /**
     * M7-T3 (css-lists-3 §3, reducido): `list-style: <type> || <position> || <image>` — los tres
     * componentes son opcionales y pueden aparecer en cualquier orden (igual convención que
     * expandBorderShorthand()). M7 solo expande a 'list-style-type': un token 'outside' se acepta
     * y se descarta en silencio (comportamiento único soportado, ver KEYWORD_PROPERTIES['list-
     * style-position']); un token 'inside' o cualquier valor de list-style-image (url(...), o
     * cualquier token no reconocido) tira TODO el shorthand con un único warning — mismo criterio
     * "todo o nada" que expandBorderShorthand() ante un componente duplicado/inválido, en vez de
     * aplicar parcialmente el type encontrado antes del componente problemático.
     *
     * Simplificación documentada: 'none' es AMBIGUO en CSS real (puede anular list-style-type O
     * list-style-image) — este parser SIEMPRE lo interpreta como list-style-type:none (el uso
     * observable de "list-style: none" en la práctica, y list-style-image no está soportado en M7
     * de todas formas — ver RESTRICCIONES GLOBALES, "Excluidos M7 con warning").
     *
     * @return array<string, mixed>
     */
    private function parseListStyleShorthand(string $value): array
    {
        $tokens = self::splitTopLevel(trim($value));
        if ($tokens === []) {
            return $this->warn("Unsupported shorthand for list-style: $value");
        }
        $type = null;
        foreach ($tokens as $token) {
            $keyword = strtolower($token);
            if (in_array($keyword, ['disc', 'circle', 'square', 'decimal', 'none'], true)) {
                if ($type !== null) {
                    return $this->warn("Duplicate list-style-type component for list-style: $value");
                }
                $type = $keyword;
                continue;
            }
            if ($keyword === 'outside') {
                continue;
            }
            if ($keyword === 'inside') {
                return $this->warn("Unsupported list-style-position (inside not supported in M7): $value");
            }
            return $this->warn("Unsupported list-style component (list-style-image not supported in M7): $token");
        }
        if ($type === null) {
            return $this->warn("Unsupported shorthand for list-style: $value");
        }
        return ['list-style-type' => $type];
    }

    /**
     * M7-T2 (css-fonts-3 §5.3.1, fallback list): 'font-family' deja de ser un keyword suelto —
     * el valor puede traer una lista de familias separadas por comas ('Arial, "Helvetica Neue",
     * sans-serif'), cada una la propia CSS trata como candidata en orden de preferencia. ESTE
     * parser solo trocea y limpia (comillas/espacios) cada nombre; NO resuelve genéricos
     * (sans-serif/serif/monospace) ni comprueba qué familia está registrada -- eso vive en
     * Layout, contra FontCatalog (ver Layout\Text\FontFamilyResolver), porque Style: [Css, Vendor]
     * en deptrac.yaml prohíbe que esta capa dependa de Text (donde vive FontCatalog). Nunca
     * avisa: una lista vacía o con nombres vacíos ("font-family: , ,") simplemente produce una
     * lista más corta (incluso []), igual que CSS descarta en silencio un nombre de familia mal
     * formado -- ComputedStyle::compute() cae a la lista heredada del padre cuando el resultado
     * es [].
     *
     * @return array<string, mixed>
     */
    private function parseFontFamily(string $value): array
    {
        $names = [];
        foreach (self::splitFontFamilyList($value) as $token) {
            $name = trim($token, " \t\n\r\0\x0B\"'");
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return ['font-family' => $names];
    }

    /**
     * Trocea por comas de NIVEL SUPERIOR -- a diferencia de splitTopLevel() (que rastrea
     * profundidad de paréntesis para calc()/var()), aquí lo que hay que respetar son las
     * COMILLAS: un nombre de familia citado ('"Helvetica Neue"') nunca debería partirse aunque
     * contuviera una coma literal dentro (no ocurre en ningún fixture de este motor, pero el
     * escaneo es igual de barato que asumirlo sin más).
     *
     * @return list<string>
     */
    private static function splitFontFamilyList(string $value): array
    {
        $tokens = [];
        $current = '';
        $quote = null;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($quote !== null) {
                $current .= $char;
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $current .= $char;
                continue;
            }
            if ($char === ',') {
                $tokens[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $tokens[] = $current;
        return $tokens;
    }

    /** @return array<string, mixed> */
    private function parseFontWeight(string $value): array
    {
        $keyword = strtolower($value);
        return match ($keyword) {
            'normal' => ['font-weight' => 400],
            'bold' => ['font-weight' => 700],
            '400' => ['font-weight' => 400],
            '700' => ['font-weight' => 700],
            default => $this->warn("Unsupported font-weight: $value"),
        };
    }

    /** @return array<string, mixed> */
    private function parseFontStyle(string $value): array
    {
        $keyword = strtolower($value);
        if ($keyword === 'normal') {
            return ['font-style' => 'normal'];
        }
        if ($keyword === 'italic') {
            return ['font-style' => 'italic'];
        }
        if ($keyword === 'oblique') {
            $this->warnings[] = "Unsupported font-style (approximated as italic): $value";
            return ['font-style' => 'italic'];
        }
        return $this->warn("Unsupported font-style: $value");
    }

    /**
     * CSS 2.2 §10.8.1: número unitless multiplica el font-size del propio elemento
     * (resuelto en ComputedStyle::compute); un valor en px pasa directo; 'normal' → null.
     * Negativo (unitless o longitud) no tiene interpretación válida — igual que las
     * propiedades en NON_NEGATIVE_PROPERTIES — así que se descarta con warning. M6-T3: %/em/rem
     * en line-height son ahora soporte real (%/em relativos al font-size PROPIO del elemento,
     * igual que el multiplicador unitless; rem contra la raíz) — quedan en CssLength simbólico
     * hasta ComputedStyle::compute, que es quien conoce ese font-size.
     *
     * @return array<string, mixed>
     */
    private function parseLineHeight(string $value): array
    {
        $keyword = strtolower($value);
        if ($keyword === 'normal') {
            return ['line-height' => null];
        }
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1) {
            $multiplier = (float) $value;
            if ($multiplier < 0.0) {
                return $this->warn("Negative value not allowed for line-height: $value");
            }
            return ['line-height' => $multiplier];
        }
        if ($this->looksLikeCalc($value)) {
            $calc = $this->tryParseCalc($value);
            if ($calc === null) {
                return [];
            }
            // M6 final-review fix (Finding 2): same definite-negative fold as parseFontSize()
            // above — a calc() with em/rem/% still has no knowable sign until compute-time.
            if ($calc->isDefinite() && $calc->pxOffset < 0.0) {
                return $this->warn("Negative value not allowed for line-height: $value");
            }
            return ['line-height' => $calc];
        }
        $css = CssLength::fromCss($value);
        if ($css !== null) {
            if ($css->value < 0.0) {
                return $this->warn("Negative value not allowed for line-height: $value");
            }
            return ['line-height' => $css->unit === LengthUnit::Px ? Length::px($css->value) : $css];
        }
        return $this->warn("Unsupported line-height: $value");
    }

    /** @return array<string, mixed> */
    private function parseTextAlign(string $value): array
    {
        $keyword = strtolower($value);
        return match ($keyword) {
            'left' => ['text-align' => 'left'],
            'center' => ['text-align' => 'center'],
            'right' => ['text-align' => 'right'],
            'justify' => $this->warn("Unsupported text-align (justify not supported in M1): $value"),
            default => $this->warn("Unsupported text-align: $value"),
        };
    }

    /** @return array<string, mixed> */
    private function parseTextDecoration(string $value): array
    {
        $keyword = strtolower($value);
        return match ($keyword) {
            'none' => ['text-decoration' => false],
            'underline' => ['text-decoration' => true],
            default => $this->warn("Unsupported text-decoration: $value"),
        };
    }

    /**
     * CSS 2.2 §17.6.1: `border-spacing: <length> <length>?` — el primer valor es horizontal, el
     * segundo vertical. M5 solo soporta la forma de un único valor (mismo px para ambos ejes,
     * como consume TableFormattingContext en M5-T4); dos valores son válidos en CSS pero fuera
     * de alcance aquí, así que caen al warning genérico en vez de tomar solo el primero (evita
     * fingir soporte de ejes independientes que el layout no respeta). Nunca admite % (igual
     * que row-gap/column-gap en LENGTH_PROPERTIES) — parseLength() ya rechaza % de forma
     * natural; M6-T3 añade em/rem/pt/cm/mm/in vía CssLength, igual que el resto de longitudes.
     *
     * @return array<string, mixed>
     */
    private function parseBorderSpacing(string $value): array
    {
        $tokens = self::splitTopLevel(trim($value));
        if (count($tokens) !== 1) {
            return $this->warn("Unsupported border-spacing (single value only in M5): $value");
        }
        $length = $this->parseLength($tokens[0]);
        if ($length === null) {
            return $this->warn("Unsupported border-spacing: $value");
        }
        if (self::rawValueOf($length) < 0.0) {
            return $this->warn("Negative value not allowed for border-spacing: $value");
        }
        return ['border-spacing' => $length];
    }

    /**
     * CSS 2.2 §10.8.1: vertical-align tiene una tabla larga de keywords (baseline, sub, super,
     * text-top, text-bottom, middle, top, bottom) más <percentage>/<length>; M5 solo soporta
     * top|middle|bottom (los que consume TableCellBox en M5-T4) — baseline (el initial value
     * real), sub/super/text-top/text-bottom y cualquier percentage/length caen al warning
     * genérico, documentado en VerticalAlign.
     *
     * @return array<string, mixed>
     */
    private function parseVerticalAlign(string $value): array
    {
        $keyword = strtolower(trim($value));
        return match ($keyword) {
            'top' => ['vertical-align' => 'top'],
            'middle' => ['vertical-align' => 'middle'],
            'bottom' => ['vertical-align' => 'bottom'],
            default => $this->warn("Unsupported vertical-align: $value"),
        };
    }

    /**
     * M6-T5 (css-color-3 opacity / CSS Compositing §5): número unitless, clampado a [0,1] —
     * fuera de rango NO es un warning, se clampa silenciosamente (css-values-3 §4.3: "values
     * outside the range are not invalid, but are clamped"), a diferencia de casi todas las demás
     * propiedades numéricas de este parser (que SÍ avisan ante un valor fuera de rango). Solo un
     * token no-numérico produce warning.
     *
     * @return array<string, mixed>
     */
    private function parseOpacity(string $value): array
    {
        $trimmed = trim($value);
        if (preg_match('/^-?(?:\d+\.?\d*|\.\d+)$/', $trimmed) !== 1) {
            return $this->warn("Unsupported opacity: $value");
        }
        return ['opacity' => max(0.0, min(1.0, (float) $trimmed))];
    }

    /**
     * M7-T5 (css-overflow-3, reducido a visible|hidden): 'visible' (initial) y 'hidden' se aceptan
     * tal cual. 'scroll'/'auto' no tienen un análogo real en un motor de impresión sin scrollbars
     * interactivas -- se COERCIONAN a 'hidden' (el comportamiento observable más cercano: el
     * contenido que desborda deja de pintarse, aunque un navegador SÍ ofrecería una barra de
     * desplazamiento) con un warning explícito, siguiendo la disciplina "todo lo excluido avisa"
     * del milestone. Cualquier otro valor (p.ej. 'clip', fuera de alcance) cae al warning genérico
     * + valor descartado (initial 'visible' en ComputedStyle::compute()).
     *
     * @return array<string, mixed>
     */
    private function parseOverflow(string $value): array
    {
        $keyword = strtolower(trim($value));
        if ($keyword === 'visible' || $keyword === 'hidden') {
            return ['overflow' => $keyword];
        }
        if ($keyword === 'scroll' || $keyword === 'auto') {
            $this->warnings[] = "Unsupported overflow (approximated as hidden, no real scrolling in a print engine): $value";
            return ['overflow' => 'hidden'];
        }
        return $this->warn("Unsupported overflow: $value");
    }

    /** @return array<string, mixed> */
    private function warn(string $message): array
    {
        $this->warnings[] = $message;
        return [];
    }

    /** @return list<string> */
    public function drainWarnings(): array
    {
        $warnings = $this->warnings;
        $this->warnings = [];
        return $warnings;
    }

    /**
     * CSS 2.2 §8.3: expansión 1/2/4 valores (3 valores: top, right+left, bottom).
     * Acepta % mezclado con px/em/rem (p.ej. "10px 5%" o "1em 2rem 10px 5%", M6-T3).
     *
     * @return array<string, mixed>
     */
    private function expandBoxShorthand(string $property, string $value): array
    {
        $parts = self::splitTopLevel($value);
        $lengths = array_map($this->parseLengthPercentage(...), $parts);
        if (in_array(null, $lengths, true) || $lengths === []) {
            return $this->warn("Unsupported shorthand for $property: $value");
        }
        /** @var list<LengthPercentage|CssLength|CalcExpr> $lengths */
        // M7-T1 housekeeping (CSS 2.2 §8.4, sign-check parity with the padding-* longhands):
        // padding-top/right/bottom/left already reject a negative value at the LENGTH_PERCENTAGE_
        // PROPERTIES branch above (line ~120) — but that branch never runs for the shorthand form,
        // because expandBoxShorthand() builds "padding-top" etc. directly as already-typed values,
        // bypassing DeclarationParser::parse('padding-top', ...) entirely. Before this fix,
        // "padding: -5px" (or a mixed "padding: 10px -5px") slipped through unwarned. Adjudication
        // (M7-T1 brief): drop the WHOLE shorthand with a SINGLE warning when ANY component is
        // negative — simpler and more defensible than silently zeroing only the negative sides
        // (which would produce a half-applied padding no author asked for). margin stays fully
        // permissive (CSS 2.2 §8.3: negative margins are valid), so this check is padding-only.
        if ($property === 'padding') {
            foreach ($lengths as $length) {
                if (self::rawValueOf($length) < 0.0) {
                    return $this->warn("Negative value not allowed in padding shorthand: $value");
                }
            }
        }
        [$top, $right, $bottom, $left] = match (count($lengths)) {
            1 => [$lengths[0], $lengths[0], $lengths[0], $lengths[0]],
            2 => [$lengths[0], $lengths[1], $lengths[0], $lengths[1]],
            3 => [$lengths[0], $lengths[1], $lengths[2], $lengths[1]],
            default => [$lengths[0], $lengths[1], $lengths[2], $lengths[3]],
        };
        return [
            "$property-top" => $top, "$property-right" => $right,
            "$property-bottom" => $bottom, "$property-left" => $left,
        ];
    }

    /**
     * css-flexbox-1 §8.1 gap shorthand: `gap: <row-gap> <column-gap>?` — un valor fija ambos
     * ejes, dos valores fijan fila y luego columna (nunca % — parseLength() rechaza % de forma
     * natural, propagando el warning genérico de shorthand; M6-T3 añade em/rem/físicos).
     *
     * @return array<string, mixed>
     */
    private function expandGapShorthand(string $value): array
    {
        $parts = self::splitTopLevel(trim($value));
        $lengths = array_map($this->parseLength(...), $parts);
        if ($parts === [] || in_array(null, $lengths, true) || count($lengths) > 2) {
            return $this->warn("Unsupported shorthand for gap: $value");
        }
        /** @var list<Length|CssLength|CalcExpr> $lengths */
        foreach ($lengths as $length) {
            if (self::rawValueOf($length) < 0.0) {
                return $this->warn("Negative value not allowed for gap: $value");
            }
        }
        [$rowGap, $columnGap] = count($lengths) === 1 ? [$lengths[0], $lengths[0]] : [$lengths[0], $lengths[1]];
        return ['row-gap' => $rowGap, 'column-gap' => $columnGap];
    }

    /** @return array<string, mixed> */
    private function parseFlexNumber(string $property, string $value): array
    {
        $trimmed = trim($value);
        if (preg_match(self::FLEX_NUMBER_RE, $trimmed) !== 1) {
            return $this->warn("Unsupported $property: $value");
        }
        return [$property => (float) $trimmed];
    }

    /** @return array<string, mixed> */
    private function parseFlexBasis(string $value): array
    {
        $keyword = strtolower(trim($value));
        if ($keyword === 'content') {
            return $this->warn("Unsupported flex-basis (content keyword not supported in M4): $value");
        }
        $basis = $this->flexBasisToken($value);
        if ($basis === null) {
            return $this->warn("Unsupported flex-basis: $value");
        }
        return ['flex-basis' => $basis];
    }

    /**
     * Un componente de <flex-basis> dentro del shorthand o de la longhand: 'auto' (sentinel
     * string, traducido a null/auto en ComputedStyle::compute igual que el resto de keywords de
     * este parser) o un LengthPercentage/CssLength no negativo (px/%/em/rem, M6-T3). 'content' y
     * cualquier otro token inválido devuelven null, que el llamador convierte en warning.
     */
    private function flexBasisToken(string $token): LengthPercentage|CssLength|CalcExpr|string|null
    {
        if (strtolower(trim($token)) === 'auto') {
            return 'auto';
        }
        $length = $this->parseLengthPercentage($token);
        if ($length !== null && self::rawValueOf($length) < 0.0) {
            return null;
        }
        return $length;
    }

    /**
     * css-flexbox-1 §7.1.1 — tabla completa del shorthand `flex`:
     *   none              → flex-grow:0    flex-shrink:0  flex-basis:auto
     *   initial           → flex-grow:0    flex-shrink:1  flex-basis:auto  (initial value)
     *   auto              → flex-grow:1    flex-shrink:1  flex-basis:auto
     *   <N>               → flex-grow:N    flex-shrink:1  flex-basis:0%
     *   <width>           → flex-grow:1    flex-shrink:1  flex-basis:<width>
     *   <N> <M>           → flex-grow:N    flex-shrink:M  flex-basis:0%
     *   <N> <width>       → flex-grow:N    flex-shrink:1  flex-basis:<width>
     *   <N> <M> <width>   → flex-grow:N    flex-shrink:M  flex-basis:<width>
     *
     * M5-T1 (housekeeping): la basis omitida es `0%` (LengthPercentage::percent(0.0)), no `0px`
     * (LengthPercentage::zero(), lo que este método usaba antes) — el propio texto del spec
     * (§7.1.1: "flex: <positive-number>" expande a "flex-grow, 1, 0%") lo fija en porcentaje.
     * Numéricamente resuelve idéntico (0% y 0px de CUALQUIER base dan 0px, ver
     * LengthPercentage::resolve()), así que esta corrección no cambia ninguna geometría ya
     * calculada — es una corrección de fidelidad al spec, no de comportamiento observable.
     *
     * @return array<string, mixed>
     */
    private function parseFlexShorthand(string $value): array
    {
        $keyword = strtolower(trim($value));
        if ($keyword === 'none') {
            return ['flex-grow' => 0.0, 'flex-shrink' => 0.0, 'flex-basis' => 'auto'];
        }
        if ($keyword === 'initial') {
            return ['flex-grow' => 0.0, 'flex-shrink' => 1.0, 'flex-basis' => 'auto'];
        }
        if ($keyword === 'auto') {
            return ['flex-grow' => 1.0, 'flex-shrink' => 1.0, 'flex-basis' => 'auto'];
        }
        $tokens = self::splitTopLevel(trim($value));
        if ($tokens === []) {
            return $this->warn("Unsupported flex shorthand: $value");
        }
        return match (count($tokens)) {
            1 => $this->parseFlexOneValue($tokens[0], $value),
            2 => $this->parseFlexTwoValues($tokens[0], $tokens[1], $value),
            3 => $this->parseFlexThreeValues($tokens[0], $tokens[1], $tokens[2], $value),
            default => $this->warn("Unsupported flex shorthand: $value"),
        };
    }

    /** @return array<string, mixed> */
    private function parseFlexOneValue(string $token, string $original): array
    {
        if (preg_match(self::FLEX_NUMBER_RE, $token) === 1) {
            return ['flex-grow' => (float) $token, 'flex-shrink' => 1.0, 'flex-basis' => LengthPercentage::percent(0.0)];
        }
        $basis = $this->flexBasisToken($token);
        if ($basis === null) {
            return $this->warn("Unsupported flex shorthand: $original");
        }
        return ['flex-grow' => 1.0, 'flex-shrink' => 1.0, 'flex-basis' => $basis];
    }

    /** @return array<string, mixed> */
    private function parseFlexTwoValues(string $first, string $second, string $original): array
    {
        if (preg_match(self::FLEX_NUMBER_RE, $first) !== 1) {
            return $this->warn("Unsupported flex shorthand: $original");
        }
        $grow = (float) $first;
        if (preg_match(self::FLEX_NUMBER_RE, $second) === 1) {
            return ['flex-grow' => $grow, 'flex-shrink' => (float) $second, 'flex-basis' => LengthPercentage::percent(0.0)];
        }
        $basis = $this->flexBasisToken($second);
        if ($basis === null) {
            return $this->warn("Unsupported flex shorthand: $original");
        }
        return ['flex-grow' => $grow, 'flex-shrink' => 1.0, 'flex-basis' => $basis];
    }

    /** @return array<string, mixed> */
    private function parseFlexThreeValues(string $first, string $second, string $third, string $original): array
    {
        if (preg_match(self::FLEX_NUMBER_RE, $first) !== 1 || preg_match(self::FLEX_NUMBER_RE, $second) !== 1) {
            return $this->warn("Unsupported flex shorthand: $original");
        }
        $basis = $this->flexBasisToken($third);
        if ($basis === null) {
            return $this->warn("Unsupported flex shorthand: $original");
        }
        return ['flex-grow' => (float) $first, 'flex-shrink' => (float) $second, 'flex-basis' => $basis];
    }
}
