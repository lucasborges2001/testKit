<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$bridge = $root . '/bin/testkit-remote-host-native-agent';
$capabilities = $root . '/lib/bash/executor_capabilities.sh';
$runner = $root . '/runners/runRemoteHostAgent.php';
$errors = [];
$assert = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) $errors[] = $message;
};

$assert(is_file($bridge), 'Bash host-native remote agent entrypoint missing');
$assert(is_file($capabilities), 'Executor capabilities helper missing');
$source = is_file($bridge) ? (string)file_get_contents($bridge) : '';
$capabilitySource = is_file($capabilities) ? (string)file_get_contents($capabilities) : '';
foreach ([
    'set -euo pipefail',
    'executor_capabilities.sh',
    'testkit_executor_capabilities_probe',
    'executor_capability_missing',
    'executor_capabilities',
    'capabilities',
    'compose -f "$COMPOSE_FILE"',
    'run --rm --no-deps -T',
    'TESTKIT_WRAPPER_KIND=bash',
    '/workspace/testkit/runners/runRemoteHostAgent.php',
    '--admit-only',
    'host_native',
    'resolve_project_path',
    'realpath',
    '/usr/bin/env bash "$script_path"',
    '.testkit/remote-host-native',
    'missing_result_file',
    'stdout_present',
    'stderr_present',
] as $fragment) {
    $assert(str_contains($source, $fragment), 'missing Bash host-native bridge fragment: ' . $fragment);
}
foreach (['docker-daemon', 'docker-compose', 'writable-tmp', 'sha256sum', 'python3', 'flock'] as $fragment) {
    $assert(str_contains($capabilitySource, $fragment), 'missing executor capability: ' . $fragment);
}
foreach (['--allow-disposable', '--allow-network', '--allow-persistent', '--allow-hardware'] as $flag) {
    $assert(str_contains($source, $flag), 'missing local risk opt-in: ' . $flag);
}
foreach (['eval ', 'git pull', 'git reset', 'curl ', 'wget ', 'bash -c'] as $forbidden) {
    $assert(!str_contains($source, $forbidden), 'Bash host-native bridge contains forbidden fragment: ' . $forbidden);
}

$runnerSource = is_file($runner) ? (string)file_get_contents($runner) : '';
$assert(str_contains($runnerSource, "['powershell', 'bash']"), 'runner must admit powershell and bash kinds');
$assert(str_contains($runnerSource, "? '.ps1' : '.sh'"), 'runner must bind extension to host-native kind');
$assert(str_contains($runnerSource, 'matching host-native bridge'), 'direct host-native execution must remain blocked');

if ($errors !== []) {
    fwrite(STDERR, "Remote host-native Bash contract failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "PASS remote_host_native_bash admission=container host_execution=bash allowlisted_paths=1 result_json=1 executor_capabilities=1 arbitrary_eval=0 git_sync=0\n";
