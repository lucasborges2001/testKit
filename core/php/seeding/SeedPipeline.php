<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use PDO;
use RuntimeException;
use Testkit\Core\Common\Trace;
use Testkit\Core\Store\StoreRegistry;

require_once __DIR__ . '/../store/bootstrap.php';

final class SeedPipeline
{
    public static function run(string $driver, string $projectRoot): int
    {
        $driver = StoreRegistry::normalizeDriver($driver);
        $projectRoot = rtrim($projectRoot, "/\\");
        $seedDir = $projectRoot . '/test/seeds/' . $driver;

        self::traceBootstrapContext($driver, $projectRoot, $seedDir);

        if (!is_dir($seedDir)) {
            throw new RuntimeException("No existe directorio de seeds: {$seedDir}");
        }

        if (self::hasLayeredLayout($seedDir)) {
            return self::runLayered($driver, $seedDir, $projectRoot);
        }

        return self::runFlat($driver, $seedDir);
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
                'TEST_SEED_FIXTURES no forma parte del lifecycle de testkit en modo layered. ' .
                'La infraestructura solo aplica schema/base/migrations/validations; ' .
                'los escenarios deben construirse desde test/_support con builders del proyecto.'
            );
        }

        $adapter = StoreRegistry::fromDriver($driver);
        $pdo = $adapter->connect();

        $rawMigrations = (string)(getenv('TEST_SEED_MIGRATIONS') ?: '');
        $migrations = self::parseCsvEnv('TEST_SEED_MIGRATIONS');
        $skipPostValidations = self::envBool('TEST_SEED_SKIP_VALIDATIONS_AFTER_EXTRAS', false);

        Trace::log('seed.layered.plan', [
            'driver' => $driver,
            'project_root' => $projectRoot,
            'seed_dir' => self::realPathOrOriginal($seedDir),
            'db_env_path' => (string)(getenv('DB_ENV_PATH') ?: ''),
            'db' => self::dbConnectionSummary($driver),
            'raw_TEST_SEED_MIGRATIONS' => $rawMigrations,
            'parsed_TEST_SEED_MIGRATIONS' => $migrations,
            'skip_validations_after_extras' => $skipPostValidations,
            'TEST_MATCH' => (string)(getenv('TEST_MATCH') ?: ''),
            'TEST_SCOPE' => (string)(getenv('TEST_SCOPE') ?: ''),
            'TEST_TARGET' => (string)(getenv('TEST_TARGET') ?: ''),
        ]);

        $adapter->reset($pdo);

        self::applySqlDir($pdo, $seedDir . '/schema', 'schema');
        self::applySqlDir($pdo, $seedDir . '/base', 'base');

        foreach ($migrations as $migration) {
            $migrationDir = self::resolveMigrationDir($seedDir, $migration);
            self::applySqlDir($pdo, $migrationDir, 'migration ' . $migration);
        }

        if (!($migrations !== [] && $skipPostValidations)) {
            self::applySqlDirIfExists($pdo, $seedDir . '/validations', 'validations');
        } else {
            Trace::log('seed.validations.skipped', [
                'reason' => 'TEST_SEED_SKIP_VALIDATIONS_AFTER_EXTRAS=1',
                'migrations' => $migrations,
            ]);
        }

        self::traceCheckoutTablesIfRelevant($pdo, $rawMigrations, $migrations);

        echo "Seed pipeline por capas aplicado correctamente\n";
        return 0;
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

    private static function traceBootstrapContext(string $driver, string $projectRoot, string $seedDir): void
    {
        Trace::log('seed.bootstrap.context', [
            'driver' => $driver,
            'project_root' => self::realPathOrOriginal($projectRoot),
            'seed_dir' => self::realPathOrOriginal($seedDir),
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
                'Checkout trace verification fallo: faltan tablas [' . implode(', ', $missing) . '] ' .
                'despues del pipeline. TEST_SEED_MIGRATIONS(raw)=' . $rawMigrations .
                ' parsed=' . json_encode($migrations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
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
