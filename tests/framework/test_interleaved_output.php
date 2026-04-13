<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Execution\ProcessRunner;

echo "--- Prueba: Salida Intercalada y Progresiva ---\n";

$size = 32 * 1024; // 32KB
$cmd = [
    PHP_BINARY,
    '-r',
    "
    echo 'OUT1';
    file_put_contents('php://stdout', str_repeat('A', {$size}));
    usleep(100000);
    fwrite(STDERR, 'ERR1');
    file_put_contents('php://stderr', str_repeat('B', {$size}));
    usleep(100000);
    echo 'OUT2';
    file_put_contents('php://stdout', str_repeat('C', {$size}));
    usleep(100000);
    fwrite(STDERR, 'ERR2');
    file_put_contents('php://stderr', str_repeat('D', {$size}));
    exit(0);
    "
];

$job = ProcessRunner::start($cmd, dirname(__DIR__, 2), []);

if (!($job['ok'] ?? false)) {
    echo "[FAIL] No se pudo iniciar el proceso\n";
    exit(1);
}

$captured_stdout_len = 0;
$captured_stderr_len = 0;
$incremental_checks = 0;

while (ProcessRunner::isRunning($job)) {
    $current_out = strlen((string)$job['stdout']);
    $current_err = strlen((string)$job['stderr']);
    
    if ($current_out > $captured_stdout_len || $current_err > $captured_stderr_len) {
        $incremental_checks++;
        echo "- Captura incremental #$incremental_checks: OUT=$current_out, ERR=$current_err\n";
    }
    
    $captured_stdout_len = $current_out;
    $captured_stderr_len = $current_err;
    
    usleep(20000);
}

$finished = ProcessRunner::finish($job);

$final_out = (string)$finished['stdout'];
$final_err = (string)$finished['stderr'];

$expected_out_len = 4 + $size + 4 + $size;
$expected_err_len = 4 + $size + 4 + $size;

echo "[OK] Proceso terminado.\n";
echo "- Total Stdout: " . strlen($final_out) . " (esperado: $expected_out_len)\n";
echo "- Total Stderr: " . strlen($final_err) . " (esperado: $expected_err_len)\n";
echo "- Capturas incrementales: $incremental_checks\n";

$ok = (strlen($final_out) === $expected_out_len) 
   && (strlen($final_err) === $expected_err_len)
   && ($incremental_checks > 1);

if (!$ok) {
    echo "[FAIL] La salida no fue la esperada o no se capturó incrementalmente.\n";
    exit(1);
}

echo "[SUCCESS] Prueba de salida intercalada superada.\n";
exit(0);
