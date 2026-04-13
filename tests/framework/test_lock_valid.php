<?php
/**
 * Self-test: A valid, live lock is NOT broken by stale detection.
 *
 * Verifies:
 *   - A second acquire() on a live lock returns null (non-blocking)
 *   - After releasing the first lock, a second acquire() succeeds
 *   - The stale detection never fires for an alive process
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/common/Env.php';
require_once __DIR__ . '/../../core/php/common/Paths.php';
require_once __DIR__ . '/../../core/php/common/Lock.php';

use Testkit\Core\Common\Lock;

$tmpRoot = sys_get_temp_dir() . '/testkit_selftest_lockvalid_' . getmypid();
@mkdir($tmpRoot . '/locks', 0777, true);
putenv('TESTKIT_ARTIFACTS_ROOT=' . $tmpRoot);

$errors   = [];
$lockName = 'selftest_valid_' . getmypid();

// 1. Acquire the lock
$lease1 = Lock::acquire($lockName, false);
if ($lease1 === null) {
    $errors[] = 'FAIL: could not acquire fresh lock';
    goto done;
}
echo "Step 1 PASS: first acquire succeeded\n";

// 2. A second non-blocking acquire must fail (lock is live)
$lease2 = Lock::acquire($lockName, false);
if ($lease2 !== null) {
    $errors[] = 'FAIL: second acquire on live lock must return null';
    $lease2->release();
    goto done;
}
echo "Step 2 PASS: concurrent acquire correctly blocked\n";

// 3. Release the first lock
$lease1->release();
echo "Step 3 PASS: first lock released\n";

// 4. Now a new acquire must succeed
$lease3 = Lock::acquire($lockName, false);
if ($lease3 === null) {
    $errors[] = 'FAIL: acquire after release returned null';
    goto done;
}
$lease3->release();
echo "Step 4 PASS: re-acquire after release succeeded\n";

done:
// Cleanup
@unlink($tmpRoot . '/locks/' . basename(Lock::pathFor($lockName)) . '/owner.json');
@rmdir($tmpRoot . '/locks/' . basename(Lock::pathFor($lockName)));
@rmdir($tmpRoot . '/locks');
@rmdir($tmpRoot);

if ($errors !== []) {
    foreach ($errors as $err) {
        fwrite(STDERR, $err . "\n");
    }
    exit(1);
}

exit(0);
