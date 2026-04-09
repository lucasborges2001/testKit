<?php
declare(strict_types=1);

namespace Testkit\Core\Execution;

use RuntimeException;
use Testkit\Core\Common\Lock;
use Testkit\Core\Common\LockLease;
use Testkit\Core\Common\Paths;
use Testkit\Core\Common\ProjectEnv;

final class ParallelGuard
{
    /**
     * @param array<int,array<string,mixed>> $tests
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function evaluate(array $tests, array $config, string $repoRoot): array
    {
        ProjectEnv::hydrateCurrentProcess($repoRoot, false);

        $jobs = max(1, (int)($config['jobs'] ?? 1));
        $strategy = self::normalizeDbStrategy((string)(getenv('TEST_DB_STRATEGY') ?: 'shared'));
        $hasDbSensitiveTests = self::hasDbSensitiveTests($tests);
        $hasDbRuntime = self::hasDbRuntimeContract();
        $driver = self::detectDriver();
        $baseDb = self::resolveBaseDatabaseName($driver);
        $suiteId = (string)($config['suite_id'] ?? 'suite');

        $requiresDbIsolation = $jobs > 1 && $hasDbSensitiveTests && $hasDbRuntime;
        $errors = [];
        $warnings = [];

        if ($requiresDbIsolation && $strategy !== 'per_worker') {
            $errors[] =
                "Configuración insegura: suite {$suiteId} usa TEST_JOBS={$jobs} sobre tests integration/e2e " .
                "con DB y TEST_DB_STRATEGY={$strategy}. Para paralelismo intra-suite con DB la única ruta " .
                "soportada es TEST_DB_STRATEGY=per_worker.";
        }

        if ($jobs > 1 && $strategy === 'per_worker' && $hasDbSensitiveTests && $hasDbRuntime) {
            $warnings[] =
                'TEST_DB_STRATEGY=per_worker aísla workers dentro de una misma suite, ' .
                'pero NO vuelve seguro correr varios runners top-level en paralelo sobre el mismo proyecto.';
        }

        $suiteLockKey = '';
        $suiteLockReason = '';
        if ($hasDbSensitiveTests && $hasDbRuntime) {
            $suiteLockKey = 'suite_store.' . self::safeLockSegment($driver) . '.' . self::safeLockSegment($baseDb !== '' ? $baseDb : 'default');
            $suiteLockReason =
                'Esta suite toca integration/e2e con DB. Para evitar seed/bootstrap cruzado y colisiones entre corridas top-level, ' .
                'testkit serializa el acceso al store base del proyecto.';
        }

        return [
            'suite_id' => $suiteId,
            'jobs' => $jobs,
            'db_strategy' => $strategy,
            'has_db_sensitive_tests' => $hasDbSensitiveTests,
            'has_db_runtime' => $hasDbRuntime,
            'requires_db_isolation' => $requiresDbIsolation,
            'base_db_driver' => $driver,
            'base_db_name' => $baseDb,
            'top_level_parallel_supported' => !($hasDbSensitiveTests && $hasDbRuntime),
            'suite_lock_key' => $suiteLockKey,
            'suite_lock_reason' => $suiteLockReason,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string,mixed> $policy
     */
    public static function assertSafe(array $policy): void
    {
        $errors = is_array($policy['errors'] ?? null) ? $policy['errors'] : [];
        if ($errors === []) {
            return;
        }

        throw new RuntimeException(implode(' ', array_map('strval', $errors)));
    }

    /**
     * @param array<string,mixed> $policy
     */
    public static function acquireSuiteStoreLock(array $policy): ?LockLease
    {
        $lockKey = trim((string)($policy['suite_lock_key'] ?? ''));
        if ($lockKey === '') {
            return null;
        }

        $lease = Lock::acquire($lockKey, false);
        if ($lease !== null) {
            return $lease;
        }

        $suiteId = (string)($policy['suite_id'] ?? 'suite');
        $driver = (string)($policy['base_db_driver'] ?? 'db');
        $baseDb = (string)($policy['base_db_name'] ?? 'default');

        throw new RuntimeException(
            "Corrida top-level concurrente no soportada para {$suiteId}: ya existe otra ejecución usando {$driver}/{$baseDb}. " .
            'No lances varios runTest.php contra el mismo store. Corré un solo runner top-level y paralelizá dentro de la suite con TEST_JOBS + TEST_DB_STRATEGY=per_worker.'
        );
    }

    /**
     * @param array<int,array<string,mixed>> $tests
     */
    private static function hasDbSensitiveTests(array $tests): bool
    {
        foreach ($tests as $test) {
            $rel = strtolower(str_replace('\\', '/', (string)($test['rel'] ?? '')));
            if (str_contains($rel, '/integration/') || str_contains($rel, '/e2e/')) {
                return true;
            }

            $tags = $test['tags'] ?? [];
            if (!is_array($tags)) {
                continue;
            }

            foreach ($tags as $tag) {
                $tag = strtolower(trim((string)$tag));
                if ($tag === 'integration' || $tag === 'e2e') {
                    return true;
                }
            }
        }

        return false;
    }

    private static function hasDbRuntimeContract(): bool
    {
        $candidates = [
            'DB_NAME',
            'TEST_MYSQL_DB',
            'MYSQL_DATABASE',
            'PG_DB',
            'TEST_PG_DB',
            'TEST_DB_DSN',
        ];

        foreach ($candidates as $key) {
            $value = trim((string)(getenv($key) ?: ''));
            if ($value !== '') {
                return true;
            }
        }

        return false;
    }

    private static function normalizeDbStrategy(string $strategy): string
    {
        $strategy = strtolower(trim($strategy));
        if (!in_array($strategy, ['shared', 'clean', 'per_worker'], true)) {
            return 'shared';
        }

        return $strategy;
    }

    private static function detectDriver(): string
    {
        $driver = strtolower(trim((string)(getenv('DB_DRIVER') ?: getenv('TEST_DB_DRIVER') ?: '')));
        if ($driver === '') {
            $dsn = trim((string)(getenv('TEST_DB_DSN') ?: ''));
            if ($dsn !== '') {
                $driver = strtolower((string)strtok($dsn, ':'));
            }
        }

        if (str_starts_with($driver, 'pg')) {
            return 'pgsql';
        }

        return 'mysql';
    }

    private static function resolveBaseDatabaseName(string $driver): string
    {
        if ($driver === 'pgsql') {
            return trim((string)(getenv('PG_DB') ?: getenv('TEST_PG_DB') ?: ''));
        }

        return trim((string)(getenv('DB_NAME') ?: getenv('TEST_MYSQL_DB') ?: getenv('MYSQL_DATABASE') ?: ''));
    }

    private static function safeLockSegment(string $value): string
    {
        $value = preg_replace('/[^a-z0-9._-]+/i', '_', strtolower(trim($value))) ?: '';
        $value = trim($value, '._-');

        return $value !== '' ? $value : 'default';
    }
}
