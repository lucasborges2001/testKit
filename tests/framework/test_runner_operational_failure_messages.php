<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Suites\MetaOperationalFailureBuilder;

$errors = [];

function tk_runner_assert_same(mixed $expected, mixed $actual, string $message, array &$errors): void
{
    if ($expected !== $actual) {
        $errors[] = $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
}

function tk_runner_assert_true(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

$tmp = sys_get_temp_dir() . '/testkit_runner_operational_failure_' . getmypid();
@mkdir($tmp, 0777, true);

$classError = new Error('Class "Testkit\\Core\\InfluxProfiling\\InfluxProfileConfig" not found');
$report = MetaOperationalFailureBuilder::build(
    target: 'back-php',
    category: 'all',
    reportRoot: $tmp,
    durationMs: 12,
    startedAt: gmdate('Y-m-d\TH:i:s\Z'),
    runId: 'test_run',
    admission: [],
    phase: 'execution',
    error: $classError
);

$failure = is_array($report['failures'][0] ?? null) ? $report['failures'][0] : [];
$infra = is_array($report['infra_error'] ?? null) ? $report['infra_error'] : [];

// The key regression guard: do not hide Class-not-found as generic runner_exception.
tk_runner_assert_same('class_not_found', $failure['cause_code'] ?? null, 'class-not-found cause_code', $errors);
tk_runner_assert_same('missing_bootstrap_or_autoload', $failure['root_cause'] ?? null, 'class-not-found root_cause', $errors);
tk_runner_assert_same('Error', $failure['throwable_class'] ?? null, 'throwable class', $errors);
tk_runner_assert_true(str_contains((string)($failure['throwable_message'] ?? ''), 'InfluxProfileConfig'), 'throwable message should include missing class', $errors);
tk_runner_assert_true((string)($failure['throwable_file'] ?? '') !== '', 'throwable file should be present', $errors);
tk_runner_assert_true(($failure['throwable_line'] ?? null) !== null, 'throwable line should be present', $errors);
tk_runner_assert_true(str_contains((string)($failure['operator_hint'] ?? ''), 'bootstrap'), 'operator hint should mention bootstrap', $errors);

tk_runner_assert_same('Error', $infra['throwable_class'] ?? null, 'infra_error throwable class', $errors);
tk_runner_assert_same('missing_bootstrap_or_autoload', $infra['root_cause'] ?? null, 'infra_error root cause', $errors);
tk_runner_assert_same('class_not_found', $report['evidence_invalid_reason'] ?? null, 'evidence invalid reason', $errors);
tk_runner_assert_same('class_not_found', $report['failure_cause_code'] ?? null, 'enriched failure cause', $errors);

$missingFile = MetaOperationalFailureBuilder::build(
    target: 'back-php',
    category: 'all',
    reportRoot: $tmp,
    durationMs: 1,
    startedAt: gmdate('Y-m-d\TH:i:s\Z'),
    runId: 'test_run',
    admission: [],
    phase: 'execution',
    error: new Error('Failed opening required /workspace/testkit/core/php/foo.php: No such file or directory')
);
tk_runner_assert_same('missing_file', $missingFile['failures'][0]['cause_code'] ?? null, 'missing file cause_code', $errors);
tk_runner_assert_same('missing_required_file', $missingFile['failures'][0]['root_cause'] ?? null, 'missing file root_cause', $errors);

$typeError = MetaOperationalFailureBuilder::build(
    target: 'back-php',
    category: 'all',
    reportRoot: $tmp,
    durationMs: 1,
    startedAt: gmdate('Y-m-d\TH:i:s\Z'),
    runId: 'test_run',
    admission: [],
    phase: 'execution',
    error: new TypeError('Example type error')
);
tk_runner_assert_same('type_error', $typeError['failures'][0]['cause_code'] ?? null, 'type error cause_code', $errors);
tk_runner_assert_same('php_type_error', $typeError['failures'][0]['root_cause'] ?? null, 'type error root_cause', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Runner operational failure messages PASS\n";
