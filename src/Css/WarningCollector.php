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

    /** @var array<string, true> M7-T2: claves de dedup ya emitidas vía addWarningOnce() — ver ahí. */
    private array $onceKeys = [];

    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * M7-T2: variante de addWarning() que emite el mensaje UNA SOLA VEZ por $key durante la vida
     * de esta instancia (Engine::render() comparte UN ÚNICO WarningCollector entre StyleResolver/
     * BoxTreeBuilder/BlockFlowContext y todos los FormattingContext anidados que este último crea
     * perezosamente — ver BlockFlowContext — así que "una vez por instancia" es, en la práctica,
     * "una vez por render completo"). Pensado para warnings que podrían dispararse una vez por
     * elemento/run (p.ej. una familia genérica de fuente sin cara registrada, resuelta en CADA
     * TextRun que la use) pero que solo aportan información nueva la PRIMERA vez — $key es un
     * identificador estable del "tipo" de warning (no necesariamente el mensaje completo), para
     * que dos mensajes distintos sobre la MISMA causa raíz deduplique aunque su texto varíe.
     */
    public function addWarningOnce(string $key, string $warning): void
    {
        if (isset($this->onceKeys[$key])) {
            return;
        }
        $this->onceKeys[$key] = true;
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
