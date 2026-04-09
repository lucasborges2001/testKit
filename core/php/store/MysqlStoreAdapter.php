<?php
declare(strict_types=1);

namespace Testkit\Core\Store;

use PDO;
use Testkit\Core\Common\Trace;

final class MysqlStoreAdapter implements StoreAdapter
{
    public function driver(): string
    {
        return 'mysql';
    }

    public function resolveDatabaseName(): string
    {
        return $this->requireEnv(
            ['DB_NAME', 'TEST_MYSQL_DB', 'MYSQL_DATABASE'],
            'nombre de base MySQL',
            'Definí DB_NAME o TEST_MYSQL_DB en <project>/test/.env.test.'
        );
    }

    public function connect(?string $database = null): PDO
    {
        $host = $this->requireEnv(
            ['DB_HOST', 'TEST_MYSQL_HOST', 'MYSQL_HOST'],
            'host MySQL',
            'Definí DB_HOST o TEST_MYSQL_HOST en <project>/test/.env.test.'
        );
        $port = $this->requireEnv(
            ['DB_PORT', 'TEST_MYSQL_PORT', 'MYSQL_PORT'],
            'puerto MySQL',
            'Definí DB_PORT o TEST_MYSQL_PORT en <project>/test/.env.test.'
        );
        $dbName = $database ?? $this->resolveDatabaseName();
        $user = $this->requireEnv(
            ['DB_USER', 'TEST_MYSQL_USER', 'MYSQL_USER'],
            'usuario MySQL',
            'Definí DB_USER o TEST_MYSQL_USER en <project>/test/.env.test.'
        );
        $pass = $this->requireEnv(
            ['DB_PASS', 'TEST_MYSQL_PASSWORD', 'MYSQL_PASSWORD'],
            'password MySQL',
            'Definí DB_PASS o TEST_MYSQL_PASSWORD en <project>/test/.env.test.'
        );

        Trace::log('store.connect', [
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'db' => $dbName,
            'user' => $user,
        ]);

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

        $mode = $this->provisionMode();
        if ($mode === 'external') {
            Trace::log('store.provision.skipped', [
                'driver' => 'mysql',
                'db' => $dbName,
                'mode' => $mode,
                'reason' => 'external_store_declared_by_contract',
            ]);
            return;
        }

        $host = $this->requireEnv(
            ['DB_HOST', 'TEST_MYSQL_HOST', 'MYSQL_HOST'],
            'host MySQL',
            'Definí DB_HOST o TEST_MYSQL_HOST en <project>/test/.env.test.'
        );
        $port = $this->requireEnv(
            ['DB_PORT', 'TEST_MYSQL_PORT', 'MYSQL_PORT'],
            'puerto MySQL',
            'Definí DB_PORT o TEST_MYSQL_PORT en <project>/test/.env.test.'
        );
        $adminUser = $this->requireEnv(
            ['TEST_MYSQL_ADMIN_USER', 'MYSQL_ROOT_USER'],
            'usuario admin MySQL',
            'Si querés que testkit provisione la DB, definí TEST_MYSQL_ADMIN_USER. Si la DB ya existe y no querés credenciales admin, seteá TEST_STORE_PROVISION=external.'
        );
        $adminPass = $this->requireEnv(
            ['TEST_MYSQL_ROOT_PASSWORD', 'MYSQL_ROOT_PASSWORD'],
            'password admin MySQL',
            'Si querés que testkit provisione la DB, definí TEST_MYSQL_ROOT_PASSWORD. Si la DB ya existe y no querés credenciales admin, seteá TEST_STORE_PROVISION=external.'
        );

        $pdo = new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $adminUser,
            $adminPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.schemata WHERE schema_name = ? LIMIT 1');
        $stmt->execute([$dbName]);
        $exists = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();

        $pdo->exec(
            'CREATE DATABASE IF NOT EXISTS ' . $this->quoteIdentifier($dbName)
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        Trace::log('store.provision', [
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'db' => $dbName,
            'admin_user' => $adminUser,
            'action' => $exists ? 'validated_existing_database' : 'created_database',
            'mode' => $mode,
        ]);
    }

    public function reset(PDO $pdo): void
    {
        Trace::log('store.reset.start', [
            'driver' => 'mysql',
            'db' => $this->currentDatabaseName($pdo),
            'action' => 'drop_all_objects',
        ]);

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $views = $this->dropObjects(
                $pdo,
                "SELECT table_name AS name
                 FROM information_schema.views
                 WHERE table_schema = DATABASE()
                 ORDER BY table_name",
                fn(string $name): string => 'DROP VIEW IF EXISTS ' . $this->quoteIdentifier($name)
            );

            $tables = $this->dropObjects(
                $pdo,
                "SELECT table_name AS name
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_type = 'BASE TABLE'
                 ORDER BY table_name",
                fn(string $name): string => 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($name)
            );

            $procedures = $this->dropObjects(
                $pdo,
                "SELECT routine_name AS name
                 FROM information_schema.routines
                 WHERE routine_schema = DATABASE()
                   AND routine_type = 'PROCEDURE'
                 ORDER BY routine_name",
                fn(string $name): string => 'DROP PROCEDURE IF EXISTS ' . $this->quoteIdentifier($name)
            );

            $functions = $this->dropObjects(
                $pdo,
                "SELECT routine_name AS name
                 FROM information_schema.routines
                 WHERE routine_schema = DATABASE()
                   AND routine_type = 'FUNCTION'
                 ORDER BY routine_name",
                fn(string $name): string => 'DROP FUNCTION IF EXISTS ' . $this->quoteIdentifier($name)
            );

            $events = $this->dropObjects(
                $pdo,
                "SELECT event_name AS name
                 FROM information_schema.events
                 WHERE event_schema = DATABASE()
                 ORDER BY event_name",
                fn(string $name): string => 'DROP EVENT IF EXISTS ' . $this->quoteIdentifier($name)
            );

            Trace::log('store.reset.ok', [
                'driver' => 'mysql',
                'db' => $this->currentDatabaseName($pdo),
                'views_dropped' => $views,
                'tables_dropped' => $tables,
                'procedures_dropped' => $procedures,
                'functions_dropped' => $functions,
                'events_dropped' => $events,
            ]);
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    public function clean(PDO $pdo): void
    {
        Trace::log('store.clean.start', [
            'driver' => 'mysql',
            'db' => $this->currentDatabaseName($pdo),
            'action' => 'truncate_all_tables',
        ]);

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $tables = $pdo->query(
                "SELECT table_name AS name
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_type = 'BASE TABLE'
                 ORDER BY table_name"
            )->fetchAll(PDO::FETCH_COLUMN);

            $truncated = 0;
            foreach ($tables as $table) {
                if (!is_string($table) || $table === '') {
                    continue;
                }

                $pdo->exec('TRUNCATE TABLE ' . $this->quoteIdentifier($table));
                $truncated++;
            }

            Trace::log('store.clean.ok', [
                'driver' => 'mysql',
                'db' => $this->currentDatabaseName($pdo),
                'tables_truncated' => $truncated,
            ]);
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function dropObjects(PDO $pdo, string $sql, callable $buildDropSql): int
    {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $dropped = 0;
        foreach ($rows as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $pdo->exec((string)$buildDropSql($name));
            $dropped++;
        }

        return $dropped;
    }

    private function currentDatabaseName(PDO $pdo): string
    {
        $value = $pdo->query('SELECT DATABASE()')->fetchColumn();
        return is_string($value) && $value !== '' ? $value : $this->resolveDatabaseName();
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

    private function provisionMode(): string
    {
        $mode = strtolower(trim((string)(getenv('TEST_STORE_PROVISION') ?: 'managed')));
        if (!in_array($mode, ['managed', 'external'], true)) {
            return 'managed';
        }

        return $mode;
    }

    /**
     * @param array<int,string> $keys
     */
    private function requireEnv(array $keys, string $label, string $help = ''): string
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

        $message = 'Falta ' . $label . ' (' . implode('/', $keys) . ') en test/.env.test o DB_ENV_PATH.';
        if ($help !== '') {
            $message .= ' ' . $help;
        }

        throw new \RuntimeException($message);
    }
}
