<?php
declare(strict_types=1);

/**
 * Resolve whether the local executor is eligible for a host-owned remote request.
 *
 * TestKit owns reusable routing semantics. The host remains responsible for
 * polling, distributed claim, exact-SHA synchronization, execution and report
 * publication.
 */

function tk_route_fail(string $code, string $message, bool $json): never
{
    $payload = [
        'schema' => 'testkit.remote-request-route.v1',
        'status' => 'ERROR',
        'code' => $code,
        'message' => $message,
    ];
    if ($json) {
        fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    } else {
        fwrite(STDERR, "REMOTE_REQUEST_ROUTE_ERROR {$code}: {$message}" . PHP_EOL);
    }
    exit(2);
}

/** @return array{request:string,json:bool} */
function tk_route_args(array $argv): array
{
    $json = false;
    $positionals = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--json') { $json = true; continue; }
        if ($arg === '--help' || $arg === '-h' || $arg === 'help') {
            fwrite(STDOUT, "Usage:\n  testkit-remote-request-route <request.json> [--json]\n");
            exit(0);
        }
        if (str_starts_with($arg, '--')) {
            tk_route_fail('unsupported_option', 'Unsupported option: ' . $arg, $json);
        }
        $positionals[] = $arg;
    }
    if (count($positionals) !== 1) {
        tk_route_fail('invalid_arguments', 'Expected <request.json>.', $json);
    }
    return ['request' => $positionals[0], 'json' => $json];
}

function tk_route_project_root(): string
{
    $candidate = trim((string)(getenv('TESTKIT_PROJECT_ROOT') ?: ''));
    if ($candidate === '') $candidate = getcwd() ?: '';
    $real = realpath($candidate);
    if (!is_string($real) || $real === '' || !is_dir($real)) {
        throw new RuntimeException('TESTKIT_PROJECT_ROOT does not resolve to a directory.');
    }
    return str_replace('\\', '/', $real);
}

function tk_route_project_file(string $projectRoot, string $path): string
{
    if ($path === '' || str_contains($path, "\0")) {
        throw new RuntimeException('Request path is empty or invalid.');
    }
    $candidate = str_starts_with($path, '/') ? $path : $projectRoot . '/' . $path;
    $real = realpath($candidate);
    if (!is_string($real) || $real === '' || !is_file($real)) {
        throw new RuntimeException('Request file not found: ' . $path);
    }
    $normalized = str_replace('\\', '/', $real);
    if ($normalized !== $projectRoot && !str_starts_with($normalized, rtrim($projectRoot, '/') . '/')) {
        throw new RuntimeException('Request file escapes TESTKIT_PROJECT_ROOT: ' . $path);
    }
    return $normalized;
}

function tk_route_local_platform(): string
{
    $override = strtolower(trim((string)(getenv('TESTKIT_REMOTE_PLATFORM') ?: '')));
    if ($override !== '') {
        if (!in_array($override, ['linux', 'windows', 'macos'], true)) {
            throw new RuntimeException('TESTKIT_REMOTE_PLATFORM is invalid.');
        }
        return $override;
    }

    return match (PHP_OS_FAMILY) {
        'Linux' => 'linux',
        'Windows' => 'windows',
        'Darwin' => 'macos',
        default => 'unknown',
    };
}

/**
 * @return array{schema:int,enabled:bool,request_id:string,mode:string,target:?string,platform:?string}
 */
function tk_route_request(string $path): array
{
    $raw = file_get_contents($path);
    $request = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($request) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Remote request is not valid JSON.');
    }

    foreach (['schema', 'enabled', 'request_id'] as $key) {
        if (!array_key_exists($key, $request)) {
            throw new RuntimeException('Remote request is missing field: ' . $key);
        }
    }
    if (!is_int($request['schema']) || !is_bool($request['enabled'])) {
        throw new RuntimeException('Remote request schema/enabled contract is invalid.');
    }
    if (!is_string($request['request_id']) || preg_match('/^[A-Za-z0-9._-]+$/', $request['request_id']) !== 1) {
        throw new RuntimeException('Remote request field is invalid: request_id');
    }

    if (in_array($request['schema'], [1, 2], true)) {
        if (!array_key_exists('target', $request) || !is_string($request['target']) || preg_match('/^[A-Za-z0-9._-]+$/', $request['target']) !== 1) {
            throw new RuntimeException('Legacy remote request field is invalid: target');
        }
        return [
            'schema' => $request['schema'],
            'enabled' => $request['enabled'],
            'request_id' => $request['request_id'],
            'mode' => 'target',
            'target' => $request['target'],
            'platform' => null,
        ];
    }

    if ($request['schema'] === 3) {
        if (array_key_exists('target', $request)) {
            throw new RuntimeException('Schema 3 must not contain target.');
        }
        if (!array_key_exists('platform', $request) || !is_string($request['platform'])) {
            throw new RuntimeException('Schema 3 requires platform.');
        }
        $platform = strtolower($request['platform']);
        if (!in_array($platform, ['any', 'linux', 'windows', 'macos'], true)) {
            throw new RuntimeException('Schema 3 platform is invalid.');
        }
        return [
            'schema' => 3,
            'enabled' => $request['enabled'],
            'request_id' => $request['request_id'],
            'mode' => 'platform',
            'target' => null,
            'platform' => $platform,
        ];
    }

    throw new RuntimeException('Unsupported remote request schema.');
}

function tk_route_emit(array $payload, bool $json): void
{
    if ($json) {
        fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
        return;
    }
    fwrite(STDOUT, sprintf(
        "REMOTE_REQUEST_ROUTE status=%s request=%s mode=%s\n",
        $payload['status'] ?? 'UNKNOWN',
        $payload['request_id'] ?? '-',
        $payload['mode'] ?? '-'
    ));
}

$args = tk_route_args($argv);
try {
    $projectRoot = tk_route_project_root();
    $requestFile = tk_route_project_file($projectRoot, $args['request']);
    $request = tk_route_request($requestFile);
    $localPlatform = tk_route_local_platform();
    $localTarget = trim((string)(getenv('TESTKIT_REMOTE_TARGET') ?: ''));
    if ($localTarget === '') $localTarget = (string)(gethostname() ?: '');

    $base = [
        'schema' => 'testkit.remote-request-route.v1',
        'request_schema' => $request['schema'],
        'request_id' => $request['request_id'],
        'mode' => $request['mode'],
        'selector' => $request['mode'] === 'platform'
            ? ['platform' => $request['platform']]
            : ['target' => $request['target']],
        'local' => [
            'platform' => $localPlatform,
            'target' => $localTarget,
        ],
    ];

    if (!$request['enabled']) {
        tk_route_emit($base + ['status' => 'DISABLED'], $args['json']);
        exit(0);
    }

    if ($request['mode'] === 'target') {
        if ($localTarget === '' || !hash_equals((string)$request['target'], $localTarget)) {
            tk_route_emit($base + ['status' => 'TARGET_MISMATCH'], $args['json']);
            exit(0);
        }
        tk_route_emit($base + ['status' => 'ELIGIBLE'], $args['json']);
        exit(0);
    }

    $requestedPlatform = (string)$request['platform'];
    if ($requestedPlatform !== 'any' && !hash_equals($requestedPlatform, $localPlatform)) {
        tk_route_emit($base + ['status' => 'PLATFORM_MISMATCH'], $args['json']);
        exit(0);
    }

    tk_route_emit($base + ['status' => 'ELIGIBLE'], $args['json']);
    exit(0);
} catch (Throwable $e) {
    tk_route_fail('contract_error', $e->getMessage(), $args['json']);
}
