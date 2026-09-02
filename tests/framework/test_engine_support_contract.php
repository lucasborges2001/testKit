<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/config/ConfigSchema.php';

use Testkit\Core\Config\ConfigSchema;

$errors = [];

function assert_true(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

function read_repo_file(string $rel): string
{
    $path = dirname(__DIR__, 2) . '/' . $rel;
    if (!is_file($path)) {
        return '';
    }
    $raw = file_get_contents($path);
    return is_string($raw) ? $raw : '';
}

function find_env_entry(array $payload, string $key): array
{
    foreach ((array)($payload['environment'] ?? []) as $entry) {
        if (is_array($entry) && (string)($entry['key'] ?? '') === $key) {
            return $entry;
        }
    }
    return [];
}

function find_matrix_entry(array $rows, string $name): array
{
    foreach ($rows as $row) {
        if (is_array($row) && (string)($row['name'] ?? '') === $name) {
            return $row;
        }
    }
    return [];
}

$payload = ConfigSchema::inspectPayload();
$matrix = is_array($payload['support_matrix'] ?? null) ? $payload['support_matrix'] : [];
$engines = is_array($matrix['engines'] ?? null) ? $matrix['engines'] : [];
$services = is_array($matrix['services'] ?? null) ? $matrix['services'] : [];

$mysql = find_matrix_entry($engines, 'mysql');
$pgsql = find_matrix_entry($engines, 'pgsql');
$none = find_matrix_entry($engines, 'none');
$redis = find_matrix_entry($services, 'redis');
$influx = find_matrix_entry($services, 'influx');

assert_true(($mysql['status'] ?? '') === 'closed_primary', 'MySQL must be declared closed_primary.', $errors);
assert_true(($mysql['contract']['snapshot_restore'] ?? null) === true, 'MySQL must keep closed snapshot_restore.', $errors);
assert_true(($mysql['contract']['per_worker_clone'] ?? null) === true, 'MySQL must keep per_worker_clone.', $errors);

assert_true(($pgsql['status'] ?? '') === 'partial_experimental', 'PostgreSQL must be partial_experimental, not closed.', $errors);
assert_true(($pgsql['contract']['snapshot_restore'] ?? null) === false, 'PostgreSQL snapshot_restore must not be advertised as closed.', $errors);
assert_true(($pgsql['contract']['per_worker_clone'] ?? null) === false, 'PostgreSQL per_worker clone must not be advertised as closed.', $errors);
assert_true(($pgsql['contract']['migration_contract'] ?? null) === false, 'PostgreSQL migration-contract must not be advertised as closed.', $errors);

assert_true(($none['status'] ?? '') === 'no_store', 'none must be declared as no_store.', $errors);
assert_true(($none['contract']['provision'] ?? null) === false, 'none must not advertise provision.', $errors);
assert_true(($none['contract']['migration_contract'] ?? null) === false, 'none must not advertise migration-contract.', $errors);

assert_true(($redis['status'] ?? '') === 'auxiliary', 'Redis must be auxiliary.', $errors);
assert_true(($redis['contract']['structural_store_lifecycle'] ?? null) === false, 'Redis must not be structural lifecycle store.', $errors);
assert_true(($influx['status'] ?? '') === 'auxiliary_profiling', 'Influx must be auxiliary_profiling.', $errors);
assert_true(($influx['contract']['structural_store_lifecycle'] ?? null) === false, 'Influx must not be structural lifecycle store.', $errors);

$strategies = is_array($payload['db_strategies'] ?? null) ? $payload['db_strategies'] : [];
assert_true(isset($strategies['supported']['shared']), 'shared strategy must be supported.', $errors);
assert_true(isset($strategies['supported']['per_worker']), 'per_worker strategy must be supported.', $errors);
assert_true(isset($strategies['rejected']['clean']), 'clean strategy must be explicitly rejected.', $errors);
assert_true(($strategies['supported']['per_worker']['top_level_parallel_safe'] ?? true) === false, 'per_worker must not be top-level parallel safe.', $errors);

$dbStrategy = find_env_entry($payload, 'TEST_DB_STRATEGY');
assert_true(in_array('shared', (array)($dbStrategy['valid_values'] ?? []), true), 'TEST_DB_STRATEGY must allow shared.', $errors);
assert_true(in_array('per_worker', (array)($dbStrategy['valid_values'] ?? []), true), 'TEST_DB_STRATEGY must allow per_worker.', $errors);
assert_true(!in_array('clean', (array)($dbStrategy['valid_values'] ?? []), true), 'TEST_DB_STRATEGY valid_values must not include clean.', $errors);
assert_true(in_array('clean', (array)($dbStrategy['rejected_values'] ?? []), true), 'TEST_DB_STRATEGY rejected_values must include clean.', $errors);

$driver = find_env_entry($payload, 'TEST_STORE_DRIVER');
assert_true(in_array('mysql', (array)($driver['valid_values'] ?? []), true), 'TEST_STORE_DRIVER must include mysql.', $errors);
assert_true(in_array('pgsql', (array)($driver['valid_values'] ?? []), true), 'TEST_STORE_DRIVER may include pgsql as partial.', $errors);
assert_true(in_array('none', (array)($driver['valid_values'] ?? []), true), 'TEST_STORE_DRIVER must include none.', $errors);
assert_true(!in_array('redis', (array)($driver['valid_values'] ?? []), true), 'TEST_STORE_DRIVER must not include redis.', $errors);
assert_true(!in_array('influx', (array)($driver['valid_values'] ?? []), true), 'TEST_STORE_DRIVER must not include influx.', $errors);

$bashDoctor = read_repo_file('lib/bash/doctor/capability_checks.sh');
$psDoctor = read_repo_file('lib/powershell/Doctor.CapabilityChecks.ps1');
foreach ([$bashDoctor, $psDoctor] as $index => $doctor) {
    $label = $index === 0 ? 'bash doctor' : 'powershell doctor';
    assert_true(str_contains($doctor, 'POSTGRES_PARTIAL_SUPPORT'), $label . ' must classify PostgreSQL as partial.', $errors);
    assert_true(str_contains($doctor, 'STORE_DRIVER_NONE'), $label . ' must classify none as no-store.', $errors);
    assert_true(str_contains($doctor, 'REDIS_AUXILIARY_SERVICE'), $label . ' must classify Redis as auxiliary.', $errors);
    assert_true(str_contains($doctor, 'INFLUX_AUXILIARY_PROFILING'), $label . ' must classify Influx as auxiliary/profiling.', $errors);
    assert_true(str_contains($doctor, 'CLEAN_STRATEGY_UNSUPPORTED'), $label . ' must reject clean.', $errors);
    assert_true(!str_contains($doctor, 'PostgreSQL full support'), $label . ' must not advertise PostgreSQL full support.', $errors);
}

$readme = read_repo_file('README.md');
$contract = read_repo_file('docs/CONTRATO.md');
$support = read_repo_file('SUPPORT_MATRIX.md');
$envExample = read_repo_file('.env.test.example');
foreach ([$readme, $contract, $support, $envExample] as $index => $text) {
    $label = ['README', 'CONTRATO', 'SUPPORT_MATRIX', '.env.test.example'][$index];
    assert_true(!preg_match('/full\s+support\s+PostgreSQL/i', $text), $label . ' must not claim full PostgreSQL support.', $errors);
    assert_true(!preg_match('/Redis.*structural.*store.*closed/i', $text), $label . ' must not claim Redis closed structural store.', $errors);
}
assert_true(
    preg_match('/\|\s*`mysql`\s*\|\s*ruta principal cerrada\s*\|/i', $contract) === 1,
    'CONTRATO must name MySQL as primary closed path.',
    $errors
);
assert_true(
    preg_match('/\|\s*`pgsql`\s*\|\s*parcial\/experimental\s*\|/i', $contract) === 1,
    'CONTRATO must mark PostgreSQL as partial.',
    $errors
);
assert_true(str_contains($support, 'Influx') && str_contains($support, 'auxiliar'), 'SUPPORT_MATRIX must mark Influx as auxiliary.', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }
    exit(1);
}

echo "Engine support contract PASS\n";
