<?php
/**
 * Self-test: Meta Action Required should expose rerun commands per failed suite.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Suites\MetaActionRequiredRenderer;

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

$meta = [
    'suites' => [
        [
            'suite_id' => 'back_php',
            'exit_code' => 1,
            'rerun_plan' => [
                [
                    'command' => "TEST_MATCH='test/back/auth/integration/auth_entry_states_integration.test.php' php runTest.php back-php",
                    'reason' => 'aislar el primer archivo fallido',
                ],
            ],
        ],
        [
            'suite_id' => 'back_python',
            'exit_code' => 1,
            'rerun_plan' => [
                [
                    'command' => "TEST_MATCH='test/back/ocpp_server/integration/ocpp_flow_handlers_unittest.py' php runTest.php back-python",
                    'reason' => 'aislar el primer archivo fallido',
                ],
            ],
        ],
        [
            'suite_id' => 'front_js',
            'exit_code' => 0,
            'rerun_plan' => [
                [
                    'command' => "TEST_MATCH='test/front/alerta/integration/alerta_contract.test.mjs' php runTest.php front-js",
                    'reason' => 'aislar el primer archivo fallido',
                ],
            ],
        ],
    ],
    'regression_delta' => [
        'new_failures' => [],
        'resolved_failures' => [],
        'status_transitions' => [],
    ],
    'recommended_actions' => [
        [
            'kind' => 'open_report_root',
            'command' => '.testkit/reports/runs/20260415T193201Z_70b0a1',
            'reason' => 'inspeccionar artefactos generados por la corrida',
        ],
    ],
];

ob_start();
MetaActionRequiredRenderer::render($meta);
$output = (string)ob_get_clean();

assert_true(str_contains($output, 'rerun by suite:'), 'meta action required: missing rerun by suite header', $errors);
assert_true(str_contains($output, "back_php: TEST_MATCH='test/back/auth/integration/auth_entry_states_integration.test.php' php runTest.php back-php"), 'meta action required: missing back_php rerun command', $errors);
assert_true(str_contains($output, "back_python: TEST_MATCH='test/back/ocpp_server/integration/ocpp_flow_handlers_unittest.py' php runTest.php back-python"), 'meta action required: missing back_python rerun command', $errors);
assert_true(!str_contains($output, 'front_js:'), 'meta action required: should not include passing suite rerun command', $errors);
assert_true(!str_contains($output, 'rerun filtered:'), 'meta action required: should not fallback to single rerun when suite reruns exist', $errors);
assert_true(str_contains($output, 'open report root: .testkit/reports/runs/20260415T193201Z_70b0a1'), 'meta action required: should keep non-rerun actions', $errors);

echo "Meta Action Required PASS\n";

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

exit(0);
