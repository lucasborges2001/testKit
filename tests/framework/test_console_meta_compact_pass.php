<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/php/bootstrap.php';

use Testkit\Core\Reporting\ConsoleReporter;

$meta = [
    'target' => 'all',
    'selected_test_count' => 3,
    'duration_ms' => 1250,
    'suites' => [
        ['suite_id' => 'back_php', 'exit_code' => 0, 'selected_test_count' => 2, 'selected_module_scope' => ''],
        ['suite_id' => 'front_js', 'exit_code' => 0, 'selected_test_count' => 1, 'selected_module_scope' => ''],
    ],
    'summary' => ['total' => 3, 'passed' => 3, 'failed' => 0, 'skipped' => 0, 'duration_ms' => 1250],
    'failed_files' => [],
    'diagnostics' => ['outcome_status' => 'passed', 'primary_phase' => 'none', 'cause_code' => 'none'],
];

putenv('NO_COLOR=1');
putenv('TESTKIT_CONSOLE_MODE=compact');
ob_start();
ConsoleReporter::printMeta($meta);
$compact = (string)ob_get_clean();
if (!str_contains($compact, 'PASS meta all') || !str_contains($compact, '3/3') || str_contains($compact, '[Suites]')) {
    fwrite(STDERR, "FAIL: compact meta pass contract\n{$compact}\n");
    exit(1);
}

putenv('TESTKIT_CONSOLE_MODE=live');
ob_start();
ConsoleReporter::printMeta($meta);
$live = (string)ob_get_clean();
if (!str_contains($live, 'META SUMMARY') || !str_contains($live, '[Suites]')) {
    fwrite(STDERR, "FAIL: live meta compatibility\n{$live}\n");
    exit(1);
}

putenv('TESTKIT_CONSOLE_MODE');
putenv('NO_COLOR');
echo "Console meta compact pass PASS\n";
