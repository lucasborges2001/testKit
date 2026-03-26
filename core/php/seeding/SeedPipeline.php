<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use PDO;
use Testkit\Core\Store\StoreRegistry;

require_once __DIR__ . '/../store/bootstrap.php';

final class SeedPipeline
{
    public static function run(string $driver, string $projectRoot): int
    {
        $driver = StoreRegistry::normalizeDriver($driver);
        $projectRoot = rtrim($projectRoot, "/\\");
        $seedDir = $projectRoot . '/test/seeds/' . $driver;

        if (!is_dir($seedDir)) {
            throw new \RuntimeException("No existe directorio de seeds: {$seedDir}");
        }

        if (self::hasLayeredLayout($seedDir)) {
            return self::runLayered($driver, $seedDir);
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
            throw new \RuntimeException("No hay seeds SQL en {$seedDir} ni en {$seedDir}/seeds");
        }

        $adapter = StoreRegistry::fromDriver($driver);
        $pdo = $adapter->connect();
        foreach ($files as $file) {
            self::applySqlFile($pdo, $file);
        }

        echo 'Seeds aplicadas: ' . count($files) . "\n";
        return 0;
    }

    private static function runLayered(string $driver, string $seedDir): int
    {
        $fixtures = self::parseCsvEnv('TEST_SEED_FIXTURES');
        if ($fixtures !== []) {
            throw new \RuntimeException(
                'TEST_SEED_FIXTURES no forma parte del lifecycle de testkit en modo layered. ' .
                'La infraestructura solo aplica schema/base/migrations/validations; ' .
                'los escenarios deben construirse desde test/_support con builders del proyecto.'
            );
        }

        $adapter = StoreRegistry::fromDriver($driver);
        $pdo = $adapter->connect();

        $adapter->reset($pdo);
        self::applySqlDir($pdo, $seedDir . '/schema', 'schema');
        self::applySqlDir($pdo, $seedDir . '/base', 'base');
        self::applySqlDirIfExists($pdo, $seedDir . '/validations', 'validations');

        $migrations = self::parseCsvEnv('TEST_SEED_MIGRATIONS');
        foreach ($migrations as $migration) {
            $migrationDir = $seedDir . '/migrations/' . $migration;
            self::applySqlDir($pdo, $migrationDir, 'migration ' . $migration);
        }

        if (
            $migrations !== []
            && !self::envBool('TEST_SEED_SKIP_VALIDATIONS_AFTER_EXTRAS', false)
        ) {
            self::applySqlDirIfExists($pdo, $seedDir . '/validations', 'validations post-migrations');
        }

        echo "Seed pipeline por capas aplicado correctamente\n";
        return 0;
    }

    private static function applySqlDirIfExists(PDO $pdo, string $dir, string $label): void
    {
        if (!is_dir($dir)) {
            return;
        }

        self::applySqlDir($pdo, $dir, $label);
    }

    private static function applySqlDir(PDO $pdo, string $dir, string $label): void
    {
        if (!is_dir($dir)) {
            throw new \RuntimeException("No existe directorio SQL: {$dir}");
        }

        $files = self::listSqlFiles($dir);
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
            throw new \RuntimeException("No se pudo leer {$file}");
        }

        if (trim($sql) === '') {
            return;
        }

        if (preg_match('/^\s*DELIMITER\s+/mi', $sql) === 1) {
            throw new \RuntimeException(
                'El archivo SQL contiene DELIMITER y no puede ejecutarse desde testkit [' . basename($file) . ']'
            );
        }

        echo "==> {$file}\n";
        $pdo->exec($sql);
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
}
