<?php
declare(strict_types=1);

$testkitRoot = realpath(__DIR__ . '/../..');
if (!is_string($testkitRoot)) {
    fwrite(STDERR, "No se pudo resolver TESTKIT_ROOT\n");
    exit(1);
}
putenv('TESTKIT_ROOT=' . $testkitRoot);
putenv('TK_REPO_ROOT=' . $testkitRoot);

require_once $testkitRoot . '/core/php/bootstrap.php';

use Testkit\Core\Execution\IsolatedRerun;

function tk_rerun_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$tests = [
    ['file' => __FILE__, 'rel' => 'test/example/pass.test.php', 'module' => 'example', 'tags' => []],
    ['file' => __FILE__, 'rel' => 'test/example/flaky.test.php', 'module' => 'example', 'tags' => ['fragile']],
    ['file' => __FILE__, 'rel' => 'test/example/hard_fail.test.php', 'module' => 'example', 'tags' => []],
];

$config = [
    'suite_id' => 'rerun_contract',
    'language' => 'php',
    'scope' => 'all',
    'category' => 'all',
    'jobs' => 1,
    'fail_fast' => false,
    'list_only' => false,
    'require_tests' => true,
    'repo_root' => $testkitRoot,
    'thresholds' => [],
    'rerun_failed_isolated' => true,
    'coverage' => false,
];

$noFailures = IsolatedRerun::run([
    'list_only' => false,
    'tests_total' => 1,
    'fail' => 0,
    'tests' => [
        ['rel' => 'test/example/pass.test.php', 'file' => 'test/example/pass.test.php', 'status' => 'pass'],
    ],
    'failed_tests' => [],
], $tests, $config, static fn(array $test, int $workerId): array => ['cmd' => [PHP_BINARY, '-r', 'exit(0);'], 'env' => []]);
tk_rerun_assert(($noFailures['attempted'] ?? true) === false, 'No debe intentar rerun si no hay fallos.');
tk_rerun_assert(($noFailures['affects_exit_code'] ?? true) === false, 'Rerun aislado no debe afectar exit code.');
tk_rerun_assert(($noFailures['coverage_policy'] ?? '') === 'disabled_for_isolated_rerun', 'Debe declarar política de coverage.');

$executed = [];
$batchFlaky = [
    'list_only' => false,
    'tests_total' => 2,
    'fail' => 1,
    'tests' => [
        ['rel' => 'test/example/pass.test.php', 'file' => 'test/example/pass.test.php', 'status' => 'pass'],
        ['rel' => 'test/example/flaky.test.php', 'file' => 'test/example/flaky.test.php', 'status' => 'fail'],
    ],
    'failed_tests' => [
        ['rel' => 'test/example/flaky.test.php', 'file' => 'test/example/flaky.test.php', 'status' => 'fail'],
    ],
];
$flaky = IsolatedRerun::run($batchFlaky, $tests, $config, static function (array $test, int $workerId) use (&$executed): array {
    $executed[] = (string)$test['rel'];
    return ['cmd' => [PHP_BINARY, '-r', 'exit(0);'], 'env' => []];
});
tk_rerun_assert($executed === ['test/example/flaky.test.php'], 'Debe reejecutar solo el archivo fallido.');
tk_rerun_assert(($flaky['results'][0]['diagnosis'] ?? '') === 'interference_suspected', 'fail batch + pass aislado debe ser interference_suspected.');
tk_rerun_assert(isset($flaky['results'][0]['duration_ms']), 'Cada resultado aislado debe exponer duration_ms.');

$hard = IsolatedRerun::run([
    'list_only' => false,
    'tests_total' => 1,
    'fail' => 1,
    'tests' => [
        ['rel' => 'test/example/hard_fail.test.php', 'file' => 'test/example/hard_fail.test.php', 'status' => 'fail'],
    ],
    'failed_tests' => [
        ['rel' => 'test/example/hard_fail.test.php', 'file' => 'test/example/hard_fail.test.php', 'status' => 'fail'],
    ],
], $tests, $config, static fn(array $test, int $workerId): array => ['cmd' => [PHP_BINARY, '-r', 'exit(1);'], 'env' => []]);
tk_rerun_assert(($hard['results'][0]['diagnosis'] ?? '') === 'confirmed_failure', 'fail batch + fail aislado debe ser confirmed_failure.');

$coverageConfig = $config;
$coverageConfig['coverage'] = true;
$coverage = IsolatedRerun::run($batchFlaky, $tests, $coverageConfig, static fn(array $test, int $workerId): array => [
    'cmd' => [PHP_BINARY, '-r', 'exit((getenv("TEST_COVERAGE") === "0" && getenv("TEST_ISOLATED_RERUN_ACTIVE") === "1") ? 0 : 1);'],
    'env' => ['TEST_COVERAGE' => '1', 'TEST_COVERAGE_FILE' => '/tmp/should_not_be_used.json'],
]);
tk_rerun_assert(($coverage['results'][0]['isolated_status'] ?? '') === 'pass', 'Rerun aislado debe forzar TEST_COVERAGE=0 y TEST_ISOLATED_RERUN_ACTIVE=1 en el child.');
tk_rerun_assert(($coverage['coverage_policy'] ?? '') === 'disabled_for_isolated_rerun', 'Coverage debe quedar desactivado en rerun aislado.');

putenv('TEST_ISOLATED_RERUN_ACTIVE=1');
$recursive = IsolatedRerun::run($batchFlaky, $tests, $config, static fn(array $test, int $workerId): array => ['cmd' => [PHP_BINARY, '-r', 'exit(1);'], 'env' => []]);
putenv('TEST_ISOLATED_RERUN_ACTIVE');
tk_rerun_assert(($recursive['attempted'] ?? true) === false, 'No debe ejecutar rerun si TEST_ISOLATED_RERUN_ACTIVE=1.');
tk_rerun_assert(($recursive['active_guard'] ?? false) === true, 'Debe reportar active_guard=true cuando evita recursión.');
tk_rerun_assert(($recursive['reason'] ?? '') === 'isolated_rerun_already_active', 'Debe reportar razón de guard activo.');

echo "[SUCCESS] rerun aislado validado\n";
exit(0);
