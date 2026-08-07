<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/common/Env.php';
require_once __DIR__ . '/../../core/php/common/Paths.php';
require_once __DIR__ . '/../../core/php/common/ProjectEnv.php';
require_once __DIR__ . '/../../core/php/reporting/StructuredWarnings.php';
require_once __DIR__ . '/../../core/php/reporting/FailureExcerpt.php';
require_once __DIR__ . '/../../core/php/reporting/FailureNormalizer.php';
require_once __DIR__ . '/../../core/php/reporting/OutcomeDiagnostics.php';
require_once __DIR__ . '/../../core/php/execution/ProcessRunner.php';
require_once __DIR__ . '/../../core/php/execution/SuiteExecutor.php';
require_once __DIR__ . '/../../core/php/store/StoreRegistry.php';
require_once __DIR__ . '/../../core/php/execution/ParallelGuard.php';

use Testkit\Core\Execution\ParallelGuard;
use Testkit\Core\Execution\SuiteExecutor;
use Testkit\Core\Reporting\FailureNormalizer;
use Testkit\Core\Reporting\OutcomeDiagnostics;
use Testkit\Core\Reporting\StructuredWarnings;

$errors = [];
$tmpRoots = [];

function assert_true_failure_contract(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

function assert_same_failure_contract(mixed $expected, mixed $actual, string $message, array &$errors): void
{
    if ($expected !== $actual) {
        $errors[] = $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
}

function assert_contains_failure_contract(string $needle, string $haystack, string $message, array &$errors): void
{
    if (!str_contains($haystack, $needle)) {
        $errors[] = $message . ' missing=' . var_export($needle, true) . ' in=' . var_export($haystack, true);
    }
}

function make_tmp_root_failure_contract(array &$tmpRoots): string
{
    $root = sys_get_temp_dir() . '/tk_failure_contract_' . bin2hex(random_bytes(4));
    if (!mkdir($root, 0777, true) && !is_dir($root)) {
        throw new RuntimeException('No se pudo crear directorio temporal: ' . $root);
    }
    $tmpRoots[] = $root;
    return $root;
}

function rm_rf_failure_contract(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $items = scandir($path);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        rm_rf_failure_contract($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

function with_env_failure_contract(array $vars, callable $fn): mixed
{
    $previous = [];
    foreach ($vars as $key => $_) {
        $previous[$key] = getenv((string)$key);
    }

    try {
        foreach ($vars as $key => $value) {
            putenv((string)$key . '=' . (string)$value);
            $_ENV[(string)$key] = (string)$value;
            $_SERVER[(string)$key] = (string)$value;
        }
        return $fn();
    } finally {
        foreach ($previous as $key => $value) {
            if ($value === false) {
                putenv((string)$key);
                unset($_ENV[(string)$key], $_SERVER[(string)$key]);
            } else {
                putenv((string)$key . '=' . (string)$value);
                $_ENV[(string)$key] = (string)$value;
                $_SERVER[(string)$key] = (string)$value;
            }
        }
    }
}

function base_config_failure_contract(string $repoRoot, array $overrides = []): array
{
    return array_replace_recursive([
        'suite_id' => 'framework_failure_fixture',
        'language' => 'php',
        'scope' => 'all',
        'category' => 'all',
        'list_only' => false,
        'require_tests' => false,
        'jobs' => 1,
        'fail_fast' => true,
        'repo_root' => $repoRoot,
        'test_timeout_sec' => 5,
        'thresholds' => [
            'slow_ms' => 999999,
            'slow_top' => 10,
            'perf_max_ms' => 0,
            'perf_warn_ms' => 0,
        ],
    ], $overrides);
}

function test_row_failure_contract(string $file, string $rel, array $tags = []): array
{
    return [
        'file' => $file,
        'rel' => $rel,
        'module' => 'framework/failure_fixture',
        'tags' => $tags,
    ];
}

function run_php_fixture_failure_contract(string $source, array $configOverrides, array &$tmpRoots): array
{
    $root = make_tmp_root_failure_contract($tmpRoots);
    $testDir = $root . '/test/back/framework/unit';
    mkdir($testDir, 0777, true);
    $file = $testDir . '/controlled.test.php';
    file_put_contents($file, $source);

    $config = base_config_failure_contract($root, $configOverrides);
    $tests = [test_row_failure_contract($file, 'test/back/framework/unit/controlled.test.php', ['unit'])];

    return with_env_failure_contract([
        'TESTKIT_PROGRESS_MODE' => 'quiet',
        'TEST_PROCESS_TIMEOUT_SEC' => '5',
        'TK_REPO_ROOT' => $root,
    ], static function () use ($tests, $config): array {
        return SuiteExecutor::execute(
            $tests,
            $config,
            static fn(array $test, int $workerId): array => [
                'cmd' => [PHP_BINARY, (string)$test['file']],
                'env' => [],
            ]
        );
    });
}

try {
    $exitResult = run_php_fixture_failure_contract(
        "<?php\nfwrite(STDERR, 'FAIL: controlled exit failure' . PHP_EOL);\nexit(7);\n",
        [],
        $tmpRoots
    );
    $exitDiagnostics = OutcomeDiagnostics::diagnostics($exitResult);
    $exitFirstFailure = FailureNormalizer::firstFailure($exitResult);

    assert_same_failure_contract(1, (int)$exitResult['fail'], 'exit-code fixture should increment fail count', $errors);
    assert_same_failure_contract(SuiteExecutor::EXIT_FAIL, (int)$exitResult['exit_code'], 'exit-code fixture should fail the suite', $errors);
    assert_same_failure_contract('failed', (string)$exitDiagnostics['outcome_status'], 'exit-code fixture should classify outcome as failed', $errors);
    assert_true_failure_contract(is_array($exitFirstFailure), 'exit-code fixture should expose first_failure', $errors);
    if (is_array($exitFirstFailure)) {
        assert_same_failure_contract('test/back/framework/unit/controlled.test.php', (string)$exitFirstFailure['file'], 'first_failure should point to failing test file', $errors);
        assert_same_failure_contract('exit_code_7', (string)$exitFirstFailure['cause_code'], 'first_failure should preserve useful exit-code cause', $errors);
        assert_contains_failure_contract('controlled exit failure', (string)$exitFirstFailure['message'], 'first_failure should preserve useful failure message', $errors);
    }

    $timeoutResult = run_php_fixture_failure_contract(
        "<?php\nusleep(2000000);\nexit(0);\n",
        ['test_timeout_sec' => 1],
        $tmpRoots
    );
    $timeoutDiagnostics = OutcomeDiagnostics::diagnostics($timeoutResult);
    $timeoutFirstFailure = FailureNormalizer::firstFailure($timeoutResult);

    assert_same_failure_contract(1, (int)$timeoutResult['timeout'], 'timeout fixture should increment timeout count', $errors);
    assert_same_failure_contract('timeout', (string)$timeoutDiagnostics['outcome_status'], 'timeout fixture should classify outcome as timeout', $errors);
    assert_true_failure_contract(is_array($timeoutFirstFailure), 'timeout fixture should expose first_failure', $errors);
    if (is_array($timeoutFirstFailure)) {
        assert_same_failure_contract('timeout', (string)$timeoutFirstFailure['kind'], 'timeout first_failure should have timeout kind', $errors);
        assert_same_failure_contract('process_timeout', (string)$timeoutFirstFailure['cause_code'], 'timeout first_failure should preserve process_timeout cause', $errors);
    }

    $emptyRoot = make_tmp_root_failure_contract($tmpRoots);
    $emptyResult = with_env_failure_contract([
        'TESTKIT_PROGRESS_MODE' => 'quiet',
        'TK_REPO_ROOT' => $emptyRoot,
    ], static fn(): array => SuiteExecutor::execute([], base_config_failure_contract($emptyRoot), static fn(): array => ['cmd' => [PHP_BINARY, '-r', 'exit(0);'], 'env' => []]));
    assert_same_failure_contract(SuiteExecutor::EXIT_SKIP, (int)$emptyResult['exit_code'], 'empty optional suite should exit as skip', $errors);
    assert_same_failure_contract('no_tests', (string)$emptyResult['suite_status'], 'empty optional suite should expose suite_status=no_tests', $errors);
    assert_same_failure_contract('discovery_empty', (string)$emptyResult['no_tests_reason'], 'empty optional suite should explain discovery_empty', $errors);

    $requiredEmptyRoot = make_tmp_root_failure_contract($tmpRoots);
    $requiredEmptyResult = with_env_failure_contract([
        'TESTKIT_PROGRESS_MODE' => 'quiet',
        'TK_REPO_ROOT' => $requiredEmptyRoot,
    ], static fn(): array => SuiteExecutor::execute([], base_config_failure_contract($requiredEmptyRoot, ['require_tests' => true]), static fn(): array => ['cmd' => [PHP_BINARY, '-r', 'exit(0);'], 'env' => []]));
    assert_same_failure_contract(SuiteExecutor::EXIT_FAIL, (int)$requiredEmptyResult['exit_code'], 'TEST_REQUIRE_TESTS=1 should convert empty selection to a failing exit code', $errors);
    assert_same_failure_contract('require_tests_enabled', (string)$requiredEmptyResult['no_tests_reason'], 'TEST_REQUIRE_TESTS=1 should preserve no_tests_reason=require_tests_enabled', $errors);

    $bootstrapFailure = FailureNormalizer::buildThrowableFailure(
        new RuntimeException('controlled bootstrap broke'),
        [
            'suite_id' => 'front_js',
            'suite' => 'front_js',
            'test_name' => 'front_js.bootstrap',
            'case' => 'front_js.bootstrap',
            'kind' => 'bootstrap_failure',
            'phase' => 'bootstrap',
            'failure_domain' => 'bootstrap',
            'cause_code' => 'bootstrap_failed',
        ]
    );
    $bootstrapDiagnostics = OutcomeDiagnostics::diagnostics([
        'suite_id' => 'front_js',
        'tests_total' => 1,
        'pass' => 0,
        'fail' => 1,
        'skip' => 0,
        'failures' => [$bootstrapFailure],
        'concurrency_admission' => ['run_admitted' => true, 'reason' => null],
    ]);
    assert_same_failure_contract('bootstrap_error', (string)$bootstrapDiagnostics['outcome_status'], 'bootstrap failure should classify as bootstrap_error', $errors);

    $contentionFailure = FailureNormalizer::buildThrowableFailure(
        new RuntimeException('controlled shared store contention'),
        [
            'suite_id' => 'front_js',
            'suite' => 'front_js',
            'test_name' => 'front_js.bootstrap',
            'case' => 'front_js.bootstrap',
            'kind' => 'environment_conflict',
            'phase' => 'store_setup',
            'failure_domain' => 'store',
            'cause_code' => 'shared_store_locked',
        ]
    );
    $contentionDiagnostics = OutcomeDiagnostics::diagnostics([
        'suite_id' => 'front_js',
        'tests_total' => 1,
        'pass' => 0,
        'fail' => 1,
        'skip' => 0,
        'failures' => [$contentionFailure],
        'concurrency_admission' => ['run_admitted' => false, 'reason' => 'shared_store_locked', 'lock_key' => 'suite_store.mysql.tk_contract'],
    ]);
    assert_same_failure_contract('contention', (string)$contentionDiagnostics['outcome_status'], 'lock contention should classify as contention', $errors);
    assert_true_failure_contract((bool)$contentionDiagnostics['has_contention'], 'lock contention diagnostics should expose has_contention=true', $errors);

    $dbSensitiveTests = [[
        'file' => '/tmp/db_sensitive.test.php',
        'rel' => 'test/back/orders/integration/db_sensitive.test.php',
        'module' => 'back/orders',
        'tags' => ['integration'],
    ]];
    $guardConfig = [
        'suite_id' => 'back_php',
        'jobs' => 2,
        'runner_hazards' => [
            'db_sensitivity' => 'discovered',
            'top_level_parallel_policy' => 'exclusive_when_db_sensitive',
            'intra_suite_parallel_policy' => 'per_worker_when_db_sensitive',
        ],
    ];

    $unsafeSharedPolicy = with_env_failure_contract([
        'TEST_DB_STRATEGY' => 'shared',
        'TEST_STORE_DRIVER' => 'mysql',
        'DB_NAME' => 'tk_contract',
        'DB_ENV_PATH' => '',
    ], static fn(): array => ParallelGuard::evaluate($dbSensitiveTests, $guardConfig, sys_get_temp_dir()));
    $unsafeSharedErrors = StructuredWarnings::canonicalize($unsafeSharedPolicy['errors'] ?? []);
    assert_true_failure_contract($unsafeSharedErrors !== [], 'TEST_JOBS>1 + TEST_DB_STRATEGY=shared + DB-sensitive tests should be rejected', $errors);
    assert_same_failure_contract('UNSAFE_PARALLEL_DB_CONFIGURATION', (string)($unsafeSharedErrors[0]['code'] ?? ''), 'unsafe shared DB parallelism should use stable warning/error code', $errors);
    $unsafeSharedAdmission = ParallelGuard::rejectedByPolicyState($unsafeSharedPolicy);
    assert_same_failure_contract(false, (bool)$unsafeSharedAdmission['run_admitted'], 'unsafe shared DB parallelism should set run_admitted=false', $errors);

    $unsafeCleanPolicy = with_env_failure_contract([
        'TEST_DB_STRATEGY' => 'clean',
        'TEST_STORE_DRIVER' => 'mysql',
        'DB_NAME' => 'tk_contract',
        'DB_ENV_PATH' => '',
    ], static fn(): array => ParallelGuard::evaluate($dbSensitiveTests, $guardConfig, sys_get_temp_dir()));
    $unsafeCleanErrors = StructuredWarnings::canonicalize($unsafeCleanPolicy['errors'] ?? []);
    assert_true_failure_contract($unsafeCleanErrors !== [], 'TEST_DB_STRATEGY=clean + DB-sensitive parallel tests should be rejected explicitly', $errors);
    assert_contains_failure_contract('TEST_DB_STRATEGY=clean', StructuredWarnings::joinSummaries($unsafeCleanErrors), 'clean strategy rejection should mention TEST_DB_STRATEGY=clean explicitly', $errors);

    $warnings = StructuredWarnings::canonicalize([
        [
            'code' => 'controlled warning',
            'severity' => 'warning',
            'classification' => 'concurrency',
            'blocking' => true,
            'summary' => 'controlled structured warning',
        ],
    ]);
    $warning = $warnings[0] ?? [];
    assert_same_failure_contract('CONTROLLED_WARNING', (string)($warning['code'] ?? ''), 'structured warnings should preserve normalized code', $errors);
    assert_same_failure_contract('warn', (string)($warning['severity'] ?? ''), 'structured warnings should preserve normalized severity', $errors);
    assert_same_failure_contract('concurrency', (string)($warning['classification'] ?? ''), 'structured warnings should preserve classification', $errors);
    assert_same_failure_contract(true, (bool)($warning['blocking'] ?? false), 'structured warnings should preserve blocking flag', $errors);
    assert_same_failure_contract('controlled structured warning', (string)($warning['summary'] ?? ''), 'structured warnings should preserve summary', $errors);
} finally {
    foreach (array_reverse($tmpRoots) as $tmpRoot) {
        rm_rf_failure_contract($tmpRoot);
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Failure classification contracts PASS\n";
