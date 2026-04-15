<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use PDO;
use RuntimeException;
use Testkit\Core\Common\Trace;
use Testkit\Core\Store\StoreMaintenance;
use Testkit\Core\Store\StoreRegistry;

require_once __DIR__ . '/../store/bootstrap.php';
require_once __DIR__ . '/BaselineManifest.php';
require_once __DIR__ . '/BackupkitArtifactResolver.php';
require_once __DIR__ . '/MigrationCatalog.php';
require_once __DIR__ . '/MigrationStateResolver.php';
require_once __DIR__ . '/SeedFailure.php';

final class SeedPipeline
{
    public static function run(string $driver, string $projectRoot): int
    {
        $driver = StoreRegistry::normalizeDriver($driver);
        $projectRoot = rtrim($projectRoot, "/\\");
        $seedDir = $projectRoot . '/test/seeds/' . $driver;
        $baselineMode = self::baselineMode();
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
            $manifestPlan = self::buildManifestPlan($driver, $seedDir, $projectRoot, $baselineMode, $databaseName, $manifestPath, $resolvedSnapshot);
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

        if (self::canReuseCurrentBaseline($driver, $databaseName, $manifestPath, $manifestPlan)) {
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

    private static function baselineMode(): string
    {
        $mode = strtolower(trim((string)(getenv('TEST_BASELINE_MODE') ?: 'layered')));
        if (!in_array($mode, ['layered', 'snapshot'], true)) {
            return 'layered';
        }

        return $mode;
    }

    private static function baselineReuseEnabled(): bool
    {
        return self::envBool('TEST_BASELINE_REUSE', false);
    }

    private static function baselineInvalidateRequested(): bool
    {
        return self::envBool('TEST_BASELINE_INVALIDATE', false);
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
        $files = self::listFlatFiles($seedDir);
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
            self::applySqlFile($pdo, $file, 'flat', [
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
            [$migrations, $rawMigrations, $skipPostValidations, $migrationState] = self::migrationPlan($pdo, $seedDir, 'layered');
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

        self::applySqlDir($pdo, $seedDir . '/schema', 'schema', 'schema', [
            'driver' => $driver,
            'db_driver' => $driver,
        ]);
        self::applySqlDir($pdo, $seedDir . '/base', 'base', 'base', [
            'driver' => $driver,
            'db_driver' => $driver,
        ]);
        self::applyRequestedMigrations($pdo, $seedDir, $migrations, $driver);
        self::applyPostValidations($pdo, $seedDir, $migrations, $skipPostValidations, $driver);

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
            [$migrations, $rawMigrations, $skipPostValidations, $migrationState] = self::migrationPlan($pdo, $seedDir, 'snapshot');
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

        self::applyRequestedMigrations($pdo, $seedDir, $migrations, $driver);
        self::applyPostValidations($pdo, $seedDir, $migrations, $skipPostValidations, $driver);

        echo "Seed pipeline snapshot aplicado correctamente\n";
        return 0;
    }

    /**
     * @param array<int,string> $migrations
     */
    private static function applyPostValidations(PDO $pdo, string $seedDir, array $migrations, bool $skipPostValidations, string $driver): void
    {
        if (!($migrations !== [] && $skipPostValidations)) {
            self::applySqlDirIfExists($pdo, $seedDir . '/validations', 'validations', 'validations', [
                'driver' => $driver,
                'db_driver' => $driver,
            ]);
            return;
        }

        Trace::log('seed.validations.skipped', [
            'reason' => 'TEST_SEED_SKIP_VALIDATIONS_AFTER_EXTRAS=1',
            'migrations' => $migrations,
        ]);
    }

    /**
     * @param array<int,string> $migrations
     */
    private static function applyRequestedMigrations(PDO $pdo, string $seedDir, array $migrations, string $driver): void
    {
        foreach ($migrations as $migration) {
            try {
                $migrationDir = self::resolveMigrationDir($seedDir, $migration);
            } catch (\Throwable $e) {
                throw SeedFailure::wrap($e, 'No se pudo resolver el directorio de migración solicitado.', [
                    'stage' => 'migration',
                    'driver' => $driver,
                    'db_driver' => $driver,
                    'label' => 'migration ' . $migration,
                    'hint' => 'Revisá que el directorio exista dentro de test/seeds/<driver>/migrations.',
                ]);
            }

            self::applySqlDir($pdo, $migrationDir, 'migration ' . $migration, 'migration', [
                'driver' => $driver,
                'db_driver' => $driver,
                'label' => 'migration ' . $migration,
                'migration' => $migration,
            ]);
        }
    }

    /**
     * @return array{0: array<int,string>, 1: string, 2: bool, 3: array<string,mixed>}
     */
    private static function migrationPlan(PDO $pdo, string $seedDir, string $baselineMode): array
    {
        $rawMigrations = (string)(getenv('TEST_SEED_MIGRATIONS') ?: '');
        $requested = MigrationCatalog::normalizeSelectedExecutables($seedDir, self::parseCsvEnv('TEST_SEED_MIGRATIONS'));
        $skipPostValidations = self::envBool('TEST_SEED_SKIP_VALIDATIONS_AFTER_EXTRAS', false);
        $autoPending = self::envBool('TEST_MIGRATION_AUTO_PENDING', $baselineMode === 'snapshot');
        if ($baselineMode === 'layered' && $autoPending) {
            throw new RuntimeException(
                'TEST_MIGRATION_AUTO_PENDING no aplica en TEST_BASELINE_MODE=layered. '
                . 'La DB se resetea antes del seed y el baseline resultante debe derivarse '
                . 'solo desde schema/base y TEST_SEED_MIGRATIONS explícitas.'
            );
        }

        if ($baselineMode === 'layered') {
            $migrationState = MigrationStateResolver::resolveLayeredBaseline($seedDir, $requested);
            $planned = array_values((array)($migrationState['applied'] ?? []));
            Trace::log('seed.migration.state', [
                'baseline_mode' => $baselineMode,
                'requested' => $requested,
                'planned' => $planned,
                'state' => $migrationState,
            ]);
            return [$planned, $rawMigrations, $skipPostValidations, $migrationState];
        }

        $migrationState = MigrationStateResolver::resolve($pdo, $seedDir);
        $planned = $requested;

        if ($planned === [] && $autoPending) {
            if ($baselineMode === 'snapshot') {
                MigrationStateResolver::assertHasReliableStateSource($seedDir);
            }
            $planned = array_values((array)($migrationState['pending'] ?? []));
            $rawMigrations = implode(',', $planned);
        }

        Trace::log('seed.migration.state', [
            'baseline_mode' => $baselineMode,
            'requested' => $requested,
            'planned' => $planned,
            'state' => $migrationState,
        ]);

        return [$planned, $rawMigrations, $skipPostValidations, $migrationState];
    }

    /**
     * @return array<string,mixed>
     */
    private static function buildManifestPlan(
        string $driver,
        string $seedDir,
        string $projectRoot,
        string $baselineMode,
        string $databaseName,
        string $manifestPath,
        ?array $resolvedSnapshot
    ): array {
        $migrationState = null;
        try {
            $adapter = StoreRegistry::fromDriver($driver);
            $pdo = $adapter->connect();
            [$migrations, , $skipPostValidations, $migrationState] = self::migrationPlan($pdo, $seedDir, $baselineMode);
        } catch (\Throwable $e) {
            if ($baselineMode === 'snapshot' && $e instanceof RuntimeException) {
                throw $e;
            }
            $migrations = self::parseCsvEnv('TEST_SEED_MIGRATIONS');
            $skipPostValidations = self::envBool('TEST_SEED_SKIP_VALIDATIONS_AFTER_EXTRAS', false);
        }

        $plan = [
            'driver' => $driver,
            'db_name' => $databaseName,
            'baseline_mode' => $baselineMode,
            'project_root' => self::realPathOrOriginal($projectRoot),
            'seed_dir' => self::realPathOrOriginal($seedDir),
            'manifest_path' => self::realPathOrOriginal($manifestPath),
            'resolved_snapshot' => $resolvedSnapshot,
            'requested_migrations' => $migrations,
            'migration_state' => is_array($migrationState) ? $migrationState : null,
            'skip_validations_after_extras' => $skipPostValidations,
            'layers' => [
                'schema' => self::directoryDescriptor($seedDir . '/schema'),
                'base' => self::directoryDescriptor($seedDir . '/base'),
                'validations' => self::directoryDescriptor($seedDir . '/validations'),
            ],
        ];

        if ($baselineMode === 'snapshot') {
            $snapshotFile = trim((string)($resolvedSnapshot['path'] ?? ''));
            $plan['snapshot'] = self::fileDescriptor($snapshotFile);
            $plan['snapshot_resolved_source'] = is_array($resolvedSnapshot) ? $resolvedSnapshot : [];
        }

        if ($migrations !== []) {
            $plan['migration_dirs'] = [];
            foreach ($migrations as $migration) {
                $plan['migration_dirs'][$migration] = self::directoryDescriptor($seedDir . '/migrations/' . $migration);
            }
        }

        $plan['fingerprint'] = hash(
            'sha256',
            (string)json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $plan;
    }

    private static function canReuseCurrentBaseline(
        string $driver,
        string $databaseName,
        string $manifestPath,
        array $manifestPlan
    ): bool {
        if (!self::baselineReuseEnabled()) {
            Trace::log('baseline.reuse.disabled', [
                'driver' => $driver,
                'db' => $databaseName,
            ]);
            return false;
        }

        if (self::baselineInvalidateRequested()) {
            Trace::log('baseline.reuse.disabled', [
                'driver' => $driver,
                'db' => $databaseName,
                'reason' => 'TEST_BASELINE_INVALIDATE=1',
            ]);
            return false;
        }

        $manifest = BaselineManifest::load($manifestPath);
        if ($manifest === null) {
            Trace::log('baseline.reuse.miss', [
                'driver' => $driver,
                'db' => $databaseName,
                'reason' => 'manifest_missing',
                'manifest_path' => self::realPathOrOriginal($manifestPath),
            ]);
            return false;
        }

        $actualFingerprint = (string)($manifestPlan['fingerprint'] ?? '');
        $manifestFingerprint = trim((string)($manifest['baseline_fingerprint'] ?? ''));
        if ($manifestFingerprint === '' || !hash_equals($manifestFingerprint, $actualFingerprint)) {
            Trace::log('baseline.reuse.miss', [
                'driver' => $driver,
                'db' => $databaseName,
                'reason' => 'fingerprint_mismatch',
                'manifest_fingerprint' => $manifestFingerprint,
                'actual_fingerprint' => $actualFingerprint,
            ]);
            return false;
        }

        if (trim((string)($manifest['status'] ?? '')) !== 'ready') {
            Trace::log('baseline.reuse.miss', [
                'driver' => $driver,
                'db' => $databaseName,
                'reason' => 'status_not_ready',
                'status' => (string)($manifest['status'] ?? ''),
            ]);
            return false;
        }

        if (!StoreMaintenance::databaseExists($driver, $databaseName)) {
            Trace::log('baseline.reuse.miss', [
                'driver' => $driver,
                'db' => $databaseName,
                'reason' => 'database_missing',
            ]);
            return false;
        }

        Trace::log('baseline.reuse.hit', [
            'driver' => $driver,
            'db' => $databaseName,
            'manifest_path' => self::realPathOrOriginal($manifestPath),
            'fingerprint' => $actualFingerprint,
        ]);
        return true;
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
     * @return array<string,mixed>
     */
    private static function directoryDescriptor(string $dir): array
    {
        if (!is_dir($dir)) {
            return [
                'path' => self::realPathOrOriginal($dir),
                'exists' => false,
                'files' => [],
                'fingerprint' => null,
            ];
        }

        $files = self::listSqlFiles($dir);
        $fileDescriptors = [];
        foreach ($files as $file) {
            $fileDescriptors[] = self::fileDescriptor($file);
        }

        return [
            'path' => self::realPathOrOriginal($dir),
            'exists' => true,
            'files' => $fileDescriptors,
            'fingerprint' => hash(
                'sha256',
                (string)json_encode($fileDescriptors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function fileDescriptor(string $path): array
    {
        $normalized = self::realPathOrOriginal($path);
        if (!is_file($path)) {
            return [
                'path' => $normalized,
                'exists' => false,
                'size_bytes' => null,
                'sha256' => null,
            ];
        }

        $sha = hash_file('sha256', $path);
        return [
            'path' => $normalized,
            'exists' => true,
            'size_bytes' => filesize($path) ?: 0,
            'sha256' => is_string($sha) ? $sha : null,
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function applySqlDirIfExists(PDO $pdo, string $dir, string $label, string $stage = 'sql', array $context = []): void
    {
        if (!is_dir($dir)) {
            Trace::log('sql.dir.skip_missing', [
                'label' => $label,
                'dir' => self::realPathOrOriginal($dir),
            ]);
            return;
        }

        self::applySqlDir($pdo, $dir, $label, $stage, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function applySqlDir(PDO $pdo, string $dir, string $label, string $stage = 'sql', array $context = []): void
    {
        if (!is_dir($dir)) {
            throw new SeedFailure('No existe el directorio SQL requerido para esta fase del seed.', array_merge($context, [
                'stage' => $stage,
                'label' => $label,
                'db_name' => self::currentDatabaseName($pdo),
                'file' => self::realPathOrOriginal($dir),
                'hint' => 'Revisá la estructura de test/seeds y que el directorio esperado exista antes de bootstrapping.',
            ]));
        }

        $files = self::listSqlFiles($dir);
        Trace::log('sql.dir.resolve', [
            'label' => $label,
            'dir' => self::realPathOrOriginal($dir),
            'count' => count($files),
            'files' => self::normalizePaths($files),
        ]);

        if ($files === []) {
            echo "==> {$label}: sin archivos SQL\n";
            return;
        }

        $suffix = count($files) === 1 ? '1 sql' : count($files) . ' sql';
        echo "==> {$label} ({$suffix})\n";

        if (self::seedVerbose()) {
            foreach ($files as $file) {
                echo "==> {$file}\n";
            }
        }

        foreach ($files as $file) {
            self::applySqlFile($pdo, $file, $stage, array_merge($context, [
                'label' => $label,
            ]));
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function applySqlFile(PDO $pdo, string $file, string $stage = 'sql', array $context = []): void
    {
        $baseContext = array_merge($context, [
            'stage' => $stage,
            'file' => self::realPathOrOriginal($file),
            'db_name' => self::currentDatabaseName($pdo),
        ]);

        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new SeedFailure('No se pudo leer el archivo SQL del seed.', array_merge($baseContext, [
                'hint' => 'Verificá permisos de lectura y que el archivo exista dentro del contenedor/entorno donde corre testkit.',
            ]));
        }

        if (trim($sql) === '') {
            Trace::log('sql.file.skip_empty', [
                'file' => self::realPathOrOriginal($file),
            ]);
            return;
        }

        if (preg_match('/^\s*DELIMITER\s+/mi', $sql) === 1) {
            throw new SeedFailure('El archivo SQL contiene DELIMITER y no puede ejecutarse desde testkit.', array_merge($baseContext, [
                'hint' => 'Convertí procedimientos/triggers a un formato compatible o materializalos fuera del runner SQL plano.',
            ]));
        }

        $statements = self::splitSqlStatements($sql);
        Trace::log('sql.file.start', [
            'file' => self::realPathOrOriginal($file),
            'statements' => count($statements),
        ]);

        $executed = 0;
        try {
            foreach ($statements as $index => $statement) {
                self::executeStatement($pdo, $statement);
                $executed = $index + 1;
            }
        } catch (\Throwable $e) {
            $failedIndex = $executed + 1;
            $statement = isset($statements[$executed]) ? (string)$statements[$executed] : '';
            $errorContext = array_merge($baseContext, [
                'statement_index' => $failedIndex,
                'statement_count' => count($statements),
                'statement_excerpt' => self::statementExcerpt($statement),
                'hint' => self::hintForSqlFailure($stage, $label = (string)($baseContext['label'] ?? ''), $e->getMessage()),
            ], self::sqlErrorContext($e));

            Trace::log('sql.file.fail', [
                'file' => self::realPathOrOriginal($file),
                'statement_index' => $failedIndex,
                'statement_count' => count($statements),
                'error' => $e->getMessage(),
            ]);

            throw SeedFailure::wrap($e, 'Falló la ejecución de una sentencia SQL del seed.', $errorContext);
        }

        Trace::log('sql.file.ok', [
            'file' => self::realPathOrOriginal($file),
            'statements' => count($statements),
        ]);
    }

    /**
     * @return array<int,string>
     */
    private static function listFlatFiles(string $seedDir): array
    {
        $files = self::listSqlFiles($seedDir);
        if ($files !== []) {
            return $files;
        }

        return self::listSqlFiles($seedDir . '/seeds');
    }

    /**
     * @return array<int,string>
     */
    private static function listSqlFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob(rtrim($dir, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        return array_values($files);
    }

    /**
     * @return array<int,string>
     */
    private static function parseCsvEnv(string $name): array
    {
        $raw = trim((string)(getenv($name) ?: ''));
        if ($raw === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $raw));
        $parts = array_values(array_filter($parts, static fn(string $value): bool => $value !== ''));
        return array_values(array_unique($parts));
    }

    private static function envBool(string $name, bool $default = false): bool
    {
        $raw = getenv($name);
        if ($raw === false) {
            return $default;
        }

        return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array<int,string>
     */
    private static function splitSqlStatements(string $sql): array
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $buffer = '';
        $statements = [];
        $insideCompoundStatement = false;

        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ($buffer === '' && ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#'))) {
                continue;
            }

            if (!$insideCompoundStatement && preg_match('/^\s*CREATE\s+(TRIGGER|PROCEDURE|FUNCTION|EVENT)\b/i', $line) === 1) {
                $insideCompoundStatement = true;
            }

            $buffer .= $line . "\n";

            if ($insideCompoundStatement) {
                if (preg_match('/\bEND\s*;\s*$/i', $line) === 1) {
                    $statement = trim($buffer);
                    if ($statement !== '') {
                        $statements[] = $statement;
                    }
                    $buffer = '';
                    $insideCompoundStatement = false;
                }
                continue;
            }

            if (preg_match('/;\s*$/', $line) === 1) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
            }
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }

    private static function executeStatement(PDO $pdo, string $statement): void
    {
        if (preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN|WITH)\b/i', $statement) === 1) {
            $result = $pdo->query($statement);
            if ($result !== false) {
                $result->fetchAll(PDO::FETCH_ASSOC);
                $result->closeCursor();
            }
            return;
        }

        $pdo->exec($statement);
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
            'baseline_reuse' => self::baselineReuseEnabled(),
            'baseline_invalidate' => self::baselineInvalidateRequested(),
            'baseline_manifest_path' => self::realPathOrOriginal($manifestPath),
            'resolved_snapshot' => $resolvedSnapshot,
            'DB_ENV_PATH' => (string)(getenv('DB_ENV_PATH') ?: ''),
            'TESTKIT_PROJECT_ROOT' => (string)(getenv('TESTKIT_PROJECT_ROOT') ?: ''),
            'TK_REPO_ROOT' => (string)(getenv('TK_REPO_ROOT') ?: ''),
            'TEST_MATCH' => (string)(getenv('TEST_MATCH') ?: ''),
        ]);
    }

    private static function resolveMigrationDir(string $seedDir, string $migration): string
    {
        $migrationDir = $seedDir . '/migrations/' . $migration;
        Trace::log('migration.resolve', [
            'migration' => $migration,
            'requested_dir' => $migrationDir,
            'resolved_dir' => self::realPathOrOriginal($migrationDir),
            'exists' => is_dir($migrationDir),
        ]);

        if (!is_dir($migrationDir)) {
            throw new RuntimeException(
                'Migracion solicitada no existe: ' . $migration . ' (' . $migrationDir . ')'
            );
        }

        return $migrationDir;
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

    /**
     * @return array<string,mixed>
     */
    private static function sqlErrorContext(\Throwable $error): array
    {
        $context = [];

        if ($error instanceof \PDOException) {
            $errorInfo = is_array($error->errorInfo ?? null) ? $error->errorInfo : [];
            $sqlState = trim((string)($errorInfo[0] ?? ''));
            $driverCode = trim((string)($errorInfo[1] ?? ''));
            $driverMessage = trim((string)($errorInfo[2] ?? ''));

            if ($sqlState !== '') {
                $context['sqlstate'] = $sqlState;
            }
            if ($driverCode !== '') {
                $context['driver_code'] = $driverCode;
            }
            if ($driverMessage !== '') {
                $context['driver_message'] = $driverMessage;
            }
        }

        $fallbackCode = trim((string)$error->getCode());
        if ($fallbackCode !== '' && !isset($context['sqlstate'])) {
            $context['sqlstate'] = $fallbackCode;
        }

        return $context;
    }

    private static function statementExcerpt(string $statement, int $maxLen = 220): string
    {
        $statement = trim((string)preg_replace('/\s+/', ' ', trim($statement)));
        if ($statement === '') {
            return '';
        }

        if (mb_strlen($statement) <= $maxLen) {
            return $statement;
        }

        return mb_substr($statement, 0, $maxLen - 3) . '...';
    }

    private static function hintForSqlFailure(string $stage, string $label, string $errorMessage): string
    {
        $normalized = strtolower($errorMessage);

        if (str_contains($normalized, 'unknown column') || str_contains($normalized, 'doesn\'t exist')) {
            return 'La sentencia referencia una columna u objeto inexistente. Revisá el orden entre schema/base/migrations y el baseline desde el que parte la DB.';
        }

        if (str_contains($normalized, 'duplicate') || str_contains($normalized, 'already exists')) {
            return 'El seed intenta crear algo que ya existe. Revisá idempotencia del SQL o residuos de una corrida previa al reset.';
        }

        if (str_contains($normalized, 'foreign key') || str_contains($normalized, 'constraint fails')) {
            return 'Hay una violación de integridad. Revisá orden de inserts, datos base requeridos y relaciones creadas en schema.';
        }

        if ($stage === 'schema') {
            return 'El fallo ocurrió en schema. Revisá DDL, compatibilidad con el engine y dependencia entre objetos creados.';
        }

        if ($stage === 'base') {
            return 'El fallo ocurrió en base. Revisá datos iniciales, columnas esperadas por el schema y dependencias entre inserts.';
        }

        if ($stage === 'migration') {
            return 'El fallo ocurrió dentro de una migración opcional o pendiente. Revisá el estado de partida del baseline y qué migraciones ya estaban absorbidas.';
        }

        if ($stage === 'validations') {
            return 'El fallo ocurrió en validations. Revisá que el estado final esperado del baseline coincida con schema/base/migrations aplicadas.';
        }

        if ($label !== '') {
            return 'Revisá la fase ' . $label . ' y el contrato estructural esperado antes de correr tests funcionales.';
        }

        return 'Revisá la sentencia SQL fallida, el estado real de la DB y el orden de ejecución del seed.';
    }
}
