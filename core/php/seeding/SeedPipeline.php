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

final class SeedPipeline
{
    public static function run(string $driver, string $projectRoot): int
    {
        $driver = StoreRegistry::normalizeDriver($driver);
        $projectRoot = rtrim($projectRoot, "/\\");
        $seedDir = $projectRoot . '/test/seeds/' . $driver;
        $baselineMode = self::baselineMode();
        $adapter = StoreRegistry::fromDriver($driver);
        $databaseName = trim((string)$adapter->resolveDatabaseName());
        $manifestPath = BaselineManifest::pathFor($projectRoot, $driver, $databaseName);
        $resolvedSnapshot = $baselineMode === 'snapshot' ? BackupkitArtifactResolver::resolveFromEnv() : null;

        self::traceBootstrapContext($driver, $projectRoot, $seedDir, $baselineMode, $databaseName, $manifestPath, $resolvedSnapshot);

        if (!is_dir($seedDir)) {
            throw new RuntimeException("No existe directorio de seeds: {$seedDir}");
        }

        $manifestPlan = self::buildManifestPlan($driver, $seedDir, $projectRoot, $baselineMode, $databaseName, $manifestPath, $resolvedSnapshot);
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

    private static function runFlat(string $driver, string $seedDir): int
    {
        $files = self::listFlatFiles($seedDir);
        if ($files === []) {
            throw new RuntimeException("No hay seeds SQL en {$seedDir} ni en {$seedDir}/seeds");
        }

        $adapter = StoreRegistry::fromDriver($driver);
        $pdo = $adapter->connect();
        Trace::log('seed.flat.files', [
            'count' => count($files),
            'files' => self::normalizePaths($files),
        ]);
        foreach ($files as $file) {
            self::applySqlFile($pdo, $file);
        }

        echo 'Seeds aplicadas: ' . count($files) . "\n";
        return 0;
    }

private static function runLayered(string $driver, string $seedDir, string $projectRoot): int
{
    $fixtures = self::parseCsvEnv('TEST_SEED_FIXTURES');
    if ($fixtures !== []) {
        throw new RuntimeException(
            'TEST_SEED_FIXTURES no forma parte del lifecycle de testkit en modo layered. '
            . 'La infraestructura solo aplica schema/base/migrations/validations; '
            . 'los escenarios deben construirse desde test/_support con builders del proyecto.'
        );
    }

    $adapter = StoreRegistry::fromDriver($driver);
    $pdo = $adapter->connect();

    [$migrations, $rawMigrations, $skipPostValidations, $migrationState] = self::migrationPlan($pdo, $seedDir, 'layered');

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

    $adapter->reset($pdo);

    self::applySqlDir($pdo, $seedDir . '/schema', 'schema');
    self::applySqlDir($pdo, $seedDir . '/base', 'base');
    self::applyRequestedMigrations($pdo, $seedDir, $migrations);
    self::applyPostValidations($pdo, $seedDir, $migrations, $skipPostValidations);

    self::traceCheckoutTablesIfRelevant($pdo, $rawMigrations, $migrations);

    echo "Seed pipeline por capas aplicado correctamente
";
    return 0;
}

/**
 * @param array<string,mixed>|null $resolvedSnapshot
 */
private static function runSnapshot(string $driver, string $seedDir, string $projectRoot, ?array $resolvedSnapshot): int
{
    $adapter = StoreRegistry::fromDriver($driver);
    $pdo = $adapter->connect();
    $resolvedSnapshot ??= BackupkitArtifactResolver::resolveFromEnv();
    $snapshotFile = trim((string)($resolvedSnapshot['path'] ?? ''));

    if ($snapshotFile === '') {
        throw new RuntimeException(
            'TEST_BASELINE_MODE=snapshot requiere snapshot resoluble desde TEST_BASELINE_SNAPSHOT_FILE o backupkit metadata/report.'
        );
    }

    $adapter->reset($pdo);
    $adapter->restoreSnapshot($snapshotFile);

    [$migrations, $rawMigrations, $skipPostValidations, $migrationState] = self::migrationPlan($pdo, $seedDir, 'snapshot');

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

    self::applyRequestedMigrations($pdo, $seedDir, $migrations);
    self::applyPostValidations($pdo, $seedDir, $migrations, $skipPostValidations);

    self::traceCheckoutTablesIfRelevant($pdo, $rawMigrations, $migrations);

    echo "Seed pipeline snapshot aplicado correctamente
";
    return 0;
}

private static function applyPostValidations(PDO $pdo, string $seedDir, array $migrations, bool $skipPostValidations): void
    {
        if (!($migrations !== [] && $skipPostValidations)) {
            self::applySqlDirIfExists($pdo, $seedDir . '/validations', 'validations');
            return;
        }

        Trace::log('seed.validations.skipped', [
            'reason' => 'TEST_SEED_SKIP_VALIDATIONS_AFTER_EXTRAS=1',
            'migrations' => $migrations,
        ]);
    }

    private static function applyRequestedMigrations(PDO $pdo, string $seedDir, array $migrations): void
    {
        foreach ($migrations as $migration) {
            $migrationDir = self::resolveMigrationDir($seedDir, $migration);
            self::applySqlDir($pdo, $migrationDir, 'migration ' . $migration);
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
            'TEST_MIGRATION_AUTO_PENDING no aplica en TEST_BASELINE_MODE=layered. ' .
            'La DB se resetea antes del seed y el baseline resultante debe derivarse ' .
            'solo desde schema/base y TEST_SEED_MIGRATIONS explícitas.'
        );
    }

    if ($baselineMode === 'layered') {
        $migrationState = MigrationStateResolver::resolveLayeredBaseline($seedDir, $requested);
        Trace::log('seed.migration.state', [
            'baseline_mode' => $baselineMode,
            'requested' => $requested,
            'planned' => $requested,
            'state' => $migrationState,
        ]);
        return [$requested, $rawMigrations, $skipPostValidations, $migrationState];
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
                'mtime' => null,
                'sha256' => null,
            ];
        }

        $sha = hash_file('sha256', $path);
        return [
            'path' => $normalized,
            'exists' => true,
            'size_bytes' => filesize($path) ?: 0,
            'mtime' => @filemtime($path) ?: 0,
            'sha256' => is_string($sha) ? $sha : null,
        ];
    }

    private static function applySqlDirIfExists(PDO $pdo, string $dir, string $label): void
    {
        if (!is_dir($dir)) {
            Trace::log('sql.dir.skip_missing', [
                'label' => $label,
                'dir' => self::realPathOrOriginal($dir),
            ]);
            return;
        }

        self::applySqlDir($pdo, $dir, $label);
    }

    private static function applySqlDir(PDO $pdo, string $dir, string $label): void
    {
        if (!is_dir($dir)) {
            throw new RuntimeException("No existe directorio SQL: {$dir}");
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

        echo "==> {$label}\n";
        foreach ($files as $file) {
            self::applySqlFile($pdo, $file);
        }
    }

    private static function applySqlFile(PDO $pdo, string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("No se pudo leer {$file}");
        }

        if (trim($sql) === '') {
            Trace::log('sql.file.skip_empty', [
                'file' => self::realPathOrOriginal($file),
            ]);
            return;
        }

        if (preg_match('/^\s*DELIMITER\s+/mi', $sql) === 1) {
            throw new RuntimeException(
                'El archivo SQL contiene DELIMITER y no puede ejecutarse desde testkit [' . basename($file) . ']'
            );
        }

        $statements = self::splitSqlStatements($sql);
        Trace::log('sql.file.start', [
            'file' => self::realPathOrOriginal($file),
            'statements' => count($statements),
        ]);

        echo "==> {$file}\n";
        $executed = 0;
        try {
            foreach ($statements as $index => $statement) {
                self::executeStatement($pdo, $statement);
                $executed = $index + 1;
            }
        } catch (\Throwable $e) {
            Trace::log('sql.file.fail', [
                'file' => self::realPathOrOriginal($file),
                'statement_index' => $executed + 1,
                'statement_count' => count($statements),
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                sprintf(
                    'Fallo aplicando SQL [%s] en statement %d/%d: %s',
                    $file,
                    $executed + 1,
                    count($statements),
                    $e->getMessage()
                ),
                0,
                $e
            );
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

    /**
     * @param array<int,string> $migrations
     */
    private static function traceCheckoutTablesIfRelevant(PDO $pdo, string $rawMigrations, array $migrations): void
    {
        if (!Trace::migrationsEnabled()) {
            return;
        }

        $match = strtolower((string)(getenv('TEST_MATCH') ?: ''));
        $target = strtolower((string)(getenv('TEST_TARGET') ?: ''));
        $scopeHint = strtolower((string)(getenv('TK_BACK_PHP_DIR') ?: ''));
        $relevant = in_array('016_checkout', $migrations, true)
            || str_contains($match, 'checkout')
            || str_contains($target, 'checkout')
            || str_contains($scopeHint, 'checkout');

        if (!$relevant) {
            return;
        }

        $requiredTables = ['CheckoutCargaOrden', 'CheckoutCargaEvento'];
        $missing = [];
        foreach ($requiredTables as $table) {
            $exists = self::tableExists($pdo, $table);
            Trace::log('checkout.table.verify', [
                'table' => $table,
                'exists' => $exists,
            ]);
            if (!$exists) {
                $missing[] = $table;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Checkout trace verification fallo: faltan tablas [' . implode(', ', $missing) . '] '
                . 'despues del pipeline. TEST_SEED_MIGRATIONS(raw)=' . $rawMigrations
                . ' parsed=' . json_encode($migrations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);
        $exists = (int)$stmt->fetchColumn() === 1;
        $stmt->closeCursor();
        return $exists;
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
}
