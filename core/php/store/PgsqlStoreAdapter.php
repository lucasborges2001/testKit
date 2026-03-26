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
        return (string)(getenv('PG_DB') ?: (getenv('TEST_PG_DB') ?: 'app_test'));
    }

    public function connect(?string $database = null): PDO
    {
        $host = (string)(getenv('PG_HOST') ?: 'postgres_test');
        $port = (string)(getenv('PG_PORT') ?: '5432');
        $dbName = $database ?? $this->resolveDatabaseName();
        $user = (string)(getenv('PG_USER') ?: (getenv('TEST_PG_USER') ?: 'app'));
        $pass = (string)(getenv('PG_PASS') ?: (getenv('TEST_PG_PASSWORD') ?: 'app'));

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
}
