<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use PDO;
use RuntimeException;
use Testkit\Core\Common\Trace;
use Testkit\Core\Store\StoreRegistry;

require_once __DIR__ . '/../store/bootstrap.php';
require_once __DIR__ . '/BaselineManifest.php';
require_once __DIR__ . '/BaselineModeResolver.php';
require_once __DIR__ . '/BaselineReuseDecider.php';
require_once __DIR__ . '/BackupkitArtifactResolver.php';
require_once __DIR__ . '/ManifestPlanBuilder.php';
require_once __DIR__ . '/MigrationCatalog.php';
require_once __DIR__ . '/MigrationPlanResolver.php';
require_once __DIR__ . '/MigrationStateResolver.php';
require_once __DIR__ . '/SeedFailure.php';
require_once __DIR__ . '/SqlFailureHintResolver.php';
require_once __DIR__ . '/SqlSeedExecutor.php';

final class SeedPipeline
{
    public static function run(string $driver, string $projectRoot): int
    {
        $driver = StoreRegistry::normalizeDriver($driver);
        $projectRoot = rtrim($projectRoot, "/\\");
        $seedDir = $projectRoot . '/test/seeds/' . $driver;
        $baselineMode = BaselineModeResolver::mode();
        $adapter = StoreRegistry::fromDriver($driver);

        try {
            $databaseName = trim((string)$adapter->resolveDatabaseName());
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo resolver el nombre de base para el seed.', [
                'stage' => 'database_name',
                'driver' => $driver,
                'db_driver' => $driver,
                'seed_dir' => self::realPathOrOriginal($seedDir),
                'project_root' => self::realPathOrOriginal($projectRoot),
                'hint' => 'Definí correctamente las variables de DB en test/.env.test o DB_ENV_PATH antes de bootstrapping.',
            ]);
        }

        $manifestPath = BaselineManifest::pathFor($projectRoot, $driver, $databaseName);

        try {
            $resolvedSnapshot = $baselineMode === 'snapshot' ? BackupkitArtifactResolver::resolveFromEnv() : null;
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo resolver el snapshot baseline para el seed.', [
                'stage' => 'snapshot_resolve',
                'driver' => $driver,
                'db_driver' => $driver,
                'db_name' => $databaseName,
                'label' => 'snapshot baseline',
                'seed_dir' => self::realPathOrOriginal($seedDir),
                'hint' => 'Definí TEST_BASELINE_SNAPSHOT_FILE o metadata/report de backupkit válido para modo snapshot.',
            ]);
        }

        self::traceBootstrapContext($driver, $projectRoot, $seedDir, $baselineMode, $databaseName, $manifestPath, $resolvedSnapshot);

        if (!is_dir($seedDir)) {
            throw new SeedFailure('No existe el directorio de seeds requerido para el bootstrap.', [
                'stage' => 'seed_dir',
                'driver' => $driver,
                'db_driver' => $driver,
                'db_name' => $databaseName,
                'file' => self::realPathOrOriginal($seedDir),
                'hint' => 'Creá test/seeds/' . $driver . ' o corregí TESTKIT_PROJECT_ROOT/TK_REPO_ROOT.',
            ]);
        }

        try {
            $manifestPlan = ManifestPlanBuilder::build(
                $driver,
                $seedDir,
                $projectRoot,
                $baselineMode,
                $databaseName,
                $manifestPath,
                $resolvedSnapshot
            );
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo construir el plan del baseline de seed.', [
                'stage' => 'manifest_plan',
                'driver' => $driver,
                'db_driver' => $driver,
                'db_name' => $databaseName,
                'label' => $baselineMode,
                'hint' => 'Revisá configuración de baseline, migraciones y materialización del estado previo al seed.',
            ]);
        }

        if (BaselineReuseDecider::canReuse($driver, $databaseName, $manifestPath, $manifestPlan)) {
            echo "Baseline reutilizado desde manifest\n";
            return 0;
        }

        if ($baselineMode === 'snapshot') {
            $result = self::runSnapshot($driver, $seedDir, $projectRoot, $resolvedSnapshot);
            self::writeManifest($manifestPath, $manifestPlan, $driver, $databaseName, $baselineMode, $projectRoot, $seedDir, $resolvedSnapshot);
            return $result;
        }

        if (self::hasLayeredLayout($seedDir)) {
            $result = self::runLayered($driver, $seedDir, $projectRoot);
            self::writeManifest($manifestPath, $manifestPlan, $driver, $databaseName, $baselineMode, $projectRoot, $seedDir, $resolvedSnapshot);
            return $result;
        }

        $result = self::runFlat($driver, $seedDir);
        self::writeManifest($manifestPath, $manifestPlan, $driver, $databaseName, $baselineMode, $projectRoot, $seedDir, $resolvedSnapshot);
        return $result;
    }

    private static function hasLayeredLayout(string $seedDir): bool
    {
        return is_dir($seedDir . '/schema') && is_dir($seedDir . '/base');
    }

    private static function seedVerbose(): bool
    {
        return self::envBool('TESTKIT_SEED_VERBOSE', false);
    }

    private static function runFlat(string $driver, string $seedDir): int
    {
        $files = SqlSeedExecutor::listFlatFiles($seedDir);
        if ($files === []) {
            throw new SeedFailure('No hay archivos SQL para seed en modo flat.', [
                'stage' => 'flat_discovery',
                'driver' => $driver,
                'db_driver' => $driver,
                'file' => self::realPathOrOriginal($seedDir),
                'hint' => 'Agregá .sql en ' . self::realPathOrOriginal($seedDir) . ' o en el subdirectorio seeds/.',
            ]);
        }

        $adapter = StoreRegistry::fromDriver($driver);
        try {
            $pdo = $adapter->connect();
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo conectar a la DB para ejecutar el seed flat.', [
                'stage' => 'connect',
                'driver' => $driver,
                'db_driver' => $driver,
                'label' => 'flat',
                'db_name' => self::dbConnectionSummary($driver)['db'] ?? '',
                'hint' => 'Revisá host, puerto, usuario y password de la conexión usada por testkit.',
            ]);
        }

        Trace::log('seed.flat.files', [
            'count' => count($files),
            'files' => self::normalizePaths($files),
        ]);

        foreach ($files as $file) {
            SqlSeedExecutor::applySqlFile($pdo, $file, 'flat', [
                'driver' => $driver,
                'db_driver' => $driver,
                'label' => 'flat',
            ]);
        }

        echo 'Seeds aplicadas: ' . count($files) . "\n";
        return 0;
    }

    private static function runLayered(string $driver, string $seedDir, string $projectRoot): int
    {
        $fixtures = self::parseCsvEnv('TEST_SEED_FIXTURES');
        if ($fixtures !== []) {
            throw new SeedFailure('TEST_SEED_FIXTURES no forma parte del lifecycle de testkit en modo layered.', [
                'stage' => 'layered_contract',
                'driver' => $driver,
                'db_driver' => $driver,
                'label' => 'layered',
                'hint' => 'La infraestructura solo aplica schema/base/migrations/validations; los escenarios deben construirse desde test/_support.',
            ]);
        }

        $adapter = StoreRegistry::fromDriver($driver);
        try {
            $pdo = $adapter->connect();
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo conectar a la DB para bootstrap layered.', [
                'stage' => 'connect',
                'driver' => $driver,
                'db_driver' => $driver,
                'label' => 'layered',
                'db_name' => self::dbConnectionSummary($driver)['db'] ?? '',
                'hint' => 'Revisá credenciales y disponibilidad de la base antes de aplicar schema/base.',
            ]);
        }

        try {
            [$migrations, $rawMigrations, $skipPostValidations, $migrationState] = MigrationPlanResolver::resolve($pdo, $seedDir, 'layered');
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo resolver el plan de migraciones para baseline layered.', [
                'stage' => 'migration_state',
                'driver' => $driver,
                'db_driver' => $driver,
                'db_name' => self::currentDatabaseName($pdo),
                'label' => 'layered',
                'hint' => 'Revisá TEST_SEED_MIGRATIONS y el catálogo en test/seeds/<driver>/migrations.',
            ]);
        }

        Trace::log('seed.layered.plan', [
            'driver' => $driver,
            'project_root' => $projectRoot,
            'seed_dir' => self::realPathOrOriginal($seedDir),
            'db_env_path' => (string)(getenv('DB_ENV_PATH') ?: ''),
            'db' => self::dbConnectionSummary($driver),
            'raw_TEST_SEED_MIGRATIONS' => $rawMigrations,
            'parsed_TEST_SEED_MIGRATIONS' => $migrations,
            'migration_state' => $migrationState,
            'skip_validations_after_extras' => $skipPostValidations,
            'TEST_MATCH' => (string)(getenv('TEST_MATCH') ?: ''),
            'TEST_SCOPE' => (string)(getenv('TEST_SCOPE') ?: ''),
            'TEST_TARGET' => (string)(getenv('TEST_TARGET') ?: ''),
        ]);

        try {
            $adapter->reset($pdo);
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo resetear la DB antes de aplicar el baseline layered.', [
                'stage' => 'reset',
                'driver' => $driver,
                'db_driver' => $driver,
                'db_name' => self::currentDatabaseName($pdo),
                'label' => 'layered reset',
                'hint' => 'Verificá privilegios para dropear objetos o residuos de una corrida previa.',
            ]);
        }

        SqlSeedExecutor::applySqlDir($pdo, $seedDir . '/schema', 'schema', 'schema', [
            'driver' => $driver,
            'db_driver' => $driver,
        ]);
        SqlSeedExecutor::applySqlDir($pdo, $seedDir . '/base', 'base', 'base', [
            'driver' => $driver,
            'db_driver' => $driver,
        ]);
        SqlSeedExecutor::applyRequestedMigrations($pdo, $seedDir, $migrations, $driver);
        SqlSeedExecutor::applyPostValidations($pdo, $seedDir, $migrations, $skipPostValidations, $driver);

        echo "Seed pipeline por capas aplicado correctamente\n";
        return 0;
    }

    /**
     * @param array<string,mixed>|null $resolvedSnapshot
     */
    private static function runSnapshot(string $driver, string $seedDir, string $projectRoot, ?array $resolvedSnapshot): int
    {
        $adapter = StoreRegistry::fromDriver($driver);

        try {
            $pdo = $adapter->connect();
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo conectar a la DB para bootstrap snapshot.', [
                'stage' => 'connect',
                'driver' => $driver,
                'db_driver' => $driver,
                'label' => 'snapshot',
                'db_name' => self::dbConnectionSummary($driver)['db'] ?? '',
                'hint' => 'Revisá credenciales y que la base objetivo exista o pueda provisionarse.',
            ]);
        }

        try {
            $resolvedSnapshot ??= BackupkitArtifactResolver::resolveFromEnv();
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo resolver el artifact snapshot durante el bootstrap.', [
                'stage' => 'snapshot_resolve',
                'driver' => $driver,
                'db_driver' => $driver,
                'db_name' => self::currentDatabaseName($pdo),
                'label' => 'snapshot',
                'hint' => 'Validá path del dump o metadata/report de backupkit antes de restaurar.',
            ]);
        }

        $snapshotFile = trim((string)($resolvedSnapshot['path'] ?? ''));
        if ($snapshotFile === '') {
            throw new SeedFailure('TEST_BASELINE_MODE=snapshot requiere un artifact snapshot resoluble.', [
                'stage' => 'snapshot_resolve',
                'driver' => $driver,
                'db_driver' => $driver,
                'db_name' => self::currentDatabaseName($pdo),
                'label' => 'snapshot',
                'hint' => 'Definí TEST_BASELINE_SNAPSHOT_FILE o una referencia válida a backupkit.',
            ]);
        }

        try {
            $adapter->reset($pdo);
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo resetear la DB antes de restaurar el snapshot.', [
                'stage' => 'reset',
                'driver' => $driver,
                'db_driver' => $driver,
                'db_name' => self::currentDatabaseName($pdo),
                'label' => 'snapshot reset',
                'file' => self::realPathOrOriginal($snapshotFile),
                'hint' => 'Revisá privilegios de borrado de objetos o residuos incompatibles en la base destino.',
            ]);
        }

        try {
            $adapter->restoreSnapshot($snapshotFile);
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo restaurar el snapshot baseline.', [
                'stage' => 'snapshot_restore',
                'driver' => $driver,
                'db_driver' => $driver,
                'db_name' => self::currentDatabaseName($pdo),
                'label' => 'snapshot restore',
                'file' => self::realPathOrOriginal($snapshotFile),
                'hint' => 'Revisá integridad del dump, binarios mysql/gzip y permisos del usuario sobre la base destino.',
            ]);
        }

        try {
            [$migrations, $rawMigrations, $skipPostValidations, $migrationState] = MigrationPlanResolver::resolve($pdo, $seedDir, 'snapshot');
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo resolver el estado de migraciones después de restaurar el snapshot.', [
                'stage' => 'migration_state',
                'driver' => $driver,
                'db_driver' => $driver,
                'db_name' => self::currentDatabaseName($pdo),
                'label' => 'snapshot',
                'file' => self::realPathOrOriginal($snapshotFile),
                'hint' => 'Definí una fuente confiable de estado (TEST_MIGRATION_APPLIED, TEST_MIGRATION_STATE_TABLE o state.json).',
            ]);
        }

        Trace::log('seed.snapshot.plan', [
            'driver' => $driver,
            'project_root' => $projectRoot,
            'seed_dir' => self::realPathOrOriginal($seedDir),
            'snapshot_file' => self::realPathOrOriginal($snapshotFile),
            'snapshot_source' => $resolvedSnapshot,
            'db' => self::dbConnectionSummary($driver),
            'raw_TEST_SEED_MIGRATIONS' => $rawMigrations,
            'parsed_TEST_SEED_MIGRATIONS' => $migrations,
            'migration_state' => $migrationState,
            'skip_validations_after_extras' => $skipPostValidations,
        ]);

        SqlSeedExecutor::applyRequestedMigrations($pdo, $seedDir, $migrations, $driver);
        SqlSeedExecutor::applyPostValidations($pdo, $seedDir, $migrations, $skipPostValidations, $driver);

        echo "Seed pipeline snapshot aplicado correctamente\n";
        return 0;
    }

    /**
     * @param array<string,mixed>|null $resolvedSnapshot
     */
    private static function writeManifest(
        string $manifestPath,
        array $manifestPlan,
        string $driver,
        string $databaseName,
        string $baselineMode,
        string $projectRoot,
        string $seedDir,
        ?array $resolvedSnapshot
    ): void {
        $payload = [
            'status' => 'ready',
            'driver' => $driver,
            'db_name' => $databaseName,
            'baseline_mode' => $baselineMode,
            'baseline_fingerprint' => (string)($manifestPlan['fingerprint'] ?? ''),
            'generated_at' => gmdate(DATE_ATOM),
            'project_root' => self::realPathOrOriginal($projectRoot),
            'seed_dir' => self::realPathOrOriginal($seedDir),
            'manifest_path' => self::realPathOrOriginal($manifestPath),
            'resolved_snapshot' => $resolvedSnapshot,
            'migration_state' => $manifestPlan['migration_state'] ?? null,
            'plan' => $manifestPlan,
        ];

        BaselineManifest::save($manifestPath, $payload);

        Trace::log('baseline.manifest.write', [
            'driver' => $driver,
            'db' => $databaseName,
            'manifest_path' => self::realPathOrOriginal($manifestPath),
            'resolved_snapshot' => $resolvedSnapshot,
            'baseline_mode' => $baselineMode,
            'fingerprint' => (string)($manifestPlan['fingerprint'] ?? ''),
        ]);
    }

    /**
     * @param array<string,mixed>|null $resolvedSnapshot
     */
    private static function traceBootstrapContext(
        string $driver,
        string $projectRoot,
        string $seedDir,
        string $baselineMode,
        string $databaseName,
        string $manifestPath,
        ?array $resolvedSnapshot
    ): void {
        Trace::log('seed.bootstrap.context', [
            'driver' => $driver,
            'project_root' => self::realPathOrOriginal($projectRoot),
            'seed_dir' => self::realPathOrOriginal($seedDir),
            'baseline_mode' => $baselineMode,
            'db_name' => $databaseName,
            'baseline_reuse' => BaselineModeResolver::reuseEnabled(),
            'baseline_invalidate' => BaselineModeResolver::invalidateRequested(),
            'baseline_manifest_path' => self::realPathOrOriginal($manifestPath),
            'resolved_snapshot' => $resolvedSnapshot,
            'DB_ENV_PATH' => (string)(getenv('DB_ENV_PATH') ?: ''),
            'TESTKIT_PROJECT_ROOT' => (string)(getenv('TESTKIT_PROJECT_ROOT') ?: ''),
            'TK_REPO_ROOT' => (string)(getenv('TK_REPO_ROOT') ?: ''),
            'TEST_MATCH' => (string)(getenv('TEST_MATCH') ?: ''),
        ]);
    }

    /**
     * @return array<string,string>
     */
    private static function dbConnectionSummary(string $driver): array
    {
        if ($driver === 'pgsql') {
            return [
                'host' => self::envFirst(['PG_HOST', 'TEST_PG_HOST', 'DB_HOST']),
                'port' => self::envFirst(['PG_PORT', 'TEST_PG_PORT', 'DB_PORT']),
                'db' => self::envFirst(['PG_DB', 'TEST_PG_DB', 'DB_NAME']),
                'user' => self::envFirst(['PG_USER', 'TEST_PG_USER', 'DB_USER']),
            ];
        }

        return [
            'host' => self::envFirst(['DB_HOST', 'TEST_MYSQL_HOST', 'MYSQL_HOST']),
            'port' => self::envFirst(['DB_PORT', 'TEST_MYSQL_PORT', 'MYSQL_PORT']),
            'db' => self::envFirst(['DB_NAME', 'TEST_MYSQL_DB', 'MYSQL_DATABASE']),
            'user' => self::envFirst(['DB_USER', 'TEST_MYSQL_USER', 'MYSQL_USER']),
        ];
    }

    private static function envFirst(array $keys): string
    {
        foreach ($keys as $key) {
            $value = getenv($key);
            if ($value === false) {
                continue;
            }

            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function realPathOrOriginal(string $path): string
    {
        $real = realpath($path);
        return $real !== false ? str_replace('\\', '/', $real) : str_replace('\\', '/', $path);
    }

    /**
     * @param array<int,string> $paths
     * @return array<int,string>
     */
    private static function normalizePaths(array $paths): array
    {
        return array_map([self::class, 'realPathOrOriginal'], $paths);
    }

    private static function currentDatabaseName(PDO $pdo): string
    {
        foreach (['SELECT DATABASE()', 'SELECT current_database()'] as $sql) {
            try {
                $value = $pdo->query($sql)?->fetchColumn();
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            } catch (\Throwable) {
                // noop
            }
        }

        return '';
    }

}
