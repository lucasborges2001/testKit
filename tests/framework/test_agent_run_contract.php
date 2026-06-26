<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Common\Paths;
use Testkit\Core\Reporting\AgentRun;
use Testkit\Core\Reporting\AgentRunArtifact;
use Testkit\Core\Reporting\AgentRunExecute;
use Testkit\Core\Reporting\Inspector;

$errors = [];

function assert_true(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

function rrmdir(string $path): void
{
    if (!is_dir($path)) {
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
        $full = $path . '/' . $item;
        if (is_dir($full)) {
            rrmdir($full);
            @rmdir($full);
            continue;
        }
        @unlink($full);
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
    'TESTKIT_ARTIFACTS_ROOT',
    'TESTKIT_PROJECT_ROOT',
    'TK_REPO_ROOT',
    'TESTKIT_ROOT',
];
$previousEnv = [];
foreach ($keys as $key) {
    $previousEnv[$key] = get_env_value($key);
}

$root = sys_get_temp_dir() . '/testkit_agent_contract_' . bin2hex(random_bytes(4));
$projectRoot = $root . '/project';
$testkitRoot = $root . '/testkit';
$runId = '20260418T120000Z_agenttest';
$runRoot = $projectRoot . '/.testkit/reports/runs/' . $runId;

@mkdir($projectRoot . '/.testkit/reports/runs', 0777, true);
@mkdir($runRoot, 0777, true);
@mkdir($testkitRoot, 0777, true);

try {
    set_env_value('TESTKIT_PROJECT_ROOT', $projectRoot);
    set_env_value('TK_REPO_ROOT', $projectRoot);
    set_env_value('TESTKIT_ARTIFACTS_ROOT', $projectRoot . '/.testkit');
    set_env_value('TESTKIT_ROOT', $testkitRoot);

    $suite = [
        'suite_id' => 'back_php',
        'run_id' => $runId,
        'meta_run_id' => $runId,
        'report_root' => $runRoot,
        'report_scope_rel' => '.testkit/reports/runs/' . $runId,
        'selected_test_count' => 2,
        'selected_module_scope' => 'back/auth',
        'summary' => [
            'total' => 2,
            'passed' => 1,
            'failed' => 1,
            'skipped' => 0,
            'duration_ms' => 250,
        ],
        'suite_status' => 'failed',
        'outcome_status' => 'failed',
        'tests_total' => 2,
        'pass' => 1,
        'fail' => 1,
        'skip' => 0,
        'duration_ms' => 250,
        'agent_mode' => [
            'enabled' => true,
            'mode' => 'agent',
            'enforced' => [
                'TEST_META_FAIL_FAST' => '0',
                'TEST_CHILD_FAIL_FAST' => '0',
                'TEST_FAIL_FAST' => '0',
                'TEST_JOBS' => '1',
                'TEST_DB_STRATEGY' => 'shared',
                'TESTKIT_PROGRESS_MODE' => 'quiet',
                'NO_COLOR' => '1',
            ],
        ],
        'canonical_report' => [
            'report_version' => 1,
            'report_kind' => 'suite',
            'final_status' => 'FAIL',
            'selection' => [
                'suite_id' => 'back_php',
                'target' => null,
                'scope' => 'all',
                'category' => 'all',
                'match' => '',
                'selected_test_count' => 2,
                'selected_test_files' => [
                    'test/back/auth/integration/a.test.php',
                    'test/back/auth/integration/b.test.php',
                ],
                'selected_module_scope' => 'back/auth',
            ],
            'summary' => [
                'total' => 2,
                'passed' => 1,
                'failed' => 1,
                'skipped' => 0,
                'duration_ms' => 250,
            ],
            'evidence' => [
                'valid' => true,
                'invalid_reason' => null,
                'first_failure' => [
                    'suite_id' => 'back_php',
                    'file' => 'test/back/auth/integration/a.test.php',
                    'case' => 'a.test.php',
                    'kind' => 'test_failure',
                    'phase' => 'execution',
                    'cause_code' => 'exit_code_1',
                    'exception_class' => null,
                    'message' => 'Expected true, got false',
                    'stack_excerpt' => ['stack line'],
                    'artifact_path' => '.testkit/reports/runs/' . $runId . '/back_php_latest.json',
                ],
            ],
            'artifacts' => [
                'report_root' => $runRoot,
                'report_scope_rel' => '.testkit/reports/runs/' . $runId,
                'report_links' => [],
                'history_file' => null,
                'manifest_path' => null,
                'snapshot_file' => null,
            ],
            'agent_mode' => [
                'enabled' => true,
                'mode' => 'agent',
                'enforced' => [
                    'TEST_META_FAIL_FAST' => '0',
                    'TEST_CHILD_FAIL_FAST' => '0',
                    'TEST_FAIL_FAST' => '0',
                    'TEST_JOBS' => '1',
                    'TEST_DB_STRATEGY' => 'shared',
                    'TESTKIT_PROGRESS_MODE' => 'quiet',
                    'NO_COLOR' => '1',
                ],
            ],
            'runner' => [
                'contract_version' => 1,
                'capabilities' => [],
                'hazards' => [],
                'mode' => 'agent',
            ],
        ],
    ];

    $meta = [
        'target' => 'back-php',
        'category' => 'all',
        'run_id' => $runId,
        'meta_run_id' => $runId,
        'run_kind' => 'meta',
        'report_root' => $runRoot,
        'report_scope_rel' => '.testkit/reports/runs/' . $runId,
        'summary' => [
            'total' => 2,
            'passed' => 1,
            'failed' => 1,
            'skipped' => 0,
            'duration_ms' => 300,
        ],
        'suite_status_counts' => ['failed' => 1],
        'agent_mode' => $suite['agent_mode'],
        'canonical_report' => [
            'report_version' => 1,
            'report_kind' => 'meta',
            'final_status' => 'FAIL',
            'selection' => [
                'suite_id' => null,
                'target' => 'back-php',
                'scope' => 'all',
                'category' => 'all',
                'match' => '',
                'selected_test_count' => 2,
                'selected_test_files' => [],
                'selected_module_scope' => '',
            ],
            'summary' => [
                'total' => 2,
                'passed' => 1,
                'failed' => 1,
                'skipped' => 0,
                'duration_ms' => 300,
            ],
            'evidence' => [
                'valid' => true,
                'invalid_reason' => null,
                'first_failure' => [
                    'suite_id' => 'back_php',
                    'file' => 'test/back/auth/integration/a.test.php',
                    'case' => 'a.test.php',
                    'kind' => 'test_failure',
                    'phase' => 'execution',
                    'cause_code' => 'exit_code_1',
                    'exception_class' => null,
                    'message' => 'Expected true, got false',
                    'stack_excerpt' => ['stack line'],
                    'artifact_path' => '.testkit/reports/runs/' . $runId . '/back_php_latest.json',
                ],
            ],
            'artifacts' => [
                'report_root' => $runRoot,
                'report_scope_rel' => '.testkit/reports/runs/' . $runId,
                'report_links' => [],
                'history_file' => null,
                'manifest_path' => null,
                'snapshot_file' => null,
            ],
            'agent_mode' => $suite['agent_mode'],
            'runner' => [
                'contract_version' => 1,
                'capabilities' => [],
                'hazards' => [],
                'mode' => 'agent',
            ],
        ],
    ];

    file_put_contents($runRoot . '/meta_latest.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    file_put_contents($runRoot . '/back_php_latest.json', json_encode($suite, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    file_put_contents($projectRoot . '/.testkit/reports/latest_run.json', json_encode([
        'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'run_id' => $runId,
        'meta_run_id' => $runId,
        'target' => 'back-php',
        'report_root' => $runRoot,
        'report_scope_rel' => '.testkit/reports/runs/' . $runId,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

    $decision = AgentRun::buildLatestDecision($runId, 'contract check');
    assert_true(($decision['agent_mode']['enabled'] ?? null) === true, 'agent-run must expose agent_mode in its decision payload', $errors);
    assert_true(($decision['agent_mode']['mode'] ?? null) === 'agent', 'agent-run must preserve agent mode label', $errors);
    assert_true(str_starts_with((string)($decision['next_action']['command'] ?? ''), 'TESTKIT_MODE=agent '), 'agent-run next_action command must keep TESTKIT_MODE explicitly', $errors);

    $execution = AgentRunExecute::execute($decision);
    $overrides = is_array($execution['command']['env_overrides'] ?? null) ? $execution['command']['env_overrides'] : [];
    assert_true(($overrides['TESTKIT_MODE'] ?? null) === 'agent', 'agent-run execute must inject TESTKIT_MODE into child env overrides', $errors);

    $artifact = AgentRunArtifact::record($decision, [
        'executed' => false,
        'kind' => (string)($decision['next_action']['kind'] ?? ''),
        'reason' => 'self_test',
        'command' => [
            'argv' => [],
            'cwd' => Paths::relativeToRepo($testkitRoot),
            'env_overrides' => ['TESTKIT_MODE' => 'agent'],
            'display' => (string)($decision['next_action']['command'] ?? ''),
        ],
        'result' => [
            'exit_code' => 0,
            'duration_ms' => 0,
            'stdout_excerpt' => null,
            'stderr_excerpt' => null,
        ],
        'child_payload' => null,
    ]);
    assert_true(($artifact['recorded'] ?? null) === true, 'agent artifact should be persisted', $errors);

    ob_start();
    Inspector::runCli(['inspect.php', 'latest', '--run=' . $runId, '--json']);
    $inspectJson = ob_get_clean();
    $inspectPayload = is_string($inspectJson) ? json_decode($inspectJson, true) : null;
    assert_true(is_array($inspectPayload), 'inspect latest --json should return decodable JSON', $errors);
    assert_true(($inspectPayload['agent_run_artifact']['executed'] ?? null) === false, 'inspect latest must expose agent_run_artifact summary', $errors);
    assert_true(($inspectPayload['agent_run_artifact']['kind'] ?? null) === ($decision['next_action']['kind'] ?? null), 'inspect artifact summary must stay aligned with recorded kind', $errors);
} finally {
    rrmdir($root);
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

echo "Agent run continuation contract PASS\n";
exit(0);
