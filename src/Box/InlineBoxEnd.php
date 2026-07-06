<?php

declare(strict_types=1);

namespace Pliego\Box;

/**
 * M7-T4 (css-inline-3 reducido): marcador de CIERRE, contraparte de InlineBoxStart -- siempre
 * emitido en pareja con un InlineBoxStart anterior (BoxTreeBuilder garantiza el anidamiento
 * balanceado por construcción, vía descenso recursivo), nunca de forma aislada.
 */
final readonly class InlineBoxEnd {}
