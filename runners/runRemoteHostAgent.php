<?php
declare(strict_types=1);

/**
 * Execute one host-owned, allowlisted suite selected by a versioned remote request.
 *
 * TestKit owns request validation, risk admission and canonical host-agent execution.
 * The host owns the request file, suite catalog, polling/sync and publication policy.
 */

function tk_remote_fail(string $code, string $message, bool $json): never
{
    $payload = [
        'schema' => 'testkit.remote-host-agent.v1',
        'status' => 'ERROR',
        'code' => $code,
        'message' => $message,
    ];
    if ($json) {
        fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    } else {
        fwrite(STDERR, "REMOTE_HOST_AGENT_ERROR {$code}: {$message}" . PHP_EOL);
    }
    exit(2);
}

/** @return array{config:string,request:string,json:bool,admit_only:bool,allow_disposable:bool,allow_network:bool,allow_persistent:bool,allow_hardware:bool} */
function tk_remote_args(array $argv): array
{
    $json = false;
    $admitOnly = false;
    $flags = [
        'allow_disposable' => false,
        'allow_network' => false,
        'allow_persistent' => false,
        'allow_hardware' => false,
    ];
    $positionals = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--json') { $json = true; continue; }
        if ($arg === '--admit-only') { $admitOnly = true; continue; }
        if ($arg === '--allow-disposable') { $flags['allow_disposable'] = true; continue; }
        if ($arg === '--allow-network') { $flags['allow_network'] = true; continue; }
        if ($arg === '--allow-persistent') { $flags['allow_persistent'] = true; continue; }
        if ($arg === '--allow-hardware') { $flags['allow_hardware'] = true; continue; }
        if ($arg === '--help' || $arg === '-h' || $arg === 'help') {
            fwrite(STDOUT, "Usage:\n  testkit-remote-host-agent <config.php> <request.json> [--json] [--admit-only] [--allow-disposable] [--allow-network] [--allow-persistent] [--allow-hardware]\n");
            exit(0);
        }
        if (str_starts_with($arg, '--')) {
            tk_remote_fail('unsupported_option', 'Unsupported option: ' . $arg, $json);
        }
        $positionals[] = $arg;
    }
    if (count($positionals) !== 2) {
        tk_remote_fail('invalid_arguments', 'Expected <config.php> <request.json>.', $json);
    }
    return [
        'config' => $positionals[0],
        'request' => $positionals[1],
        'json' => $json,
        'admit_only' => $admitOnly,
        ...$flags,
    ];
}

function tk_remote_project_root(): string
{
    $candidate = trim((string)(getenv('TESTKIT_PROJECT_ROOT') ?: ''));
    if ($candidate === '') $candidate = getcwd() ?: '';
    $real = realpath($candidate);
    if (!is_string($real) || $real === '' || !is_dir($real)) {
        throw new RuntimeException('TESTKIT_PROJECT_ROOT does not resolve to a directory.');
    }
    return str_replace('\\', '/', $real);
}

function tk_remote_project_file(string $projectRoot, string $path): string
{
    if ($path === '' || str_contains($path, "\0")) {
        throw new RuntimeException('Host path is empty or invalid.');
    }
    $candidate = str_starts_with($path, '/') ? $path : $projectRoot . '/' . $path;
    $real = realpath($candidate);
    if (!is_string($real) || $real === '' || !is_file($real)) {
        throw new RuntimeException('Host file not found: ' . $path);
    }
    $normalized = str_replace('\\', '/', $real);
    if ($normalized !== $projectRoot && !str_starts_with($normalized, rtrim($projectRoot, '/') . '/')) {
        throw new RuntimeException('Host file escapes TESTKIT_PROJECT_ROOT: ' . $path);
    }
    return $normalized;
}

/** @return array{schema:int,enabled:bool,request_id:string,target:string,suite:string} */
function tk_remote_request(string $path): array
{
    $raw = file_get_contents($path);
    $request = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($request) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Remote request is not valid JSON.');
    }
    $allowed = ['schema', 'enabled', 'request_id', 'target', 'suite'];
    foreach (array_keys($request) as $key) {
        if (!is_string($key) || !in_array($key, $allowed, true)) {
            throw new RuntimeException('Remote request contains an unsupported field.');
        }
    }
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $request)) {
            throw new RuntimeException('Remote request is missing field: ' . $key);
        }
    }
    if ($request['schema'] !== 1 || !is_bool($request['enabled'])) {
        throw new RuntimeException('Remote request schema/enabled contract is invalid.');
    }
    foreach (['request_id', 'target', 'suite'] as $key) {
        if (!is_string($request[$key]) || preg_match('/^[A-Za-z0-9._-]+$/', $request[$key]) !== 1) {
            throw new RuntimeException('Remote request field is invalid: ' . $key);
        }
    }
    return $request;
}

function tk_remote_relative_host_path(string $value, string $field): string
{
    $value = str_replace('\\', '/', trim($value));
    if ($value === '' || str_contains($value, "\0") || str_starts_with($value, '/') || preg_match('/^[A-Za-z]:/', $value) === 1) {
        throw new RuntimeException('Remote host-native ' . $field . ' must be a relative project path.');
    }
    $segments = explode('/', $value);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new RuntimeException('Remote host-native ' . $field . ' contains an unsafe path segment.');
        }
    }
    if (preg_match('/^[A-Za-z0-9._\/-]+$/', $value) !== 1) {
        throw new RuntimeException('Remote host-native ' . $field . ' contains unsupported characters.');
    }
    return $value;
}

/** @return array{risk:string,required:bool,requires:array<int,string>,execution_backend:string,host_native:?array{kind:string,script:string,result_file:string}} */
function tk_remote_suite_metadata(string $configFile, string $suiteKey): array
{
    $config = require $configFile;
    if (!is_array($config) || !is_array($config['suites'] ?? null)) {
        throw new RuntimeException('Host suite config is invalid.');
    }
    foreach ($config['suites'] as $suite) {
        if (!is_array($suite) || (string)($suite['key'] ?? '') !== $suiteKey) continue;
        if (($suite['required'] ?? null) !== true) {
            throw new RuntimeException('Remote suites must be required=true.');
        }
        $risk = (string)($suite['risk'] ?? '');
        $allowed = ['safe', 'disposable', 'persistent', 'hardware'];
        if (!in_array($risk, $allowed, true)) {
            throw new RuntimeException('Remote suite risk is unsupported: ' . $risk);
        }

        $executionBackend = (string)($suite['execution_backend'] ?? 'container');
        if (!in_array($executionBackend, ['container', 'host_native'], true)) {
            throw new RuntimeException('Remote suite execution_backend is unsupported: ' . $executionBackend);
        }

        $hostNative = null;
        if ($executionBackend === 'host_native') {
            $candidate = $suite['host_native'] ?? null;
            if (!is_array($candidate)) {
                throw new RuntimeException('Remote host-native suite requires host_native metadata.');
            }
            $kind = (string)($candidate['kind'] ?? '');
            if ($kind !== 'powershell') {
                throw new RuntimeException('Remote host-native kind is unsupported: ' . $kind);
            }
            $script = tk_remote_relative_host_path((string)($candidate['script'] ?? ''), 'script');
            if (!str_ends_with(strtolower($script), '.ps1')) {
                throw new RuntimeException('Remote host-native script must end with .ps1.');
            }
            $resultFile = tk_remote_relative_host_path((string)($candidate['result_file'] ?? ''), 'result_file');
            if (!str_ends_with(strtolower($resultFile), '.json')) {
                throw new RuntimeException('Remote host-native result_file must end with .json.');
            }
            $hostNative = [
                'kind' => $kind,
                'script' => $script,
                'result_file' => $resultFile,
            ];
        }

        return [
            'risk' => $risk,
            'required' => true,
            'requires' => array_values(array_map('strval', (array)($suite['requires'] ?? []))),
            'execution_backend' => $executionBackend,
            'host_native' => $hostNative,
        ];
    }
    throw new RuntimeException('Requested suite is not allowlisted by the host config.');
}

function tk_remote_risk_allowed(string $risk, array $args): bool
{
    return match ($risk) {
        'safe' => true,
        'disposable' => $args['allow_disposable'],
        'persistent' => $args['allow_persistent'],
        'hardware' => $args['allow_hardware'],
        default => false,
    };
}

/** @return array{exit_code:int,stdout:string,stderr:string} */
function tk_remote_execute(string $testkitRoot, string $projectRoot, string $configArg, string $suite, string $requestId, bool $allowPersistent): array
{
    $argv = ['bash', $testkitRoot . '/bin/testkit-host-agent', $configArg, $suite, '--goal=remote:' . $requestId, '--json'];
    if ($allowPersistent) $argv[] = '--allow-persistent';
    $out = tempnam(sys_get_temp_dir(), 'tk-remote-out-');
    $err = tempnam(sys_get_temp_dir(), 'tk-remote-err-');
    if (!is_string($out) || !is_string($err)) throw new RuntimeException('Unable to create capture files.');
    $env = getenv();
    $processEnv = is_array($env) ? $env : [];
    $processEnv['TESTKIT_PROJECT_ROOT'] = $projectRoot;
    $process = proc_open($argv, [0 => STDIN, 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, $testkitRoot, $processEnv);
    if (!is_resource($process)) {
        @unlink($out); @unlink($err);
        throw new RuntimeException('Unable to start testkit-host-agent.');
    }
    $exit = proc_close($process);
    $stdout = (string)@file_get_contents($out);
    $stderr = (string)@file_get_contents($err);
    @unlink($out); @unlink($err);
    return ['exit_code' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
}

function tk_remote_emit(array $payload, bool $json): void
{
    if ($json) {
        fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
        return;
    }
    fwrite(STDOUT, sprintf("REMOTE_HOST_AGENT status=%s request=%s suite=%s\n", $payload['status'] ?? 'UNKNOWN', $payload['request_id'] ?? '-', $payload['suite'] ?? '-'));
}

$args = tk_remote_args($argv);
try {
    $projectRoot = tk_remote_project_root();
    $configFile = tk_remote_project_file($projectRoot, $args['config']);
    $requestFile = tk_remote_project_file($projectRoot, $args['request']);
    $request = tk_remote_request($requestFile);

    $base = [
        'schema' => 'testkit.remote-host-agent.v1',
        'request_id' => $request['request_id'],
        'target' => $request['target'],
        'suite' => $request['suite'],
    ];
    if (!$request['enabled']) {
        tk_remote_emit($base + ['status' => 'DISABLED', 'risk' => null, 'evidence' => null], $args['json']);
        exit(0);
    }

    $localTarget = trim((string)(getenv('TESTKIT_REMOTE_TARGET') ?: ''));
    if ($localTarget === '') $localTarget = (string)(gethostname() ?: '');
    if ($localTarget === '' || !hash_equals($request['target'], $localTarget)) {
        tk_remote_emit($base + ['status' => 'TARGET_MISMATCH', 'local_target' => $localTarget, 'risk' => null, 'evidence' => null], $args['json']);
        exit(0);
    }

    $metadata = tk_remote_suite_metadata($configFile, $request['suite']);
    if (!tk_remote_risk_allowed($metadata['risk'], $args)) {
        tk_remote_fail('risk_not_allowed', 'Remote suite risk requires explicit local opt-in: ' . $metadata['risk'], $args['json']);
    }
    if (in_array('network', $metadata['requires'], true) && !$args['allow_network']) {
        tk_remote_fail('network_not_allowed', 'Remote suite requires network and needs explicit local --allow-network.', $args['json']);
    }

    if ($args['admit_only']) {
        tk_remote_emit($base + [
            'status' => 'ADMITTED',
            'risk' => $metadata['risk'],
            'requires' => $metadata['requires'],
            'execution_backend' => $metadata['execution_backend'],
            'host_native' => $metadata['host_native'],
            'evidence' => null,
        ], $args['json']);
        exit(0);
    }

    if ($metadata['execution_backend'] !== 'container') {
        tk_remote_fail('host_native_requires_bridge', 'Host-native suites must execute through the PowerShell host-native bridge.', $args['json']);
    }

    $testkitRoot = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
    $execution = tk_remote_execute($testkitRoot, $projectRoot, $args['config'], $request['suite'], $request['request_id'], $args['allow_persistent']);
    $evidence = json_decode(trim($execution['stdout']), true);
    if (!is_array($evidence)) {
        tk_remote_fail('invalid_evidence', 'testkit-host-agent did not return valid JSON evidence.', $args['json']);
    }
    $pass = $execution['exit_code'] === 0 && strtoupper((string)($evidence['status'] ?? '')) === 'PASS';
    tk_remote_emit($base + [
        'status' => $pass ? 'PASS' : 'FAIL',
        'risk' => $metadata['risk'],
        'requires' => $metadata['requires'],
        'execution_backend' => 'container',
        'exit_code' => $execution['exit_code'],
        'evidence' => $evidence,
        'stderr_excerpt' => $execution['stderr'] === '' ? null : substr($execution['stderr'], 0, 2000),
    ], $args['json']);
    exit($pass ? 0 : max(1, min(255, $execution['exit_code'])));
} catch (Throwable $e) {
    tk_remote_fail('contract_error', $e->getMessage(), $args['json']);
}
