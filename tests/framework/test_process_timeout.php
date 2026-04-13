<?php
/**
 * Self-test: ProcessRunner kills a hanging process after the configured timeout.
 *
 * Verifies:
 *   - process is killed before the full sleep duration completes
 *   - job['timeout'] flag is set
 *   - exit code is non-zero (124 by convention)
 *   - stderr contains the [testkit] TIMEOUT message
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/execution/ProcessRunner.php';

use Testkit\Core\Execution\ProcessRunner;

$timeoutSec  = 2;
$sleepSec    = 60; // much longer than timeout — process must be killed
$wallStart   = microtime(true);

$job = ProcessRunner::start(['sleep', (string)$sleepSec], sys_get_temp_dir(), [], $timeoutSec);

if (!$job['ok']) {
    fwrite(STDERR, "FAIL: could not start sleep process\n");
    exit(1);
}

// Poll isRunning() until it returns false (timeout fires here)
$maxWall = $timeoutSec + 10; // allow grace period on top of configured timeout
while (ProcessRunner::isRunning($job)) {
    usleep(100000);
    if ((microtime(true) - $wallStart) > $maxWall) {
        fwrite(STDERR, "FAIL: process did not terminate within {$maxWall}s wall time\n");
        exit(1);
    }
}

$finished = ProcessRunner::finish($job);
$elapsed  = microtime(true) - $wallStart;

// 1. Timeout flag must be set
if (!($finished['timeout'] ?? false)) {
    fwrite(STDERR, "FAIL: finished['timeout'] is not set\n");
    exit(1);
}

// 2. Must not report success
if ((int)$finished['code'] === 0) {
    fwrite(STDERR, "FAIL: exit code is 0 — timed-out process cannot succeed\n");
    exit(1);
}

// 3. Stderr must contain the marker
if (strpos((string)$finished['stderr'], '[testkit] TIMEOUT') === false) {
    fwrite(STDERR, "FAIL: TIMEOUT marker not found in stderr\n");
    exit(1);
}

// 4. Wall time must be well under the sleep duration
if ($elapsed >= $sleepSec) {
    fwrite(STDERR, "FAIL: wall time {$elapsed}s reached the sleep duration — process was not killed\n");
    exit(1);
}

echo sprintf(
    "PASS: process killed after %.2fs (timeout=%ds, exit_code=%d)\n",
    $elapsed,
    $timeoutSec,
    $finished['code']
);
exit(0);
