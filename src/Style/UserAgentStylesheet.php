<?php

declare(strict_types=1);

namespace Pliego\Style;

use Pliego\Css\StyleRule;
use Pliego\Css\StylesheetParser;

/**
 * M7-T2 (CSS 2.2 Appendix D, adaptada): la hoja de estilos de agente de usuario como texto CSS
 * REAL, parseada por el MISMO StylesheetParser que procesa el CSS del autor ("dogfooding" —
 * em/rem/herencia/shorthand funcionan exactamente igual que en cualquier otra hoja, sin una
 * segunda implementación paralela de esos mecanismos solo para reglas UA). StyleResolver la
 * antepone SIEMPRE a las reglas de autor (ver allRules()) — ningún caller (Engine, tests,
 * goldens) necesita "acordarse" de incluirla: es una propiedad del resolver, igual que un
 * navegador real siempre tiene una hoja UA cargada.
 *
 * QUÉ MIGRA AQUÍ DESDE ComputedStyle::compute() (antes hardcoded como listas de tags) Y POR QUÉ:
 *   - head/script/style/title/meta/link -> display:none (antes HIDDEN_BY_DEFAULT): expresable
 *     1:1 como CSS real, sin pérdida de matiz — el autor SIEMPRE pudo (y sigue pudiendo) ganarle
 *     con su propia regla de mayor origen, algo que el hardcoding anterior no permitía (una
 *     divergencia respecto a CSS real que esta migración además CORRIGE).
 *   - b/strong/th -> font-weight:bold (antes BOLD_BY_DEFAULT); i/em -> font-style:italic (antes
 *     ITALIC_BY_DEFAULT); a/u -> text-decoration:underline (antes UNDERLINE_BY_DEFAULT); th ->
 *     text-align:center (antes CENTER_ALIGN_BY_DEFAULT): las cuatro son declaraciones CSS
 *     triviales, sin ningún componente que dependa de lógica fuera del propio cascade.
 *
 * QUÉ NO MIGRA (se queda hardcoded en ComputedStyle::compute(), ver TABLE_DISPLAY_BY_TAG ahí) Y
 * POR QUÉ: los cinco display de estructura de tabla (table/tr/td/th/thead/tbody). SÍ serían
 * expresables como CSS (`tr { display: table-row }` es válido y el motor ya entiende ese valor
 * literal) pero M5 los dejó como default de compute() ANTES de que existiera este mecanismo de
 * origen UA, y la extensa batería de goldens de tabla (M5/M6) depende de esa ruta exacta —
 * migrarlos aquí sería observacionalmente un NO-OP (mismo resultado, mismo origen efectivo: un
 * default sin especificidad que el autor siempre puede pisar) a cambio de tocar código estable
 * sin necesidad; se deja fuera de alcance de esta tarea, documentado en vez de migrado.
 *
 * CONTENIDO NUEVO DE ESTA TAREA (Appendix D adaptada):
 *   - h1..h6: font-size 2/1.5/1.17/1/.83/.67 em, margin .67/.75/.83/1.12/1.5/1.67 em (top/bottom;
 *     left/right 0), font-weight:bold. NOTA DE FIDELIDAD AL SPEC: la tabla real de CSS 2.1
 *     Appendix D usa h6 { font-size: .75em }, no .67em — este motor sigue literalmente los
 *     valores del brief de la tarea (h1..h6: 2/1.5/1.17/1/.83/.67 em), que difieren de esa única
 *     celda; se documenta aquí la divergencia en vez de "corregirla" en silencio, por si una
 *     tarea futura decide alinear con el spec al pie de la letra. El resto de la tabla (tamaños
 *     h1-h5 y los 6 márgenes) coincide exactamente con CSS 2.1 Appendix D / los valores por
 *     defecto reales de los navegadores.
 *   - p/ul/ol/dl: margin 1em 0. blockquote/figure: margin 1em 40px (left/right también
 *     desplazados, a diferencia de p/ul/ol/dl). ul/ol: padding-left 40px (marcadores de lista
 *     son M8/T3 -- css-lists-3 -- el padding ya deja sitio para ellos).
 *   - pre: font-family monospace, margin 1em 0, white-space:pre (ver BoxTreeBuilder::
 *     textRunTokensFor()/collapse() e InlineFlowContext::layout() para la mecánica de "sin
 *     colapso, \n -> salto duro, sin wrap"). code/kbd/samp: font-family monospace (inline, ver
 *     BoxTreeBuilder::INLINE_TAGS).
 *   - hr: border-top 1px solid + margin .5em 0. SIMPLIFICACIÓN DOCUMENTADA: el spec real usa
 *     "margin: .5em auto" (centrado horizontal cuando hr tiene un width propio menor que su
 *     containing block) — este motor no soporta `margin: auto` en ningún eje todavía (ni aquí ni
 *     en ninguna otra propiedad, M8+), así que se usa 0 en vez de auto (mismo efecto que auto
 *     cuando hr ocupa el 100% del ancho disponible, que es el caso normal sin un width propio).
 *   - small: font-size .83em (relativo al font-size del PADRE, como cualquier em -- ver
 *     ComputedStyle::compute()).
 *   - sub/sup NO se declaran aquí (vertical-align sub/super es M8) — BoxTreeBuilder avisa
 *     explícitamente al encontrarlos (ver su docblock), esta hoja no necesita suprimir el warning
 *     con una regla que no podría cumplir de todas formas.
 */
final class UserAgentStylesheet
{
    private const string CSS = <<<'CSS'
        head, script, style, title, meta, link { display: none; }
        b, strong { font-weight: bold; }
        i, em { font-style: italic; }
        a, u { text-decoration: underline; }
        th { font-weight: bold; text-align: center; }
        h1 { font-size: 2em; margin: .67em 0; font-weight: bold; }
        h2 { font-size: 1.5em; margin: .75em 0; font-weight: bold; }
        h3 { font-size: 1.17em; margin: .83em 0; font-weight: bold; }
        h4 { font-size: 1em; margin: 1.12em 0; font-weight: bold; }
        h5 { font-size: .83em; margin: 1.5em 0; font-weight: bold; }
        h6 { font-size: .67em; margin: 1.67em 0; font-weight: bold; }
        p, ul, ol, dl { margin: 1em 0; }
        blockquote, figure { margin: 1em 40px; }
        ul, ol { padding-left: 40px; }
        pre { font-family: monospace; margin: 1em 0; white-space: pre; }
        code, kbd, samp { font-family: monospace; }
        hr { border-top: 1px solid; margin: .5em 0; }
        small { font-size: .83em; }
        CSS;

    /** @var list<StyleRule>|null memoizado a nivel de PROCESO -- self::CSS es texto estático puro
     *  (sin ningún input dinámico), así que da igual cuántas StyleResolver/render se ejecuten en
     *  el mismo proceso (tests incluidos): se parsea una única vez. */
    private static ?array $rules = null;

    public static function css(): string
    {
        return self::CSS;
    }

    /** @return list<StyleRule> reglas UA, ya re-etiquetadas con userAgent=true (ver StyleRule::withOrigin()). */
    public static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }
        // Paréntesis explícitos ("(new X())->metodo()" en vez del encadenamiento directo de PHP
        // 8.4): el parser interno de deptrac.phar (nikic/php-parser v4.19.1) no reconoce
        // "new X()->metodo()" y marca el fichero entero "Syntax Error" -- misma deviación
        // documentada en Engine.php.
        $parsed = (new StylesheetParser())->parse(self::CSS);
        return self::$rules = array_map(
            static fn(StyleRule $rule): StyleRule => $rule->withOrigin(true),
            $parsed->rules,
        );
    }
}
