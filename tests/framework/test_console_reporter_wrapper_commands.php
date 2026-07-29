<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Reporting\ConsoleReporter;
use Testkit\Core\Reporting\ReportSummary;

$errors = [];
function assert_true(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

putenv('TESTKIT_WRAPPER_KIND=bash');

$result = [
    'suite_id' => 'back_php',
    'pass' => 0,
    'fail' => 1,
    'skip' => 0,
    'duration_ms' => 12,
    'summary' => ['total' => 1, 'passed' => 0, 'failed' => 1, 'skipped' => 0, 'duration_ms' => 12],
    'failures' => [[
        'file' => 'test/back/auth/integration/03_auth_logout_and_session_touch.test.php',
        'error_type' => 'exit_code_255',
        'phase' => 'execution',
        'cause_code' => 'exit_code_255',
        'message' => 'boom',
    ]],
];
$result = ReportSummary::enrichReport($result);
ob_start();
ConsoleReporter::printSuiteResult($result);
$output = (string)ob_get_clean();
assert_true(
    str_contains($output, './bin/testkit run --rm testkit php runTest.php --suite back-php --test '),
    'Next Step should use typed wrapper rerun command',
    $errors
);
assert_true(str_contains($output, './bin/testkit run --rm testkit php scripts/report.php'), 'Next Step should use wrapper report command', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Console reporter wrapper commands PASS\n";
