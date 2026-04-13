<?php
/**
 * Self-test: Lock::acquire() reclaims a stale lock whose owner is dead.
 *
 * Scenario A — PID-based detection (same host, posix available):
 *   Create a lock with a non-existent PID on the current host.
 *   Acquire should succeed immediately.
 *
 * Scenario B — TTL-based detection (different host, no posix):
 *   Create a lock with a valid PID but a foreign hostname and a very old acquired_at.
 *   Acquire should succeed once the TTL has elapsed.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/common/Env.php';
require_once __DIR__ . '/../../core/php/common/Paths.php';
require_once __DIR__ . '/../../core/php/common/Lock.php';

use Testkit\Core\Common\Lock;

// Isolate all lock files under a per-run temp directory.
$tmpRoot = sys_get_temp_dir() . '/testkit_selftest_lock_' . getmypid();
@mkdir($tmpRoot . '/locks', 0777, true);
putenv('TESTKIT_ARTIFACTS_ROOT=' . $tmpRoot);

$errors = [];

// -----------------------------------------------------------------------
// Scenario A: stale by dead PID (same host)
// -----------------------------------------------------------------------
$lockNameA = 'selftest_stale_pid_' . getmypid();
$lockPathA = Lock::pathFor($lockNameA);

@mkdir($lockPathA, 0777, true);
$deadPid = 9_999_999; // Extremely unlikely to be a live PID
if (function_exists('posix_kill') && @posix_kill($deadPid, 0)) {
    // Astronomically unlikely — fall back to TTL scenario only
    @rmdir($lockPathA);
    echo "NOTE: Scenario A skipped — PID {$deadPid} happens to be alive\n";
} else {
    $ownerA = [
        'name'        => $lockNameA,
        'pid'         => $deadPid,
        'hostname'    => (string)@gethostname(),
        'acquired_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 7200),
    ];
    file_put_contents($lockPathA . '/owner.json', json_encode($ownerA));

    $leaseA = Lock::acquire($lockNameA, false);
    if ($leaseA === null) {
        $errors[] = 'Scenario A FAIL: stale lock with dead PID was not reclaimed';
    } else {
        $leaseA->release();
        echo "Scenario A PASS: stale lock (dead PID) reclaimed\n";
    }
}

// -----------------------------------------------------------------------
// Scenario B: stale by TTL (different host, acquired_at > TTL ago)
// -----------------------------------------------------------------------
$lockNameB = 'selftest_stale_ttl_' . getmypid();
$lockPathB = Lock::pathFor($lockNameB);

@mkdir($lockPathB, 0777, true);
$ownerB = [
    'name'        => $lockNameB,
    'pid'         => getmypid(), // alive PID — but hostname differs, so PID check is skipped
    'hostname'    => 'some-other-host-' . uniqid('', true),
    'acquired_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 7200), // 2 hours ago > default 3600s TTL
];
file_put_contents($lockPathB . '/owner.json', json_encode($ownerB));

// Override TTL to something smaller than 7200 to ensure the test is deterministic.
putenv('TEST_LOCK_STALE_TTL_SEC=3600');

$leaseB = Lock::acquire($lockNameB, false);
if ($leaseB === null) {
    $errors[] = 'Scenario B FAIL: stale lock (old acquired_at, different host) was not reclaimed';
} else {
    $leaseB->release();
    echo "Scenario B PASS: stale lock (TTL expired, different host) reclaimed\n";
}

// -----------------------------------------------------------------------
// Cleanup
// -----------------------------------------------------------------------
@unlink($tmpRoot . '/locks/' . basename($lockPathA) . '/owner.json');
@rmdir($tmpRoot . '/locks/' . basename($lockPathA));
@unlink($tmpRoot . '/locks/' . basename($lockPathB) . '/owner.json');
@rmdir($tmpRoot . '/locks/' . basename($lockPathB));
@rmdir($tmpRoot . '/locks');
@rmdir($tmpRoot);

if ($errors !== []) {
    foreach ($errors as $err) {
        fwrite(STDERR, $err . "\n");
    }
    exit(1);
}

exit(0);
