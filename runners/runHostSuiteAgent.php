<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/php/bootstrap.php';

use Testkit\Core\Reporting\AgentRun;
use Testkit\Core\Reporting\AgentRunArtifact;

/** @return array{config:string,suite:string,allow_persistent:bool,json:bool,goal:string} */
function testkit_host_agent_parse_args(array $argv): array
{
    $args = array_values(array_slice($argv, 1));
    $allowPersistent = false;
    $json = false;
    $goal = '';
    $positionals = [];

    foreach ($args as $arg) {
        if ($arg === '--allow-persistent') {
            $allowPersistent = true;
            continue;
        }
        if ($arg === '--json') {
            $json = true;
            continue;
        }
        if (str_starts_with($arg, '--goal=')) {
            $goal = trim(substr($arg, strlen('--goal=')));
            continue;
        }
        if ($arg === '--help' || $arg === '-h' || $arg === 'help') {
            testkit_host_agent_print_help();
            exit(0);
        }
        if (str_starts_with($arg, '--')) {
            fwrite(STDERR, "Unsupported option: {$arg}\n");
            exit(2);
        }
        $positionals[] = $arg;
    }

    if (count($positionals) !== 2) {
        testkit_host_agent_print_help(STDERR);
        exit(2);
    }

    return [
        'config' => $positionals[0],
        'suite' => $positionals[1],
        'allow_persistent' => $allowPersistent,
        'json' => $json,
        'goal' => $goal,
    ];
}

/** @param resource|null $stream */
function testkit_host_agent_print_help($stream = null): void
{
    $stream = $stream ?? STDOUT;
    fwrite($stream, "Usage:\n");
    fwrite($stream, "  testkit-host-agent <config.php> <suite> [--allow-persistent] [--goal=<text>] [--json]\n");
}

function testkit_host_agent_project_root(): string
{
    $candidate = trim((string)(getenv('TESTKIT_PROJECT_ROOT') ?: ''));
    if ($candidate === '') {
        $candidate = getcwd() ?: '';
    }
    $resolved = realpath($candidate);
    if (!is_string($resolved) || $resolved === '' || !is_dir($resolved)) {
        throw new RuntimeException('TESTKIT_PROJECT_ROOT does not resolve to a directory.');
    }
    return str_replace('\\', '/', $resolved);
}

/** @return array<string,mixed> */
function testkit_host_agent_suite_metadata(string $projectRoot, string $configPath, string $suiteKey): array
{
    $absolute = str_starts_with($configPath, '/') ? $configPath : $projectRoot . '/' . $configPath;
    $real = realpath($absolute);
    if (!is_string($real) || $real === '' || !is_file($real)) {
        throw new RuntimeException('Host suite config not found: ' . $configPath);
    }
    $config = require $real;
    if (!is_array($config) || !is_array($config['suites'] ?? null)) {
        throw new RuntimeException('Host suite config is invalid.');
    }
    foreach ($config['suites'] as $suite) {
        if (is_array($suite) && (string)($suite['key'] ?? '') === $suiteKey) {
            return [
                'config' => $configPath,
                'suite' => $suiteKey,
                'risk' => (string)($suite['risk'] ?? 'unclassified'),
                'requires' => array_values(array_map('strval', (array)($suite['requires'] ?? []))),
                'exclusive' => (bool)($suite['exclusive'] ?? false),
                'cleanup' => is_array($suite['cleanup'] ?? null) ? $suite['cleanup'] : null,
            ];
        }
    }
    throw new RuntimeException('Host suite not found in config: ' . $suiteKey);
}

/** @return array{exit_code:int,stdout:string,stderr:string,duration_ms:int,argv:array<int,string>} */
function testkit_host_agent_execute_suite(
    string $testkitRoot,
    string $projectRoot,
    string $configPath,
    string $suiteKey,
    bool $allowPersistent,
    string $resultPath
): array {
    $argv = ['bash', $testkitRoot . '/bin/testkit-suite-config', $configPath, $suiteKey];
    if ($allowPersistent) {
        $argv[] = '--allow-persistent';
    }
    $argv[] = '--result-json';
    $argv[] = $resultPath;

    $stdoutPath = tempnam(sys_get_temp_dir(), 'testkit-host-agent-out-');
    $stderrPath = tempnam(sys_get_temp_dir(), 'testkit-host-agent-err-');
    if (!is_string($stdoutPath) || !is_string($stderrPath)) {
        throw new RuntimeException('Unable to create host-agent capture files.');
    }

    $env = getenv();
    $processEnv = is_array($env) ? $env : [];
    $processEnv['TESTKIT_MODE'] = 'agent';
    $processEnv['TESTKIT_PROJECT_ROOT'] = $projectRoot;
    $processEnv['TK_REPO_ROOT'] = $projectRoot;

    $started = microtime(true);
    $process = proc_open(
        $argv,
        [0 => STDIN, 1 => ['file', $stdoutPath, 'w'], 2 => ['file', $stderrPath, 'w']],
        $pipes,
        $testkitRoot,
        $processEnv
    );
    if (!is_resource($process)) {
        @unlink($stdoutPath);
        @unlink($stderrPath);
        throw new RuntimeException('Unable to start host suite runner.');
    }

    $exitCode = proc_close($process);
    $stdout = (string)@file_get_contents($stdoutPath);
    $stderr = (string)@file_get_contents($stderrPath);
    @unlink($stdoutPath);
    @unlink($stderrPath);

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        'argv' => $argv,
    ];
}

/** @return array<string,mixed>|null */
function testkit_host_agent_read_json(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function testkit_host_agent_write_json(string $path, array $payload): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create report directory: ' . $dir);
    }
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '') {
        throw new RuntimeException('Unable to serialize host-agent report.');
    }
    $tmp = tempnam($dir, basename($path) . '.tmp.');
    if (!is_string($tmp) || $tmp === '') {
        throw new RuntimeException('Unable to create host-agent report temp file.');
    }
    file_put_contents($tmp, $json . PHP_EOL, LOCK_EX);
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to publish host-agent report atomically.');
    }
}

function testkit_host_agent_excerpt(string $text, int $lines = 20): ?string
{
    $rows = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
    $rows = array_values(array_filter(array_map('rtrim', $rows), static fn(string $row): bool => $row !== ''));
    return $rows === [] ? null : implode("\n", array_slice($rows, 0, $lines));
}

/** @return array<string,mixed>|null */
function testkit_host_agent_first_failure(array $result, string $suiteId, string $artifactPath): ?array
{
    foreach ((array)($result['commands'] ?? []) as $command) {
        if (!is_array($command) || strtoupper((string)($command['status'] ?? '')) !== 'FAIL') {
            continue;
        }
        $index = (int)($command['command_index'] ?? 0);
        $exit = $command['exit_code'] ?? null;
        return [
            'suite_id' => $suiteId,
            'file' => '',
            'case' => $index > 0 ? 'command_' . $index : 'host_suite_command',
            'kind' => 'host_suite_command_failure',
            'phase' => 'execution',
            'failure_domain' => 'execution',
            'cause_code' => is_int($exit) ? 'exit_code_' . $exit : 'host_suite_failure',
            'exception_class' => null,
            'message' => $index > 0 ? 'Host suite command ' . $index . ' failed.' : 'Host suite failed.',
            'stack_excerpt' => [],
            'artifact_path' => $artifactPath,
        ];
    }
    return null;
}

$args = testkit_host_agent_parse_args($argv);
$projectRoot = testkit_host_agent_project_root();
$testkitRoot = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
$metadata = testkit_host_agent_suite_metadata($projectRoot, $args['config'], $args['suite']);

$runId = gmdate('Ymd\THis\Z') . '_host_' . substr(hash('sha256', $args['suite'] . microtime(true) . random_bytes(8)), 0, 10);
$runRoot = $projectRoot . '/.testkit/reports/runs/' . $runId;
$exchangeDir = $projectRoot . '/.testkit/exchange/host-agent';
if (!is_dir($runRoot) && !mkdir($runRoot, 0777, true) && !is_dir($runRoot)) {
    throw new RuntimeException('Unable to create host-agent run root.');
}
if (!is_dir($exchangeDir) && !mkdir($exchangeDir, 0777, true) && !is_dir($exchangeDir)) {
    throw new RuntimeException('Unable to create host-agent exchange directory.');
}
$resultPath = $exchangeDir . '/' . $runId . '.json';

$execution = testkit_host_agent_execute_suite(
    $testkitRoot,
    $projectRoot,
    $args['config'],
    $args['suite'],
    $args['allow_persistent'],
    $resultPath
);
$result = testkit_host_agent_read_json($resultPath);
$evidenceValid = is_array($result)
    && ($result['schema'] ?? null) === 1
    && ($result['runner'] ?? null) === 'runSuiteConfig'
    && ($result['suite'] ?? null) === $args['suite'];

$status = $evidenceValid && strtoupper((string)($result['status'] ?? '')) === 'PASS' && $execution['exit_code'] === 0 ? 'PASS' : 'FAIL';
$outcome = $status === 'PASS' ? 'passed' : 'failed';
$suiteId = 'host_' . preg_replace('/[^a-z0-9_]+/', '_', strtolower($args['suite']));
$suiteId = trim($suiteId, '_');
$summarySource = is_array($result['summary'] ?? null) ? $result['summary'] : [];
$total = max(1, (int)($summarySource['commands'] ?? 1));
$passed = $status === 'PASS' ? max(1, (int)($summarySource['passed_commands'] ?? $total)) : (int)($summarySource['passed_commands'] ?? 0);
$failed = $status === 'PASS' ? 0 : max(1, (int)($summarySource['failed_commands'] ?? 1));
$summary = [
    'total' => $total,
    'passed' => $passed,
    'failed' => $failed,
    'skipped' => 0,
    'duration_ms' => (int)($summarySource['duration_ms'] ?? $execution['duration_ms']),
];
$reportScopeRel = '.testkit/reports/runs/' . $runId;
$suiteArtifactRel = $reportScopeRel . '/' . $suiteId . '_latest.json';
$firstFailure = $status === 'PASS' || !is_array($result) ? null : testkit_host_agent_first_failure($result, $suiteId, $suiteArtifactRel);
if ($status === 'FAIL' && $firstFailure === null) {
    $firstFailure = [
        'suite_id' => $suiteId,
        'file' => '',
        'case' => 'host_suite',
        'kind' => 'host_suite_failure',
        'phase' => 'execution',
        'failure_domain' => 'execution',
        'cause_code' => 'exit_code_' . $execution['exit_code'],
        'exception_class' => null,
        'message' => $evidenceValid ? 'Host suite failed.' : 'Host suite did not publish valid machine evidence.',
        'stack_excerpt' => [],
        'artifact_path' => $suiteArtifactRel,
    ];
}
$agentMode = [
    'enabled' => true,
    'mode' => 'agent',
    'enforced' => [
        'TESTKIT_MODE' => 'agent',
        'TEST_JOBS' => '1',
        'TEST_DB_STRATEGY' => 'shared',
        'TEST_FAIL_FAST' => '0',
        'TEST_CHILD_FAIL_FAST' => '0',
        'TEST_META_FAIL_FAST' => '0',
        'TESTKIT_PROGRESS_MODE' => 'quiet',
        'NO_COLOR' => '1',
    ],
];
$hostDescriptor = $metadata + ['allow_persistent' => $args['allow_persistent']];
$canonical = [
    'report_version' => 1,
    'report_kind' => 'suite',
    'final_status' => $status,
    'selection' => [
        'suite_id' => $suiteId,
        'target' => $args['suite'],
        'scope' => 'host_suite',
        'category' => 'host',
        'match' => '',
        'selected_test_count' => $total,
        'selected_test_files' => [],
        'selected_module_scope' => 'host-suite/' . $args['suite'],
    ],
    'summary' => $summary,
    'evidence' => [
        'valid' => $evidenceValid,
        'invalid_reason' => $evidenceValid ? null : 'host_suite_machine_result_invalid',
        'first_failure' => $firstFailure,
    ],
    'artifacts' => [
        'report_root' => $runRoot,
        'report_scope_rel' => $reportScopeRel,
        'report_links' => [$suiteArtifactRel],
        'history_file' => null,
        'manifest_path' => '.testkit/reports/latest_run.json',
        'snapshot_file' => null,
    ],
    'agent_mode' => $agentMode,
    'runner' => [
        'contract_version' => 1,
        'capabilities' => ['host_suite_config', 'host_suite_agent'],
        'hazards' => [(string)$metadata['risk']],
        'mode' => 'agent',
        'host_suite' => $hostDescriptor,
    ],
];
$suiteReport = [
    'suite_id' => $suiteId,
    'run_id' => $runId,
    'meta_run_id' => $runId,
    'report_root' => $runRoot,
    'report_scope_rel' => $reportScopeRel,
    'selected_test_count' => $total,
    'selected_module_scope' => 'host-suite/' . $args['suite'],
    'summary' => $summary,
    'suite_status' => strtolower($status === 'PASS' ? 'passed' : 'failed'),
    'outcome_status' => $outcome,
    'tests_total' => $total,
    'pass' => $passed,
    'fail' => $failed,
    'skip' => 0,
    'duration_ms' => $summary['duration_ms'],
    'agent_mode' => $agentMode,
    'host_suite' => $hostDescriptor,
    'canonical_report' => $canonical,
];
$metaReport = [
    'target' => $args['suite'],
    'category' => 'host',
    'run_id' => $runId,
    'meta_run_id' => $runId,
    'run_kind' => 'meta',
    'report_root' => $runRoot,
    'report_scope_rel' => $reportScopeRel,
    'summary' => $summary,
    'suite_status_counts' => [strtolower($status === 'PASS' ? 'passed' : 'failed') => 1],
    'agent_mode' => $agentMode,
    'outcome_status' => $outcome,
    'canonical_report' => array_replace_recursive($canonical, [
        'report_kind' => 'meta',
        'selection' => [
            'suite_id' => null,
            'target' => $args['suite'],
            'scope' => 'host_suite',
            'category' => 'host',
            'match' => '',
            'selected_test_count' => $total,
            'selected_test_files' => [],
            'selected_module_scope' => 'host-suite/' . $args['suite'],
        ],
    ]),
];

testkit_host_agent_write_json($runRoot . '/' . $suiteId . '_latest.json', $suiteReport);
testkit_host_agent_write_json($runRoot . '/meta_latest.json', $metaReport);
testkit_host_agent_write_json($projectRoot . '/.testkit/reports/latest_run.json', [
    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'run_id' => $runId,
    'meta_run_id' => $runId,
    'target' => $args['suite'],
    'report_root' => $runRoot,
    'report_scope_rel' => $reportScopeRel,
]);

$decision = AgentRun::buildLatestDecision($runId, $args['goal']);
$artifact = AgentRunArtifact::record($decision, [
    'executed' => true,
    'kind' => 'run_host_suite',
    'reason' => 'host_suite_agent_initial_execution',
    'admission' => [
        'accepted' => true,
        'schema' => 'host_suite_config/v1',
        'executor' => 'testkit-host-agent',
        'error' => null,
    ],
    'command_spec' => null,
    'command' => [
        'argv' => $execution['argv'],
        'cwd' => '.',
        'env_overrides' => ['TESTKIT_MODE' => 'agent'],
        'display' => 'testkit-host-agent ' . $args['config'] . ' ' . $args['suite'],
    ],
    'result' => [
        'exit_code' => $execution['exit_code'],
        'duration_ms' => $execution['duration_ms'],
        'stdout_excerpt' => testkit_host_agent_excerpt($execution['stdout']),
        'stderr_excerpt' => testkit_host_agent_excerpt($execution['stderr']),
    ],
    'child_payload' => $result,
]);

$payload = [
    'ok' => $status === 'PASS',
    'mode' => 'host_suite_agent',
    'run_id' => $runId,
    'suite' => $args['suite'],
    'risk' => $metadata['risk'],
    'result' => $result,
    'decision' => $decision,
    'artifact' => $artifact,
    'execution' => [
        'exit_code' => $execution['exit_code'],
        'duration_ms' => $execution['duration_ms'],
        'stdout_excerpt' => testkit_host_agent_excerpt($execution['stdout']),
        'stderr_excerpt' => testkit_host_agent_excerpt($execution['stderr']),
    ],
];

if ($args['json']) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    if ($execution['stdout'] !== '') {
        fwrite(STDOUT, $execution['stdout']);
    }
    if ($execution['stderr'] !== '') {
        fwrite(STDERR, $execution['stderr']);
    }
    printf(
        "host-agent suite=%s status=%s run_id=%s next_action=%s\n",
        $args['suite'],
        $status,
        $runId,
        (string)($decision['next_action']['kind'] ?? 'no_action')
    );
}

exit($status === 'PASS' ? 0 : max(1, $execution['exit_code']));
