#!/usr/bin/env php
<?php
declare(strict_types=1);

const SQLOBS_RUN_SCHEMA = 'testkit-sql-observability-run-manifest-v1';

/** @return array<string,mixed> */
function sqlobs_manifest_args(array $argv): array
{
    $out = ['artifact' => [], 'exit' => [], 'limitation' => []];
    $allowed = [
        'output','run-id','repository','commit-sha','branch','event-name','scenario-id',
        'repetition','target','test-match','module-id','dataset-id','dataset-version',
        'dataset-hash','environment-id','engine-version','started-at','finished-at',
        'requested-gate-mode','effective-gate-mode','gate-mode-source','artifact','exit',
        'limitation','testkit-commit','base-commit','docker-image','baseline-status',
    ];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = (string)$argv[$i];
        if ($arg === '--help') {
            $out['help'] = true;
            continue;
        }
        if (!str_starts_with($arg, '--')) {
            throw new InvalidArgumentException('Unexpected positional argument.');
        }
        $raw = substr($arg, 2);
        [$key, $value] = str_contains($raw, '=') ? explode('=', $raw, 2) : [$raw, null];
        if (!in_array($key, $allowed, true)) {
            throw new InvalidArgumentException('Unknown option --' . $key);
        }
        if ($value === null) {
            if (!isset($argv[$i + 1]) || str_starts_with((string)$argv[$i + 1], '--')) {
                throw new InvalidArgumentException('Missing value for --' . $key);
            }
            $value = (string)$argv[++$i];
        }
        if (in_array($key, ['artifact','exit','limitation'], true)) {
            $out[$key][] = $value;
        } else {
            $out[$key] = $value;
        }
    }
    return $out;
}

function sqlobs_manifest_usage(): void
{
    echo "Build testkit-sql-observability-run-manifest-v1.\n";
    echo "Repeat --artifact name=path, --exit name=code and --limitation text.\n";
}

function sqlobs_manifest_string(array $args, string $key, int $max = 300, bool $required = true): string
{
    $value = trim((string)($args[$key] ?? ''));
    if ($required && $value === '') {
        throw new InvalidArgumentException('--' . $key . ' is required.');
    }
    if (strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
        throw new InvalidArgumentException('--' . $key . ' is invalid.');
    }
    return $value;
}

function sqlobs_manifest_id(array $args, string $key, int $max = 160): string
{
    $value = sqlobs_manifest_string($args, $key, $max);
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/', $value) !== 1) {
        throw new InvalidArgumentException('--' . $key . ' is not a safe identifier.');
    }
    return $value;
}

function sqlobs_manifest_time(array $args, string $key): string
{
    $value = sqlobs_manifest_string($args, $key, 40);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
    if (!$dt || $dt->format('Y-m-d\TH:i:s\Z') !== $value) {
        throw new InvalidArgumentException('--' . $key . ' must be UTC RFC3339 seconds.');
    }
    return $value;
}

function sqlobs_manifest_safe_path(string $path, string $base): string
{
    $real = realpath($path);
    if ($real === false || !is_file($real) || is_link($path)) {
        throw new RuntimeException('Artifact is missing or unsafe: ' . basename($path));
    }
    $real = str_replace('\\', '/', $real);
    $base = rtrim(str_replace('\\', '/', $base), '/');
    if ($real === $base || str_starts_with($real, $base . '/')) {
        return ltrim(substr($real, strlen($base)), '/');
    }
    $repo = realpath(dirname(__DIR__, 2));
    if ($repo !== false) {
        $repo = rtrim(str_replace('\\', '/', $repo), '/');
        if ($real === $repo || str_starts_with($real, $repo . '/')) {
            return ltrim(substr($real, strlen($repo)), '/');
        }
    }
    return basename($real);
}

function sqlobs_manifest_atomic(string $path, array $payload): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create manifest directory.');
    }
    if (is_link($path)) {
        throw new RuntimeException('Refusing to replace a symlink.');
    }
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    $tmp = tempnam($dir, '.sqlobs-manifest-');
    if ($tmp === false) {
        throw new RuntimeException('Unable to allocate manifest temporary file.');
    }
    try {
        $handle = fopen($tmp, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open manifest temporary file.');
        }
        fwrite($handle, $json);
        fflush($handle);
        if (function_exists('fsync')) {
            fsync($handle);
        }
        fclose($handle);
        chmod($tmp, 0640);
        if (!rename($tmp, $path)) {
            throw new RuntimeException('Unable to publish manifest.');
        }
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

try {
    $args = sqlobs_manifest_args($argv);
    if (!empty($args['help'])) {
        sqlobs_manifest_usage();
        exit(0);
    }
    $output = sqlobs_manifest_string($args, 'output', 1000);
    $outputAbsolute = str_starts_with($output, '/') ? $output : getcwd() . '/' . $output;
    $outputDir = dirname($outputAbsolute);
    $started = sqlobs_manifest_time($args, 'started-at');
    $finished = sqlobs_manifest_time($args, 'finished-at');
    $startTs = strtotime($started);
    $finishTs = strtotime($finished);
    if ($startTs === false || $finishTs === false || $finishTs < $startTs) {
        throw new InvalidArgumentException('Invalid run time range.');
    }

    $artifacts = [];
    $hashes = [];
    foreach ((array)$args['artifact'] as $entry) {
        if (!str_contains((string)$entry, '=')) {
            throw new InvalidArgumentException('--artifact must be name=path.');
        }
        [$name, $path] = explode('=', (string)$entry, 2);
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1 || isset($artifacts[$name])) {
            throw new InvalidArgumentException('Invalid or duplicate artifact name.');
        }
        $safePath = sqlobs_manifest_safe_path($path, $outputDir);
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new RuntimeException('Unable to hash artifact.');
        }
        $artifacts[$name] = $safePath;
        $hashes[$name] = $hash;
    }

    $exitCodes = [];
    foreach ((array)$args['exit'] as $entry) {
        if (!preg_match('/^([a-z][a-z0-9_]{0,63})=(-?\d+)$/', (string)$entry, $m)) {
            throw new InvalidArgumentException('--exit must be name=integer.');
        }
        $exitCodes[$m[1]] = (int)$m[2];
    }
    ksort($artifacts);
    ksort($hashes);
    ksort($exitCodes);

    $repetitionRaw = sqlobs_manifest_string($args, 'repetition', 2);
    if (!ctype_digit($repetitionRaw) || (int)$repetitionRaw < 1 || (int)$repetitionRaw > 5) {
        throw new InvalidArgumentException('--repetition must be 1..5.');
    }
    $datasetHash = strtolower(sqlobs_manifest_string($args, 'dataset-hash', 64));
    if (preg_match('/^[a-f0-9]{64}$/', $datasetHash) !== 1) {
        throw new InvalidArgumentException('--dataset-hash must be SHA-256.');
    }
    $commit = strtolower(sqlobs_manifest_string($args, 'commit-sha', 64));
    if ($commit !== 'unknown' && preg_match('/^[a-f0-9]{7,64}$/', $commit) !== 1) {
        throw new InvalidArgumentException('--commit-sha is invalid.');
    }

    $payload = [
        'schema_version' => SQLOBS_RUN_SCHEMA,
        'run_id' => sqlobs_manifest_id($args, 'run-id'),
        'repository' => sqlobs_manifest_string($args, 'repository', 240),
        'commit_sha' => $commit,
        'branch' => sqlobs_manifest_string($args, 'branch', 160),
        'event_name' => sqlobs_manifest_id($args, 'event-name', 80),
        'scenario_id' => sqlobs_manifest_id($args, 'scenario-id', 80),
        'repetition' => (int)$repetitionRaw,
        'target' => sqlobs_manifest_id($args, 'target', 40),
        'test_match' => sqlobs_manifest_string($args, 'test-match', 300),
        'module_id' => sqlobs_manifest_id($args, 'module-id', 160),
        'dataset_id' => sqlobs_manifest_id($args, 'dataset-id', 80),
        'dataset_version' => sqlobs_manifest_id($args, 'dataset-version', 80),
        'dataset_hash' => $datasetHash,
        'environment_id' => sqlobs_manifest_id($args, 'environment-id', 160),
        'engine_version' => sqlobs_manifest_string($args, 'engine-version', 80),
        'testkit_commit' => sqlobs_manifest_string($args, 'testkit-commit', 64, false),
        'base_commit' => sqlobs_manifest_string($args, 'base-commit', 64, false),
        'docker_image' => sqlobs_manifest_string($args, 'docker-image', 160, false),
        'baseline_status' => sqlobs_manifest_id($args, 'baseline-status', 80),
        'gate_mode' => [
            'requested' => sqlobs_manifest_id($args, 'requested-gate-mode', 20),
            'effective' => sqlobs_manifest_id($args, 'effective-gate-mode', 20),
            'source' => sqlobs_manifest_id($args, 'gate-mode-source', 80),
        ],
        'started_at' => $started,
        'finished_at' => $finished,
        'duration_ms' => ($finishTs - $startTs) * 1000,
        'exit_codes' => $exitCodes,
        'artifacts' => $artifacts,
        'hashes' => $hashes,
        'limitations' => array_values(array_unique(array_map(
            static fn(string $v): string => substr(trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', $v) ?? ''), 0, 500),
            (array)$args['limitation']
        ))),
    ];

    sqlobs_manifest_atomic($outputAbsolute, $payload);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'ERROR[run_manifest_contract] ' . $e->getMessage() . PHP_EOL);
    exit(3);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR[run_manifest_operational] ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
