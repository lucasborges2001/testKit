<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Execution\SuiteExecutor;
use Testkit\Core\Reporting\HistoryRepository;

$errors = [];

function assert_true(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

function set_env_value(string $key, ?string $value): void
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

function get_env_value(string $key): ?string
{
    $value = getenv($key);
    return $value === false ? null : (string)$value;
}

$keys = [
    'TESTKIT_PROGRESS_MODE',
    'TESTKIT_PROGRESS_INTERVAL_SEC',
    'TESTKIT_LONG_TEST_WARN_SEC',
];
$previousEnv = [];
foreach ($keys as $key) {
    $previousEnv[$key] = get_env_value($key);
}

try {
    set_env_value('TESTKIT_PROGRESS_MODE', null);
    set_env_value('TESTKIT_PROGRESS_INTERVAL_SEC', null);
    set_env_value('TESTKIT_LONG_TEST_WARN_SEC', null);

    $policy = SuiteExecutor::progressPolicy();
    assert_true(($policy['mode'] ?? null) === 'heartbeat', 'default mode should be heartbeat', $errors);
    assert_true(($policy['interval_sec'] ?? null) === 15, 'default interval should be 15', $errors);
    assert_true(($policy['long_test_warn_sec'] ?? null) === 60, 'default long test warn should be 60', $errors);

    set_env_value('TESTKIT_PROGRESS_MODE', 'per_test');
    set_env_value('TESTKIT_PROGRESS_INTERVAL_SEC', '7');
    set_env_value('TESTKIT_LONG_TEST_WARN_SEC', '11');
    $policy = SuiteExecutor::progressPolicy();
    assert_true(($policy['mode'] ?? null) === 'per_test', 'per_test should be accepted', $errors);
    assert_true(($policy['interval_sec'] ?? null) === 7, 'interval override should be honored', $errors);
    assert_true(($policy['long_test_warn_sec'] ?? null) === 11, 'long warn override should be honored', $errors);

    set_env_value('TESTKIT_PROGRESS_MODE', 'quiet');
    $policy = SuiteExecutor::progressPolicy();
    assert_true(($policy['mode'] ?? null) === 'quiet', 'quiet should be accepted', $errors);

    set_env_value('TESTKIT_PROGRESS_MODE', 'broken-mode');
    $policy = SuiteExecutor::progressPolicy();
    assert_true(($policy['mode'] ?? null) === 'heartbeat', 'invalid mode should fall back to heartbeat', $errors);

    $normalize = new ReflectionMethod(HistoryRepository::class, 'normalizeProgressPolicy');
    $normalize->setAccessible(true);

    /** @var array<string,int|string>|null $normalized */
    $normalized = $normalize->invoke(null, [
        'mode' => 'per_test',
        'interval_sec' => 9,
        'long_test_warn_sec' => 13,
    ]);
    assert_true(is_array($normalized), 'history progress policy should normalize arrays', $errors);
    assert_true(($normalized['mode'] ?? null) === 'per_test', 'history should preserve per_test mode', $errors);
    assert_true(($normalized['interval_sec'] ?? null) === 9, 'history should preserve interval_sec', $errors);
    assert_true(($normalized['long_test_warn_sec'] ?? null) === 13, 'history should preserve long_test_warn_sec', $errors);

    /** @var array<string,int|string>|null $normalizedInvalid */
    $normalizedInvalid = $normalize->invoke(null, [
        'mode' => 'nope',
        'interval_sec' => 0,
        'long_test_warn_sec' => 0,
    ]);
    assert_true(($normalizedInvalid['mode'] ?? null) === 'heartbeat', 'history invalid mode should fall back to heartbeat', $errors);
    assert_true(($normalizedInvalid['interval_sec'] ?? null) === 1, 'history interval should clamp to >= 1', $errors);
    assert_true(($normalizedInvalid['long_test_warn_sec'] ?? null) === 1, 'history long warn should clamp to >= 1', $errors);
} finally {
    foreach ($previousEnv as $key => $value) {
        set_env_value($key, $value);
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Observability progress policy PASS\n";
exit(0);
