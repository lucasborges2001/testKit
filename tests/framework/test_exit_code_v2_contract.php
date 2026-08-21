<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Execution\ExitCode;
use Testkit\Core\Execution\SuiteExecutor;

$errors = [];
$tmpRoot = sys_get_temp_dir() . '/tk_exit_v2_' . bin2hex(random_bytes(4));
@mkdir($tmpRoot, 0777, true);

$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$errors): void {
    if ($expected !== $actual) {
        $errors[] = $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
};

$expectedTable = [
    0 => 'OK',
    1 => 'TEST_FAILURE',
    2 => 'INVALID_REQUEST',
    3 => 'OPERATIONAL_ERROR',
    4 => 'EVIDENCE_INCOMPLETE',
    5 => 'POLICY_BLOCKED',
    6 => 'NO_TESTS',
    7 => 'CONTENTION',
    8 => 'TIMEOUT',
];
$assertSame($expectedTable, ExitCode::table(), 'v2 exit table must be closed and stable');
$assertSame(ExitCode::OPERATIONAL_ERROR, ExitCode::normalize(99), 'unknown external process code must normalize to OPERATIONAL_ERROR');
$assertSame(ExitCode::NO_TESTS, SuiteExecutor::EXIT_NO_TESTS, 'SuiteExecutor NO_TESTS alias must be 6');
$assertSame(2, SuiteExecutor::CHILD_EXIT_SKIP, 'child skip protocol must remain distinct from process INVALID_REQUEST semantics');

$config = [
    'suite_id' => 'exit_v2_fixture',
    'language' => 'php',
    'scope' => 'all',
    'category' => 'all',
    'list_only' => false,
    'require_tests' => false,
    'jobs' => 1,
    'fail_fast' => false,
    'repo_root' => $tmpRoot,
    'test_timeout_sec' => 1,
    'thresholds' => [
        'slow_ms' => 999999,
        'slow_top' => 10,
        'perf_max_ms' => 0,
        'perf_warn_ms' => 0,
    ],
];

$empty = SuiteExecutor::execute([], $config, static fn(): array => ['cmd' => [PHP_BINARY, '-r', 'exit(0);'], 'env' => []]);
$assertSame(ExitCode::NO_TESTS, (int)$empty['exit_code'], 'tolerated empty selection must exit as NO_TESTS');
$assertSame('no_tests', (string)$empty['suite_status'], 'empty selection must keep suite_status=no_tests');

$test = [[
    'file' => $tmpRoot . '/skip.test.php',
    'rel' => 'test/back/fixture/unit/skip.test.php',
    'module' => 'back/fixture',
    'tags' => ['unit'],
]];
$allSkipped = SuiteExecutor::execute(
    $test,
    $config,
    static fn(): array => ['cmd' => [PHP_BINARY, '-r', 'exit(2);'], 'env' => []]
);
$assertSame(ExitCode::OK, (int)$allSkipped['exit_code'], 'all-skipped selected tests must not reuse process exit code 2');
$assertSame(1, (int)$allSkipped['skip'], 'child exit 2 must remain visible as per-test skip');
$assertSame('skipped', (string)$allSkipped['suite_status'], 'all-skipped suite must preserve structured skipped status');

$timeout = SuiteExecutor::execute(
    $test,
    $config,
    static fn(): array => ['cmd' => [PHP_BINARY, '-r', 'usleep(2000000); exit(0);'], 'env' => []]
);
$assertSame(ExitCode::TIMEOUT, (int)$timeout['exit_code'], 'suite timeout must use process exit code 8');
$assertSame(1, (int)$timeout['timeout'], 'timeout count must remain explicit');

if ($errors !== []) {
    fwrite(STDERR, "Exit code v2 contract failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "Exit code v2 contract PASS\n";
