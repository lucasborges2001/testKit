<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Reporting\ConsoleReporter;

$errors = [];

/** @param array<int,string> $needles @param array<int,string> $forbidden */
function assert_output_contract(string $output, array $needles, array $forbidden, array &$errors, string $label): void
{
    foreach ($needles as $needle) {
        if (!str_contains($output, $needle)) {
            $errors[] = $label . ': missing "' . $needle . '"';
        }
    }
    foreach ($forbidden as $needle) {
        if (str_contains($output, $needle)) {
            $errors[] = $label . ': unexpected "' . $needle . '"';
        }
    }
}

function strip_ansi(string $value): string
{
    return (string)preg_replace('/\e\[[0-9;]*m/', '', $value);
}

$baseReport = [
    'suite_id' => 'front_js',
    'pass' => 19,
    'fail' => 0,
    'skip' => 0,
    'timeout' => 0,
    'duration_ms' => 1858,
    'diagnostics' => [
        'outcome_status' => 'passed',
        'primary_phase' => 'none',
        'failure_domain' => 'none',
        'cause_code' => 'none',
        'resource' => 'mysql/cargadores_test',
        'phase_failure_counts' => [],
        'cause_counts' => [],
        'lock_key' => '',
    ],
    'selection_manifest' => [
        'selected_test_count' => 19,
        'selected_common_dir' => 'test/front',
        'selected_module_scope' => '',
        'match' => '',
    ],
    'summary' => [
        'total' => 19,
        'passed' => 19,
        'failed' => 0,
        'skipped' => 0,
        'duration_ms' => 1858,
        'suite_status' => 'passed',
    ],
    'suite_status' => 'passed',
    'report_scope_rel' => '.testkit/reports/runs/20260415T191124Z_70101d',
    'recommended_actions' => [
        ['command' => '.testkit/reports/runs/20260415T191124Z_70101d', 'reason' => 'inspeccionar artefactos'],
        ['command' => 'php scripts/report.php', 'reason' => 'ver resumen consolidado'],
    ],
    'agent_summary' => [
        'status' => 'PASSED',
        'primary_problem' => 'none',
        'suggested_focus' => ['seed_state', 'selection_manifest'],
    ],
    'module_summary' => [
        'front/alerta' => ['total' => 17, 'pass' => 17, 'fail' => 0, 'skip' => 0, 'timeout' => 0],
        'front/sitio' => ['total' => 1, 'pass' => 1, 'fail' => 0, 'skip' => 0, 'timeout' => 0],
        'front/usuario' => ['total' => 1, 'pass' => 1, 'fail' => 0, 'skip' => 0, 'timeout' => 0],
    ],
    'slow_tests' => [],
    'fragility_hints' => [['type' => 'flaky', 'test' => 'test/front/alerta/integration/alerta_x.test.mjs', 'pass_count' => 5, 'fail_count' => 2]],
    'perf_violations' => [],
    'warnings' => [],
    'evidence_valid' => true,
    'evidence_invalid_reason' => null,
];

putenv('TESTKIT_CONSOLE_MODE=compact');
putenv('NO_COLOR=1');
ob_start();
ConsoleReporter::printSuiteResult($baseReport);
$compactOutput = strip_ansi((string)ob_get_clean());
assert_output_contract(
    $compactOutput,
    ['PASS front-js', '19/19'],
    ['[Result]', '[Selection Summary]', '[Diagnostics]', '[Decision]', '[Recommended Actions]', '[Module Summary]', '[Fragility Hints]'],
    $errors,
    'compact pass'
);

$slowReport = $baseReport;
$slowReport['slow_tests'] = [['rel' => 'test/front/alerta/integration/alerta_smoke.test.mjs', 'duration_ms' => 1800]];
ob_start();
ConsoleReporter::printSuiteResult($slowReport);
$slowOutput = strip_ansi((string)ob_get_clean());
assert_output_contract(
    $slowOutput,
    ['PASS front-js', '[Slow Tests]'],
    ['[Result]', '[Decision]', '[Recommended Actions]', '[Module Summary]'],
    $errors,
    'compact pass with slow tests'
);

putenv('TESTKIT_CONSOLE_MODE=live');
ob_start();
ConsoleReporter::printSuiteResult($baseReport);
$liveOutput = strip_ansi((string)ob_get_clean());
assert_output_contract(
    $liveOutput,
    ['[Result]', '[Selection Summary]'],
    ['[Decision]', '[Recommended Actions]', '[Module Summary]'],
    $errors,
    'live compatibility'
);

putenv('TESTKIT_CONSOLE_MODE');
putenv('NO_COLOR');
if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Console reporter compact pass PASS\n";
