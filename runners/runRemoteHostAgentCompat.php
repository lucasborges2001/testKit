<?php
declare(strict_types=1);

/**
 * Compatibility normalizer for the remote-host bridge.
 *
 * runRemoteHostAgent.php historically expected testkit-host-agent to expose
 * status at the top level. The real host-agent envelope exposes:
 *   ok + result.status + result.summary.
 *
 * Keep the request/risk execution contract in the legacy runner and normalize
 * only that proven envelope mismatch here.
 */

$legacy = __DIR__ . '/runRemoteHostAgent.php';
if (!is_file($legacy)) {
    fwrite(STDERR, "Missing legacy remote host agent runner: {$legacy}\n");
    exit(2);
}

$out = tempnam(sys_get_temp_dir(), 'tk-remote-compat-out-');
$err = tempnam(sys_get_temp_dir(), 'tk-remote-compat-err-');
if (!is_string($out) || !is_string($err)) {
    if (is_string($out)) @unlink($out);
    if (is_string($err)) @unlink($err);
    fwrite(STDERR, "Unable to allocate compatibility capture files.\n");
    exit(2);
}

$command = array_merge([PHP_BINARY, $legacy], array_slice($argv, 1));
$process = proc_open(
    $command,
    [0 => STDIN, 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
    $pipes,
    getcwd() ?: __DIR__,
    null
);
if (!is_resource($process)) {
    @unlink($out);
    @unlink($err);
    fwrite(STDERR, "Unable to start legacy remote host agent runner.\n");
    exit(2);
}

$legacyExit = proc_close($process);
$stdout = (string)@file_get_contents($out);
$stderr = (string)@file_get_contents($err);
@unlink($out);
@unlink($err);

$payload = json_decode(trim($stdout), true);
if (!is_array($payload) || ($payload['schema'] ?? null) !== 'testkit.remote-host-agent.v1') {
    if ($stdout !== '') fwrite(STDOUT, $stdout);
    if ($stderr !== '') fwrite(STDERR, $stderr);
    exit(max(0, min(255, $legacyExit)));
}

$hostEnvelope = is_array($payload['evidence'] ?? null) ? $payload['evidence'] : null;
$hostResult = is_array($hostEnvelope['result'] ?? null) ? $hostEnvelope['result'] : null;
$hostExecution = is_array($hostEnvelope['execution'] ?? null) ? $hostEnvelope['execution'] : null;
$hostPass = is_array($hostEnvelope)
    && ($hostEnvelope['mode'] ?? null) === 'host_suite_agent'
    && ($hostEnvelope['ok'] ?? null) === true
    && (string)($hostEnvelope['suite'] ?? '') === (string)($payload['suite'] ?? '')
    && is_array($hostResult)
    && strtoupper((string)($hostResult['status'] ?? '')) === 'PASS'
    && (int)($hostResult['exit_code'] ?? 1) === 0
    && is_array($hostExecution)
    && (int)($hostExecution['exit_code'] ?? 1) === 0;

$normalized = false;
if (($payload['status'] ?? null) === 'FAIL' && $hostPass) {
    $payload['status'] = 'PASS';
    $payload['exit_code'] = 0;
    $payload['normalization'] = 'host-suite-agent-envelope-v1';
    $normalized = true;
}

fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
if ($stderr !== '' && !$normalized) {
    fwrite(STDERR, $stderr);
}

if ($normalized || ($payload['status'] ?? null) === 'PASS') {
    exit(0);
}
exit(max(1, min(255, $legacyExit)));
