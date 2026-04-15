<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Suites\MetaRunner;

$errors = [];

/** @param mixed $value */
function assert_true(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

$reportWithActions = [
    'suite_id' => 'back_php',
    'recommended_actions' => [
        [
            'kind' => 'rerun_filtered',
            'command' => "TEST_MATCH='test/back/auth/integration/auth_entry_states_integration.test.php' php runTest.php back-php",
            'reason' => 'aislar el primer archivo fallido',
        ],
        [
            'kind' => 'aggregate_report',
            'command' => 'php scripts/report.php',
            'reason' => 'ver resumen consolidado',
        ],
    ],
];

$plan = MetaRunner::suiteRerunPlanFromReport($reportWithActions);
assert_true(is_array($plan) && count($plan) === 1, 'should derive rerun plan from recommended_actions', $errors);
assert_true(
    str_contains((string)($plan[0]['command'] ?? ''), "TEST_MATCH='test/back/auth/integration/auth_entry_states_integration.test.php' php runTest.php back-php"),
    'derived rerun plan should preserve filtered command',
    $errors
);

$reportWithFirstFailure = [
    'suite_id' => 'front_php',
    'first_failure' => [
        'suite_id' => 'front_php',
        'file' => 'test/front/cliente/integration/cliente_shell_cross_page_contract.test.php',
    ],
];

$plan = MetaRunner::suiteRerunPlanFromReport($reportWithFirstFailure);
assert_true(is_array($plan) && count($plan) === 1, 'should derive rerun plan from first_failure fallback', $errors);
assert_true(
    str_contains((string)($plan[0]['command'] ?? ''), "TEST_MATCH='test/front/cliente/integration/cliente_shell_cross_page_contract.test.php' php runTest.php front-php"),
    'first_failure fallback should build filtered command',
    $errors
);

$reportWithExplicitPlan = [
    'suite_id' => 'back_python',
    'rerun_plan' => [[
        'command' => "TEST_MATCH='test/back/ocpp_server/integration/ocpp_flow_handlers_unittest.py' php runTest.php back-python",
        'reason' => 'aislar el primer archivo fallido',
    ]],
];

$plan = MetaRunner::suiteRerunPlanFromReport($reportWithExplicitPlan);
assert_true(
    str_contains((string)($plan[0]['command'] ?? ''), "TEST_MATCH='test/back/ocpp_server/integration/ocpp_flow_handlers_unittest.py' php runTest.php back-python"),
    'explicit rerun_plan should win over fallbacks',
    $errors
);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Meta rerun plan fallback PASS\n";
