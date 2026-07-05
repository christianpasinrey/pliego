<?php

// src/RenderReport.php
declare(strict_types=1);

namespace Pliego;

final readonly class RenderReport
{
    /** @param list<string> $warnings */
    public function __construct(public array $warnings, public int $pageCount) {}
}
