<?php
declare(strict_types=1);

namespace Testkit\Core\Store;

use PDO;

final class PgsqlStoreAdapter implements StoreAdapter
{
    public function driver(): string
    {
        return 'pgsql';
    }

    public function resolveDatabaseName(): string
    {
        return $this->requireEnv(
            ['PG_DB', 'TEST_PG_DB', 'DB_NAME'],
            'nombre de base Postgres'
        );
    }

    public function connect(?string $database = null): PDO
    {
        $host = $this->requireEnv(
            ['PG_HOST', 'TEST_PG_HOST', 'DB_HOST'],
            'host Postgres'
        );
        $port = $this->requireEnv(
            ['PG_PORT', 'TEST_PG_PORT', 'DB_PORT'],
            'puerto Postgres'
        );
        $dbName = $database ?? $this->resolveDatabaseName();
        $user = $this->requireEnv(
            ['PG_USER', 'TEST_PG_USER', 'DB_USER'],
            'usuario Postgres'
        );
        $pass = $this->requireEnv(
            ['PG_PASS', 'TEST_PG_PASSWORD', 'DB_PASS'],
            'password Postgres'
        );

        return new PDO(
            "pgsql:host={$host};port={$port};dbname={$dbName}",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    public function provision(?string $database = null): void
    {
        $dbName = $database ?? $this->resolveDatabaseName();
        $this->assertSafeDatabaseName($dbName);

        $pdo = $this->connect('postgres');
        $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ? LIMIT 1');
        $stmt->execute([$dbName]);
        $exists = $stmt->fetchColumn();

        if ($exists === false) {
            $pdo->exec('CREATE DATABASE ' . $this->quoteIdentifier($dbName));
        }
    }

    public function reset(PDO $pdo): void
    {
        $pdo->exec('DROP SCHEMA IF EXISTS public CASCADE');
        $pdo->exec('CREATE SCHEMA public');
        $pdo->exec('GRANT ALL ON SCHEMA public TO public');
    }

    public function clean(PDO $pdo): void
    {
        $tables = $pdo->query(
            "SELECT tablename
             FROM pg_tables
             WHERE schemaname = 'public'
             ORDER BY tablename"
        )->fetchAll(PDO::FETCH_COLUMN);

        if ($tables === []) {
            return;
        }

        $quoted = [];
        foreach ($tables as $table) {
            if (!is_string($table) || $table === '') {
                continue;
            }

            $quoted[] = $this->quoteIdentifier($table);
        }

        if ($quoted !== []) {
            $pdo->exec('TRUNCATE TABLE ' . implode(', ', $quoted) . ' RESTART IDENTITY CASCADE');
        }
    }

    public function databaseExists(string $database): bool
    {
        $this->assertSafeDatabaseName($database);
        $pdo = $this->connect('postgres');
        $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ? LIMIT 1');
        $stmt->execute([$database]);
        $exists = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();
        return $exists;
    }

    public function dropDatabase(string $database): void
    {
        $this->assertSafeDatabaseName($database);
        $pdo = $this->connect('postgres');
        $pdo->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($database));
    }

    public function cloneDatabase(string $sourceDatabase, string $targetDatabase): void
    {
        throw new \RuntimeException(
            'cloneDatabase para Postgres no esta implementado en esta primera pasada. Foco actual: MySQL.'
        );
    }

    public function restoreSnapshot(string $artifactPath, ?string $database = null): void
    {
        throw new \RuntimeException(
            'restoreSnapshot para Postgres no esta implementado en esta primera pasada. Foco actual: MySQL.'
        );
    }

    private function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    private function assertSafeDatabaseName(string $dbName): void
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $dbName) !== 1) {
            throw new \RuntimeException("Nombre de DB Postgres inválido: {$dbName}");
        }
    }

    /**
     * @param array<int,string> $keys
     */
    private function requireEnv(array $keys, string $label): string
    {
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

        throw new \RuntimeException(
            'Falta ' . $label . ' (' . implode('/', $keys) . ') en test/.env.test o DB_ENV_PATH.'
        );
    }
}
