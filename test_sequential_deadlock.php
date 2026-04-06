<?php
declare(strict_types=1);

require_once __DIR__ . '/core/php/bootstrap.php';

use Testkit\Core\Execution\ProcessRunner;

$size = 128 * 1024;
$cmd = [
    PHP_BINARY,
    '-r',
    "file_put_contents('php://stderr', str_repeat('E', {$size})); exit(33);",
];

echo "--- Prueba 2: Bloqueo secuencial (finish) ---\n";
$job = ProcessRunner::start($cmd, __DIR__, []);

if (!($job['ok'] ?? false)) {
    fwrite(STDERR, "Error al iniciar proceso\n");
    exit(2);
}

$finished = ProcessRunner::finish($job);
echo "[OK] finish() terminó.\n";
echo "- Exit Code: {$finished['code']} (esperado: 33)\n";
echo "- Stderr: " . strlen((string)$finished['stderr']) . " bytes (esperado: {$size})\n";

$ok =
    (int)$finished['code'] === 33
    && strlen((string)$finished['stderr']) === $size;

exit($ok ? 0 : 1);
