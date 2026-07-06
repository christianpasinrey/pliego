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
    // css-tables-3 §2 / CSS 2.1 §17.2: los cinco display values de tabla. M5-T2 solo los
    // introduce en el vocabulario de Style — BoxTreeBuilder (M5-T3/T4 lo consume) todavía no
    // distingue por $display para estos casos, así que un elemento con cualquiera de estos
    // cinco valores sigue fluyendo como un BlockBox normal en BoxTreeBuilder::buildBlock()
    // (grep verificado, igual que Display::Flex: ningún consumidor de Display en src/ hace un
    // match/switch exhaustivo sobre el enum, solo comparaciones puntuales contra
    // Display::None/Display::Flex — añadir estos cinco cases no cambia ningún comportamiento
    // existente).
    case Table = 'table';
    case TableRow = 'table-row';
    case TableCell = 'table-cell';
    case TableHeaderGroup = 'table-header-group';
    case TableRowGroup = 'table-row-group';
    // M7-T3 (css-lists-3 §3 / CSS 2.2 §12.5.1): <li> es UA default (Style\UserAgentStylesheet,
    // `li { display: list-item }`) — un elemento con este display genera una caja de bloque
    // NORMAL en el flujo (BlockFlowContext::layout() la trata exactamente como Display::Block,
    // mismo grep-verificado que Display::Flex/Table de arriba: ningún consumidor de Display hace
    // un match exhaustivo) MÁS un marcador sintético emitido por BlockFlowContext cuando detecta
    // este display en el hijo que está layouteando (ver su docblock de clase) — el marcador nunca
    // vive en el árbol de caja (Box\BlockBox no gana ninguna subclase "ListItemBox"), solo en el
    // fragment tree de Layout.
    case ListItem = 'list-item';
}
