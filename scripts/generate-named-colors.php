#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Regenerates the body of `Color::KEYWORDS` (css-color-4 §6.1: the 148 CSS named colors,
 * both `gray`/`grey` spellings, `rebeccapurple`, no `transparent`/`currentcolor` — those are
 * separate CSS keywords with their own semantics, handled directly in Color::fromCss()) from
 * the canonical `color-name` npm package (colorjs/color-name, MIT license) — the same source
 * used to originally populate the table by hand in M6-T5 (see .superpowers/sdd/m6-task-5-report.md).
 *
 * USAGE
 *   php scripts/generate-named-colors.php            Print the generated PHP array-body
 *                                                     lines to stdout, reading the committed
 *                                                     source at scripts/data/color-name.js.
 *   php scripts/generate-named-colors.php --check     Instead of printing, diff the freshly
 *                                                     generated table against the CURRENT
 *                                                     Color::KEYWORDS block in
 *                                                     src/Css/Value/Color.php. Exit 0 (silent)
 *                                                     if byte-identical; exit 1 with a unified
 *                                                     diff otherwise. Regression guard so the
 *                                                     generator and the committed table can
 *                                                     never silently drift apart.
 *   php scripts/generate-named-colors.php --source=<url> [--check]
 *                                                     Re-fetch the source fresh from <url>
 *                                                     (documented canonical location:
 *                                                     https://raw.githubusercontent.com/colorjs/color-name/master/index.js)
 *                                                     instead of the committed copy, OVERWRITING
 *                                                     scripts/data/color-name.js with the fetched
 *                                                     body so the committed source stays in sync
 *                                                     with upstream. Combine with --check to
 *                                                     verify Color::KEYWORDS still matches
 *                                                     upstream without touching Color.php.
 *
 * To apply a regenerated table to Color.php: run WITHOUT --check, paste the printed lines
 * over the body of `private const array KEYWORDS = [ ... ];` in src/Css/Value/Color.php, then
 * re-run WITH --check to confirm the replacement is byte-identical to what the generator
 * itself produces (i.e. no manual edit drift).
 */

const DEFAULT_SOURCE_PATH = __DIR__ . '/data/color-name.js';
const COLOR_PHP_PATH = __DIR__ . '/../src/Css/Value/Color.php';
const ENTRY_LINE_RE = '/^\s*([a-z]+):\s*\[(\d+),\s*(\d+),\s*(\d+)\],?\s*$/m';

/** @return array<int, string> */
function parseArgs(array $argv): array
{
    $flags = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--source=')) {
            $flags['source'] = substr($arg, strlen('--source='));
        } elseif ($arg === '--check') {
            $flags['check'] = '1';
        }
    }
    return $flags;
}

/** @return array<string, array{int, int, int}> name (lowercase) => [r, g, b], in source order. */
function extractEntries(string $js): array
{
    if (preg_match_all(ENTRY_LINE_RE, $js, $matches, PREG_SET_ORDER) === false) {
        fwrite(STDERR, "Failed to parse color-name source (unexpected format)\n");
        exit(1);
    }
    $entries = [];
    foreach ($matches as $m) {
        $entries[$m[1]] = [(int) $m[2], (int) $m[3], (int) $m[4]];
    }
    return $entries;
}

/**
 * @param array<string, array{int, int, int}> $entries
 * @return list<string>
 */
function renderLines(array $entries): array
{
    $lines = [];
    foreach ($entries as $name => [$r, $g, $b]) {
        $lines[] = sprintf("        '%s' => [%d, %d, %d],", $name, $r, $g, $b);
    }
    return $lines;
}

/** Extracts the current body lines (one per KEYWORDS entry) from the committed Color.php,
 * so --check can diff against them without re-parsing PHP source with a full tokenizer. */
function currentKeywordsLines(string $colorPhp): ?array
{
    if (preg_match('/private const array KEYWORDS = \[\n(.*?)\n\s*\];/s', $colorPhp, $m) !== 1) {
        return null;
    }
    return explode("\n", rtrim($m[1], "\n"));
}

function main(): void
{
    $flags = parseArgs($GLOBALS['argv']);

    if (isset($flags['source'])) {
        $fetched = @file_get_contents($flags['source']);
        if ($fetched === false) {
            fwrite(STDERR, "Failed to fetch source: {$flags['source']}\n");
            exit(1);
        }
        file_put_contents(DEFAULT_SOURCE_PATH, $fetched);
        $js = $fetched;
    } else {
        $js = file_get_contents(DEFAULT_SOURCE_PATH);
        if ($js === false) {
            fwrite(STDERR, 'Cannot read committed source: ' . DEFAULT_SOURCE_PATH . "\n");
            exit(1);
        }
    }

    $entries = extractEntries($js);
    fwrite(STDERR, 'Parsed ' . count($entries) . " color entries.\n");
    $generatedLines = renderLines($entries);

    if (!isset($flags['check'])) {
        echo implode("\n", $generatedLines), "\n";
        return;
    }

    $colorPhp = file_get_contents(COLOR_PHP_PATH);
    if ($colorPhp === false) {
        fwrite(STDERR, 'Cannot read ' . COLOR_PHP_PATH . "\n");
        exit(1);
    }
    $currentLines = currentKeywordsLines($colorPhp);
    if ($currentLines === null) {
        fwrite(STDERR, "Could not locate the KEYWORDS block in " . COLOR_PHP_PATH . "\n");
        exit(1);
    }

    if ($currentLines === $generatedLines) {
        fwrite(STDERR, "OK: Color::KEYWORDS is byte-identical to the generated table (" . count($generatedLines) . " entries).\n");
        return;
    }

    fwrite(STDERR, "MISMATCH: Color::KEYWORDS differs from the generated table.\n");
    $max = max(count($currentLines), count($generatedLines));
    for ($i = 0; $i < $max; $i++) {
        $current = $currentLines[$i] ?? '(missing)';
        $generated = $generatedLines[$i] ?? '(missing)';
        if ($current !== $generated) {
            fwrite(STDERR, "  line $i:\n    committed:  $current\n    generated:  $generated\n");
        }
    }
    exit(1);
}

main();
