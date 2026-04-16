<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Reporting\ConsoleReporter;

$errors = [];

function assert_contains(string $haystack, string $needle, string $label, array &$errors): void
{
    if (!str_contains($haystack, $needle)) {
        $errors[] = $label . ': missing "' . $needle . '"';
    }
}

function strip_ansi_console(string $value): string
{
    return (string)preg_replace('/\e\[[0-9;]*m/', '', $value);
}

$longRelA = 'test/back/checkoutCarga/integration/really/very/long/path/that/should/not/blow/up/in/powershell/FooTest.php';
$longRelB = 'test/back/checkoutCarga/integration/really/very/long/path/that/should/not/blow/up/in/powershell/BarTest.php';
$longRelC = 'test/back/checkoutCarga/integration/really/very/long/path/that/should/not/blow/up/in/powershell/BazTest.php';

ob_start();
ConsoleReporter::printSuiteProgress([
    'elapsed_ms' => 125000,
    'completed' => 3,
    'total' => 10,
    'pass' => 2,
    'fail' => 1,
    'skip' => 0,
    'timeout' => 0,
    'current_test_rel' => $longRelA,
    'current_elapsed_ms' => 33000,
    'avg_ms_per_test' => 41000,
    'eta_ms' => 287000,
    'jobs' => 3,
    'workers' => [
        ['worker' => 1, 'rel' => $longRelA, 'elapsed_ms' => 33000],
        ['worker' => 2, 'rel' => $longRelB, 'elapsed_ms' => 12000],
        ['worker' => 3, 'rel' => $longRelC, 'elapsed_ms' => 4000],
    ],
]);
$progressOutput = strip_ansi_console((string)ob_get_clean());
assert_contains($progressOutput, '[Progress]', 'progress', $errors);
assert_contains($progressOutput, 'el=00:02:05', 'progress', $errors);
assert_contains($progressOutput, 'done=3/10', 'progress', $errors);
assert_contains($progressOutput, 'p/f/s/to=2/1/0/0', 'progress', $errors);
assert_contains($progressOutput, 'cur=', 'progress', $errors);
assert_contains($progressOutput, 'cur_el=00:00:33', 'progress', $errors);
assert_contains($progressOutput, 'avg=41.0s/test', 'progress', $errors);
assert_contains($progressOutput, 'eta=00:04:47', 'progress', $errors);
assert_contains($progressOutput, 'jobs=3', 'progress', $errors);
assert_contains($progressOutput, 'workers=', 'progress', $errors);
assert_contains($progressOutput, 'w1:', 'progress', $errors);
assert_contains($progressOutput, 'w2:', 'progress', $errors);

ob_start();
ConsoleReporter::printPerTestProgress([
    'status' => 'fail',
    'worker' => 2,
    'rel' => $longRelB,
    'duration_ms' => 9000,
    'elapsed_ms' => 134000,
    'completed' => 4,
    'total' => 10,
    'pass' => 2,
    'fail' => 2,
    'skip' => 0,
    'timeout' => 0,
    'jobs' => 3,
    'workers' => [
        ['worker' => 1, 'rel' => $longRelA, 'elapsed_ms' => 42000],
        ['worker' => 3, 'rel' => $longRelC, 'elapsed_ms' => 15000],
    ],
]);
$testOutput = strip_ansi_console((string)ob_get_clean());
assert_contains($testOutput, '[Test]', 'per_test', $errors);
assert_contains($testOutput, 'status=FAIL', 'per_test', $errors);
assert_contains($testOutput, 'worker=2', 'per_test', $errors);
assert_contains($testOutput, 'done=4/10', 'per_test', $errors);
assert_contains($testOutput, 'dur=00:00:09', 'per_test', $errors);
assert_contains($testOutput, 'rel=', 'per_test', $errors);
assert_contains($testOutput, 'el=00:02:14', 'per_test', $errors);
assert_contains($testOutput, 'p/f/s/to=2/2/0/0', 'per_test', $errors);
assert_contains($testOutput, 'jobs=3', 'per_test', $errors);
assert_contains($testOutput, 'active=', 'per_test', $errors);
assert_contains($testOutput, 'w1:', 'per_test', $errors);

ob_start();
ConsoleReporter::printLongRunningTest([
    'elapsed_ms' => 65000,
    'rel' => $longRelA,
    'worker' => 1,
]);
$warnOutput = strip_ansi_console((string)ob_get_clean());
assert_contains($warnOutput, '[WARN]', 'warn', $errors);
assert_contains($warnOutput, 'long_running_test', 'warn', $errors);
assert_contains($warnOutput, 'elapsed=00:01:05', 'warn', $errors);
assert_contains($warnOutput, 'worker=1', 'warn', $errors);

ob_start();
ConsoleReporter::printPhaseTimings([
    'discovery' => 10,
    'admission' => 20,
    'execution' => 30,
    'reporting' => 40,
]);
$phaseOutput = strip_ansi_console((string)ob_get_clean());
assert_contains($phaseOutput, '[Phase Timings]', 'phase', $errors);
assert_contains($phaseOutput, 'discovery_ms=10', 'phase', $errors);
assert_contains($phaseOutput, 'admission_ms=20', 'phase', $errors);
assert_contains($phaseOutput, 'execution_ms=30', 'phase', $errors);
assert_contains($phaseOutput, 'reporting_ms=40', 'phase', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Observability console contract PASS\n";
exit(0);
