<?php

declare(strict_types=1);

namespace Pliego\Style;

enum Display: string
{
    case Block = 'block';
    case None = 'none';
    // css-flexbox-1 §2: display:flex genera un block-level box en el flujo normal (igual que
    // Block hasta que su contenido se pone en juego) — M4-T4 lo consume vía FlexFormattingContext;
    // hasta entonces BlockFlowContext no distingue por $display, así que la caja sigue fluyendo
    // como un Block normal (grep verificado: ningún match/switch de la base de código depende de
    // Display de forma exhaustiva, solo comparaciones puntuales contra Display::None).
    case Flex = 'flex';
}
