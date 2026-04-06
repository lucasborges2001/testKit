<?php
declare(strict_types=1);

/**
 * TestKit report aggregator.
 *
 * Reads latest JSON outputs from <repo>/test/reports and prints:
 * - suite summary
 * - grouped failures
 * - slow tests
 * - fragility hints
 * - coverage gaps (if diagnostics exist)
 */

$testkitRoot = rtrim((string)(getenv('TESTKIT_ROOT') ?: dirname(__DIR__)), '/\\');
$repoRoot = rtrim((string)(getenv('TK_REPO_ROOT') ?: dirname($testkitRoot)), '/\\');
$reportsRoot = $repoRoot . '/test/reports';

if (!is_dir($reportsRoot)) {
    fwrite(STDERR, "No existe directorio de reportes: {$reportsRoot}\n");
    fwrite(STDERR, "Corré primero un runner para generar reportes.\n");
    exit(2);
}

$latestFiles = glob($reportsRoot . '/*_latest.json') ?: [];
if (!$latestFiles) {
    fwrite(STDERR, "No hay reportes *_latest.json en {$reportsRoot}\n");
    exit(2);
}

$reports = [];
foreach ($latestFiles as $file) {
    $raw = file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') {
        continue;
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        continue;
    }
    if (!isset($json['suite_id'])) {
        continue;
    }
    $reports[] = $json;
}

if (!$reports) {
    fwrite(STDERR, "No se pudieron parsear reportes latest.\n");
    exit(2);
}

usort($reports, static fn(array $a, array $b): int => strcmp((string)$a['suite_id'], (string)$b['suite_id']));

$totalPass = 0; $totalFail = 0; $totalSkip = 0; $totalTests = 0;
foreach ($reports as $r) {
    $totalPass += (int)($r['pass'] ?? 0);
    $totalFail += (int)($r['fail'] ?? 0);
    $totalSkip += (int)($r['skip'] ?? 0);
    $totalTests += (int)($r['tests_total'] ?? 0);
}

echo "== TestKit Executive Summary ==\n";
echo "Status:    " . ($totalFail > 0 ? "FAIL" : "PASS") . "\n";
echo "Total:     {$totalTests} tests\n";
echo "Results:   pass={$totalPass} fail={$totalFail} skip={$totalSkip}\n";
echo "Reports:   {$reportsRoot}\n";
echo str_repeat("=", 32) . "\n\n";

echo "Suite Summary\n";
echo str_pad("Suite", 20) . " | Exit | Pass | Fail | Skip | Time (ms)\n";
echo str_repeat("-", 65) . "\n";
foreach ($reports as $report) {
    $suite = str_pad((string)$report['suite_id'], 20);
    $pass = str_pad((string)($report['pass'] ?? 0), 4, " ", STR_PAD_LEFT);
    $fail = str_pad((string)($report['fail'] ?? 0), 4, " ", STR_PAD_LEFT);
    $skip = str_pad((string)($report['skip'] ?? 0), 4, " ", STR_PAD_LEFT);
    $duration = str_pad((string)($report['duration_ms'] ?? 0), 9, " ", STR_PAD_LEFT);
    $exitCode = (int)($report['exit_code'] ?? 1);

    echo "{$suite} |  {$exitCode}   | {$pass} | {$fail} | {$skip} | {$duration}\n";
}

echo "\nFailed Tests by Suite\n";
$hasFailures = false;
foreach ($reports as $report) {
    $failed = $report['failed_tests'] ?? [];
    if (!is_array($failed) || !$failed) {
        continue;
    }
    $hasFailures = true;
    echo "- " . (string)$report['suite_id'] . "\n";
    foreach ($failed as $entry) {
        echo "    * " . (string)($entry['rel'] ?? 'unknown') . " (exit=" . (int)($entry['exit_code'] ?? 1) . ")\n";
    }
}
if (!$hasFailures) {
    echo "- none\n";
}

echo "\nSlow tests\n";
$hasSlow = false;
foreach ($reports as $report) {
    $slow = $report['slow_tests'] ?? [];
    if (!is_array($slow) || !$slow) {
        continue;
    }
    $hasSlow = true;
    echo "- " . (string)$report['suite_id'] . "\n";
    foreach ($slow as $entry) {
        echo "    * " . str_pad((string)($entry['duration_ms'] ?? 0), 6, ' ', STR_PAD_LEFT) . " ms  " . (string)($entry['rel'] ?? 'unknown') . "\n";
    }
}
if (!$hasSlow) {
    echo "- none\n";
}

echo "\nFragility hints\n";
$hasFragility = false;
foreach ($reports as $report) {
    $hints = $report['fragility_hints'] ?? [];
    if (!is_array($hints) || !$hints) {
        continue;
    }

    $printedSuite = false;
    foreach ($hints as $hint) {
        if (($hint['type'] ?? '') !== 'flaky') {
            continue;
        }
        if (!$printedSuite) {
            echo "- " . (string)$report['suite_id'] . "\n";
            $printedSuite = true;
            $hasFragility = true;
        }
        echo "    * flaky: " . (string)($hint['test'] ?? 'unknown') . " (pass=" . (int)($hint['pass_count'] ?? 0) . ", fail=" . (int)($hint['fail_count'] ?? 0) . ")\n";
    }
}
if (!$hasFragility) {
    echo "- none\n";
}

echo "\nCoverage diagnostics\n";
$covDirs = [
    $repoRoot . '/test/coverage/php_back',
    $repoRoot . '/test/coverage/php_front',
    $repoRoot . '/test/coverage/python_back',
];
$printedCoverage = false;
foreach ($covDirs as $dir) {
    $diag = $dir . '/coverage_diagnostics.json';
    if (!is_file($diag)) {
        continue;
    }
    $raw = file_get_contents($diag);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($json)) {
        continue;
    }

    $printedCoverage = true;
    $name = basename($dir);
    $overall = (float)($json['overall']['percent'] ?? 0.0);
    $criticalMissing = is_array($json['critical_missing'] ?? null) ? count($json['critical_missing']) : 0;
    $criticalLow = is_array($json['critical_low'] ?? null) ? count($json['critical_low']) : 0;

    echo "- {$name}: overall={$overall}% critical_missing={$criticalMissing} critical_low={$criticalLow}\n";
}

if (!$printedCoverage) {
    echo "- no php coverage diagnostics generated\n";
}

exit(0);
