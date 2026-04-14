<?php
/**
 * Self-test: reporting contract remains stable for suite and meta reports.
 *
 * Verifies:
 *   - enrichReport() populates the agent-oriented fields consumed by ConsoleReporter.
 *   - failure clusters, phase timeline, rerun plan, recommended actions and delta exist.
 *   - meta-style reports preserve a rerun path even when failures come from child suites.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/reporting/ReportSummary.php';

use Testkit\Core\Reporting\ReportSummary;

$errors = [];

/**
 * @param mixed $value
 */
function assert_true(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

/**
 * @param array<string,mixed> $row
 */
function failure_row(
    string $file,
    string $message,
    string $causeCode = 'exit_code_1',
    string $kind = 'test_failure',
    string $phase = 'execution',
    string $domain = 'test'
): array {
    return [
        'test_id' => $file,
        'test_name' => basename($file),
        'case' => basename($file),
        'suite_id' => 'back_php',
        'suite' => 'back_php',
        'scope' => 'integration',
        'file' => $file,
        'line' => null,
        'category' => 'all',
        'status' => 'fail',
        'duration_ms' => 120,
        'error_type' => $causeCode,
        'exception_class' => null,
        'kind' => $kind,
        'phase' => $phase,
        'failure_domain' => $domain,
        'cause_code' => $causeCode,
        'message' => $message,
        'assertion' => null,
        'diff_excerpt' => null,
        'trace_excerpt' => null,
        'stdout_excerpt' => null,
        'stderr_excerpt' => $message,
        'artifact_path' => null,
    ];
}

$suiteReport = [
    'suite_id' => 'back_php',
    'tests_total' => 3,
    'pass' => 1,
    'fail' => 2,
    'skip' => 0,
    'duration_ms' => 912,
    'summary' => [
        'total' => 3,
        'passed' => 1,
        'failed' => 2,
        'skipped' => 0,
        'duration_ms' => 912,
    ],
    'module_summary' => [
        'back/auth' => ['total' => 2, 'pass' => 0, 'fail' => 2, 'skip' => 0, 'timeout' => 0],
        'back/log' => ['total' => 1, 'pass' => 1, 'fail' => 0, 'skip' => 0, 'timeout' => 0],
    ],
    'previous_run_id' => '20260414T101500Z_prev01',
    'new_failures_count' => 1,
    'resolved_failures_count' => 0,
    'failures' => [
        failure_row(
            'test/back/auth/integration/auth_entry_states_integration.test.php',
            "FAIL: SQL setup fallo: Duplicate entry '2' for key 'Organizacion.PRIMARY'",
            'exit_code_1'
        ),
        failure_row(
            'test/back/auth/integration/auth_password_recovery_integration.test.php',
            'FAIL: Too few arguments to function auth_password_reset_request()'
        ),
    ],
];

$enrichedSuite = ReportSummary::enrichReport($suiteReport);

assert_true(isset($enrichedSuite['failure_clusters']) && is_array($enrichedSuite['failure_clusters']), 'suite: missing failure_clusters', $errors);
assert_true(isset($enrichedSuite['phase_timeline']) && is_array($enrichedSuite['phase_timeline']), 'suite: missing phase_timeline', $errors);
assert_true(isset($enrichedSuite['rerun_plan']) && is_array($enrichedSuite['rerun_plan']), 'suite: missing rerun_plan', $errors);
assert_true(isset($enrichedSuite['recommended_actions']) && is_array($enrichedSuite['recommended_actions']), 'suite: missing recommended_actions', $errors);
assert_true(isset($enrichedSuite['run_delta']) && is_array($enrichedSuite['run_delta']), 'suite: missing run_delta', $errors);
assert_true(isset($enrichedSuite['agent_summary']) && is_array($enrichedSuite['agent_summary']), 'suite: missing agent_summary', $errors);
assert_true(($enrichedSuite['failure_clusters'][0]['family'] ?? null) === 'seed_drift', 'suite: first cluster should classify duplicate-entry seed drift', $errors);
assert_true(($enrichedSuite['phase_timeline'][3]['phase'] ?? null) === 'execution', 'suite: execution phase should exist in phase_timeline', $errors);
assert_true(($enrichedSuite['phase_timeline'][3]['primary'] ?? false) === true, 'suite: execution should be primary phase for runtime failures', $errors);
assert_true(str_contains((string)($enrichedSuite['rerun_plan'][0]['command'] ?? ''), "TEST_MATCH='test/back/auth/integration/auth_entry_states_integration.test.php'"), 'suite: rerun plan should isolate first failing file', $errors);
assert_true(($enrichedSuite['run_delta']['new_failures_count'] ?? null) === 1, 'suite: run_delta should preserve new_failures_count', $errors);
assert_true(($enrichedSuite['run_delta']['persistent_failures_count'] ?? null) === 1, 'suite: run_delta should compute persistent_failures_count', $errors);
assert_true(($enrichedSuite['agent_summary']['primary_problem'] ?? null) === 'seed_drift', 'suite: agent_summary should prioritize the dominant cluster family', $errors);
assert_true(((string)($enrichedSuite['recommended_actions'][0]['command'] ?? '')) !== '', 'suite: recommended_actions should expose a command', $errors);

echo "Suite report contract PASS\n";

$metaReport = [
    'target' => 'back',
    'category' => 'all',
    'duration_ms' => 1500,
    'summary' => [
        'total' => 12,
        'passed' => 9,
        'failed' => 3,
        'skipped' => 0,
        'duration_ms' => 1500,
    ],
    'suites' => [
        [
            'suite_id' => 'back_php',
            'exit_code' => 1,
            'new_failures_count' => 1,
            'resolved_failures_count' => 0,
            'previous_run_id' => '20260414T101500Z_prev01',
            'summary' => ['failed' => 2],
        ],
        [
            'suite_id' => 'back_python',
            'exit_code' => 0,
            'new_failures_count' => 0,
            'resolved_failures_count' => 1,
            'summary' => ['failed' => 0],
        ],
    ],
    'failures' => [],
];

$enrichedMeta = ReportSummary::enrichReport($metaReport);

assert_true(($enrichedMeta['outcome_status'] ?? null) === 'failed', 'meta: outcome_status should become failed when summary.failed > 0', $errors);
assert_true(isset($enrichedMeta['phase_timeline']) && count((array)$enrichedMeta['phase_timeline']) === 3, 'meta: phase_timeline should use admission/execution/reporting only', $errors);
assert_true(isset($enrichedMeta['rerun_plan']) && is_array($enrichedMeta['rerun_plan']), 'meta: missing rerun_plan', $errors);
assert_true(str_contains((string)($enrichedMeta['rerun_plan'][0]['command'] ?? ''), 'php runTest.php back-php'), 'meta: rerun_plan should collapse to first failed suite', $errors);
assert_true(($enrichedMeta['run_delta']['new_failures_count'] ?? null) === 1, 'meta: run_delta should aggregate suite deltas', $errors);
assert_true(($enrichedMeta['run_delta']['resolved_failures_count'] ?? null) === 1, 'meta: run_delta should aggregate resolved failures', $errors);
assert_true(((string)($enrichedMeta['agent_summary']['primary_problem'] ?? '')) !== '', 'meta: agent_summary should expose a primary_problem', $errors);

echo "Meta report contract PASS\n";

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

exit(0);
