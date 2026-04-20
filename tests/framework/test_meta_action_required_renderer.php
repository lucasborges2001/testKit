<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Suites\MetaActionRequiredRenderer;

$errors = [];
function assert_true(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

putenv('TESTKIT_WRAPPER_KIND=bash');

$meta = [
    'suites' => [[
        'suite_id' => 'back_php',
        'exit_code' => 1,
        'rerun_plan' => [[
            'command' => "./bin/testkit run --rm -e TEST_MATCH='test/back/auth/integration/auth_entry_states_integration.test.php' testkit php runTest.php back-php",
            'reason' => 'aislar el primer archivo fallido',
        ]],
    ]],
    'regression_delta' => [
        'new_failures' => [],
        'resolved_failures' => [],
        'status_transitions' => [],
    ],
    'recommended_actions' => [],
];

ob_start();
MetaActionRequiredRenderer::render($meta);
$output = (string)ob_get_clean();
assert_true(str_contains($output, './bin/testkit run --rm -e TEST_MATCH='), 'meta action required should show wrapper rerun command', $errors);
assert_true(str_contains($output, './bin/testkit run --rm testkit php scripts/report.php'), 'meta action required should show wrapper report command', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Meta Action Required PASS\n";
