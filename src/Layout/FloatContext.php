<?php

declare(strict_types=1);

namespace Pliego\Layout;

use Pliego\Layout\Geometry\Rect;
use Pliego\Style\FloatSide;

/**
 * CSS 2.2 §9.5 (floats, reducido): registro de "bandas" ocupadas por floats DENTRO DE UN ÚNICO
 * block formatting context (BFC). BlockFlowContext::layout() crea una instancia NUEVA de esta
 * clase en cada punto que CSS considera un BFC root: la raíz del documento (nadie le pasa un
 * FloatContext existente), cualquier caja con `overflow:hidden` (el "clearfix" clásico, CSS 2.2
 * §9.4.1/§10.6.7 -- ya wireado desde M7-T5 para el clip de pintado, ahora TAMBIÉN aísla floats), y
 * — automáticamente, por simple omisión de parámetro, sin tocar sus clases — cualquier llamador
 * que YA establece su propio contexto de layout: FlexFormattingContext (items), TableFormattingContext
 * (celdas) e InlineFlowContext::layoutInlineBlockAtomic() (inline-blocks), ninguno de los cuales
 * pasa un FloatContext al invocar BlockFlowContext::layout()/layoutImage(). En cualquier otro caso
 * (una caja normal, position:static y overflow:visible, anidada dentro de un BFC ya existente) el
 * MISMO objeto que el padre recibió se REUTILIZA para los hijos -- así los floats de un
 * descendiente "escapan" hacia arriba hasta el BFC real que los contiene, comportamiento CSS
 * correcto (solo una caja que ESTABLECE BFC aísla sus floats).
 *
 * MODELO DE BANDAS (simplificado, suficiente para el alcance reducido de esta tarea): cada float
 * colocado se guarda como un Rect de MARGIN BOX en coordenadas ABSOLUTAS de página completa (igual
 * que el resto de este motor -- ver Layout\Geometry\Rect / Layout\Fragment\GeometryShift: nunca
 * coordenadas locales al padre). place() busca, empezando en $minY y bajando de banda en banda, la
 * primera Y donde el hueco horizontal libre (el ancho del content box del BFC menos los floats de
 * AMBOS lados cuyo rango vertical cubre esa Y) sea >= el ancho del margin box pedido -- si nunca
 * cabe (un float más ancho que el propio BFC), se coloca de todas formas en la primera Y sin NINGÚN
 * float activo (evita un bucle infinito; el float desborda horizontalmente, comportamiento
 * observable normal, sin clipping especial).
 *
 * SIMPLIFICACIÓN DELIBERADA (documentada en el brief de esta tarea): el ANCHO shrink-to-fit de un
 * float con `width:auto` se calcula SIEMPRE contra el ancho COMPLETO del content box del BFC (nunca
 * contra el hueco YA estrechado por floats anteriores) -- un navegador real re-mediría el
 * contenido contra el hueco disponible en cada banda candidata; este motor no lo hace. Suficiente
 * para el alcance de esta tarea (floats con ancho declarado, el caso mayoritario de los tests) y
 * documentado como gap conocido para floats width:auto muy anchos junto a otro float.
 */
final class FloatContext
{
    /** @var list<Rect> */
    private array $leftFloats = [];
    /** @var list<Rect> */
    private array $rightFloats = [];

    public function __construct(
        private readonly float $contentLeft,
        private readonly float $contentRight,
    ) {}

    /**
     * Coloca un float de lado $side (margin box $marginBoxWidth x $marginBoxHeight) empezando a
     * buscar hueco desde $minY (nunca más arriba que el cursor de flujo actual, CSS 2.2 §9.5.1:
     * "the outer top of a floating box may not be higher than..."). Devuelve el Rect (margin box,
     * coordenadas absolutas) donde se colocó, y lo registra internamente para que floats/líneas
     * posteriores lo vean.
     */
    public function place(FloatSide $side, float $marginBoxWidth, float $marginBoxHeight, float $minY): Rect
    {
        $y = $minY;
        while (true) {
            [$left, $right] = $this->availableSpan($y);
            $available = $right - $left;
            $noActiveFloats = $left === $this->contentLeft && $right === $this->contentRight;
            if ($available >= $marginBoxWidth || $noActiveFloats) {
                $x = $side === FloatSide::Left ? $left : $right - $marginBoxWidth;
                $rect = new Rect($x, $y, $marginBoxWidth, $marginBoxHeight);
                $this->register($side, $rect);
                return $rect;
            }
            $nextY = $this->nextBandChange($y);
            // Guarda defensiva: con $available < $marginBoxWidth Y floats activos, siempre existe
            // al menos un float cubriendo $y (ver availableSpan()), así que nextBandChange()
            // SIEMPRE devuelve algo > $y aquí -- esta rama nunca debería alcanzarse en la
            // práctica, pero evita un bucle infinito ante cualquier inconsistencia futura.
            if ($nextY === null || $nextY <= $y) {
                $x = $side === FloatSide::Left ? $left : $right - $marginBoxWidth;
                $rect = new Rect($x, $y, $marginBoxWidth, $marginBoxHeight);
                $this->register($side, $rect);
                return $rect;
            }
            $y = $nextY;
        }
    }

    private function register(FloatSide $side, Rect $rect): void
    {
        if ($side === FloatSide::Left) {
            $this->leftFloats[] = $rect;
        } else {
            $this->rightFloats[] = $rect;
        }
    }

    /**
     * Hueco horizontal libre en la altura $y: el ancho completo del BFC, recortado por el borde
     * derecho del left-float más ancho y el borde izquierdo del right-float más estrecho ENTRE
     * los que estén verticalmente activos en $y (top <= y < bottom).
     *
     * @return array{0: float, 1: float} [left, right]
     */
    public function availableSpan(float $y): array
    {
        $left = $this->contentLeft;
        foreach ($this->leftFloats as $float) {
            if ($float->y <= $y && $y < $float->bottom()) {
                $left = max($left, $float->right());
            }
        }
        $right = $this->contentRight;
        foreach ($this->rightFloats as $float) {
            if ($float->y <= $y && $y < $float->bottom()) {
                $right = min($right, $float->x);
            }
        }
        return [$left, $right];
    }

    /** Y del borde inferior MÁS CERCANO (por encima) entre todos los floats (de cualquier lado)
     * que siguen activos en $y, o null si ninguno lo está -- SIEMPRE > $y cuando no es null (ver
     * el docblock de place()). */
    private function nextBandChange(float $y): ?float
    {
        $next = null;
        foreach ([...$this->leftFloats, ...$this->rightFloats] as $float) {
            if ($float->y <= $y && $float->bottom() > $y) {
                $next = $next === null ? $float->bottom() : min($next, $float->bottom());
            }
        }
        return $next;
    }

    /**
     * M7-T6 (line shortening, InlineFlowContext): alias público de availableSpan() con un nombre
     * que refleja su uso desde el lado de las líneas de texto (BlockFlowContext le pasa ESTE
     * objeto -- no un closure suelto, ver deviación documentada en el reporte de esta tarea -- a
     * InlineFlowContext::layout()).
     *
     * @return array{0: float, 1: float} [lineLeft, lineRight]
     */
    public function lineExtents(float $y): array
    {
        return $this->availableSpan($y);
    }

    /** Y justo debajo de la banda de floats más baja que sigue activa en $y (o $y mismo si
     * ninguna lo está) -- usado por InlineFlowContext cuando NI SIQUIERA la primera palabra de
     * una línea vacía cabe en el hueco actual (ver su docblock/el brief: "a line that can't fit
     * any content next to encroaching floats must move below the lowest intersecting float
     * band"). */
    public function nextClearY(float $y): float
    {
        return $this->nextBandChange($y) ?? $y;
    }

    /**
     * CSS 2.2 §9.5.2: Y del borde inferior del float MÁS BAJO en el/los lado(s) relevante(s) de
     * $clear ('left'|'right'|'both'|'none'), o -INF si no hay ninguno en ese lado (sentinel "sin
     * restricción" -- un `max($cursorY, $bfc->clearBottom(...))` en el caller es siempre un no-op
     * cuando no hay floats que despejar).
     */
    public function clearBottom(string $clear): float
    {
        $bottom = -INF;
        if ($clear === 'left' || $clear === 'both') {
            foreach ($this->leftFloats as $float) {
                $bottom = max($bottom, $float->bottom());
            }
        }
        if ($clear === 'right' || $clear === 'both') {
            foreach ($this->rightFloats as $float) {
                $bottom = max($bottom, $float->bottom());
            }
        }
        return $bottom;
    }

    /**
     * CSS 2.2 §10.6.7: Y del borde inferior del float MÁS BAJO registrado en este BFC (cualquier
     * lado), o null si ninguno -- usado por BlockFlowContext para que un BFC root (overflow:hidden
     * o la raíz del documento) "contenga" la altura de sus propios floats en su propio cálculo de
     * altura de contenido (el comportamiento normal, SIN esto, es que los floats NO cuentan para
     * la altura del contenedor -- ver el brief de esta tarea, "block container height does NOT
     * include contained floats... EXCEPT... BFC roots").
     */
    public function maxBottom(): ?float
    {
        $bottom = null;
        foreach ([...$this->leftFloats, ...$this->rightFloats] as $float) {
            $bottom = $bottom === null ? $float->bottom() : max($bottom, $float->bottom());
        }
        return $bottom;
    }
}
