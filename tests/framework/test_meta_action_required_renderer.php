<?php
/**
 * Self-test: Meta Action Required should expose rerun commands per failed suite.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Reporting\SuggestedCommandBuilder;
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

$backPhpCommand = SuggestedCommandBuilder::rerunFiltered(
    'back_php',
    'test/back/auth/integration/auth_entry_states_integration.test.php'
);
$backPythonCommand = SuggestedCommandBuilder::rerunFiltered(
    'back_python',
    'test/back/ocpp_server/integration/ocpp_flow_handlers_unittest.py'
);
$frontJsCommand = SuggestedCommandBuilder::rerunFiltered(
    'front_js',
    'test/front/alerta/integration/alerta_contract.test.mjs'
);
$reportCommand = SuggestedCommandBuilder::aggregateReport();

$meta = [
    'suites' => [
        [
            'suite_id' => 'back_php',
            'exit_code' => 1,
            'rerun_plan' => [
                [
                    'command' => $backPhpCommand,
                    'reason' => 'aislar el primer archivo fallido',
                ],
            ],
        ],
        [
            'suite_id' => 'back_python',
            'exit_code' => 1,
            'rerun_plan' => [
                [
                    'command' => $backPythonCommand,
                    'reason' => 'aislar el primer archivo fallido',
                ],
            ],
        ],
        [
            'suite_id' => 'front_js',
            'exit_code' => 0,
            'rerun_plan' => [
                [
                    'command' => $frontJsCommand,
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
assert_true(str_contains($output, 'back_php: ' . $backPhpCommand), 'meta action required: missing back_php rerun command', $errors);
assert_true(str_contains($output, 'back_python: ' . $backPythonCommand), 'meta action required: missing back_python rerun command', $errors);
assert_true(!str_contains($output, 'front_js:'), 'meta action required: should not include passing suite rerun command', $errors);
assert_true(!str_contains($output, 'rerun filtered:'), 'meta action required: should not fallback to single rerun when suite reruns exist', $errors);
assert_true(str_contains($output, 'Reporte detallado: ' . $reportCommand), 'meta action required: should render exact aggregate report command', $errors);
assert_true(str_contains($output, 'open report root: .testkit/reports/runs/20260415T193201Z_70b0a1'), 'meta action required: should keep non-rerun actions', $errors);

echo "Meta Action Required PASS\n";

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

exit(0);
