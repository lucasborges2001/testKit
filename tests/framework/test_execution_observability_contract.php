<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Execution\SuiteExecutor;
use Testkit\Core\Reporting\HistoryRepository;
use Testkit\Core\Suites\SuiteOperationalFailure;
use Testkit\Core\Suites\SuiteOrchestrator;

$errors = [];

function assert_true(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

function assert_contains_exec(string $haystack, string $needle, string $label, array &$errors): void
{
    if (!str_contains($haystack, $needle)) {
        $errors[] = $label . ': missing "' . $needle . '"';
    }
}

function strip_ansi_exec(string $value): string
{
    return (string)preg_replace('/\e\[[0-9;]*m/', '', $value);
}

function set_env_exec(string $key, ?string $value): void
{
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
        return;
    }

    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

function get_env_exec(string $key): ?string
{
    $value = getenv($key);
    return $value === false ? null : (string)$value;
}

function build_config(string $repoRoot, int $jobs = 1, bool $listOnly = false, bool $requireTests = false): array
{
    return [
        'suite_id' => 'framework_observability',
        'language' => 'php',
        'scope' => 'all',
        'category' => 'all',
        'list_only' => $listOnly,
        'require_tests' => $requireTests,
        'jobs' => $jobs,
        'fail_fast' => false,
        'repo_root' => $repoRoot,
        'test_timeout_sec' => 0,
        'thresholds' => [
            'perf_max_ms' => 0,
            'perf_warn_ms' => 0,
            'slow_top' => 5,
            'slow_ms' => 1,
        ],
    ];
}

function make_test(array $script, string $rel, string $module = 'back/framework'): array
{
    return [
        'file' => $script['path'],
        'rel' => $rel,
        'module' => $module,
        'tags' => [],
        'script_type' => $script['type'],
    ];
}

$keys = [
    'TESTKIT_PROGRESS_MODE',
    'TESTKIT_PROGRESS_INTERVAL_SEC',
    'TESTKIT_LONG_TEST_WARN_SEC',
    'TESTKIT_ARTIFACTS_ROOT',
    'TESTKIT_PROJECT_ROOT',
    'TK_REPO_ROOT',
];
$previousEnv = [];
foreach ($keys as $key) {
    $previousEnv[$key] = get_env_exec($key);
}

$root = sys_get_temp_dir() . '/testkit_obs_' . uniqid();
$repoRoot = $root . '/repo';
$artifactRoot = $repoRoot . '/.testkit';
$scriptDir = $root . '/scripts';
@mkdir($repoRoot, 0777, true);
@mkdir($artifactRoot, 0777, true);
@mkdir($scriptDir, 0777, true);

$scriptSlow = [
    'path' => $scriptDir . '/slow.php',
    'type' => 'slow',
];
file_put_contents(
    $scriptSlow['path'],
    "<?php\nsleep(2);\necho 'slow';\nexit(0);\n"
);

$scriptFast = [
    'path' => $scriptDir . '/fast.php',
    'type' => 'fast',
];
file_put_contents(
    $scriptFast['path'],
    "<?php\necho 'fast';\nexit(0);\n"
);

$scriptFail = [
    'path' => $scriptDir . '/fail.php',
    'type' => 'fail',
];
file_put_contents(
    $scriptFail['path'],
    "<?php\nsleep(1);\nfwrite(STDERR, 'boom');\nexit(1);\n"
);

$buildCommand = static function (array $test, int $workerId): array {
    return [
        'cmd' => ['php', (string)$test['file']],
        'env' => ['FRAMEWORK_OBS_WORKER' => (string)$workerId],
    ];
};

try {
    set_env_exec('TESTKIT_ARTIFACTS_ROOT', $artifactRoot);
    set_env_exec('TESTKIT_PROJECT_ROOT', $repoRoot);
    set_env_exec('TK_REPO_ROOT', $repoRoot);

    set_env_exec('TESTKIT_PROGRESS_MODE', 'heartbeat');
    set_env_exec('TESTKIT_PROGRESS_INTERVAL_SEC', '1');
    set_env_exec('TESTKIT_LONG_TEST_WARN_SEC', '1');

    $heartbeatTests = [
        make_test($scriptSlow, 'test/back/framework/integration/VeryLongFrameworkSlowTest.php'),
        make_test($scriptFail, 'test/back/framework/integration/VeryLongFrameworkFailTest.php'),
        make_test($scriptFast, 'test/back/framework/integration/VeryLongFrameworkFastTest.php'),
    ];

    ob_start();
    $heartbeatResult = SuiteExecutor::execute($heartbeatTests, build_config($repoRoot, 2), $buildCommand);
    $heartbeatOutput = strip_ansi_exec((string)ob_get_clean());

    assert_true(($heartbeatResult['progress_policy']['mode'] ?? null) === 'heartbeat', 'heartbeat run should persist heartbeat mode', $errors);
    assert_true(($heartbeatResult['execution_metrics']['selected_test_count'] ?? null) === 3, 'heartbeat selected count should be 3', $errors);
    assert_true(($heartbeatResult['execution_metrics']['completed_test_count'] ?? null) === 3, 'heartbeat completed count should be 3', $errors);
    assert_true(is_int($heartbeatResult['execution_metrics']['avg_test_ms'] ?? null), 'heartbeat avg_test_ms should be numeric', $errors);
    assert_contains_exec($heartbeatOutput, '[Progress]', 'heartbeat output', $errors);
    assert_contains_exec($heartbeatOutput, 'jobs=2', 'heartbeat output', $errors);
    assert_contains_exec($heartbeatOutput, 'workers=', 'heartbeat output', $errors);
    assert_contains_exec($heartbeatOutput, '[WARN]', 'heartbeat output', $errors);
    assert_contains_exec($heartbeatOutput, 'long_running_test', 'heartbeat output', $errors);

    set_env_exec('TESTKIT_PROGRESS_MODE', 'per_test');
    set_env_exec('TESTKIT_PROGRESS_INTERVAL_SEC', '5');
    set_env_exec('TESTKIT_LONG_TEST_WARN_SEC', '1');

    $perTestTests = [
        make_test($scriptSlow, 'test/back/framework/integration/PerTestSlowWorkerTest.php'),
        make_test($scriptFast, 'test/back/framework/integration/PerTestFastWorkerTest.php'),
    ];

    ob_start();
    $perTestResult = SuiteExecutor::execute($perTestTests, build_config($repoRoot, 2), $buildCommand);
    $perTestOutput = strip_ansi_exec((string)ob_get_clean());

    assert_true(($perTestResult['progress_policy']['mode'] ?? null) === 'per_test', 'per_test run should persist per_test mode', $errors);
    assert_true(($perTestResult['execution_metrics']['selected_test_count'] ?? null) === 2, 'per_test selected count should be 2', $errors);
    assert_true(($perTestResult['execution_metrics']['completed_test_count'] ?? null) === 2, 'per_test completed count should be 2', $errors);
    assert_true(!str_contains($perTestOutput, '[Progress]'), 'per_test output should not emit heartbeat lines', $errors);
    assert_contains_exec($perTestOutput, '[Test]', 'per_test output', $errors);
    assert_contains_exec($perTestOutput, 'worker=', 'per_test output', $errors);
    assert_contains_exec($perTestOutput, 'active=', 'per_test output', $errors);
    assert_contains_exec($perTestOutput, '[WARN]', 'per_test output', $errors);

    HistoryRepository::recordSuiteMetrics($perTestResult, 5);
    $historyPath = $artifactRoot . '/history/framework_observability.json';
    $history = json_decode((string)file_get_contents($historyPath), true);
    $lastSuiteRun = is_array($history['suite_runs'] ?? null) ? end($history['suite_runs']) : null;
    assert_true(is_array($lastSuiteRun), 'history should record suite_runs snapshots', $errors);
    assert_true(($lastSuiteRun['progress_policy']['mode'] ?? null) === 'per_test', 'history should persist per_test mode', $errors);
    assert_true(($lastSuiteRun['execution_metrics']['completed_test_count'] ?? null) === 2, 'history should persist execution metrics', $errors);

    set_env_exec('TESTKIT_PROGRESS_MODE', 'quiet');
    set_env_exec('TESTKIT_PROGRESS_INTERVAL_SEC', '1');
    set_env_exec('TESTKIT_LONG_TEST_WARN_SEC', '1');

    ob_start();
    $quietResult = SuiteExecutor::execute($perTestTests, build_config($repoRoot, 2), $buildCommand);
    $quietOutput = strip_ansi_exec((string)ob_get_clean());

    assert_true(($quietResult['progress_policy']['mode'] ?? null) === 'quiet', 'quiet run should persist quiet mode', $errors);
    assert_true(!str_contains($quietOutput, '[Progress]'), 'quiet output should suppress heartbeat', $errors);
    assert_true(!str_contains($quietOutput, '[Test]'), 'quiet output should suppress per_test lines', $errors);
    assert_true(!str_contains($quietOutput, '[WARN]'), 'quiet output should suppress long-running warnings', $errors);

    set_env_exec('TESTKIT_PROGRESS_MODE', 'heartbeat');
    set_env_exec('TESTKIT_PROGRESS_INTERVAL_SEC', '1');
    set_env_exec('TESTKIT_LONG_TEST_WARN_SEC', '1');

    $listOnlyTests = [
        make_test($scriptSlow, 'test/back/framework/integration/ListOnlySlowTest.php'),
        make_test($scriptFast, 'test/back/framework/integration/ListOnlyFastTest.php'),
    ];
    $listOnlyResult = SuiteExecutor::execute($listOnlyTests, build_config($repoRoot, 1, true, false), $buildCommand);
    assert_true(($listOnlyResult['suite_status'] ?? null) === 'listed', 'list_only suite_status should be listed', $errors);
    assert_true(($listOnlyResult['execution_metrics']['selected_test_count'] ?? null) === 2, 'list_only selected count should be 2', $errors);
    assert_true(($listOnlyResult['execution_metrics']['completed_test_count'] ?? null) === 0, 'list_only completed count should be 0', $errors);
    assert_true(($listOnlyResult['execution_metrics']['avg_test_ms'] ?? null) === null, 'list_only avg_test_ms should be null', $errors);

    $noTestsResult = SuiteExecutor::execute([], build_config($repoRoot, 1, false, false), $buildCommand);
    assert_true(($noTestsResult['suite_status'] ?? null) === 'no_tests', 'no_tests suite_status should be no_tests', $errors);
    assert_true(($noTestsResult['execution_metrics']['selected_test_count'] ?? null) === 0, 'no_tests selected count should be 0', $errors);
    assert_true(($noTestsResult['execution_metrics']['completed_test_count'] ?? null) === 0, 'no_tests completed count should be 0', $errors);
    assert_true(($noTestsResult['execution_metrics']['avg_test_ms'] ?? null) === null, 'no_tests avg_test_ms should be null', $errors);

    $orchestratorDefaults = new ReflectionMethod(SuiteOrchestrator::class, 'attachObservabilityDefaults');
    $orchestratorDefaults->setAccessible(true);
    $phaseSnapshot = new ReflectionMethod(SuiteOrchestrator::class, 'phaseTimingsSnapshot');
    $phaseSnapshot->setAccessible(true);

    $failureResult = SuiteOperationalFailure::build(
        config: build_config($repoRoot, 1, false, false),
        tests: [],
        reportRoot: $artifactRoot . '/reports',
        runId: 'run_failure_test',
        metaRunId: 'meta_failure_test',
        policy: [],
        warnings: [],
        admission: [
            'store_mode' => 'shared',
            'concurrency_policy' => 'not_applicable',
            'run_admitted' => true,
            'reason' => null,
            'resource' => '',
            'lock_key' => '',
            'lock_owner_run_id' => null,
            'lock_owner_meta_run_id' => null,
            'lock_owner_hostname' => null,
            'lock_acquired_at' => null,
        ],
        phase: 'discovery',
        error: new RuntimeException('synthetic failure')
    );
    $orchestratorDefaults->invokeArgs(null, [&$failureResult, 0]);
    $failurePhaseTimings = $phaseSnapshot->invoke(null, ['discovery' => 0, 'admission' => 0, 'execution' => 0, 'reporting' => 0], 'reporting', (int)round(microtime(true) * 1000));

    assert_true(is_array($failureResult['progress_policy'] ?? null), 'failure path should inject progress_policy', $errors);
    assert_true(is_array($failureResult['execution_metrics'] ?? null), 'failure path should inject execution_metrics', $errors);
    assert_true(($failureResult['execution_metrics']['completed_test_count'] ?? null) === 0, 'failure path completed count should stay 0 without executed tests', $errors);
    assert_true(is_array($failurePhaseTimings), 'phase timings snapshot should return an array', $errors);
    assert_true(array_keys($failurePhaseTimings) === ['discovery', 'admission', 'execution', 'reporting'], 'phase timings snapshot should preserve the four canonical phases', $errors);
} finally {
    foreach ($previousEnv as $key => $value) {
        set_env_exec($key, $value);
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Observability execution contract PASS\n";
exit(0);
