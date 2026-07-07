<?php

// tools/oracle/run.php
declare(strict_types=1);

/**
 * M9-T5: one-command entry point for the oracle (`composer oracle`, and the exact same script
 * .github/workflows/oracle.yml's CI job calls). Orchestrates, in order:
 *
 *   1. node/Playwright availability check -- degrades gracefully (prints a note, exits 0, does
 *      NOT fail the caller) when node isn't installed locally, per this task's constraint that
 *      the oracle stays opt-in local tooling; CI always has node (workflow installs it), so the
 *      real, authoritative signal always runs there regardless of any given developer's machine.
 *   2. `npm install` + `npx playwright install chromium` in this directory, ONLY if
 *      node_modules/ is missing (so repeat local runs are fast; CI does its own `npm ci` +
 *      `playwright install --with-deps` steps before ever invoking this script -- see the
 *      workflow, which skips straight past this block since node_modules/ already exists there).
 *   3. render-chrome.mjs (Playwright screenshots) + render-pliego.php (Engine -> PDF ->
 *      Ghostscript raster) -- independent of each other, run sequentially for simplicity (six
 *      fixtures, not a scale where parallelizing them is worth the added complexity).
 *   4. compare.php (PixelDiff against thresholds.json) -- its exit code becomes this script's.
 */

function oracleCommandExists(string $binary): bool
{
    $probe = PHP_OS_FAMILY === 'Windows' ? "where $binary" : "command -v $binary";
    $result = shell_exec("$probe 2>&1");
    return $result !== null && trim($result) !== '' && !str_contains(strtolower($result), 'not found') && !str_contains(strtolower($result), 'no such file');
}

/** Runs a command with the CWD set to this directory, streaming output, returning its exit code. */
function oracleRun(string $cmd): int
{
    echo "\$ $cmd\n";
    $descriptors = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
    $process = proc_open($cmd, $descriptors, $pipes, __DIR__);
    if ($process === false) {
        fwrite(STDERR, "run: could not start: $cmd\n");
        return 1;
    }
    return proc_close($process);
}

function main(): int
{
    if (!oracleCommandExists('node')) {
        echo "oracle: node not found on PATH -- skipping locally (the oracle still runs in CI, see .github/workflows/oracle.yml).\n";
        return 0;
    }
    if (!oracleCommandExists('gswin64c') && !oracleCommandExists('gswin32c') && !oracleCommandExists('gs')) {
        echo "oracle: Ghostscript not found on PATH (tried gs/gswin64c/gswin32c) -- skipping locally (the oracle still runs in CI).\n";
        return 0;
    }

    if (!is_dir(__DIR__ . '/node_modules')) {
        echo "oracle: node_modules/ missing -- running npm install + playwright install chromium (one-time local setup)...\n";
        $installExit = oracleRun('npm install');
        if ($installExit !== 0) {
            fwrite(STDERR, "oracle: npm install failed (exit $installExit).\n");
            return $installExit;
        }
        $playwrightExit = oracleRun('npx playwright install chromium');
        if ($playwrightExit !== 0) {
            fwrite(STDERR, "oracle: npx playwright install chromium failed (exit $playwrightExit).\n");
            return $playwrightExit;
        }
    }

    $chromeExit = oracleRun('node render-chrome.mjs');
    if ($chromeExit !== 0) {
        fwrite(STDERR, "oracle: render-chrome.mjs failed (exit $chromeExit).\n");
        return $chromeExit;
    }

    $pliegoExit = oracleRun('php render-pliego.php');
    if ($pliegoExit !== 0) {
        fwrite(STDERR, "oracle: render-pliego.php failed (exit $pliegoExit).\n");
        return $pliegoExit;
    }

    return oracleRun('php compare.php');
}

exit(main());
