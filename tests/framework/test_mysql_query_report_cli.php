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
$tmp = sys_get_temp_dir() . '/testkit_query_report_cli_' . getmypid();
@mkdir($tmp, 0777, true);
$missing = $tmp . '/missing.json';
$valid = $tmp . '/mysql_profile_latest.json';

$report = [
    'report_version' => 1,
    'engine' => 'mysql',
    'profile_enabled' => true,
    'run_id' => 'cli_fixture',
    'started_at' => '2026-04-25T00:00:00Z',
    'finished_at' => '2026-04-25T00:00:01Z',
    'duration_ms' => 1000,
    'summary' => [
        'total_queries' => 1,
        'unique_fingerprints' => 1,
        'total_db_time_ms' => 12.3,
        'slow_count' => 0,
        'hotspot_count' => 1,
        'n_plus_one_candidates' => 0,
    ],
    'rankings' => [
        'by_max_ms' => [],
        'by_total_ms' => [],
        'by_calls' => [],
        'by_avg_ms' => [],
    ],
    'queries' => [],
    'recommendations' => [],
    'explain' => [
        'enabled' => true,
        'attempted' => 1,
        'analyzed' => 1,
        'skipped' => 0,
        'failed' => 0,
        'findings' => [[
            'query_id' => 'user.scan',
            'fingerprint' => 'select * from users where status = ?',
            'sample_sql' => 'select * from users where status = ?',
            'explain_status' => 'analyzed',
            'skip_reason' => '',
            'error' => '',
            'plan_summary' => ['tables' => [], 'access_types' => ['ALL'], 'keys_used' => [], 'possible_keys' => [], 'estimated_rows' => 20000],
            'flags' => ['full_table_scan', 'no_key_used'],
            'severity' => 'warn',
            'recommendation' => 'Revisar filtros y joins.',
        ]],
    ],
    'limitations' => [],
];
file_put_contents($valid, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$cmdMissing = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/query_report.php') . ' --path ' . escapeshellarg($missing);
$missingOutput = shell_exec($cmdMissing) ?: '';
assert_true(str_contains($missingOutput, 'No profile report found'), 'query_report should handle missing report', $errors);

$cmdValid = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/query_report.php') . ' --path ' . escapeshellarg($valid);
$validOutput = shell_exec($cmdValid) ?: '';
assert_true(str_contains($validOutput, 'Explain analysis'), 'query_report should render explain section', $errors);
assert_true(str_contains($validOutput, 'full_table_scan'), 'query_report should render explain flags', $errors);

$invalid = $tmp . '/invalid.json';
file_put_contents($invalid, '{not json');
$cmdInvalid = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/query_report.php') . ' --path ' . escapeshellarg($invalid) . ' 2>&1';
$invalidOutput = shell_exec($cmdInvalid) ?: '';
assert_true(str_contains($invalidOutput, 'Invalid MySQL profile report'), 'query_report should reject invalid JSON', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "MySQL query report CLI PASS\n";
