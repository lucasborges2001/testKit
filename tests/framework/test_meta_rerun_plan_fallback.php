<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Suites\MetaRunner;

$errors = [];
function assert_true(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

putenv('TESTKIT_WRAPPER_KIND=bash');

$reportWithActions = [
    'suite_id' => 'back_php',
    'recommended_actions' => [[
        'kind' => 'rerun_filtered',
        'command' => "./bin/testkit run --rm -e TEST_MATCH='test/back/auth/integration/auth_entry_states_integration.test.php' testkit php runTest.php back-php",
        'reason' => 'aislar el primer archivo fallido',
    ]],
];
$plan = MetaRunner::suiteRerunPlanFromReport($reportWithActions);
assert_true(str_contains((string)($plan[0]['command'] ?? ''), './bin/testkit run --rm -e TEST_MATCH='), 'derived rerun plan should preserve wrapper command', $errors);

$reportWithFirstFailure = [
    'suite_id' => 'front_php',
    'first_failure' => [
        'suite_id' => 'front_php',
        'file' => 'test/front/cliente/integration/cliente_shell_cross_page_contract.test.php',
    ],
];
$plan = MetaRunner::suiteRerunPlanFromReport($reportWithFirstFailure);
assert_true(str_contains((string)($plan[0]['command'] ?? ''), './bin/testkit run --rm -e TEST_MATCH='), 'first_failure fallback should build wrapper command', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Meta rerun plan fallback PASS\n";
