<?php

// tools/oracle/compare.php
declare(strict_types=1);

/**
 * M9-T5: compares each tools/oracle/out/NN-chrome.png against its NN-pliego-1.png counterpart
 * (produced by render-chrome.mjs / render-pliego.php respectively) using PliegoOracle\PixelDiff,
 * writes an NN-diff.png visualization (PliegoOracle\DiffPngEncoder) for every fixture regardless
 * of outcome (uploaded as a CI artifact unconditionally -- see .github/workflows/oracle.yml), and
 * exits non-zero if ANY fixture's measured diffPercent exceeds its calibrated threshold in
 * thresholds.json.
 *
 * Standalone-runnable (assumes render-chrome.mjs and render-pliego.php already populated out/) --
 * run.php is the one-command orchestrator that runs both renderers first, then this.
 */

require __DIR__ . '/../../vendor/autoload.php';

use PliegoOracle\DiffPngEncoder;
use PliegoOracle\PixelDiff;

/** @return array<string, float> fixture number ("01".."06") => threshold percent */
function oracleLoadThresholds(): array
{
    $path = __DIR__ . '/thresholds.json';
    $json = file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException("compare: could not read $path");
    }
    /** @var array<string, float>|null $decoded */
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("compare: $path is not a valid JSON object.");
    }
    return $decoded;
}

/** @return list<string> fixture numbers, derived from the fixtures directory, sorted */
function oracleFixtureNumbers(): array
{
    $fixtures = glob(__DIR__ . '/fixtures/*.html');
    if ($fixtures === false) {
        throw new RuntimeException('compare: glob() failed while listing fixtures.');
    }
    sort($fixtures);
    $numbers = [];
    foreach ($fixtures as $fixturePath) {
        if (preg_match('/^(\d+)-/', basename($fixturePath), $m) === 1) {
            $numbers[] = $m[1];
        }
    }
    return $numbers;
}

/** @return array{percent: float, threshold: float, pass: bool, error: ?string} */
function oracleCompareFixture(string $number, array $thresholds): array
{
    $outDir = __DIR__ . '/out';
    $chromePath = "$outDir/$number-chrome.png";
    $pliegoPath = "$outDir/$number-pliego-1.png";
    $threshold = $thresholds[$number] ?? null;

    if ($threshold === null) {
        return ['percent' => 0.0, 'threshold' => 0.0, 'pass' => false, 'error' => "no threshold configured for fixture $number in thresholds.json"];
    }
    if (!is_file($chromePath)) {
        return ['percent' => 0.0, 'threshold' => (float) $threshold, 'pass' => false, 'error' => "missing $chromePath (render-chrome.mjs did not run?)"];
    }
    if (!is_file($pliegoPath)) {
        return ['percent' => 0.0, 'threshold' => (float) $threshold, 'pass' => false, 'error' => "missing $pliegoPath (render-pliego.php did not run?)"];
    }

    $chromeBytes = (string) file_get_contents($chromePath);
    $pliegoBytes = (string) file_get_contents($pliegoPath);

    $result = PixelDiff::compare($chromeBytes, $pliegoBytes);

    $diffPngPath = "$outDir/$number-diff.png";
    file_put_contents($diffPngPath, DiffPngEncoder::encode($result->width, $result->height, $result->backgroundRgb, $result->mask));

    return [
        'percent' => $result->diffPercent,
        'threshold' => (float) $threshold,
        'pass' => $result->diffPercent <= (float) $threshold,
        'error' => null,
    ];
}

function main(): int
{
    $thresholds = oracleLoadThresholds();
    $numbers = oracleFixtureNumbers();
    if ($numbers === []) {
        fwrite(STDERR, "compare: no fixtures found under tools/oracle/fixtures/.\n");
        return 1;
    }

    $allPass = true;
    printf("%-8s %10s %10s %6s\n", 'fixture', 'diff %', 'threshold', 'result');
    printf("%s\n", str_repeat('-', 40));

    foreach ($numbers as $number) {
        $row = oracleCompareFixture($number, $thresholds);
        if ($row['error'] !== null) {
            printf("%-8s %10s %10s %6s  (%s)\n", $number, '-', '-', 'ERROR', $row['error']);
            $allPass = false;
            continue;
        }
        $status = $row['pass'] ? 'PASS' : 'FAIL';
        printf("%-8s %9.3f%% %9.3f%% %6s\n", $number, $row['percent'], $row['threshold'], $status);
        if (!$row['pass']) {
            $allPass = false;
        }
    }

    return $allPass ? 0 : 1;
}

exit(main());
