<?php
declare(strict_types=1);

namespace Testkit\Core\Store;

use PDO;

final class MysqlStoreAdapter implements StoreAdapter
{
    public function driver(): string
    {
        return 'mysql';
    }

    public function resolveDatabaseName(): string
    {
        return (string)(getenv('DB_NAME') ?: (getenv('TEST_MYSQL_DB') ?: 'app_test'));
    }

    public function connect(?string $database = null): PDO
    {
        $host = (string)(getenv('DB_HOST') ?: 'mysql_test');
        $port = (string)(getenv('DB_PORT') ?: '3306');
        $dbName = $database ?? $this->resolveDatabaseName();
        $user = (string)(getenv('DB_USER') ?: (getenv('TEST_MYSQL_USER') ?: 'app'));
        $pass = (string)(getenv('DB_PASS') ?: (getenv('TEST_MYSQL_PASSWORD') ?: 'app'));

        return new PDO(
            "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
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

        $host = (string)(getenv('DB_HOST') ?: 'mysql_test');
        $port = (string)(getenv('DB_PORT') ?: '3306');
        $adminUser = (string)(getenv('TEST_MYSQL_ADMIN_USER') ?: 'root');
        $adminPass = (string)(getenv('TEST_MYSQL_ROOT_PASSWORD') ?: '');

        $pdo = new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $adminUser,
            $adminPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        $pdo->exec(
            'CREATE DATABASE IF NOT EXISTS ' . $this->quoteIdentifier($dbName)
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    }

    public function reset(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $this->dropObjects(
                $pdo,
                "SELECT table_name AS name
                 FROM information_schema.views
                 WHERE table_schema = DATABASE()
                 ORDER BY table_name",
                fn(string $name): string => 'DROP VIEW IF EXISTS ' . $this->quoteIdentifier($name)
            );

            $this->dropObjects(
                $pdo,
                "SELECT table_name AS name
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_type = 'BASE TABLE'
                 ORDER BY table_name",
                fn(string $name): string => 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($name)
            );

            $this->dropObjects(
                $pdo,
                "SELECT routine_name AS name
                 FROM information_schema.routines
                 WHERE routine_schema = DATABASE()
                   AND routine_type = 'PROCEDURE'
                 ORDER BY routine_name",
                fn(string $name): string => 'DROP PROCEDURE IF EXISTS ' . $this->quoteIdentifier($name)
            );

            $this->dropObjects(
                $pdo,
                "SELECT routine_name AS name
                 FROM information_schema.routines
                 WHERE routine_schema = DATABASE()
                   AND routine_type = 'FUNCTION'
                 ORDER BY routine_name",
                fn(string $name): string => 'DROP FUNCTION IF EXISTS ' . $this->quoteIdentifier($name)
            );

            $this->dropObjects(
                $pdo,
                "SELECT event_name AS name
                 FROM information_schema.events
                 WHERE event_schema = DATABASE()
                 ORDER BY event_name",
                fn(string $name): string => 'DROP EVENT IF EXISTS ' . $this->quoteIdentifier($name)
            );
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    public function clean(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $tables = $pdo->query(
                "SELECT table_name AS name
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_type = 'BASE TABLE'
                 ORDER BY table_name"
            )->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                if (!is_string($table) || $table === '') {
                    continue;
                }

                $pdo->exec('TRUNCATE TABLE ' . $this->quoteIdentifier($table));
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function dropObjects(PDO $pdo, string $sql, callable $buildDropSql): void
    {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $pdo->exec((string)$buildDropSql($name));
        }
    }

    private function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function assertSafeDatabaseName(string $dbName): void
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $dbName) !== 1) {
            throw new \RuntimeException("Nombre de DB MySQL inválido: {$dbName}");
        }
    }
}
