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

        $pdo = $this->adminConnectNoDatabase();
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
            'host' => $this->mysqlHost(),
            'port' => $this->mysqlPort(),
            'db' => $dbName,
            'admin_user' => $this->adminUser(),
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

    public function databaseExists(string $database): bool
    {
        $this->assertSafeDatabaseName($database);
        $pdo = $this->adminConnectNoDatabase();
        $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.schemata WHERE schema_name = ? LIMIT 1');
        $stmt->execute([$database]);
        $exists = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();
        return $exists;
    }

    public function dropDatabase(string $database): void
    {
        $this->assertSafeDatabaseName($database);

        if ($this->provisionMode() === 'external') {
            throw new \RuntimeException(
                'dropDatabase requiere privilegios de provision. Seteá TEST_STORE_PROVISION=managed o implementá un store externo con permisos equivalentes.'
            );
        }

        $pdo = $this->adminConnectNoDatabase();
        $pdo->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($database));

        Trace::log('store.drop_database', [
            'driver' => 'mysql',
            'db' => $database,
        ]);
    }

    public function cloneDatabase(string $sourceDatabase, string $targetDatabase): void
    {
        $this->assertSafeDatabaseName($sourceDatabase);
        $this->assertSafeDatabaseName($targetDatabase);

        if (!$this->databaseExists($sourceDatabase)) {
            throw new \RuntimeException('No existe DB fuente para clone: ' . $sourceDatabase);
        }

        $this->dropDatabase($targetDatabase);
        $this->provision($targetDatabase);

        $binDump = $this->requireBinary('mysqldump');
        $binMysql = $this->requireBinary('mysql');
        $env = $this->mysqlCliEnv(true);

        $source = escapeshellarg($sourceDatabase);
        $target = escapeshellarg($targetDatabase);
        $host = escapeshellarg($this->mysqlHost());
        $port = escapeshellarg($this->mysqlPort());
        $user = escapeshellarg($this->adminUser());

        // set -o pipefail ensures that a mysqldump failure is not masked by mysql's exit code.
        $command = sprintf(
            'set -o pipefail; %s --host=%s --port=%s --user=%s --single-transaction --skip-lock-tables --routines --triggers --events --no-tablespaces %s'
            . ' | %s --host=%s --port=%s --user=%s %s',
            escapeshellcmd($binDump),
            $host,
            $port,
            $user,
            $source,
            escapeshellcmd($binMysql),
            $host,
            $port,
            $user,
            $target
        );

        $this->runShellCommand($command, $env, 'mysql clone database');
        $this->verifyCloneResult($sourceDatabase, $targetDatabase);

        Trace::log('store.clone_database', [
            'driver' => 'mysql',
            'source_db' => $sourceDatabase,
            'target_db' => $targetDatabase,
        ]);
    }

    public function restoreSnapshot(string $artifactPath, ?string $database = null): void
    {
        $targetDatabase = $database ?? $this->resolveDatabaseName();
        $this->assertSafeDatabaseName($targetDatabase);

        if (!is_file($artifactPath)) {
            throw new \RuntimeException('Snapshot no existe: ' . $artifactPath);
        }

        $binMysql = $this->requireBinary('mysql');
        $env = $this->mysqlCliEnv(false);
        $host = escapeshellarg($this->mysqlHost());
        $port = escapeshellarg($this->mysqlPort());
        $user = escapeshellarg($this->runtimeUser());
        $target = escapeshellarg($targetDatabase);
        $file = escapeshellarg($artifactPath);

        if (str_ends_with(strtolower($artifactPath), '.gz')) {
            $binGzip = $this->requireBinary('gzip');
            // set -o pipefail ensures gzip decompression failures are not hidden by mysql's exit code.
            $command = sprintf(
                'set -o pipefail; %s -dc %s | %s --host=%s --port=%s --user=%s %s',
                escapeshellcmd($binGzip),
                $file,
                escapeshellcmd($binMysql),
                $host,
                $port,
                $user,
                $target
            );
        } else {
            $command = sprintf(
                '%s --host=%s --port=%s --user=%s %s < %s',
                escapeshellcmd($binMysql),
                $host,
                $port,
                $user,
                $target,
                $file
            );
        }

        $this->runShellCommand($command, $env, 'mysql restore snapshot');

        Trace::log('store.restore_snapshot', [
            'driver' => 'mysql',
            'db' => $targetDatabase,
            'artifact' => $artifactPath,
        ]);
    }

    /**
     * After a clone, verify that the target database received at least as many tables
     * as the source had. A discrepancy of source > 0 AND target = 0 indicates that
     * mysqldump produced no output (e.g. auth failure, empty dump, early crash).
     *
     * Uses information_schema so no extra binaries or connections are needed.
     * The check is intentionally cheap: we count tables, we do not compare rows.
     */
    private function verifyCloneResult(string $source, string $target): void
    {
        try {
            $pdo  = $this->adminConnectNoDatabase();
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = ? AND table_type = 'BASE TABLE'"
            );

            $stmt->execute([$source]);
            $sourceCount = (int)$stmt->fetchColumn();
            $stmt->closeCursor();

            if ($sourceCount === 0) {
                return; // source was empty — nothing meaningful to check
            }

            $stmt->execute([$target]);
            $targetCount = (int)$stmt->fetchColumn();
            $stmt->closeCursor();

            if ($targetCount === 0) {
                throw new \RuntimeException(
                    sprintf(
                        'Clone fallido: fuente "%s" tiene %d tabla(s) pero target "%s" tiene 0. '
                        . 'Revisá el stderr de mysqldump en el log.',
                        $source,
                        $sourceCount,
                        $target
                    )
                );
            }

            Trace::log('store.clone_verify', [
                'driver'       => 'mysql',
                'source_db'    => $source,
                'target_db'    => $target,
                'source_tables' => $sourceCount,
                'target_tables' => $targetCount,
            ]);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Error verificando clone de DB: ' . $e->getMessage(),
                0,
                $e
            );
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

    private function adminConnectNoDatabase(): PDO
    {
        $host = $this->mysqlHost();
        $port = $this->mysqlPort();
        $user = $this->adminUser();
        $pass = $this->adminPassword();

        return new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    private function mysqlCliEnv(bool $preferAdmin): array
    {
        $password = $preferAdmin ? $this->adminPassword() : $this->runtimePassword();
        return [
            'MYSQL_PWD' => $password,
        ];
    }

    private function mysqlHost(): string
    {
        return $this->requireEnv(
            ['DB_HOST', 'TEST_MYSQL_HOST', 'MYSQL_HOST'],
            'host MySQL',
            'Definí DB_HOST o TEST_MYSQL_HOST en <project>/test/.env.test.'
        );
    }

    private function mysqlPort(): string
    {
        return $this->requireEnv(
            ['DB_PORT', 'TEST_MYSQL_PORT', 'MYSQL_PORT'],
            'puerto MySQL',
            'Definí DB_PORT o TEST_MYSQL_PORT en <project>/test/.env.test.'
        );
    }

    private function runtimeUser(): string
    {
        return $this->requireEnv(
            ['DB_USER', 'TEST_MYSQL_USER', 'MYSQL_USER'],
            'usuario MySQL',
            'Definí DB_USER o TEST_MYSQL_USER en <project>/test/.env.test.'
        );
    }

    private function runtimePassword(): string
    {
        return $this->requireEnv(
            ['DB_PASS', 'TEST_MYSQL_PASSWORD', 'MYSQL_PASSWORD'],
            'password MySQL',
            'Definí DB_PASS o TEST_MYSQL_PASSWORD en <project>/test/.env.test.'
        );
    }

    private function adminUser(): string
    {
        if ($this->provisionMode() === 'external') {
            return $this->runtimeUser();
        }

        return $this->requireEnv(
            ['TEST_MYSQL_ADMIN_USER', 'MYSQL_ROOT_USER'],
            'usuario admin MySQL',
            'Si querés que testkit provisione la DB, definí TEST_MYSQL_ADMIN_USER. Si la DB ya existe y no querés credenciales admin, seteá TEST_STORE_PROVISION=external.'
        );
    }

    private function adminPassword(): string
    {
        if ($this->provisionMode() === 'external') {
            return $this->runtimePassword();
        }

        return $this->requireEnv(
            ['TEST_MYSQL_ROOT_PASSWORD', 'MYSQL_ROOT_PASSWORD'],
            'password admin MySQL',
            'Si querés que testkit provisione la DB, definí TEST_MYSQL_ROOT_PASSWORD. Si la DB ya existe y no querés credenciales admin, seteá TEST_STORE_PROVISION=external.'
        );
    }

    private function requireBinary(string $binary): string
    {
        $path = trim((string)shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));
        if ($path === '') {
            throw new \RuntimeException('No se encontró binario requerido para MySQL baseline flow: ' . $binary);
        }

        return $path;
    }

    private function runShellCommand(string $command, array $env, string $label): void
    {
        $process = proc_open(
            ['bash', '-lc', $command],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            array_merge($_ENV, $env)
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('No se pudo iniciar proceso shell para ' . $label);
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                sprintf(
                    'Fallo %s (exit=%d). stderr=%s stdout=%s',
                    $label,
                    $exitCode,
                    trim((string)$stderr),
                    trim((string)$stdout)
                )
            );
        }
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
