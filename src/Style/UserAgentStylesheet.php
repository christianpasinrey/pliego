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
 *   - h1..h6: font-size 2/1.5/1.17/1/.83/.75 em, margin .67/.75/.83/1.12/1.5/1.67 em (top/bottom;
 *     left/right 0), font-weight:bold. Todas las celdas coinciden exactamente con CSS 2.2
 *     Appendix D (spec-compliant).
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
 *
 * M7-T3 (css-lists-3 §3, reducido) — reglas nuevas de esta tarea:
 *   - `li { display: list-item }`: el ÚNICO sitio que fija este default — antes de esta tarea un
 *     <li> sin CSS propio caía al default genérico Display::Block de ComputedStyle::compute()
 *     (ningún tag lo hacía list-item). BlockFlowContext trata Display::ListItem como un bloque
 *     normal MÁS un marcador (ver su docblock de clase) — un autor SIEMPRE puede pisar este
 *     default con su propio `li { display: block }` (o incluso :none), igual que cualquier otra
 *     regla de esta hoja.
 *   - `ul { list-style-type: disc }` / `ol { list-style-type: decimal }`: redundante con el
 *     initial value real de la propiedad (ComputedStyle::root() ya fija 'disc') pero documenta la
 *     intención explícitamente, igual que el resto de esta hoja — y le da a `ol` un valor DISTINTO
 *     del initial value sin necesitar ninguna tabla hardcoded por tag (a diferencia de
 *     TABLE_DISPLAY_BY_TAG, ver ComputedStyle).
 *   - `ul ul { list-style-type: circle }` / `ul ul ul { list-style-type: square }`: adjudicación
 *     "per navegadores" del brief M7-T3 — un <ul> anidado a 2 niveles de profundidad (con un
 *     ancestro <ul> que a su vez tiene otro ancestro <ul>) usa circle; a 3+ niveles, square. Estas
 *     dos reglas combinator (descendant, funcionan desde M6) tienen especificidad (0,0,2) y
 *     (0,0,3) respectivamente — un <ul> de profundidad ≥3 coincide con AMBAS, y la de mayor
 *     especificidad (`ul ul ul`) gana, así que la progresión se queda en square para cualquier
 *     profundidad ≥3 (nunca vuelve a disc/circle, a diferencia de algunos navegadores reales que
 *     ciclan disc→circle→square→circle...; divergencia documentada, exactamente lo que pide el
 *     brief: solo dos niveles de reglas). `ol` NO tiene equivalente anidado: decimal se queda
 *     decimal a cualquier profundidad, igual que en cualquier navegador real (el contador, no el
 *     glifo, es lo que distingue cada nivel — ver BlockFlowContext, que reinicia el contador POR
 *     CONTENEDOR, nunca por profundidad).
 *
 * M7-T4 (css-inline-3 reducido) — migración del INLINE_TAGS hardcoded de BoxTreeBuilder:
 *   - `span, strong, em, b, i, a, small, code, u, kbd, samp, sub, sup { display: inline }`: ANTES
 *     de esta tarea, BoxTreeBuilder::collectChildren()/collectInline() decidían "¿es este tag
 *     inline?" consultando una lista de tags hardcoded (INLINE_TAGS) -- completamente al margen del
 *     cascade. Ahora es una regla UA real como cualquiera de las de arriba: BoxTreeBuilder consulta
 *     ComputedStyle::$display === Display::Inline (ver Display::Inline), así que un autor puede
 *     pisar el default de CUALQUIERA de estos tags (`span { display: block }`) y, a la inversa,
 *     declarar `display: inline` en un tag arbitrario para que se trate como inline real --
 *     ninguna de las dos cosas era posible con el hardcoding anterior. La lista de tags es EXACTA
 *     (mismo conjunto que BoxTreeBuilder::INLINE_TAGS traía desde M1-M7-T2).
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
        h6 { font-size: .75em; margin: 1.67em 0; font-weight: bold; }
        p, ul, ol, dl { margin: 1em 0; }
        blockquote, figure { margin: 1em 40px; }
        ul, ol { padding-left: 40px; }
        li { display: list-item; }
        ul { list-style-type: disc; }
        ol { list-style-type: decimal; }
        ul ul { list-style-type: circle; }
        ul ul ul { list-style-type: square; }
        pre { font-family: monospace; margin: 1em 0; white-space: pre; }
        code, kbd, samp { font-family: monospace; }
        hr { border-top: 1px solid; margin: .5em 0; }
        small { font-size: .83em; }
        span, strong, em, b, i, a, small, code, u, kbd, samp, sub, sup { display: inline; }
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
