<?php
declare(strict_types=1);

/**
 * TestKit report aggregator.
 *
 * Discovers *_latest.json files from:
 *   - test/reports/                         (fallback / multi-suite runs)
 *   - test/back/<module>/report/            (scoped back runs)
 *   - test/front/<module>/report/           (scoped front runs)
 *
 * Prints suite summary, grouped failures, slow tests, fragility hints,
 * and coverage diagnostics.
 */

$testkitRoot = rtrim((string)(getenv('TESTKIT_ROOT') ?: dirname(__DIR__)), '/\\');
$repoRoot    = rtrim((string)(getenv('TK_REPO_ROOT') ?: dirname($testkitRoot)), '/\\');
$testRoot    = $repoRoot . '/test';

if (!is_dir($testRoot)) {
    fwrite(STDERR, "No existe directorio de tests: {$testRoot}\n");
    exit(2);
}

// ---- Discover all *_latest.json from known report locations ---------------

$latestFiles = [];

// 1. Fallback / classic location: test/reports/
$fallback = glob($testRoot . '/reports/*_latest.json') ?: [];
$latestFiles = array_merge($latestFiles, $fallback);

// 2. Scoped locations: test/back/<module>/report/ and test/front/<module>/report/
foreach (['back', 'front'] as $side) {
    $sideDir = $testRoot . '/' . $side;
    if (!is_dir($sideDir)) {
        continue;
    }
    $modules = @scandir($sideDir) ?: [];
    foreach ($modules as $module) {
        if ($module === '.' || $module === '..') {
            continue;
        }
        $reportDir = $sideDir . '/' . $module . '/report';
        if (!is_dir($reportDir)) {
            continue;
        }
        $found = glob($reportDir . '/*_latest.json') ?: [];
        $latestFiles = array_merge($latestFiles, $found);
    }
}

$latestFiles = array_values(array_unique($latestFiles));

if (!$latestFiles) {
    fwrite(STDERR, "No hay reportes *_latest.json bajo {$testRoot}\n");
    fwrite(STDERR, "Corré primero un runner para generar reportes.\n");
    exit(2);
}

// ---- Parse reports ---------------------------------------------------------

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
    // Skip meta files (no suite_id) unless they are the only thing available
    if (!isset($json['suite_id'])) {
        continue;
    }
    $json['_source_file'] = $file;
    $reports[] = $json;
}

if (!$reports) {
    fwrite(STDERR, "No se pudieron parsear reportes latest con suite_id.\n");
    exit(2);
}

usort($reports, static fn(array $a, array $b): int => strcmp((string)$a['suite_id'], (string)$b['suite_id']));

// ---- Aggregate totals ------------------------------------------------------

$totalPass = 0; $totalFail = 0; $totalSkip = 0; $totalTests = 0;
foreach ($reports as $r) {
    $totalPass  += (int)($r['pass'] ?? $r['summary']['passed'] ?? 0);
    $totalFail  += (int)($r['fail'] ?? $r['summary']['failed'] ?? 0);
    $totalSkip  += (int)($r['skip'] ?? $r['summary']['skipped'] ?? 0);
    $totalTests += (int)($r['tests_total'] ?? $r['summary']['total'] ?? 0);
}

// ---- Print -----------------------------------------------------------------

echo "== TestKit Executive Summary ==\n";
echo "Status:    " . ($totalFail > 0 ? "FAIL" : "PASS") . "\n";
echo "Total:     {$totalTests} tests\n";
echo "Results:   pass={$totalPass} fail={$totalFail} skip={$totalSkip}\n";
echo str_repeat("=", 32) . "\n\n";

echo "Suite Summary\n";
echo str_pad("Suite", 20) . " | Exit | Pass | Fail | Skip | Time (ms) | Location\n";
echo str_repeat("-", 80) . "\n";
foreach ($reports as $report) {
    $suite    = str_pad((string)$report['suite_id'], 20);
    $pass     = str_pad((string)($report['pass'] ?? $report['summary']['passed'] ?? 0), 4, " ", STR_PAD_LEFT);
    $fail     = str_pad((string)($report['fail'] ?? $report['summary']['failed'] ?? 0), 4, " ", STR_PAD_LEFT);
    $skip     = str_pad((string)($report['skip'] ?? $report['summary']['skipped'] ?? 0), 4, " ", STR_PAD_LEFT);
    $duration = str_pad((string)($report['duration_ms'] ?? $report['summary']['duration_ms'] ?? 0), 9, " ", STR_PAD_LEFT);
    $exitCode = (int)($report['exit_code'] ?? 1);
    $scope    = (string)($report['report_scope_rel'] ?? '');
    $location = $scope !== '' ? $scope : '(default)';

    echo "{$suite} |  {$exitCode}   | {$pass} | {$fail} | {$skip} | {$duration} | {$location}\n";
}

echo "\nFailed Tests by Suite\n";
$hasFailures = false;
foreach ($reports as $report) {
    // Prefer enriched 'failures' if present, fall back to 'failed_tests'
    $failures = $report['failures'] ?? null;
    if (!is_array($failures) || !$failures) {
        $failures = $report['failed_tests'] ?? [];
    }
    if (!is_array($failures) || !$failures) {
        continue;
    }
    $hasFailures = true;
    echo "- " . (string)$report['suite_id'] . "\n";
    foreach ($failures as $entry) {
        $name = (string)($entry['test_id'] ?? $entry['rel'] ?? 'unknown');
        $code = (int)($entry['exit_code'] ?? $entry['error_type'] ?? 1);
        $msg  = (string)($entry['message'] ?? '');
        echo "    * {$name}";
        if ($msg !== '') {
            echo "  →  " . substr($msg, 0, 100);
        }
        echo "\n";
    }

    // Print grouped failures if available
    $grouped = $report['grouped_failures'] ?? [];
    if (is_array($grouped) && !empty($grouped['by_error_type'])) {
        foreach ((array)$grouped['by_error_type'] as $errType => $testIds) {
            $count = count($testIds);
            echo "      [by_error_type] {$errType}: {$count} test(s)\n";
        }
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
            $printedSuite  = true;
            $hasFragility  = true;
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
    $raw  = file_get_contents($diag);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($json)) {
        continue;
    }

    $printedCoverage  = true;
    $name             = basename($dir);
    $overall          = (float)($json['overall']['percent'] ?? 0.0);
    $criticalMissing  = is_array($json['critical_missing'] ?? null) ? count($json['critical_missing']) : 0;
    $criticalLow      = is_array($json['critical_low'] ?? null) ? count($json['critical_low']) : 0;

    echo "- {$name}: overall={$overall}% critical_missing={$criticalMissing} critical_low={$criticalLow}\n";
}

if (!$printedCoverage) {
    echo "- no php coverage diagnostics generated\n";
}

exit(0);
