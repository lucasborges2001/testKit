<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Execution\SuiteExecutor;

echo "--- Prueba: Trabajos Concurrentes con Salida Voluminosa ---\n";

$numTests = 8;
$numJobs = 4;
$sizePerTest = 64 * 1024; // 64KB

$tests = [];
for ($i = 0; $i < $numTests; $i++) {
    $tests[] = [
        'file' => "test_$i.php",
        'rel' => "test_$i.php",
        'module' => 'concurrent_test',
        'tags' => ['parallel'],
    ];
}

$config = [
    'suite_id' => 'concurrent_suite',
    'language' => 'php',
    'scope' => 'parallel',
    'category' => 'stress',
    'jobs' => $numJobs,
    'fail_fast' => false,
    'list_only' => false,
    'require_tests' => true,
    'repo_root' => __DIR__,
    'thresholds' => [],
];

$buildCommand = function (array $test, int $workerId) use ($sizePerTest): array {
    return [
        'cmd' => [
            PHP_BINARY,
            '-r',
            "
            file_put_contents('php://stdout', str_repeat('O', {$sizePerTest}));
            file_put_contents('php://stderr', str_repeat('E', {$sizePerTest}));
            exit(0);
            "
        ],
        'env' => []
    ];
};

$start = microtime(true);
$result = SuiteExecutor::execute($tests, $config, $buildCommand);
$duration = microtime(true) - $start;

echo "[OK] Suite ejecutada en {$duration}s.\n";
echo "- Tests totales: {$result['tests_total']}\n";
echo "- Pasados: {$result['pass']}\n";
echo "- Fallados: {$result['fail']}\n";

$ok = true;
if ($result['tests_total'] !== $numTests) {
    echo "[FAIL] No se ejecutaron todos los tests.\n";
    $ok = false;
}
if ($result['pass'] !== $numTests) {
    echo "[FAIL] Algunos tests fallaron.\n";
    $ok = false;
}

foreach ($result['tests'] as $entry) {
    if (strlen($entry['stdout']) !== $sizePerTest) {
        echo "[FAIL] Test {$entry['file']} tiene stdout incompleto: " . strlen($entry['stdout']) . "\n";
        $ok = false;
    }
    if (strlen($entry['stderr']) !== $sizePerTest) {
        echo "[FAIL] Test {$entry['file']} tiene stderr incompleto: " . strlen($entry['stderr']) . "\n";
        $ok = false;
    }
}

if (!$ok) {
    echo "[FAIL] Prueba de concurrencia fallida.\n";
    exit(1);
}

echo "[SUCCESS] Prueba de concurrencia superada.\n";
exit(0);
