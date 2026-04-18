<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Common\AgentMode;
use Testkit\Core\Config\RunnerConfig;
use Testkit\Core\Execution\SuiteExecutor;
use Testkit\Core\Reporting\UI;

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
    'TESTKIT_MODE',
    'TEST_META_FAIL_FAST',
    'TEST_CHILD_FAIL_FAST',
    'TEST_FAIL_FAST',
    'TEST_JOBS',
    'TEST_DB_STRATEGY',
    'TESTKIT_PROGRESS_MODE',
    'NO_COLOR',
];
$previousEnv = [];
foreach ($keys as $key) {
    $previousEnv[$key] = get_env_value($key);
}

try {
    foreach ($keys as $key) {
        set_env_value($key, null);
    }

    assert_true(AgentMode::isEnabled() === false, 'agent mode should be disabled by default', $errors);

    set_env_value('TESTKIT_MODE', 'agent');
    set_env_value('TEST_META_FAIL_FAST', '1');
    set_env_value('TEST_CHILD_FAIL_FAST', '1');
    set_env_value('TEST_FAIL_FAST', '1');
    set_env_value('TEST_JOBS', '8');
    set_env_value('TEST_DB_STRATEGY', 'per_worker');
    set_env_value('TESTKIT_PROGRESS_MODE', 'per_test');
    set_env_value('NO_COLOR', '0');

    AgentMode::applyRuntimeEnv();

    assert_true(AgentMode::isEnabled() === true, 'agent mode should activate with TESTKIT_MODE=agent', $errors);
    assert_true(get_env_value('TEST_META_FAIL_FAST') === '0', 'agent mode must force TEST_META_FAIL_FAST=0', $errors);
    assert_true(get_env_value('TEST_CHILD_FAIL_FAST') === '0', 'agent mode must force TEST_CHILD_FAIL_FAST=0', $errors);
    assert_true(get_env_value('TEST_FAIL_FAST') === '0', 'agent mode must force TEST_FAIL_FAST=0', $errors);
    assert_true(get_env_value('TEST_JOBS') === '1', 'agent mode must force TEST_JOBS=1', $errors);
    assert_true(get_env_value('TEST_DB_STRATEGY') === 'shared', 'agent mode must force TEST_DB_STRATEGY=shared', $errors);
    assert_true(get_env_value('TESTKIT_PROGRESS_MODE') === 'quiet', 'agent mode must force quiet progress', $errors);
    assert_true(get_env_value('NO_COLOR') === '1', 'agent mode must disable ANSI through NO_COLOR=1', $errors);

    $suiteConfig = RunnerConfig::forSuite('back_php', '/tmp/tests', '/tmp/cov', 'php');
    $metaConfig = RunnerConfig::meta();
    $payload = AgentMode::reportPayload();

    assert_true(($suiteConfig['jobs'] ?? null) === 1, 'suite config must collapse jobs to 1 in agent mode', $errors);
    assert_true(($suiteConfig['fail_fast'] ?? null) === false, 'suite config must disable fail_fast in agent mode', $errors);
    assert_true(($metaConfig['meta_fail_fast'] ?? null) === false, 'meta config must disable meta_fail_fast in agent mode', $errors);
    assert_true(($metaConfig['child_fail_fast'] ?? null) === false, 'meta config must disable child_fail_fast in agent mode', $errors);
    assert_true(($payload['enabled'] ?? null) === true, 'report payload must persist enabled=true', $errors);
    assert_true(($payload['mode'] ?? null) === 'agent', 'report payload must persist mode=agent', $errors);
    assert_true(($payload['enforced']['TESTKIT_PROGRESS_MODE'] ?? null) === 'quiet', 'report payload must expose quiet progress as enforced', $errors);
    assert_true((SuiteExecutor::progressPolicy()['mode'] ?? null) === 'quiet', 'execution progress policy must resolve to quiet after overrides', $errors);
    assert_true(str_contains(UI::info('hello'), "\033") === false, 'UI text must not contain ANSI when agent mode is enabled', $errors);
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

echo "Agent mode runtime contract PASS\n";
exit(0);
