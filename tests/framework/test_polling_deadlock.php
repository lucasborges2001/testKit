<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Execution\ProcessRunner;

$size = 128 * 1024;
$cmd = [
    PHP_BINARY,
    '-r',
    "file_put_contents('php://stdout', str_repeat('O', {$size})); " .
    "file_put_contents('php://stderr', str_repeat('E', {$size})); " .
    "exit(42);",
];

echo "--- Prueba 1: Bloqueo en Polling (bucle isRunning) ---\n";
$job = ProcessRunner::start($cmd, dirname(__DIR__, 2), []);

if (!($job['ok'] ?? false)) {
    fwrite(STDERR, "Error al iniciar proceso\n");
    exit(2);
}

$timeout = 3.0;
$start = microtime(true);

while (ProcessRunner::isRunning($job)) {
    if ((microtime(true) - $start) > $timeout) {
        echo "[FAIL] Posible deadlock: el proceso sigue bloqueado tras {$timeout}s.\n";
        if (is_resource($job['proc'] ?? null)) {
            proc_terminate($job['proc']);
        }
        exit(1);
    }
    usleep(10000);
}

$finished = ProcessRunner::finish($job);
echo "[OK] Proceso terminado con éxito.\n";
echo "- Exit Code: {$finished['code']} (esperado: 42)\n";
echo "- Stdout: " . strlen((string)$finished['stdout']) . " bytes (esperado: {$size})\n";
echo "- Stderr: " . strlen((string)$finished['stderr']) . " bytes (esperado: {$size})\n";

$ok =
    (int)$finished['code'] === 42
    && strlen((string)$finished['stdout']) === $size
    && strlen((string)$finished['stderr']) === $size;

exit($ok ? 0 : 1);
