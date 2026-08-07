<?php
declare(strict_types=1);

/**
 * TAGS: integration,critical
 * SCOPE: runtime-mysql
 */

$host = (string)(getenv('DB_HOST') ?: 'mysql_test');
$port = (string)(getenv('DB_PORT') ?: '3306');
$db = (string)(getenv('DB_NAME') ?: 'app_test');
$user = (string)(getenv('DB_USER') ?: 'app');
$pass = (string)(getenv('DB_PASS') ?: 'app');

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db),
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $marker = $pdo->query('SELECT marker FROM testkit_runtime_probe WHERE id = 1')->fetchColumn();
    if ($marker !== 'seeded') {
        fwrite(STDERR, "runtime mysql fixture: expected seeded marker\n");
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'runtime mysql fixture failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "runtime mysql fixture PASS\n";
