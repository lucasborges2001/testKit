<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use RuntimeException;
use Testkit\Core\Common\Env;
use Testkit\Core\Seeding\BaselineManifest;
use Testkit\Core\Seeding\SeedPipeline;
use Testkit\Core\Store\StoreMaintenance;
use Testkit\Core\Store\StoreRegistry;

require_once __DIR__ . '/../seeding/SeedPipeline.php';
require_once __DIR__ . '/../seeding/BaselineManifest.php';

final class ContractWorldBootstrap
{
    /**
     * Punto de entrada canónico para el lifecycle de bootstrap.
     * Owner de la política operativa: strategy, naming de DB, per-worker loop, baseline clone.
     *
     * @param string|null $driver          Si se pasa, se usa directamente; si no, se detecta del entorno.
     * @param bool        $respectSkipEnv  Si es true (default, contexto suite), honora TESTKIT_SKIP_STORE_BOOTSTRAP.
     *                                     Si es false (contexto CLI explícito), el bootstrap siempre corre sin importar
     *                                     el valor de TESTKIT_SKIP_STORE_BOOTSTRAP.
     *
     * Contrato de TESTKIT_SKIP_STORE_BOOTSTRAP:
     *   - Aplica SOLO cuando se llama desde suites (back-php, front-php, front-js, back-python, migration-contract).
     *   - NO aplica cuando se invoca por CLI vía `store_router.php bootstrap` o `prepare-baseline`.
     *   - Usar para iteración local cuando la DB ya está bootstrapeada y no querés re-correr seeds.
     */
    public static function prepare(string $suiteId, string $repoRoot, ?string $driver = null, bool $respectSkipEnv = true): void
    {
        if ($respectSkipEnv && Env::bool('TESTKIT_SKIP_STORE_BOOTSTRAP', false)) {
            return;
        }

        $driver = $driver ?? StoreRegistry::detectDriver('mysql');
        $strategy = self::normalizeStrategy(Env::string('TEST_DB_STRATEGY', 'shared'));

        if ($strategy === 'clean') {
            throw new RuntimeException(
                "TEST_DB_STRATEGY=clean no está implementado: el framework no limpia entre tests individuales.\n"
                . "Alternativas:\n"
                . "  - TEST_DB_STRATEGY=shared  → todos los tests usan la misma DB, sin limpieza\n"
                . "  - TEST_DB_STRATEGY=per_worker → cada worker tiene su propia DB (requiere TEST_JOBS>1)"
            );
        }

        fwrite(
            STDERR,
            sprintf(
                "[testkit] bootstrap suite=%s driver=%s strategy=%s\n",
                $suiteId,
                $driver,
                $strategy
            )
        );

        if ($strategy === 'per_worker') {
            $jobs = max(1, Env::int('TEST_JOBS', 1));
            $baseDb = self::resolveBaseDatabaseName($driver);

            if (Env::bool('TEST_BASELINE_CLONE_PER_WORKER', false)) {
                self::assertBaselineCloneSupported($driver);
                self::bootstrapWorkersFromBaseline($driver, $repoRoot, $baseDb, $jobs);
                return;
            }

            for ($workerId = 1; $workerId <= $jobs; $workerId++) {
                $dbName = self::buildWorkerDatabaseName($baseDb, $workerId);
                self::withDatabaseOverrides($driver, $dbName, static function () use ($driver, $repoRoot): void {
                    self::bootstrapStore($driver, $repoRoot);
                });
            }
            return;
        }

        self::bootstrapStore($driver, $repoRoot);
    }

    private static function bootstrapWorkersFromBaseline(
        string $driver,
        string $repoRoot,
        string $baseDb,
        int $jobs
    ): void {
        $baselineDb = self::resolveBaselineDatabaseName($baseDb);
        $manifestPath = BaselineManifest::pathFor($repoRoot, $driver, $baselineDb);

        fwrite(
            STDERR,
            sprintf(
                "[testkit] bootstrap baseline driver=%s baseline_db=%s jobs=%d reuse=%s\n",
                $driver,
                $baselineDb,
                $jobs,
                Env::bool('TEST_BASELINE_REUSE', false) ? 'true' : 'false'
            )
        );

        if (Env::bool('TEST_BASELINE_INVALIDATE', false)) {
            self::invalidateBaselineArtifacts($driver, $baselineDb, $manifestPath);
        }

        self::withDatabaseOverrides($driver, $baselineDb, static function () use ($driver, $repoRoot): void {
            self::bootstrapStore($driver, $repoRoot);
        });

        for ($workerId = 1; $workerId <= $jobs; $workerId++) {
            $workerDb = self::buildWorkerDatabaseName($baseDb, $workerId);
            StoreMaintenance::cloneDatabase($driver, $baselineDb, $workerDb);
        }
    }

    private static function invalidateBaselineArtifacts(string $driver, string $baselineDb, string $manifestPath): void
    {
        if (StoreMaintenance::databaseExists($driver, $baselineDb)) {
            StoreMaintenance::dropDatabase($driver, $baselineDb);
        }

        BaselineManifest::delete($manifestPath);

        fwrite(
            STDERR,
            sprintf(
                "[testkit] baseline invalidated driver=%s baseline_db=%s manifest=%s\n",
                $driver,
                $baselineDb,
                $manifestPath
            )
        );
    }

    private static function bootstrapStore(string $driver, string $repoRoot): void
    {
        StoreMaintenance::provision($driver);
        $exitCode = SeedPipeline::run($driver, $repoRoot);
        if ($exitCode !== 0) {
            throw new RuntimeException(
                sprintf('El bootstrap estructural devolvió exit=%d para driver=%s', $exitCode, $driver)
            );
        }
    }

    private static function normalizeStrategy(string $strategy): string
    {
        $strategy = strtolower(trim($strategy));
        if (!in_array($strategy, ['shared', 'clean', 'per_worker'], true)) {
            return 'shared';
        }
        return $strategy;
    }

    private static function resolveBaseDatabaseName(string $driver): string
    {
        $keys = $driver === 'pgsql'
            ? ['PG_DB', 'TEST_PG_DB', 'DB_NAME']
            : ['DB_NAME', 'TEST_MYSQL_DB', 'MYSQL_DATABASE'];

        foreach ($keys as $key) {
            $value = getenv($key);
            if ($value === false) {
                continue;
            }

            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }

            return $value;
        }

        throw new RuntimeException(
            'Falta nombre de base para bootstrap per-worker (' . implode('/', $keys) . ').'
        );
    }

    private static function resolveBaselineDatabaseName(string $baseDb): string
    {
        $explicit = trim(Env::string('TEST_BASELINE_DB_NAME', ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $suffix = trim(Env::string('TEST_BASELINE_DB_SUFFIX', '_baseline'));
        if ($suffix === '' || preg_match('/^[A-Za-z0-9._-]+$/', $suffix) !== 1) {
            $suffix = '_baseline';
        }

        return $baseDb . $suffix;
    }

    private static function buildWorkerDatabaseName(string $baseDb, int $workerId): string
    {
        $format = Env::string('TEST_DB_WORKER_SUFFIX_FORMAT', '_w%02d');
        if (preg_match('/^[A-Za-z0-9_%._-]+$/', $format) !== 1) {
            $format = '_w%02d';
        }

        $suffix = @sprintf($format, $workerId);
        if (!is_string($suffix) || $suffix === '' || preg_match('/^[A-Za-z0-9._-]+$/', $suffix) !== 1) {
            $suffix = '_w' . $workerId;
        }

        return $baseDb . $suffix;
    }

    private static function withDatabaseOverrides(string $driver, string $dbName, callable $callback): void
    {
        $keys = ['DB_NAME', 'TEST_MYSQL_DB', 'MYSQL_DATABASE', 'PG_DB', 'TEST_PG_DB', 'TEST_DB_DSN'];
        $snapshot = [];
        foreach ($keys as $key) {
            $snapshot[$key] = getenv($key);
        }

        try {
            if ($driver === 'pgsql') {
                self::exportEnv('PG_DB', $dbName);
                self::exportEnv('TEST_PG_DB', $dbName);
                self::exportEnv('DB_NAME', $dbName);
            } else {
                self::exportEnv('DB_NAME', $dbName);
                self::exportEnv('TEST_MYSQL_DB', $dbName);
                self::exportEnv('MYSQL_DATABASE', $dbName);
            }

            $dsn = getenv('TEST_DB_DSN');
            if ($dsn !== false && trim((string)$dsn) !== '') {
                self::exportEnv('TEST_DB_DSN', self::rewriteDsnDatabase((string)$dsn, $dbName));
            }

            $callback();
        } finally {
            foreach ($snapshot as $key => $value) {
                if ($value === false) {
                    self::clearEnv($key);
                    continue;
                }
                self::exportEnv($key, (string)$value);
            }
        }
    }

    private static function rewriteDsnDatabase(string $dsn, string $dbName): string
    {
        $rewritten = preg_replace('/(dbname=)[^;]+/i', '${1}' . $dbName, $dsn, 1);
        return is_string($rewritten) ? $rewritten : $dsn;
    }

    private static function exportEnv(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private static function clearEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    private static function assertBaselineCloneSupported(string $driver): void
    {
        if ($driver !== 'mysql') {
            throw new RuntimeException(
                'TEST_BASELINE_CLONE_PER_WORKER solo está cerrado para MySQL en esta versión. '
                . 'Driver recibido: ' . $driver . '.'
            );
        }

        $provisionMode = self::normalizeProvisionMode(Env::string('TEST_STORE_PROVISION', 'managed'));
        if ($provisionMode !== 'managed') {
            throw new RuntimeException(
                'TEST_BASELINE_CLONE_PER_WORKER requiere TEST_STORE_PROVISION=managed. '
                . 'Clone-per-worker crea, invalida y clona DBs auxiliares; con TEST_STORE_PROVISION=' . $provisionMode
                . ' ese lifecycle no forma parte del contrato operativo soportado.'
            );
        }
    }

    private static function normalizeProvisionMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['managed', 'external'], true)) {
            return 'managed';
        }

        return $mode;
    }
}
