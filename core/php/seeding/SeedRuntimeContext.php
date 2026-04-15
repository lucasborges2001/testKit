<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use PDO;
use Testkit\Core\Store\StoreAdapter;

require_once __DIR__ . '/../store/bootstrap.php';

final class SeedRuntimeContext
{
    /**
     * @param array<string,mixed>|null $resolvedSnapshot
     */
    public function __construct(
        private string $driver,
        private string $seedDir,
        private string $projectRoot,
        private string $baselineMode,
        private StoreAdapter $adapter,
        private string $databaseName,
        private ?array $resolvedSnapshot
    ) {
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function seedDir(): string
    {
        return $this->seedDir;
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    public function baselineMode(): string
    {
        return $this->baselineMode;
    }

    public function adapter(): StoreAdapter
    {
        return $this->adapter;
    }

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function resolvedSnapshot(): ?array
    {
        return $this->resolvedSnapshot;
    }

    /**
     * @return array<string,string>
     */
    public function connectionSummary(): array
    {
        if ($this->driver === 'pgsql') {
            return [
                'host' => $this->envFirst(['PG_HOST', 'TEST_PG_HOST', 'DB_HOST']),
                'port' => $this->envFirst(['PG_PORT', 'TEST_PG_PORT', 'DB_PORT']),
                'db' => $this->envFirst(['PG_DB', 'TEST_PG_DB', 'DB_NAME']),
                'user' => $this->envFirst(['PG_USER', 'TEST_PG_USER', 'DB_USER']),
            ];
        }

        return [
            'host' => $this->envFirst(['DB_HOST', 'TEST_MYSQL_HOST', 'MYSQL_HOST']),
            'port' => $this->envFirst(['DB_PORT', 'TEST_MYSQL_PORT', 'MYSQL_PORT']),
            'db' => $this->envFirst(['DB_NAME', 'TEST_MYSQL_DB', 'MYSQL_DATABASE']),
            'user' => $this->envFirst(['DB_USER', 'TEST_MYSQL_USER', 'MYSQL_USER']),
        ];
    }

    /**
     * @return array<int,string>
     */
    public function parseCsvEnv(string $name): array
    {
        $raw = trim((string)(getenv($name) ?: ''));
        if ($raw === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $raw));
        $parts = array_values(array_filter($parts, static fn(string $value): bool => $value !== ''));
        return array_values(array_unique($parts));
    }

    public function realPathOrOriginal(string $path): string
    {
        $real = realpath($path);
        return $real !== false ? str_replace('\\', '/', $real) : str_replace('\\', '/', $path);
    }

    /**
     * @param array<int,string> $paths
     * @return array<int,string>
     */
    public function normalizePaths(array $paths): array
    {
        return array_map([$this, 'realPathOrOriginal'], $paths);
    }

    public function currentDatabaseName(PDO $pdo): string
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

    private function envFirst(array $keys): string
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
}
