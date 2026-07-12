#!/usr/bin/env php
<?php
declare(strict_types=1);

const SQLOBS_RUN_SCHEMA = 'testkit-sql-observability-run-manifest-v1';
const SQLOBS_GATE_EVIDENCE_SCHEMA = 'mysql-query-gate-evidence-v1';

/** @return array<string,mixed> */
function evidence_args(array $argv): array
{
    $out = ['repetitions' => 3, 'baseline-pending' => false];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = (string)$argv[$i];
        if ($arg === '--help') {
            $out['help'] = true;
            continue;
        }
        if ($arg === '--baseline-pending') {
            $out['baseline-pending'] = true;
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

function evidence_load_json(string $path): array
{
    if (!is_file($path) || is_link($path) || filesize($path) > 10_485_760) {
        throw new RuntimeException('Artifact unavailable or unsafe: ' . basename($path));
    }
    try {
        $value = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new InvalidArgumentException('Invalid JSON: ' . basename($path));
    }
    if (!is_array($value) || array_is_list($value)) {
        throw new InvalidArgumentException('Expected JSON object: ' . basename($path));
    }
    return $value;
}

function evidence_atomic(string $path, array $payload): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create evidence directory.');
    }
    if (is_link($path)) {
        throw new RuntimeException('Refusing to replace a symlink.');
    }
    $tmp = tempnam($dir, '.sqlobs-evidence-');
    if ($tmp === false) {
        throw new RuntimeException('Unable to create temporary file.');
    }
    try {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $h = fopen($tmp, 'wb');
        if ($h === false) {
            throw new RuntimeException('Unable to open temporary evidence file.');
        }
        fwrite($h, $json);
        fflush($h);
        if (function_exists('fsync')) {
            fsync($h);
        }
        fclose($h);
        chmod($tmp, 0640);
        if (!rename($tmp, $path)) {
            throw new RuntimeException('Unable to publish evidence manifest.');
        }
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

try {
    $args = evidence_args($argv);
    if (!empty($args['help'])) {
        echo "Usage: php scripts/sql-observability/evidence.php --scenario id --runs dir --output file [--repetitions 3] [--baseline-pending]\n";
        exit(0);
    }
    $scenario = trim((string)($args['scenario'] ?? ''));
    if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,79}$/', $scenario) !== 1) {
        throw new InvalidArgumentException('Invalid scenario id.');
    }
    $runsDir = realpath((string)($args['runs'] ?? ''));
    if ($runsDir === false || !is_dir($runsDir)) {
        throw new RuntimeException('Runs directory does not exist.');
    }
    $repetitions = (int)$args['repetitions'];
    if ($repetitions < 1 || $repetitions > 5) {
        throw new InvalidArgumentException('repetitions must be 1..5.');
    }
    $output = (string)($args['output'] ?? '');
    if ($output === '') {
        throw new InvalidArgumentException('output is required.');
    }

    $reference = null;
    $artifacts = [];
    $missing = [];
    for ($repetition = 1; $repetition <= $repetitions; $repetition++) {
        $manifestPath = $runsDir . '/run-' . $repetition . '/run-manifest.json';
        $manifest = evidence_load_json($manifestPath);
        if (($manifest['schema_version'] ?? '') !== SQLOBS_RUN_SCHEMA) {
            throw new InvalidArgumentException('Unsupported run manifest schema for repetition ' . $repetition . '.');
        }
        if (($manifest['scenario_id'] ?? '') !== $scenario || (int)($manifest['repetition'] ?? 0) !== $repetition) {
            throw new InvalidArgumentException('Run manifest identity mismatch for repetition ' . $repetition . '.');
        }
        $identity = [
            'dataset_id' => (string)($manifest['dataset_id'] ?? ''),
            'dataset_version' => (string)($manifest['dataset_version'] ?? ''),
            'dataset_hash' => (string)($manifest['dataset_hash'] ?? ''),
            'environment_id' => (string)($manifest['environment_id'] ?? ''),
            'baseline_status' => (string)($manifest['baseline_status'] ?? ''),
        ];
        if ($reference === null) {
            $reference = $identity;
        } elseif ($reference !== $identity) {
            throw new InvalidArgumentException('Run manifests do not share dataset, environment and baseline identity.');
        }

        $comparisonRel = (string)($manifest['artifacts']['mysql_comparison'] ?? '');
        $comparisonHash = (string)($manifest['hashes']['mysql_comparison'] ?? '');
        if ($comparisonRel === '' || $comparisonHash === '') {
            $missing[] = $repetition;
            continue;
        }
        $candidate = realpath(dirname($manifestPath) . '/' . $comparisonRel);
        if ($candidate === false) {
            $candidate = realpath($runsDir . '/run-' . $repetition . '/' . basename($comparisonRel));
        }
        if ($candidate === false || !is_file($candidate)) {
            throw new RuntimeException('Comparison artifact is missing for repetition ' . $repetition . '.');
        }
        $actual = hash_file('sha256', $candidate);
        if (!is_string($actual) || !hash_equals($comparisonHash, $actual)) {
            throw new InvalidArgumentException('Comparison hash mismatch for repetition ' . $repetition . '.');
        }
        $comparison = evidence_load_json($candidate);
        if (($comparison['schema_version'] ?? '') !== 'mysql-query-comparison-report-v1') {
            throw new InvalidArgumentException('Comparison schema mismatch for repetition ' . $repetition . '.');
        }
        $rel = str_replace('\\', '/', substr($candidate, strlen(rtrim($runsDir, DIRECTORY_SEPARATOR)) + 1));
        $artifacts[] = ['path' => $rel, 'sha256' => $actual];
    }

    $payload = ['schema_version' => SQLOBS_GATE_EVIDENCE_SCHEMA, 'artifacts' => $artifacts];
    $outputAbsolute = str_starts_with($output, '/') ? $output : getcwd() . '/' . $output;
    evidence_atomic($outputAbsolute, $payload);
    echo json_encode([
        'status' => $missing === [] ? 'ready' : 'baseline_pending',
        'scenario_id' => $scenario,
        'artifacts' => count($artifacts),
        'missing_comparison_repetitions' => $missing,
        'output' => $output,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

    if ($missing !== []) {
        if (empty($args['baseline-pending'])) {
            fwrite(STDERR, "ERROR[evidence_incomplete] Comparison artifacts are missing.\n");
            exit(4);
        }
        fwrite(STDERR, "WARN[evidence_baseline_pending] Evidence manifest is valid but has no complete comparison set.\n");
    }
    exit(0);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'ERROR[evidence_contract] ' . $e->getMessage() . PHP_EOL);
    exit(3);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR[evidence_operational] ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
