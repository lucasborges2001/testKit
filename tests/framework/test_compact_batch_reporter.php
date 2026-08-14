<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/php/common/Env.php';
require_once $root . '/core/php/common/AgentMode.php';
require_once $root . '/core/php/reporting/UI.php';
require_once $root . '/core/php/reporting/CompactBatchReporter.php';

use Testkit\Core\Reporting\CompactBatchReporter;

function fail_compact_batch(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}
function assert_compact_batch(bool $condition, string $message): void
{
    if (!$condition) {
        fail_compact_batch($message);
    }
}

$checks = [
    ['label' => 'PHP lint', 'total' => 556, 'passed' => 556, 'failed' => 0, 'skipped' => 0, 'duration_ms' => 2810],
    [
        'label' => 'Framework tests', 'total' => 45, 'passed' => 44, 'failed' => 1, 'skipped' => 0, 'duration_ms' => 3400,
        'failures' => [[
            'label' => 'ConsoleReporter compact pass', 'exit_code' => 1,
            'output' => 'expected compact output',
            'rerun' => 'php tests/framework/test_console_reporter_compact_pass.php',
        ]],
    ],
    ['label' => 'Smoke', 'total' => 35, 'passed' => 0, 'failed' => 0, 'skipped' => 35, 'duration_ms' => 0, 'skip_reason' => 'dependency failure'],
];

putenv('TESTKIT_MODE');
putenv('NO_COLOR=1');
ob_start();
foreach ($checks as $check) {
    CompactBatchReporter::printCheck($check);
}
CompactBatchReporter::printSummary($checks);
$plain = (string)ob_get_clean();
foreach ([
    'PASS PHP lint', '556/556', 'FAIL Framework tests', '44/45',
    'FAIL ConsoleReporter compact pass', 'exit_code=1',
    'rerun: php tests/framework/test_console_reporter_compact_pass.php',
    'SKIP Smoke', 'reason: dependency failure', 'Summary:', '600 PASS', '1 FAIL', '35 SKIP',
] as $needle) {
    assert_compact_batch(str_contains($plain, $needle), 'missing plain output fragment: ' . $needle);
}
assert_compact_batch(!str_contains($plain, "\033["), 'NO_COLOR must suppress ANSI');

putenv('NO_COLOR');
ob_start(); CompactBatchReporter::printCheck($checks[0]); $pass = (string)ob_get_clean();
assert_compact_batch(str_contains($pass, "\033[32mPASS\033[0m"), 'PASS must be green');
ob_start(); CompactBatchReporter::printCheck($checks[1]); $fail = (string)ob_get_clean();
assert_compact_batch(str_contains($fail, "\033[31mFAIL\033[0m"), 'FAIL must be red');
assert_compact_batch(str_contains($fail, "\033[36mphp tests/framework/test_console_reporter_compact_pass.php\033[0m"), 'rerun must be cyan');
ob_start(); CompactBatchReporter::printCheck($checks[2]); $skip = (string)ob_get_clean();
assert_compact_batch(str_contains($skip, "\033[33mSKIP\033[0m"), 'SKIP must be yellow');

echo "Compact batch reporter PASS\n";
