<?php
declare(strict_types=1);
$driver = strtolower($argv[1] ?? 'mysql');
if (!in_array($driver, ['mysql', 'pgsql', 'postgres', 'postgresql'], true)) {
    fwrite(STDERR, "Driver inválido. Usá mysql|pgsql\n");
    exit(1);
}
$driver = str_starts_with($driver, 'pg') ? 'pgsql' : 'mysql';
$projectRoot = rtrim((string)(getenv('TK_REPO_ROOT') ?: '/workspace/project'), '/\\');
$seedDir = $projectRoot . '/test/seeds/' . $driver;
if (!is_dir($seedDir)) {
    fwrite(STDERR, "No existe directorio de seeds: {$seedDir}\n");
    exit(1);
}
$files = glob($seedDir . '/*.sql') ?: [];
sort($files);
if (!$files) {
    fwrite(STDERR, "No hay seeds SQL en {$seedDir}\n");
    exit(1);
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
        exit(1);
    }
    $pdo->exec($sql);
}
echo 'Seeds aplicadas: ' . count($files) . "\n";
