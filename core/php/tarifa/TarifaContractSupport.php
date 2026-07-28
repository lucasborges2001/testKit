<?php
declare(strict_types=1);

namespace Testkit\Core\Tarifa;

final class TarifaContractSupport
{
    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    public static function pricingSnapshotFixture(string $seed, array $overrides = []): array
    {
        $n = (int)(hexdec(substr(hash('sha256', $seed), 0, 6)) % 9000) + 1000;
        $scope = (string)($overrides['scope'] ?? 'organization');
        $organizationId = (int)($overrides['organization_id'] ?? $n);
        $usage = [
            'producer' => 'testkit',
            'operation_id' => 'op_' . substr(hash('sha256', $seed . ':op'), 0, 16),
            'organization_id' => $organizationId,
            'tarifa_id' => (int)($overrides['tarifa_id'] ?? ($n + 1)),
            'rate_version' => (int)($overrides['rate_version'] ?? 1),
            'currency' => (string)($overrides['currency'] ?? 'UYU'),
            'energy_wh' => 1200,
            'idle_seconds' => 60,
            'offline_seconds' => 0,
            'connection_count' => 1,
            'manual_service_minor' => 0,
            'occurred_from' => '2026-01-01T10:00:00Z',
            'occurred_until' => '2026-01-01T11:00:00Z',
        ];
        $inputHash = hash('sha256', self::canonicalJson($usage));
        $quote = [
            'contract_version' => '2.0.0',
            'engine' => 'testkit-neutral',
            'engine_version' => '1',
            'tarifa_id' => $usage['tarifa_id'],
            'rate_version' => $usage['rate_version'],
            'organization_id' => $organizationId,
            'resolved_scope' => $scope,
            'resolved_scope_reference_id' => (int)($overrides['resolved_scope_reference_id'] ?? $organizationId),
            'currency' => $usage['currency'],
            'components' => [
                ['code' => 'energy', 'quantity' => 1200, 'unit' => 'wh', 'unit_price' => '0.10', 'amount_minor' => 120],
                ['code' => 'connection_fee', 'quantity' => 1, 'unit' => 'connection', 'unit_price' => '50.00', 'amount_minor' => 50],
            ],
            'subtotal_minor' => (int)($overrides['total_minor'] ?? 170),
            'discount_minor' => 0,
            'total_minor' => (int)($overrides['total_minor'] ?? 170),
            'calculated_at' => '2026-01-01T11:00:01Z',
        ];
        $fixture = [
            'snapshot_id' => 'snap_' . substr(hash('sha256', $seed . ':snapshot'), 0, 24),
            'producer' => $usage['producer'],
            'operation_id' => $usage['operation_id'],
            'input_hash' => $inputHash,
            'organization_id' => $organizationId,
            'payer_type' => (string)($overrides['payer_type'] ?? 'organization'),
            'payer_reference_id' => (string)($overrides['payer_reference_id'] ?? ('org_' . $organizationId)),
            'currency' => $usage['currency'],
            'total_minor' => $quote['total_minor'],
            'correlation_id' => 'corr_' . substr(hash('sha256', $seed . ':corr'), 0, 16),
            'usage' => $usage,
            'quote' => $quote,
            'immutable' => true,
            'created_at' => '2026-01-01T11:00:02Z',
        ];
        return array_replace_recursive($fixture, $overrides);
    }

    /** @param array<string,mixed> $snapshot */
    public static function validateSnapshot(array $snapshot, int $tenantId): void
    {
        foreach (['group', 'sede', 'organization'] as $scope) {
            if (($snapshot['quote']['resolved_scope'] ?? null) === $scope) {
                break;
            }
            if ($scope === 'organization') {
                throw new \RuntimeException('invalid canonical scope');
            }
        }
        foreach (['organizacion_id', 'grupo_id', 'sitio_id'] as $legacyKey) {
            if (array_key_exists($legacyKey, $snapshot) || array_key_exists($legacyKey, (array)($snapshot['usage'] ?? []))) {
                throw new \RuntimeException('legacy key accepted: ' . $legacyKey);
            }
        }
        if ((int)($snapshot['organization_id'] ?? 0) !== $tenantId) {
            throw new \RuntimeException('tenant mismatch');
        }
        if (!preg_match('/^[A-Z]{3}$/', (string)($snapshot['currency'] ?? ''))) {
            throw new \RuntimeException('invalid currency');
        }
        if (!is_int($snapshot['total_minor'] ?? null) || (int)$snapshot['total_minor'] < 0) {
            throw new \RuntimeException('invalid minor units');
        }
        if (self::containsFloat($snapshot)) {
            throw new \RuntimeException('float value accepted');
        }
        if (($snapshot['immutable'] ?? null) !== true) {
            throw new \RuntimeException('snapshot mutable');
        }
        if (($snapshot['quote']['currency'] ?? null) !== $snapshot['currency']
            || ($snapshot['quote']['total_minor'] ?? null) !== $snapshot['total_minor']) {
            throw new \RuntimeException('repricing detected');
        }
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public static function economicOperationFixture(array $snapshot): array
    {
        return [
            'producer' => $snapshot['producer'],
            'operation_id' => $snapshot['operation_id'],
            'snapshot_id' => $snapshot['snapshot_id'],
            'input_hash' => $snapshot['input_hash'],
            'organization_id' => $snapshot['organization_id'],
            'payer_type' => $snapshot['payer_type'],
            'payer_reference_id' => $snapshot['payer_reference_id'],
            'currency' => $snapshot['currency'],
            'total_minor' => $snapshot['total_minor'],
            'correlation_id' => $snapshot['correlation_id'],
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function applyIdempotent(string $stateFile, string $key, array $payload): array
    {
        $dir = dirname($stateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $fh = fopen($stateFile, 'c+');
        if (!$fh) {
            throw new \RuntimeException('cannot open idempotency state');
        }
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        $state = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
        if (!is_array($state)) {
            $state = [];
        }
        $hash = hash('sha256', self::canonicalJson($payload));
        $status = 'created';
        if (isset($state[$key])) {
            $status = (($state[$key]['payload_hash'] ?? '') === $hash) ? 'replay' : 'conflict';
        } else {
            $state[$key] = ['payload_hash' => $hash, 'payload' => $payload, 'created_at' => gmdate('c')];
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, self::prettyJson($state));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        return ['status' => $status, 'key' => $key, 'payload_hash' => $hash, 'effects' => count($state)];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public static function runConcurrency(array $snapshot, string $workDir): array
    {
        if (!is_dir($workDir)) {
            mkdir($workDir, 0777, true);
        }
        $script = $workDir . '/worker.php';
        file_put_contents($script, <<<'PHP'
<?php
declare(strict_types=1);
require_once $argv[1];
$id = $argv[2];
$dir = $argv[3];
$payload = json_decode((string)file_get_contents($argv[4]), true);
file_put_contents($dir . '/ready_' . $id, getmypid() . "\n");
$deadline = microtime(true) + 5.0;
while (count(glob($dir . '/ready_*') ?: []) < 2) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('barrier timeout');
    }
    usleep(10000);
}
$result = \Testkit\Core\Tarifa\TarifaContractSupport::applyIdempotent($dir . '/state.json', $payload['producer'] . ':' . $payload['operation_id'], $payload);
file_put_contents($dir . '/process_' . $id . '.json', json_encode(['pid' => getmypid(), 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
PHP);
        $payloadFile = $workDir . '/payload.json';
        file_put_contents($payloadFile, self::prettyJson(self::economicOperationFixture($snapshot)));
        $support = __FILE__;
        $children = [];
        for ($i = 1; $i <= 2; $i++) {
            $cmd = [PHP_BINARY, $script, $support, (string)$i, $workDir, $payloadFile];
            $children[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        }
        foreach ($children as $child) {
            if (is_resource($child)) {
                proc_close($child);
            }
        }
        $processes = [];
        foreach (glob($workDir . '/process_*.json') ?: [] as $file) {
            $processes[] = json_decode((string)file_get_contents($file), true);
        }
        $statuses = array_map(static fn(array $p): string => (string)($p['result']['status'] ?? ''), $processes);
        sort($statuses);
        if ($statuses !== ['created', 'replay']) {
            throw new \RuntimeException('concurrency did not produce one create and one replay');
        }
        return [
            'barrier_ready_count' => count(glob($workDir . '/ready_*') ?: []),
            'processes' => $processes,
            'state' => json_decode((string)file_get_contents($workDir . '/state.json'), true),
            'result' => ['effects' => 1, 'statuses' => $statuses],
        ];
    }

    /** @param array<string,mixed> $payload */
    public static function writeEvidence(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, self::prettyJson($payload));
    }

    /** @param mixed $value */
    private static function containsFloat(mixed $value): bool
    {
        if (is_float($value)) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (self::containsFloat($entry)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param mixed $value */
    private static function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            ksort($value);
            foreach ($value as $k => $v) {
                $value[$k] = is_array($v) ? json_decode(self::canonicalJson($v), true) : $v;
            }
        }
        return (string)json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    /** @param mixed $value */
    private static function prettyJson(mixed $value): string
    {
        return (string)json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
