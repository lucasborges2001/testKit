<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use PDO;
use RuntimeException;
use Testkit\Core\Common\Trace;

final class SqlSeedExecutor
{
    /**
     * @return array<int,string>
     */
    public static function listFlatFiles(string $seedDir): array
    {
        $files = self::listSqlFiles($seedDir);
        if ($files !== []) {
            return $files;
        }

        return self::listSqlFiles($seedDir . '/seeds');
    }

    /**
     * @param array<int,string> $migrations
     */
    public static function applyRequestedMigrations(PDO $pdo, string $seedDir, array $migrations, string $driver): void
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
     * @param array<int,string> $migrations
     */
    public static function applyPostValidations(PDO $pdo, string $seedDir, array $migrations, bool $skipPostValidations, string $driver): void
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
     * @param array<string,mixed> $context
     */
    public static function applySqlDirIfExists(PDO $pdo, string $dir, string $label, string $stage = 'sql', array $context = []): void
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
    public static function applySqlDir(PDO $pdo, string $dir, string $label, string $stage = 'sql', array $context = []): void
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
            if (!SeedConsoleNarrative::isCompact()) {
                echo "==> {$label}: sin archivos SQL\n";
            }
            return;
        }

        if (!SeedConsoleNarrative::isCompact()) {
            $suffix = count($files) === 1 ? '1 sql' : count($files) . ' sql';
            echo "==> {$label} ({$suffix})\n";

            if (self::seedVerbose()) {
                foreach ($files as $file) {
                    echo "==> {$file}\n";
                }
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
    public static function applySqlFile(PDO $pdo, string $file, string $stage = 'sql', array $context = []): void
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
                'statement_excerpt' => SqlFailureHintResolver::statementExcerpt($statement),
                'hint' => SqlFailureHintResolver::hintForSqlFailure($stage, (string)($baseContext['label'] ?? ''), $e->getMessage()),
            ], SqlFailureHintResolver::sqlErrorContext($e));

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
    public static function listSqlFiles(string $dir): array
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
    public static function splitSqlStatements(string $sql): array
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

    public static function executeStatement(PDO $pdo, string $statement): void
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

    private static function seedVerbose(): bool
    {
        $raw = getenv('TESTKIT_SEED_VERBOSE');
        if ($raw === false) {
            return false;
        }

        return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
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
            throw new RuntimeException('Migracion solicitada no existe: ' . $migration . ' (' . $migrationDir . ')');
        }

        return $migrationDir;
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
