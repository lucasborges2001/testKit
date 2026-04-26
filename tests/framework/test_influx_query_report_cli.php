<?php
declare(strict_types=1);

$errors = [];
function assert_true(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/testkit_influx_cli_' . getmypid();
@mkdir($tmp . '/reports', 0777, true);
$script = $root . '/scripts/influx_query_report.php';

$missingPath = $tmp . '/reports/missing.json';
$cmd = 'TESTKIT_ARTIFACTS_ROOT=' . escapeshellarg($tmp) . ' ' . PHP_BINARY . ' ' . escapeshellarg($script) . ' --path=' . escapeshellarg($missingPath) . ' 2>&1';
$missingOut = shell_exec($cmd);
assert_true(is_string($missingOut) && str_contains($missingOut, 'No profile report found'), 'CLI should handle missing report', $errors);

$invalidPath = $tmp . '/reports/invalid.json';
file_put_contents($invalidPath, '{invalid');
$cmd = 'TESTKIT_ARTIFACTS_ROOT=' . escapeshellarg($tmp) . ' ' . PHP_BINARY . ' ' . escapeshellarg($script) . ' --path=' . escapeshellarg($invalidPath) . ' 2>&1';
$invalidOut = shell_exec($cmd);
assert_true(is_string($invalidOut) && str_contains($invalidOut, 'Invalid Influx profile report'), 'CLI should reject invalid JSON', $errors);

$validPath = $tmp . '/reports/influx_profile_latest.json';
file_put_contents($validPath, json_encode([
    'report_version' => 1,
    'engine' => 'influx',
    'profile_enabled' => true,
    'run_id' => 'cli_test',
    'started_at' => '2026-04-25T00:00:00Z',
    'finished_at' => '2026-04-25T00:00:01Z',
    'summary' => [
        'total_queries' => 1,
        'unique_fingerprints' => 1,
        'total_query_time_ms' => 920,
        'slow_count' => 1,
        'hotspot_count' => 0,
        'n_plus_one_candidates' => 0,
        'risky_count' => 1,
    ],
    'rankings' => [
        'by_max_ms' => [[
            'sample_query' => 'from(bucket: ?) |> range(start: ?)',
            'fingerprint' => 'from(bucket: ?) |> range(start: ?)',
            'calls' => 1,
            'avg_ms' => 920,
            'max_ms' => 920,
            'total_ms' => 920,
            'classification' => 'slow',
            'risk_severity' => 'warn',
            'risk_flags' => ['missing_range'],
        ]],
        'by_total_ms' => [],
        'by_calls' => [],
        'by_avg_ms' => [],
        'by_risk' => [],
    ],
    'queries' => [],
    'recommendations' => [[
        'classification' => 'slow',
        'risk_flags' => ['missing_range'],
        'fingerprint' => 'from(bucket: ?)',
        'recommendation' => 'Add explicit range().',
    ]],
    'limitations' => [],
], JSON_PRETTY_PRINT));
$cmd = 'TESTKIT_ARTIFACTS_ROOT=' . escapeshellarg($tmp) . ' ' . PHP_BINARY . ' ' . escapeshellarg($script) . ' --path=' . escapeshellarg($validPath) . ' 2>&1';
$validOut = shell_exec($cmd);
assert_true(is_string($validOut) && str_contains($validOut, 'Influx Query Profile'), 'CLI should render title', $errors);
assert_true(is_string($validOut) && str_contains($validOut, 'Total queries: 1'), 'CLI should render summary', $errors);
assert_true(is_string($validOut) && str_contains($validOut, 'Top by max latency'), 'CLI should render rankings', $errors);
assert_true(is_string($validOut) && str_contains($validOut, 'Recommendations'), 'CLI should render recommendations', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Influx query report CLI PASS\n";
