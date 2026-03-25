<?php
declare(strict_types=1);

$driver = strtolower($argv[1] ?? 'mysql');
if (!in_array($driver, ['mysql', 'pgsql', 'postgres', 'postgresql'], true)) {
    fwrite(STDERR, "Driver inválido. Usá mysql|pgsql\n");
    exit(1);
}
$driver = str_starts_with($driver, 'pg') ? 'pgsql' : 'mysql';
$projectRoot = rtrim((string)(getenv('TK_REPO_ROOT') ?: getenv('TESTKIT_PROJECT_ROOT') ?: '/workspace/project'), "/\\");
$seedDir = $projectRoot . '/test/seeds/' . $driver;
if (!is_dir($seedDir)) {
    fwrite(STDERR, "No existe directorio de seeds: {$seedDir}\n");
    exit(1);
}

/**
 * @return array<int,string>
 */
function tk_parse_csv_env(string $name): array
{
    $raw = trim((string)(getenv($name) ?: ''));
    if ($raw === '') {
        return [];
    }

    $parts = array_map('trim', explode(',', $raw));
    $parts = array_values(array_filter($parts, static fn(string $v): bool => $v !== ''));
    return array_values(array_unique($parts));
}

function tk_env_bool(string $name, bool $default = false): bool
{
    $raw = getenv($name);
    if ($raw === false) {
        return $default;
    }
    return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
}

function tk_run_flat_seed_dir(string $driver, string $seedDir): int
{
    $files = glob($seedDir . '/*.sql') ?: [];
    sort($files);

    if (!$files) {
        $nestedSeedDir = $seedDir . '/seeds';
        $files = glob($nestedSeedDir . '/*.sql') ?: [];
        sort($files);
    }

    if (!$files) {
        fwrite(STDERR, "No hay seeds SQL en {$seedDir} ni en {$seedDir}/seeds\n");
        return 1;
    }

    $host = getenv($driver === 'mysql' ? 'DB_HOST' : 'PG_HOST') ?: ($driver === 'mysql' ? 'mysql_test' : 'postgres_test');
    $port = getenv($driver === 'mysql' ? 'DB_PORT' : 'PG_PORT') ?: ($driver === 'mysql' ? '3306' : '5432');
    $db   = getenv($driver === 'mysql' ? 'DB_NAME' : 'PG_DB') ?: 'app_test';
    $user = getenv($driver === 'mysql' ? 'DB_USER' : 'PG_USER') ?: 'app';
    $pass = getenv($driver === 'mysql' ? 'DB_PASS' : 'PG_PASS') ?: 'app';
    $dsn = $driver === 'mysql'
        ? "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4"
        : "pgsql:host={$host};port={$port};dbname={$db}";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    foreach ($files as $file) {
        echo "==> {$file}\n";
        $sql = file_get_contents($file);
        if ($sql === false) {
            fwrite(STDERR, "No se pudo leer {$file}\n");
            return 1;
        }
        $pdo->exec($sql);
    }

    echo 'Seeds aplicadas: ' . count($files) . "\n";
    return 0;
}

function tk_run_layered_mysql(string $projectRoot): int
{
    $support = $projectRoot . '/test/_support/seed_db_support.php';
    if (!is_file($support)) {
        fwrite(STDERR, "Falta soporte de seeds por capas: {$support}\n");
        return 1;
    }

    require_once $support;

    if (!function_exists('test_seed_db') || !function_exists('test_seed_bootstrap_minimal_world')) {
        fwrite(STDERR, "El soporte de seeds no expone las funciones esperadas en {$support}\n");
        return 1;
    }

    $db = test_seed_db();
    test_seed_bootstrap_minimal_world($db);

    $migrations = tk_parse_csv_env('TEST_SEED_MIGRATIONS');
    foreach ($migrations as $migration) {
        echo "==> migration {$migration}\n";
        test_seed_apply_migration($db, $migration);
    }

    $fixtures = tk_parse_csv_env('TEST_SEED_FIXTURES');
    foreach ($fixtures as $fixtureSpec) {
        $group = $fixtureSpec;
        $file = null;
        if (str_contains($fixtureSpec, ':')) {
            [$group, $file] = array_map('trim', explode(':', $fixtureSpec, 2));
            $file = $file !== '' ? $file : null;
        }
        echo "==> fixture {$fixtureSpec}\n";
        test_seed_apply_fixture($db, $group, $file);
    }

    if (!tk_env_bool('TEST_SEED_SKIP_VALIDATIONS_AFTER_EXTRAS', false) && ($migrations !== [] || $fixtures !== [])) {
        if ($fixtures !== []) {
            putenv('TEST_SEED_VALIDATION_MODE=permissive');
        }
        echo "==> validations after extras\n";
        test_seed_run_validations($db);
    }

    echo "Seed pipeline por capas aplicado correctamente\n";
    return 0;
}

$layeredMysql = $driver === 'mysql'
    && is_dir($seedDir . '/schema')
    && is_dir($seedDir . '/base')
    && is_dir($projectRoot . '/test/_support');

try {
    $code = $layeredMysql
        ? tk_run_layered_mysql($projectRoot)
        : tk_run_flat_seed_dir($driver, $seedDir);
    exit($code);
} catch (Throwable $e) {
    fwrite(STDERR, '[seed_router] ' . $e->getMessage() . "\n");
    exit(1);
}
