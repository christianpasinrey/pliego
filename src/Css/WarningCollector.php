<?php

declare(strict_types=1);

namespace Pliego\Css;

/**
 * Colector de warnings reutilizable (M3-T2): vive en Css\ (capa base, sin dependencias propias en
 * el ruleset de deptrac) para que cualquier capa superior con permiso hacia Css (Style, Box, Page,
 * ...) pueda compartir el mismo mecanismo de "warning + continuar" que StylesheetParser,
 * DeclarationParser y PageRuleFactory ya implementan cada uno por su cuenta (warnings[] +
 * drainWarnings()). Box\BoxTreeBuilder es el primer consumidor (M3-T2); StylesheetParser NO se
 * refactoriza a usarlo todavía (fuera de alcance de esta tarea).
 */
final class WarningCollector
{
    /** @var list<string> */
    private array $warnings = [];

    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    /** @return list<string> */
    public function drain(): array
    {
        $warnings = $this->warnings;
        $this->warnings = [];
        return $warnings;
    }
}
