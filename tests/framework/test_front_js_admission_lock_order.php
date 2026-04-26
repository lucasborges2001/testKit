<?php
declare(strict_types=1);

$frontJsSuite = __DIR__ . '/../../core/php/suites/FrontJsSuite.php';
$errors = [];

function assert_true_front_js_order(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

function position_or_error(string $source, string $needle, array &$errors): int
{
    $position = strpos($source, $needle);
    if ($position === false) {
        $errors[] = 'Missing expected source fragment: ' . $needle;
        return PHP_INT_MAX;
    }

    return $position;
}

if (!is_file($frontJsSuite)) {
    fwrite(STDERR, 'FAIL: FrontJsSuite.php not found at ' . $frontJsSuite . "\n");
    exit(1);
}

$source = (string)file_get_contents($frontJsSuite);

$evaluatePos = position_or_error($source, 'ParallelGuard::evaluate($discovered, $config, $repoRoot)', $errors);
$policyRejectPos = position_or_error($source, 'ParallelGuard::rejectedByPolicyState($policy)', $errors);
$assertSafePos = position_or_error($source, 'ParallelGuard::assertSafe($policy)', $errors);
$storeGatePos = position_or_error($source, '$requiresStoreBootstrap = !(bool)($config[\'list_only\'] ?? false) && $discovered !== [];', $errors);
$acquirePos = position_or_error($source, 'ParallelGuard::acquireSuiteStoreLock($policy)', $errors);
$lockRejectPos = position_or_error($source, 'ParallelGuard::rejectedByLockState($policy)', $errors);
$bootstrapPos = position_or_error($source, 'ContractWorldBootstrap::prepare(\'front_js\', $repoRoot)', $errors);
$runnerPos = position_or_error($source, 'ProcessRunner::start([$nodePath, $runner], $repoRoot, $env)', $errors);
$releasePos = position_or_error($source, '$lockLease?->release();', $errors);

assert_true_front_js_order(substr_count($source, 'ContractWorldBootstrap::prepare(\'front_js\', $repoRoot)') === 1, 'FrontJsSuite should prepare front_js exactly once.', $errors);
assert_true_front_js_order($evaluatePos < $policyRejectPos, 'Policy evaluation must happen before policy rejection state is built.', $errors);
assert_true_front_js_order($policyRejectPos < $assertSafePos, 'Rejected admission state must be attached before assertSafe throws.', $errors);
assert_true_front_js_order($assertSafePos < $storeGatePos, 'Unsafe policies must be rejected before store bootstrap gating.', $errors);
assert_true_front_js_order($storeGatePos < $acquirePos, 'List-only/no-tests gate must be evaluated before acquiring the store lock.', $errors);
assert_true_front_js_order($acquirePos < $lockRejectPos, 'Lock contention must update concurrency admission before rethrow.', $errors);
assert_true_front_js_order($lockRejectPos < $bootstrapPos, 'Lock contention handling must occur before any bootstrap call.', $errors);
assert_true_front_js_order($acquirePos < $bootstrapPos, 'Suite/store lock must be acquired before ContractWorldBootstrap::prepare.', $errors);
assert_true_front_js_order($bootstrapPos < $runnerPos, 'ContractWorldBootstrap::prepare must run before the Node runner only after admission/lock.', $errors);
assert_true_front_js_order($runnerPos < $releasePos, 'Lock release must remain in the outer finally after runner execution path.', $errors);
assert_true_front_js_order(str_contains($source, '$options[\'default_failure_phase\'] = \'admission\''), 'Admission rejections should be reported with an admission failure phase.', $errors);
assert_true_front_js_order(str_contains($source, '$options[\'default_failure_domain\'] = \'configuration\''), 'Admission rejections should be reported as configuration failures, not bootstrap failures.', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "FrontJsSuite admission/lock order PASS\n";
