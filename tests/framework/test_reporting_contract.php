<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Reporting\ReportSummary;

$errors = [];
function assert_true(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}
function failure_row(string $file, string $message): array {
    return [
        'test_id' => $file,
        'test_name' => basename($file),
        'case' => basename($file),
        'suite_id' => 'back_php',
        'suite' => 'back_php',
        'scope' => 'integration',
        'file' => $file,
        'status' => 'fail',
        'duration_ms' => 120,
        'error_type' => 'exit_code_1',
        'kind' => 'test_failure',
        'phase' => 'execution',
        'failure_domain' => 'test',
        'cause_code' => 'exit_code_1',
        'message' => $message,
    ];
}

putenv('TESTKIT_WRAPPER_KIND=bash');

$suiteReport = [
    'suite_id' => 'back_php',
    'tests_total' => 2,
    'pass' => 0,
    'fail' => 2,
    'summary' => ['total' => 2, 'passed' => 0, 'failed' => 2, 'skipped' => 0, 'duration_ms' => 100],
    'failures' => [failure_row('test/back/auth/integration/a.test.php', 'boom')],
];
$enrichedSuite = ReportSummary::enrichReport($suiteReport);
assert_true(
    str_contains(
        (string)($enrichedSuite['recommended_actions'][0]['command'] ?? ''),
        './bin/testkit run --rm testkit php runTest.php --suite back-php --test '
    ),
    'recommended_actions should use typed wrapper rerun command',
    $errors
);
assert_true(str_contains((string)($enrichedSuite['recommended_actions'][1]['command'] ?? ''), './bin/testkit run --rm testkit php scripts/report.php') || str_contains((string)($enrichedSuite['recommended_actions'][2]['command'] ?? ''), './bin/testkit run --rm testkit php scripts/report.php'), 'recommended_actions should expose wrapper report command', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Reporting contract PASS\n";
