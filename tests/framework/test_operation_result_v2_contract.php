<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Execution\ExitCode;
use Testkit\Core\Reporting\OperationResult;

$errors = [];
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$errors): void {
    if ($expected !== $actual) {
        $errors[] = $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
};
$assertThrows = static function (callable $fn, string $message) use (&$errors): void {
    try {
        $fn();
        $errors[] = $message . ' expected exception';
    } catch (InvalidArgumentException) {
        // expected
    }
};

$pass = OperationResult::attach([
    'exit_code' => ExitCode::OK,
    'pass' => 2,
    'skip' => 0,
    'evidence_valid' => true,
], 'run_suite');
$assertSame(['name' => 'testkit.operation_result', 'version' => 2], $pass['schema'] ?? null, 'root schema must be versioned');
$assertSame('run_suite', $pass['operation'] ?? null, 'operation must be explicit');
$assertSame(['code' => 0, 'name' => 'OK'], $pass['exit'] ?? null, 'exit code/name must agree');
$assertSame('passed', $pass['status'] ?? null, 'OK result must be passed for normal execution');
$assertSame(true, $pass['evidence_valid'] ?? null, 'evidence_valid must be boolean');
OperationResult::validate($pass);

$noTests = OperationResult::attach([
    'exit_code' => ExitCode::NO_TESTS,
    'tests_total' => 0,
    'evidence_valid' => true,
], 'run_suite');
$assertSame(['code' => 6, 'name' => 'NO_TESTS'], $noTests['exit'] ?? null, 'NO_TESTS must use code 6');
$assertSame('no_tests', $noTests['status'] ?? null, 'NO_TESTS status must be canonical');

$timeout = OperationResult::attach([
    'exit_code' => ExitCode::TIMEOUT,
    'timeout' => 1,
    'evidence_valid' => true,
], 'run_suite');
$assertSame(['code' => 8, 'name' => 'TIMEOUT'], $timeout['exit'] ?? null, 'TIMEOUT must use code 8');
$assertSame('timeout', $timeout['status'] ?? null, 'TIMEOUT status must be canonical');

$badName = $timeout;
$badName['exit']['name'] = 'TEST_FAILURE';
$assertThrows(static fn() => OperationResult::validate($badName), 'exit name mismatch must be rejected');

$badStatus = $noTests;
$badStatus['status'] = 'passed';
$assertThrows(static fn() => OperationResult::validate($badStatus), 'status/exit mismatch must be rejected');

$assertThrows(
    static fn() => OperationResult::attach(['exit_code' => 99], 'run_suite'),
    'unknown exit code must be rejected before publishing machine JSON'
);
$assertThrows(
    static fn() => OperationResult::attach(['exit_code' => 0], ''),
    'empty operation must be rejected'
);

if ($errors !== []) {
    fwrite(STDERR, "Operation result v2 contract failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "Operation result v2 contract PASS\n";
