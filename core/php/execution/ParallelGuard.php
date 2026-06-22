<?php
declare(strict_types=1);

namespace Testkit\Core\Execution;

use RuntimeException;
use Testkit\Core\Common\Lock;
use Testkit\Core\Common\LockLease;
use Testkit\Core\Common\ProjectEnv;
use Testkit\Core\Config\SuiteContractRegistry;
use Testkit\Core\Reporting\StructuredWarnings;

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
        $hasDbRuntime = self::hasDbRuntimeContract();
        $driver = self::detectDriver();
        $baseDb = self::resolveBaseDatabaseName($driver);
        $suiteId = (string)($config['suite_id'] ?? 'suite');
        $declaredHazards = self::declaredHazards($config);

        $dbSensitivityMode = self::dbSensitivityMode($declaredHazards);
        $hasDbSensitiveTests = match ($dbSensitivityMode) {
            'always' => true,
            'never' => false,
            default => self::hasDbSensitiveTests($tests),
        };

        $topLevelPolicy = self::topLevelParallelPolicy($declaredHazards);
        $intraSuitePolicy = self::intraSuiteParallelPolicy($declaredHazards);
        $requiresDbIsolation = self::requiresDbIsolation($jobs, $hasDbSensitiveTests, $hasDbRuntime, $intraSuitePolicy);

        $errors = [];
        $warnings = [];

        if ($requiresDbIsolation && $strategy !== 'per_worker') {
            $errors[] = StructuredWarnings::fromText(
                "Configuración insegura: suite {$suiteId} usa TEST_JOBS={$jobs} sobre tests DB-sensibles con TEST_DB_STRATEGY={$strategy}. Para paralelismo intra-suite con DB la única ruta soportada es TEST_DB_STRATEGY=per_worker.",
                'UNSAFE_PARALLEL_DB_CONFIGURATION',
                'error',
                true,
                [
                    'suite_id' => $suiteId,
                    'jobs' => $jobs,
                    'db_strategy' => $strategy,
                    'intra_suite_parallel_policy' => $intraSuitePolicy,
                ]
            );
        }

        if ($jobs > 1 && $intraSuitePolicy === 'sequential_only') {
            $errors[] = StructuredWarnings::fromText(
                "La suite {$suiteId} declara ejecución secuencial únicamente. No se admite TEST_JOBS={$jobs}.",
                'SUITE_DECLARED_SEQUENTIAL_ONLY',
                'error',
                true,
                [
                    'suite_id' => $suiteId,
                    'jobs' => $jobs,
                ]
            );
        }

        if ($jobs > 1 && $strategy === 'per_worker' && $hasDbSensitiveTests && $hasDbRuntime) {
            $warnings[] = StructuredWarnings::fromText(
                'TEST_DB_STRATEGY=per_worker aísla workers dentro de una misma suite, pero NO vuelve seguro correr varios runners top-level en paralelo sobre el mismo proyecto.',
                'TOP_LEVEL_PARALLEL_STILL_UNSAFE',
                'warn',
                false,
                [
                    'suite_id' => $suiteId,
                    'top_level_parallel_policy' => $topLevelPolicy,
                ]
            );
        }

        $suiteLockKey = '';
        $suiteLockReason = '';
        $requiresExclusiveTopLevel = self::requiresExclusiveTopLevel($topLevelPolicy, $hasDbSensitiveTests, $hasDbRuntime);
        if ($requiresExclusiveTopLevel) {
            $suiteLockKey = 'suite_store.' . self::safeLockSegment($driver) . '.' . self::safeLockSegment($baseDb !== '' ? $baseDb : 'default');
            $suiteLockReason =
                'La suite requiere exclusividad top-level sobre el store base del proyecto para evitar bootstrap/seed cruzado y colisiones entre corridas.';
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
            'top_level_parallel_supported' => !$requiresExclusiveTopLevel,
            'suite_lock_key' => $suiteLockKey,
            'suite_lock_reason' => $suiteLockReason,
            'warnings' => StructuredWarnings::canonicalize($warnings),
            'errors' => StructuredWarnings::canonicalize($errors),
            'declared_runner_hazards' => $declaredHazards,
            'db_sensitivity_mode' => $dbSensitivityMode,
            'top_level_parallel_policy' => $topLevelPolicy,
            'intra_suite_parallel_policy' => $intraSuitePolicy,
        ];
    }

    /**
     * @param array<string,mixed> $policy
     */
    public static function assertSafe(array $policy): void
    {
        $errors = StructuredWarnings::canonicalize($policy['errors'] ?? []);
        if ($errors === []) {
            return;
        }

        throw new RuntimeException(StructuredWarnings::joinSummaries($errors));
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
     * @param array<int,string> $suiteIds
     * @return array<string,mixed>
     */
    public static function evaluateRunResource(array $suiteIds, string $repoRoot): array
    {
        ProjectEnv::hydrateCurrentProcess($repoRoot, false);

        $driver = self::detectDriver();
        $baseDb = self::resolveBaseDatabaseName($driver);
        $hasDbRuntime = self::hasDbRuntimeContract();
        $selectedSuites = array_values(array_unique(array_filter(array_map(
            static fn(string $suiteId): string => strtolower(trim($suiteId)),
            $suiteIds
        ))));

        $requiresLock = false;
        foreach ($selectedSuites as $suiteId) {
            $hazards = SuiteContractRegistry::hazards($suiteId);
            $mutatesSharedStore = (bool)($hazards['bootstrap_mutates_store'] ?? false);
            $sharedBootstrap = (string)($hazards['store_bootstrap'] ?? '') === 'project_shared_store';
            if ($mutatesSharedStore || $sharedBootstrap) {
                $requiresLock = true;
                break;
            }
        }

        $lockKey = '';
        $lockReason = '';
        if ($requiresLock && $hasDbRuntime) {
            $lockKey = 'store_resource.' . self::safeLockSegment($driver) . '.' . self::safeLockSegment($baseDb !== '' ? $baseDb : 'default');
            $lockReason = 'La corrida top-level reserva el store base completo para impedir intercalado entre runners independientes.';
        }

        return [
            'suite_ids' => $selectedSuites,
            'base_db_driver' => $driver,
            'base_db_name' => $baseDb,
            'has_db_runtime' => $hasDbRuntime,
            'requires_resource_lock' => $requiresLock && $hasDbRuntime,
            'resource_lock_key' => $lockKey,
            'resource_lock_reason' => $lockReason,
            'resource' => self::resourceLabel([
                'base_db_driver' => $driver,
                'base_db_name' => $baseDb,
            ]),
        ];
    }

    /**
     * @param array<string,mixed> $policy
     */
    public static function acquireRunResourceLock(array $policy): ?LockLease
    {
        $lockKey = trim((string)($policy['resource_lock_key'] ?? ''));
        if ($lockKey === '') {
            return null;
        }

        $lease = Lock::acquire($lockKey, false);
        if ($lease !== null) {
            return $lease;
        }

        $driver = (string)($policy['base_db_driver'] ?? 'db');
        $baseDb = (string)($policy['base_db_name'] ?? 'default');

        throw new RuntimeException(
            "Corrida top-level concurrente no soportada: ya existe otra ejecución usando {$driver}/{$baseDb}. " .
            'No lances varios runTest.php sobre el mismo store compartido al mismo tiempo.'
        );
    }

    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    public static function admissionState(array $policy): array
    {
        return [
            'store_mode' => (string)($policy['db_strategy'] ?? 'shared'),
            'concurrency_policy' => self::concurrencyPolicy($policy),
            'run_admitted' => true,
            'reason' => null,
            'resource' => self::resourceLabel($policy),
            'lock_key' => trim((string)($policy['suite_lock_key'] ?? '')),
            'lock_scope' => 'suite',
            'lock_owner_run_id' => null,
            'lock_owner_meta_run_id' => null,
            'lock_owner_hostname' => null,
            'lock_acquired_at' => null,
        ];
    }

    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    public static function rejectedByPolicyState(array $policy): array
    {
        $state = self::admissionState($policy);
        $state['run_admitted'] = false;
        $state['reason'] = 'unsafe_parallel_db_configuration';
        $state['message'] = StructuredWarnings::joinSummaries(StructuredWarnings::canonicalize($policy['errors'] ?? []));

        return $state;
    }

    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    public static function rejectedByLockState(array $policy): array
    {
        $state = self::admissionState($policy);
        $state['run_admitted'] = false;
        $state['reason'] = 'shared_store_locked';
        self::attachLockOwner($state, trim((string)($policy['suite_lock_key'] ?? '')));
        return $state;
    }

    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    public static function runResourceAdmissionState(array $policy): array
    {
        return [
            'store_mode' => 'shared',
            'concurrency_policy' => ($policy['requires_resource_lock'] ?? false) ? 'exclusive' : 'not_applicable',
            'run_admitted' => true,
            'reason' => null,
            'resource' => (string)($policy['resource'] ?? ''),
            'lock_key' => trim((string)($policy['resource_lock_key'] ?? '')),
            'lock_scope' => 'run',
            'lock_owner_run_id' => null,
            'lock_owner_meta_run_id' => null,
            'lock_owner_hostname' => null,
            'lock_acquired_at' => null,
        ];
    }

    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    public static function rejectedByRunLockState(array $policy): array
    {
        $state = self::runResourceAdmissionState($policy);
        $state['run_admitted'] = false;
        $state['reason'] = 'store_resource_locked';
        self::attachLockOwner($state, trim((string)($policy['resource_lock_key'] ?? '')));
        return $state;
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
        if (strtolower(trim((string)(getenv('TEST_STORE_DRIVER') ?: ''))) === 'none') {
            return false;
        }

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
        $driver = strtolower(trim((string)(getenv('TEST_STORE_DRIVER') ?: getenv('DB_DRIVER') ?: getenv('TEST_DB_DRIVER') ?: '')));
        if ($driver === '') {
            $dsn = trim((string)(getenv('TEST_DB_DSN') ?: ''));
            if ($dsn !== '') {
                $driver = strtolower((string)strtok($dsn, ':'));
            }
        }

        if (str_starts_with($driver, 'pg')) {
            return 'pgsql';
        }
        if ($driver === 'none') {
            return 'none';
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

    /**
     * @param array<string,mixed> $policy
     */
    private static function concurrencyPolicy(array $policy): string
    {
        if ((bool)($policy['has_db_sensitive_tests'] ?? false) && (bool)($policy['has_db_runtime'] ?? false)) {
            return 'exclusive';
        }

        $strategy = (string)($policy['db_strategy'] ?? 'shared');
        if ($strategy === 'per_worker') {
            return 'per_worker';
        }

        return 'not_applicable';
    }

    /**
     * @param array<string,mixed> $policy
     */
    private static function resourceLabel(array $policy): string
    {
        $driver = trim((string)($policy['base_db_driver'] ?? ''));
        $dbName = trim((string)($policy['base_db_name'] ?? ''));

        if ($driver === '' && $dbName === '') {
            return '';
        }

        return ($driver !== '' ? $driver : 'db') . '/' . ($dbName !== '' ? $dbName : 'default');
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function attachLockOwner(array &$state, string $lockKey): void
    {
        $owner = $lockKey !== '' ? Lock::readOwner($lockKey) : null;
        if (!is_array($owner)) {
            return;
        }

        $state['lock_owner_run_id'] = trim((string)($owner['run_id'] ?? '')) ?: null;
        $state['lock_owner_meta_run_id'] = trim((string)($owner['meta_run_id'] ?? '')) ?: null;
        $state['lock_owner_hostname'] = trim((string)($owner['hostname'] ?? '')) ?: null;
        $state['lock_acquired_at'] = trim((string)($owner['acquired_at'] ?? '')) ?: null;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function declaredHazards(array $config): array
    {
        return is_array($config['runner_hazards'] ?? null) ? $config['runner_hazards'] : [];
    }

    /**
     * @param array<string,mixed> $hazards
     */
    private static function dbSensitivityMode(array $hazards): string
    {
        $mode = strtolower(trim((string)($hazards['db_sensitivity'] ?? 'discovered')));
        return in_array($mode, ['always', 'never', 'discovered'], true) ? $mode : 'discovered';
    }

    /**
     * @param array<string,mixed> $hazards
     */
    private static function topLevelParallelPolicy(array $hazards): string
    {
        $policy = strtolower(trim((string)($hazards['top_level_parallel_policy'] ?? 'exclusive_when_db_sensitive')));
        return in_array($policy, ['exclusive', 'exclusive_when_db_sensitive', 'allowed'], true)
            ? $policy
            : 'exclusive_when_db_sensitive';
    }

    /**
     * @param array<string,mixed> $hazards
     */
    private static function intraSuiteParallelPolicy(array $hazards): string
    {
        $policy = strtolower(trim((string)($hazards['intra_suite_parallel_policy'] ?? 'per_worker_when_db_sensitive')));
        return in_array($policy, ['per_worker_when_db_sensitive', 'per_worker', 'sequential_only', 'allowed'], true)
            ? $policy
            : 'per_worker_when_db_sensitive';
    }

    private static function requiresDbIsolation(int $jobs, bool $hasDbSensitiveTests, bool $hasDbRuntime, string $policy): bool
    {
        if ($jobs <= 1 || !$hasDbRuntime) {
            return false;
        }

        return match ($policy) {
            'per_worker' => true,
            'per_worker_when_db_sensitive' => $hasDbSensitiveTests,
            default => false,
        };
    }

    private static function requiresExclusiveTopLevel(string $policy, bool $hasDbSensitiveTests, bool $hasDbRuntime): bool
    {
        if (!$hasDbRuntime) {
            return false;
        }

        return match ($policy) {
            'exclusive' => true,
            'exclusive_when_db_sensitive' => $hasDbSensitiveTests,
            default => false,
        };
    }
}
