#!/usr/bin/env php
<?php
/**
 * Testkit framework self-test runner.
 *
 * Usage:  php tests/framework/run.php
 * Exit:   0 if all pass, 1 if any fail.
 *
 * Each test file is a standalone PHP script that exits 0 on success, non-zero on failure.
 * Output from failing tests is printed to help diagnose the problem.
 */
declare(strict_types=1);

$tests = [
    'ProcessRunner timeout'                 => __DIR__ . '/test_process_timeout.php',
    'ProcessRunner polling no deadlock'     => __DIR__ . '/test_polling_deadlock.php',
    'ProcessRunner finish no deadlock'      => __DIR__ . '/test_sequential_deadlock.php',
    'ProcessRunner interleaved output'      => __DIR__ . '/test_interleaved_output.php',
    'SuiteExecutor concurrent jobs'         => __DIR__ . '/test_concurrent_jobs.php',
    'Lock stale detection'                  => __DIR__ . '/test_lock_stale.php',
    'Lock valid not broken'                 => __DIR__ . '/test_lock_valid.php',
    'Store resource lock'                   => __DIR__ . '/test_store_resource_lock.php',
    'FrontJsSuite admission/lock order'     => __DIR__ . '/test_front_js_admission_lock_order.php',
    'Failure classification contracts'      => __DIR__ . '/test_failure_classification_contracts.php',
    'Manifest atomic write'                 => __DIR__ . '/test_manifest_write.php',
    'Reporting contract stable'             => __DIR__ . '/test_reporting_contract.php',
    'ConsoleReporter compact pass'          => __DIR__ . '/test_console_reporter_compact_pass.php',
    'Observability progress policy'         => __DIR__ . '/test_progress_policy.php',
    'Observability console contract'        => __DIR__ . '/test_console_observability_contract.php',
    'Observability execution contract'      => __DIR__ . '/test_execution_observability_contract.php',
    'Bootstrap visual dedupe'               => __DIR__ . '/test_bootstrap_visual_dedupe.php',
    'Meta action required renderer'         => __DIR__ . '/test_meta_action_required_renderer.php',
    'Meta rerun plan fallback'              => __DIR__ . '/test_meta_rerun_plan_fallback.php',
    'Agent mode runtime contract'           => __DIR__ . '/test_agent_mode_contract.php',
    'Agent run continuation contract'       => __DIR__ . '/test_agent_run_contract.php',
];

$pass   = 0;
$fail   = 0;
$width  = max(array_map('strlen', array_keys($tests)));

echo str_repeat('-', $width + 12) . "\n";
echo " Testkit self-tests\n";
echo str_repeat('-', $width + 12) . "\n";

foreach ($tests as $name => $file) {
    if (!is_file($file)) {
        printf("  [SKIP] %-{$width}s  (file not found: %s)\n", $name, $file);
        continue;
    }

    $output   = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);

    if ($exitCode === 0) {
        printf("  [PASS] %s\n", $name);
        $pass++;
    } else {
        printf("  [FAIL] %s\n", $name);
        foreach ($output as $line) {
            printf("         %s\n", $line);
        }
        $fail++;
    }
}

echo str_repeat('-', $width + 12) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
echo str_repeat('-', $width + 12) . "\n";

exit($fail > 0 ? 1 : 0);
