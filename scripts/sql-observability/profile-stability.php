#!/usr/bin/env php
<?php
declare(strict_types=1);

const SQLOBS_PROFILE_STABILITY_SCHEMA = 'testkit-sql-observability-profile-stability-v1';
const SQLOBS_RUN_MANIFEST_SCHEMA = 'testkit-sql-observability-run-manifest-v1';

function stability_args(array $argv): array
{
    $out = ['repetitions' => 3];
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
        if (!in_array($key, ['scenario','runs','output','repetitions'], true)) {
            throw new InvalidArgumentException('Unknown option --' . $key);
        }
        if ($value === null) {
            if (!isset($argv[$i + 1]) || str_starts_with((string)$argv[$i + 1], '--')) {
                throw new InvalidArgumentException('Missing value for --' . $key);
            }
            $value = (string)$argv[++$i];
        }
        $out[$key] = $value;
    }
    return $out;
}

function stability_json(string $path): array
{
    if (!is_file($path) || is_link($path) || filesize($path) > 10_485_760) {
        throw new RuntimeException('Artifact unavailable or unsafe: ' . basename($path));
    }
    try {
        $data = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new InvalidArgumentException('Invalid JSON: ' . basename($path));
    }
    if (!is_array($data) || array_is_list($data)) {
        throw new InvalidArgumentException('Expected object: ' . basename($path));
    }
    return $data;
}

/** @param array<string,mixed> $profile @return list<string> */
function stability_identities(array $profile): array
{
    $identities = [];
    foreach ((array)($profile['queries'] ?? []) as $query) {
        if (!is_array($query)) {
            continue;
        }
        $queryIds = array_values(array_filter((array)($query['query_ids'] ?? []), static fn(mixed $v): bool => is_string($v) && trim($v) !== ''));
        if (count($queryIds) === 1) {
            $identity = 'query_id:' . trim($queryIds[0]);
        } else {
            $fingerprint = strtolower(trim((string)($query['fingerprint'] ?? '')));
            if ($fingerprint === '') {
                throw new InvalidArgumentException('Profile contains a query without a stable fingerprint.');
            }
            $identity = 'fingerprint:' . hash('sha256', $fingerprint);
        }
        $identities[$identity] = true;
    }
    $out = array_keys($identities);
    sort($out, SORT_STRING);
    return $out;
}

function stability_atomic(string $path, array $payload): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create output directory.');
    }
    if (is_link($path)) {
        throw new RuntimeException('Refusing to replace a symlink.');
    }
    $tmp = tempnam($dir, '.sqlobs-stability-');
    if ($tmp === false) {
        throw new RuntimeException('Unable to create temporary output.');
    }
    try {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($tmp, $json, LOCK_EX);
        chmod($tmp, 0640);
        if (!rename($tmp, $path)) {
            throw new RuntimeException('Unable to publish stability report.');
        }
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

try {
    $args = stability_args($argv);
    if (!empty($args['help'])) {
        echo "Usage: php scripts/sql-observability/profile-stability.php --scenario id --runs dir --output file [--repetitions 3]\n";
        exit(0);
    }
    $scenario = trim((string)($args['scenario'] ?? ''));
    if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,79}$/', $scenario) !== 1) {
        throw new InvalidArgumentException('Invalid scenario id.');
    }
    $runs = realpath((string)($args['runs'] ?? ''));
    if ($runs === false || !is_dir($runs)) {
        throw new RuntimeException('Runs directory is unavailable.');
    }
    $repetitions = (int)$args['repetitions'];
    if ($repetitions < 2 || $repetitions > 5) {
        throw new InvalidArgumentException('repetitions must be 2..5.');
    }
    $output = (string)($args['output'] ?? '');
    if ($output === '') {
        throw new InvalidArgumentException('output is required.');
    }

    $referenceIdentity = null;
    $referenceContext = null;
    $rows = [];
    $stable = true;
    for ($repetition = 1; $repetition <= $repetitions; $repetition++) {
        $runDir = $runs . '/run-' . $repetition;
        $manifest = stability_json($runDir . '/run-manifest.json');
        if (($manifest['schema_version'] ?? '') !== SQLOBS_RUN_MANIFEST_SCHEMA
            || ($manifest['scenario_id'] ?? '') !== $scenario
            || (int)($manifest['repetition'] ?? 0) !== $repetition) {
            throw new InvalidArgumentException('Run manifest identity mismatch at repetition ' . $repetition . '.');
        }
        if ((int)($manifest['exit_codes']['suite'] ?? 1) !== 0) {
            throw new InvalidArgumentException('A failed run cannot confirm profile stability.');
        }
        $profileRel = (string)($manifest['artifacts']['mysql_profile'] ?? '');
        $profileHash = (string)($manifest['hashes']['mysql_profile'] ?? '');
        if ($profileRel === '' || preg_match('/^[a-f0-9]{64}$/', $profileHash) !== 1) {
            throw new InvalidArgumentException('Profile artifact metadata is missing.');
        }
        $profilePath = realpath($runDir . '/' . $profileRel);
        if ($profilePath === false || !is_file($profilePath)) {
            throw new RuntimeException('Profile artifact is missing.');
        }
        $actualHash = hash_file('sha256', $profilePath);
        if (!is_string($actualHash) || !hash_equals($profileHash, $actualHash)) {
            throw new InvalidArgumentException('Profile artifact hash mismatch.');
        }
        $profile = stability_json($profilePath);
        if (($profile['schema_version'] ?? '') !== 'mysql-query-profile-report-v2') {
            throw new InvalidArgumentException('Profile schema mismatch.');
        }
        $identities = stability_identities($profile);
        if ($identities === []) {
            throw new InvalidArgumentException('Profile contains no query identities.');
        }
        $context = [
            'dataset_id' => (string)($manifest['dataset_id'] ?? ''),
            'dataset_version' => (string)($manifest['dataset_version'] ?? ''),
            'dataset_hash' => (string)($manifest['dataset_hash'] ?? ''),
            'environment_id' => (string)($manifest['environment_id'] ?? ''),
            'module_id' => (string)($manifest['module_id'] ?? ''),
            'scenario_id' => (string)($manifest['scenario_id'] ?? ''),
        ];
        if ($referenceIdentity === null) {
            $referenceIdentity = $identities;
            $referenceContext = $context;
        } elseif ($referenceIdentity !== $identities || $referenceContext !== $context) {
            $stable = false;
        }
        $rows[] = [
            'repetition' => $repetition,
            'run_id' => (string)($manifest['run_id'] ?? ''),
            'profile_path' => 'run-' . $repetition . '/' . basename($profilePath),
            'profile_sha256' => $actualHash,
            'query_count' => count($identities),
            'query_identities' => $identities,
            'context' => $context,
        ];
    }

    $payload = [
        'schema_version' => SQLOBS_PROFILE_STABILITY_SCHEMA,
        'scenario_id' => $scenario,
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'status' => $stable ? 'stable' : 'unstable',
        'stable' => $stable,
        'repetitions' => $repetitions,
        'selected_candidate_repetition' => $stable ? $repetitions : null,
        'runs' => $rows,
        'limitations' => [
            'This report confirms structural query identity only; it does not approve temporal budgets or a baseline.',
        ],
    ];
    $outputAbsolute = str_starts_with($output, '/') ? $output : getcwd() . '/' . $output;
    stability_atomic($outputAbsolute, $payload);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($stable ? 0 : 4);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'ERROR[profile_stability_contract] ' . $e->getMessage() . PHP_EOL);
    exit(3);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR[profile_stability_operational] ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
