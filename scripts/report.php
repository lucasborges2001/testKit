<?php
declare(strict_types=1);

/**
 * TestKit report aggregator.
 *
 * Reads latest JSON outputs from testkit/_out/reports and prints:
 * - suite summary
 * - grouped failures
 * - slow tests
 * - fragility hints
 * - coverage gaps (if diagnostics exist)
 */

$testkitRoot = rtrim((string)(getenv('TESTKIT_ROOT') ?: dirname(__DIR__)), '/\\');
$reportsRoot = $testkitRoot . '/_out/reports';

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

echo "== TestKit Report ==\n";
echo "reports: {$reportsRoot}\n\n";

echo "Suite summary\n";
foreach ($reports as $report) {
    $suite = (string)$report['suite_id'];
    $pass = (int)($report['pass'] ?? 0);
    $fail = (int)($report['fail'] ?? 0);
    $skip = (int)($report['skip'] ?? 0);
    $duration = (int)($report['duration_ms'] ?? 0);
    $exitCode = (int)($report['exit_code'] ?? 1);

    echo str_pad($suite, 20) . " exit={$exitCode} pass={$pass} fail={$fail} skip={$skip} time_ms={$duration}\n";
}

echo "\nFailed tests by suite\n";
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
    $testkitRoot . '/_out/coverage/php_back',
    $testkitRoot . '/_out/coverage/php_front',
    $testkitRoot . '/_out/coverage/python_back',
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
