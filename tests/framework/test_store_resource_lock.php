<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Execution\ParallelGuard;

$repoRoot = dirname(__DIR__, 2);
$suiteIds = ['back_php', 'front_php'];
$errors = [];

putenv('DB_DRIVER=mysql');
putenv('DB_NAME=testkit_lock_' . getmypid());
putenv('TEST_RUN_ID=selftest_run_a_' . getmypid());
putenv('TEST_META_RUN_ID=selftest_meta_a_' . getmypid());

$policy = ParallelGuard::evaluateRunResource($suiteIds, $repoRoot);
if (!($policy['requires_resource_lock'] ?? false)) {
    fwrite(STDERR, "FAIL: expected run resource lock policy to require locking\n");
    exit(1);
}

$lease = ParallelGuard::acquireRunResourceLock($policy);
if ($lease === null) {
    fwrite(STDERR, "FAIL: could not acquire initial run resource lock\n");
    exit(1);
}

putenv('TEST_RUN_ID=selftest_run_b_' . getmypid());
putenv('TEST_META_RUN_ID=selftest_meta_b_' . getmypid());

$admission = ParallelGuard::rejectedByRunLockState($policy);
if (($admission['reason'] ?? null) !== 'store_resource_locked') {
    $errors[] = 'FAIL: expected rejectedByRunLockState reason=store_resource_locked';
}

if (($admission['resource'] ?? '') === '') {
    $errors[] = 'FAIL: expected resource label in rejected admission';
}

if (($admission['lock_scope'] ?? '') !== 'run') {
    $errors[] = 'FAIL: expected lock_scope=run in rejected admission';
}

if (($admission['lock_owner_run_id'] ?? null) !== 'selftest_run_a_' . getmypid()) {
    $errors[] = 'FAIL: expected owner run id from first lease';
}

$lease->release();

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
}

echo "PASS: run resource lock blocks a second runner on the same store resource\n";
exit(0);
