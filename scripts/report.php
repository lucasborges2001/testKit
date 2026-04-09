<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/bootstrap.php';

use Testkit\Core\Reporting\FailureClassifier;
use Testkit\Core\Reporting\ReportSummary;

$testkitRoot = rtrim((string)(getenv('TESTKIT_ROOT') ?: dirname(__DIR__)), '/\\');
$repoRoot = rtrim((string)(getenv('TK_REPO_ROOT') ?: dirname($testkitRoot)), '/\\');
$testRoot = $repoRoot . '/test';

if (!is_dir($testRoot)) {
    fwrite(STDERR, "No existe directorio de tests: {$testRoot}\n");
    exit(2);
}

$latestFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS)
);

/** @var SplFileInfo $file */
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $name = $file->getFilename();
    if (!str_ends_with($name, '_latest.json')) {
        continue;
    }

    $parent = basename((string)$file->getPath());
    if (!in_array($parent, ['report', 'reports'], true)) {
        continue;
    }

    $latestFiles[] = str_replace('\\', '/', $file->getPathname());
}

$latestFiles = array_values(array_unique($latestFiles));
sort($latestFiles);

if (!$latestFiles) {
    fwrite(STDERR, "No hay reportes *_latest.json bajo {$testRoot}\n");
    fwrite(STDERR, "Corré primero un runner para generar reportes.\n");
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
    $json['_source_file'] = $file;
    $reports[] = $json;
}

if (!$reports) {
    fwrite(STDERR, "No se pudieron parsear reportes latest con suite_id.\n");
    exit(2);
}

usort($reports, static fn(array $a, array $b): int => strcmp((string)$a['suite_id'], (string)$b['suite_id']));

$totalPass = 0;
$totalFail = 0;
$totalSkip = 0;
$totalTests = 0;
$allFailures = [];

foreach ($reports as &$r) {
    $summary = is_array($r['summary'] ?? null) ? $r['summary'] : [];
    $totalPass += (int)($r['pass'] ?? $summary['passed'] ?? 0);
    $totalFail += (int)($r['fail'] ?? $summary['failed'] ?? 0);
    $totalSkip += (int)($r['skip'] ?? $summary['skipped'] ?? 0);
    $totalTests += (int)($r['tests_total'] ?? $summary['total'] ?? 0);

    $failures = ReportSummary::canonicalFailures($r);
    $r['_canonical_failures'] = $failures;
    $r['_triage_summary'] = is_array($r['triage_summary'] ?? null)
        ? $r['triage_summary']
        : FailureClassifier::summarize($failures, 4);

    foreach ($failures as $failure) {
        $allFailures[] = $failure;
    }
}
unset($r);

echo "== TestKit Executive Summary ==\n";
echo "Status:    " . ($totalFail > 0 ? "FAIL" : "PASS") . "\n";
echo "Total:     {$totalTests} tests\n";
echo "Results:   pass={$totalPass} fail={$totalFail} skip={$totalSkip}\n";
echo str_repeat("=", 96) . "\n\n";

echo "Suite Summary\n";
echo str_pad("Suite", 16) . " | "
    . str_pad("Scope", 16) . " | "
    . str_pad("Tests", 5, ' ', STR_PAD_LEFT) . " | "
    . str_pad("Pass", 4, ' ', STR_PAD_LEFT) . " | "
    . str_pad("Fail", 4, ' ', STR_PAD_LEFT) . " | "
    . str_pad("Skip", 4, ' ', STR_PAD_LEFT) . " | "
    . str_pad("Time (ms)", 9, ' ', STR_PAD_LEFT) . " | "
    . "Location\n";
echo str_repeat("-", 120) . "\n";
foreach ($reports as $report) {
    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
    $suite = str_pad((string)$report['suite_id'], 16);
    $scope = trim((string)($report['selected_module_scope'] ?? ''));
    if ($scope === '') {
        $scope = 'global';
    }
    $scope = str_pad($scope, 16);
    $tests = str_pad((string)($report['selected_test_count'] ?? $report['tests_total'] ?? $summary['total'] ?? 0), 5, ' ', STR_PAD_LEFT);
    $pass = str_pad((string)($report['pass'] ?? $summary['passed'] ?? 0), 4, ' ', STR_PAD_LEFT);
    $fail = str_pad((string)($report['fail'] ?? $summary['failed'] ?? 0), 4, ' ', STR_PAD_LEFT);
    $skip = str_pad((string)($report['skip'] ?? $summary['skipped'] ?? 0), 4, ' ', STR_PAD_LEFT);
    $duration = str_pad((string)($report['duration_ms'] ?? $summary['duration_ms'] ?? 0), 9, ' ', STR_PAD_LEFT);
    $location = (string)($report['report_scope_rel'] ?? $report['report_root'] ?? '(default)');

    echo "{$suite} | {$scope} | {$tests} | {$pass} | {$fail} | {$skip} | {$duration} | {$location}\n";
}

echo "\nDominant blockers\n";
$globalTriage = FailureClassifier::summarize($allFailures, 6);
if ($globalTriage === []) {
    echo "- none\n";
} else {
    foreach ($globalTriage as $row) {
        $label = (string)($row['label'] ?? $row['family'] ?? 'unknown');
        $count = (int)($row['count'] ?? 0);
        $next = (string)($row['next_step'] ?? '');
        echo "- {$label}: {$count} failure(s)\n";
        $examples = is_array($row['examples'] ?? null) ? $row['examples'] : [];
        foreach (array_slice($examples, 0, 2) as $example) {
            if (!is_array($example)) {
                continue;
            }
            $file = trim((string)($example['file'] ?? ''));
            $message = trim((string)($example['message'] ?? ''));
            if ($file === '') {
                continue;
            }
            echo "    * {$file}";
            if ($message !== '') {
                echo " -> {$message}";
            }
            echo "\n";
        }
        if ($next !== '') {
            echo "    action: {$next}\n";
        }
    }
}

echo "\nScope Details\n";
$printedScope = false;
foreach ($reports as $report) {
    $scope = trim((string)($report['selected_module_scope'] ?? ''));
    $count = (int)($report['selected_test_count'] ?? $report['tests_total'] ?? 0);
    $root = (string)($report['report_scope_rel'] ?? $report['report_root'] ?? '');
    $match = (string)($report['match'] ?? '');
    echo '- ' . (string)$report['suite_id'] . ': scope=' . ($scope !== '' ? $scope : 'global') . ', tests=' . $count . ', root=' . ($root !== '' ? $root : '(default)');
    if ($match !== '') {
        echo ', match=' . $match;
    }
    echo "\n";
    $printedScope = true;
}
if (!$printedScope) {
    echo "- none\n";
}

echo "\nFailures by Suite\n";
$hasFailures = false;
foreach ($reports as $report) {
    $failures = is_array($report['_canonical_failures'] ?? null) ? $report['_canonical_failures'] : [];
    if ($failures === []) {
        continue;
    }

    $hasFailures = true;
    echo '- ' . (string)$report['suite_id'] . "\n";

    $failedFiles = ReportSummary::failedFiles($failures);
    echo '    files: ' . ($failedFiles ? implode(', ', $failedFiles) : 'none') . "\n";

    $triage = is_array($report['_triage_summary'] ?? null) ? $report['_triage_summary'] : [];
    if ($triage !== []) {
        echo "    blockers:\n";
        foreach (array_slice($triage, 0, 3) as $row) {
            if (!is_array($row)) {
                continue;
            }
            echo '      - ' . (string)($row['label'] ?? $row['family'] ?? 'unknown') . ': ' . (int)($row['count'] ?? 0) . "\n";
        }
    }

    $topMessages = ReportSummary::topFailureMessages($failures, 5);
    echo "    top messages:\n";
    if ($topMessages === []) {
        echo "      * none\n";
    } else {
        foreach ($topMessages as $row) {
            echo '      * [' . (int)($row['count'] ?? 0) . 'x] ' . (string)($row['message'] ?? '') . "\n";
        }
    }

    echo "    failures:\n";
    foreach (array_slice($failures, 0, 10) as $entry) {
        $name = (string)($entry['file'] ?? $entry['test_id'] ?? 'unknown');
        $msg = trim((string)($entry['message'] ?? ''));
        echo '      * ' . $name;
        if ($msg !== '') {
            echo '  →  ' . substr($msg, 0, 140);
        }
        echo "\n";
    }

    $grouped = $report['grouped_failures'] ?? ReportSummary::groupFailures($failures);
    if (is_array($grouped) && !empty($grouped['by_error_type'])) {
        echo "    grouped by error type:\n";
        foreach ((array)$grouped['by_error_type'] as $errType => $testIds) {
            echo '      - ' . $errType . ': ' . count((array)$testIds) . " test(s)\n";
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
    echo '- ' . (string)$report['suite_id'] . "\n";
    foreach ($slow as $entry) {
        echo '    * ' . str_pad((string)($entry['duration_ms'] ?? 0), 6, ' ', STR_PAD_LEFT) . ' ms  ' . (string)($entry['rel'] ?? $entry['file'] ?? 'unknown') . "\n";
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
            echo '- ' . (string)$report['suite_id'] . "\n";
            $printedSuite = true;
            $hasFragility = true;
        }
        echo '    * flaky: ' . (string)($hint['test'] ?? 'unknown') . ' (pass=' . (int)($hint['pass_count'] ?? 0) . ', fail=' . (int)($hint['fail_count'] ?? 0) . ")\n";
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
