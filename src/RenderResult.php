<?php

// src/RenderResult.php
declare(strict_types=1);

namespace Pliego;

final readonly class RenderResult
{
    /** @param \Closure(resource): RenderReport $writer */
    public function __construct(private \Closure $writer) {}

    public function save(string $path): RenderReport
    {
        $stream = fopen($path, 'wb');
        if ($stream === false) {
            throw new \RuntimeException("Cannot open for writing: $path");
        }
        try {
            return ($this->writer)($stream);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    public function toStream(mixed $stream): RenderReport
    {
        return ($this->writer)($stream);
    }
}
