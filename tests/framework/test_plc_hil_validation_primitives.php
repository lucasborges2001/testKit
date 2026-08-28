#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/php/plc/bootstrap.php';

use Testkit\Core\Plc\CoherentSnapshotReader;
use Testkit\Core\Plc\FunctionalHilGate;
use Testkit\Core\Plc\FunctionalHilLifecycle;
use Testkit\Core\Plc\FunctionalHilSession;
use Testkit\Core\Plc\PlcArtifact;
use Testkit\Core\Plc\ScanDrivenWait;
use Testkit\Core\Plc\StressSoakRunner;

$errors = [];
$assert = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) { $errors[] = $message; }
};

$gate = static function (string $application = 'PASS'): array {
    return [
        'schema' => FunctionalHilGate::SCHEMA,
        'runtime' => ['status' => 'PASS', 'id' => 'runtime.fixture', 'version' => '1'],
        'application' => ['status' => $application, 'id' => 'application.fixture', 'version' => '1'],
        'bridge' => ['status' => 'PASS', 'id' => 'bridge.fixture', 'version' => '1'],
        'metadata' => ['consumer' => 'framework'],
    ];
};
$map = [
    'control.arm' => 1200,
    'control.heartbeat' => 1201,
    'input.synthetic' => 1202,
    'control.release' => 1203,
    'control.clear_arm' => 1204,
];
$plan = [
    'arm' => ['id' => 'control.arm', 'value' => 1],
    'release' => ['id' => 'control.release', 'value' => 0],
    'cleanupWrites' => [['id' => 'control.clear_arm', 'value' => 0]],
    'metadata' => ['consumer' => 'framework', 'api_token' => 'must-not-leak'],
];

$startServer = static function (array $extraArgs = []) use ($assert): ?array {
    $ready = sys_get_temp_dir() . '/testkit-plc-lifecycle-' . bin2hex(random_bytes(6)) . '.port';
    $count = sys_get_temp_dir() . '/testkit-plc-lifecycle-' . bin2hex(random_bytes(6)) . '.count';
    $fixture = __DIR__ . '/fixtures/fake_modbus_functional_hil_server.php';
    $args = [PHP_BINARY, $fixture, '--ready=' . $ready, '--count=' . $count, '--allowed-addresses=1200,1201,1202,1203,1204'];
    foreach ($extraArgs as $arg) { $args[] = $arg; }
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($args, $descriptors, $pipes);
    if (!is_resource($process)) {
        $assert(false, 'unable to start lifecycle fake server');
        return null;
    }
    fclose($pipes[0]);
    $port = 0;
    for ($i = 0; $i < 100; $i++) {
        if (is_file($ready)) {
            $port = (int)trim((string)file_get_contents($ready));
            if ($port > 0) { break; }
        }
        usleep(10000);
    }
    if ($port <= 0) {
        $assert(false, 'lifecycle fake server did not publish port');
        proc_terminate($process);
        return null;
    }
    return ['process' => $process, 'pipes' => $pipes, 'port' => $port, 'count' => $count, 'ready' => $ready];
};
$stopServer = static function (?array $server): void {
    if ($server === null) { return; }
    if (is_resource($server['process'])) { proc_terminate($server['process']); }
    foreach ([1, 2] as $fd) {
        if (isset($server['pipes'][$fd]) && is_resource($server['pipes'][$fd])) { fclose($server['pipes'][$fd]); }
    }
    if (is_resource($server['process'])) { proc_close($server['process']); }
    @unlink($server['count']);
    @unlink($server['ready']);
};
$countWrites = static fn(array $server): int => is_file($server['count']) ? (int)trim((string)file_get_contents($server['count'])) : -1;

// Structured artifact and secret redaction.
$artifact = PlcArtifact::build('testkit.plc-test.v1', 'EXECUTED', 'PASS', [
    'password' => 'abc',
    'nested' => ['api_token' => 'def', 'message' => 'Authorization: Bearer ghp_123'],
]);
$assert(($artifact['data']['password'] ?? null) === '[REDACTED]', 'artifact password was not redacted');
$assert(($artifact['data']['nested']['api_token'] ?? null) === '[REDACTED]', 'artifact token was not redacted');
$artifactJson = json_encode($artifact);
$assert(is_string($artifactJson) && !str_contains($artifactJson, 'ghp_123') && !str_contains($artifactJson, 'abc') && !str_contains($artifactJson, 'def'), 'artifact leaked secret material');

// Coherent snapshot: first attempt torn, second committed/even and accepted.
$snapshotAttempt = 0;
$snapshot = CoherentSnapshotReader::read(
    static function () use (&$snapshotAttempt): int { $snapshotAttempt++; return $snapshotAttempt === 1 ? 1 : 2; },
    static function () use (&$snapshotAttempt): array { return ['value' => $snapshotAttempt]; },
    static function () use (&$snapshotAttempt): int { return $snapshotAttempt === 1 ? 9 : 2; },
    static fn(array $payload): int => (int)$payload['value'],
    static fn(int $head, array $payload, int $tail): bool => true,
    static fn(int $head, array $payload, int $tail): bool => ($head % 2) === 0,
    3
);
$assert(($snapshot['artifact']['status'] ?? null) === 'PASS', 'coherent snapshot did not PASS');
$assert(($snapshot['artifact']['data']['attempts'] ?? null) === 2, 'coherent snapshot did not retry torn read');
$assert(($snapshot['artifact']['data']['inconsistent_attempts'] ?? null) === 1, 'torn snapshot was not counted');
$assert(($snapshot['snapshot'] ?? null) === 2, 'decoded coherent snapshot mismatch');

$torn = CoherentSnapshotReader::read(static fn(): int => 1, static fn(): array => [1], static fn(): int => 2, null, null, null, 2);
$assert(($torn['artifact']['data']['outcome'] ?? null) === 'INCONSISTENT', 'permanently torn snapshot was not rejected');
$uncommitted = CoherentSnapshotReader::read(static fn(): int => 2, static fn(): array => [1], static fn(): int => 2, null, static fn(): bool => false, null, 2);
$assert(($uncommitted['artifact']['data']['outcome'] ?? null) === 'TIMEOUT', 'uncommitted snapshot did not exhaust finite budget');
$transportSnapshot = CoherentSnapshotReader::read(static function (): int { throw new RuntimeException('read failed'); }, static fn(): array => [], static fn(): int => 0, null, null, null, 2);
$assert(($transportSnapshot['artifact']['data']['outcome'] ?? null) === 'TRANSPORT_ERROR', 'snapshot transport exception was not classified');

// Scan-driven waiting with stalls and wrap; no time-based authority.
$scanValues = [5, 5, 6, 7, 8];
$scanIndex = 0;
$scanWait = ScanDrivenWait::waitUntilScanDelta(static function () use (&$scanValues, &$scanIndex): int {
    $value = $scanValues[min($scanIndex, count($scanValues) - 1)];
    $scanIndex++;
    return $value;
}, 3, 8);
$assert(($scanWait['status'] ?? null) === 'PASS' && ($scanWait['data']['scan_delta'] ?? null) >= 3, 'scan delta wait failed');
$stalled = ScanDrivenWait::waitUntilScanDelta(static fn(): int => 9, 1, 3);
$assert(($stalled['data']['outcome'] ?? null) === 'TIMEOUT' && ($stalled['data']['reason'] ?? null) === 'stalled_scan', 'stalled scan was not detected');
$wrapValues = [65534, 65535, 0, 1];
$wrapIndex = 0;
$wrap = ScanDrivenWait::waitUntilScanDelta(static function () use (&$wrapValues, &$wrapIndex): int {
    $value = $wrapValues[min($wrapIndex, count($wrapValues) - 1)];
    $wrapIndex++;
    return $value;
}, 3, 5, 65536);
$assert(($wrap['status'] ?? null) === 'PASS' && ($wrap['data']['scan_delta'] ?? null) === 3, 'scan wrap handling failed');

// Stress/soak policy and metrics.
$stressScanCalls = 0;
$stress = StressSoakRunner::run(5, static function (int $iteration): array {
    return match ($iteration) {
        2 => ['pass' => false, 'outcome' => 'TRANSPORT_ERROR', 'stage' => 'tcp_timeout', 'reason' => 'fixture'],
        4 => ['pass' => false, 'outcome' => 'INCONSISTENT', 'snapshot_inconsistent' => true],
        5 => ['pass' => false, 'outcome' => 'FAIL', 'cleanup_failure' => true],
        default => ['pass' => true, 'outcome' => 'PASS'],
    };
}, [
    'policy' => 'keep-going',
    'maxFailureDetails' => 2,
    'readScan' => static function () use (&$stressScanCalls): int { return $stressScanCalls++ === 0 ? 100 : 110; },
    'readMetrics' => static fn(): array => ['watchdogCount' => 0, 'overrunCount' => 1, 'applicationErrorCount' => 0, 'ignored' => 999],
]);
$assert(($stress['status'] ?? null) === 'FAIL', 'stress result with failures did not FAIL');
$assert(($stress['data']['iterations_completed'] ?? null) === 5, 'keep-going stress did not complete all iterations');
$assert(($stress['data']['failures'] ?? null) === 3, 'stress failure count mismatch');
$assert(($stress['data']['transport_errors'] ?? null) === 1, 'stress transport error count mismatch');
$assert(($stress['data']['snapshot_inconsistencies'] ?? null) === 1, 'stress snapshot inconsistency count mismatch');
$assert(($stress['data']['cleanup_failures'] ?? null) === 1, 'stress cleanup failure count mismatch');
$assert(($stress['data']['scan_delta'] ?? null) === 10, 'stress scan delta mismatch');
$assert(count($stress['data']['failure_details'] ?? []) === 2, 'stress failure details were not bounded');
$assert(!array_key_exists('ignored', $stress['data'] ?? []), 'stress persisted undeclared optional metric');
$stopFirstCalls = 0;
$stopFirst = StressSoakRunner::run(5, static function (int $iteration) use (&$stopFirstCalls): bool {
    $stopFirstCalls++;
    return $iteration < 2;
}, ['policy' => 'stop-on-first-fail']);
$assert($stopFirstCalls === 2 && ($stopFirst['data']['iterations_completed'] ?? null) === 2, 'stop-on-first-fail policy did not stop');

// Lifecycle success + idempotent cleanup.
$server = $startServer();
if ($server !== null) {
    $session = FunctionalHilSession::open($gate(), '127.0.0.1', $map, true, $server['port'], 1, 1000);
    $lifecycle = new FunctionalHilLifecycle($session);
    $result = $lifecycle->execute($plan, static function (FunctionalHilLifecycle $run): bool {
        $run->heartbeat('control.heartbeat', 1);
        $run->writeStimulus('input.synthetic', 7);
        return true;
    }, [
        'state.bridge_inactive' => static fn(): bool => true,
        'state.physical_disabled' => static fn(): bool => true,
    ]);
    usleep(20000);
    $beforeSecondCleanup = $countWrites($server);
    $cleanupAgain = $lifecycle->cleanup();
    usleep(20000);
    $afterSecondCleanup = $countWrites($server);
    $assert(($result['status'] ?? null) === 'PASS', 'successful lifecycle did not PASS');
    $assert(($result['data']['cleanup']['status'] ?? null) === 'PASS', 'successful lifecycle cleanup did not PASS');
    $assert($beforeSecondCleanup === 5, 'successful lifecycle write count mismatch');
    $assert($afterSecondCleanup === $beforeSecondCleanup, 'idempotent cleanup executed additional writes');
    $assert(($cleanupAgain['idempotent'] ?? null) === true, 'cleanup report lost idempotence flag');
    $assert(($result['metadata']['api_token'] ?? null) === '[REDACTED]', 'lifecycle metadata secret was not redacted');
    $stopServer($server);
}

// Pre-arm failure must execute zero writes.
$server = $startServer();
if ($server !== null) {
    $session = FunctionalHilSession::open($gate(), '127.0.0.1', $map, true, $server['port'], 1, 1000);
    $lifecycle = new FunctionalHilLifecycle($session);
    $result = $lifecycle->execute($plan, static fn(FunctionalHilLifecycle $run): bool => true, [], static function (): void {
        throw new RuntimeException('timeout before arm');
    });
    usleep(20000);
    $assert(($result['data']['outcome'] ?? null) === 'PRE_ARM_FAILED', 'pre-arm failure outcome mismatch');
    $assert($countWrites($server) === 0, 'pre-arm failure produced FC06 writes');
    $stopServer($server);
}

// Failure after arm must still release and cleanup.
$server = $startServer();
if ($server !== null) {
    $session = FunctionalHilSession::open($gate(), '127.0.0.1', $map, true, $server['port'], 1, 1000);
    $lifecycle = new FunctionalHilLifecycle($session);
    $result = $lifecycle->execute($plan, static function (FunctionalHilLifecycle $run): never {
        throw new RuntimeException('runner exception');
    }, ['state.safe' => static fn(): bool => true]);
    usleep(20000);
    $assert(($result['status'] ?? null) === 'FAIL', 'runner exception did not fail lifecycle');
    $assert(($result['data']['cleanup']['status'] ?? null) === 'PASS', 'cleanup after runner exception failed');
    $assert($countWrites($server) === 3, 'runner exception cleanup write count mismatch');
    $stopServer($server);
}

// Heartbeat protocol failure: cleanup must continue.
$server = $startServer(['--fail-on-write=2']);
if ($server !== null) {
    $session = FunctionalHilSession::open($gate(), '127.0.0.1', $map, true, $server['port'], 1, 1000);
    $lifecycle = new FunctionalHilLifecycle($session);
    $result = $lifecycle->execute($plan, static function (FunctionalHilLifecycle $run): bool {
        $run->heartbeat('control.heartbeat', 1);
        return true;
    }, ['state.safe' => static fn(): bool => true]);
    usleep(20000);
    $assert(($result['status'] ?? null) === 'FAIL', 'heartbeat failure did not fail lifecycle');
    $assert(($result['data']['transport_stage'] ?? null) === 'modbus_exception', 'heartbeat failure transport stage missing');
    $assert(($result['data']['cleanup']['status'] ?? null) === 'PASS', 'cleanup after heartbeat failure did not PASS');
    $assert($countWrites($server) === 4, 'heartbeat failure cleanup write count mismatch');
    $stopServer($server);
}

// Release failure must be retried during cleanup and remain a failed run.
$server = $startServer(['--fail-on-write=2']);
if ($server !== null) {
    $session = FunctionalHilSession::open($gate(), '127.0.0.1', $map, true, $server['port'], 1, 1000);
    $lifecycle = new FunctionalHilLifecycle($session);
    $result = $lifecycle->execute($plan, static fn(FunctionalHilLifecycle $run): bool => true, ['state.safe' => static fn(): bool => true]);
    usleep(20000);
    $assert(($result['status'] ?? null) === 'FAIL', 'release failure did not fail lifecycle');
    $assert(($result['data']['failure_stage'] ?? null) === 'release', 'release failure stage mismatch');
    $assert(($result['data']['release_succeeded'] ?? null) === true, 'cleanup did not recover release after initial failure');
    $assert(($result['data']['cleanup']['status'] ?? null) === 'PASS', 'cleanup after release failure did not PASS');
    $stopServer($server);
}

// Cleanup write failure must fail the final lifecycle status.
$server = $startServer(['--fail-on-write=3']);
if ($server !== null) {
    $session = FunctionalHilSession::open($gate(), '127.0.0.1', $map, true, $server['port'], 1, 1000);
    $lifecycle = new FunctionalHilLifecycle($session);
    $result = $lifecycle->execute($plan, static fn(FunctionalHilLifecycle $run): bool => true, ['state.safe' => static fn(): bool => true]);
    $assert(($result['status'] ?? null) === 'FAIL', 'cleanup failure did not fail lifecycle');
    $assert(($result['data']['cleanup']['status'] ?? null) === 'FAIL', 'cleanup failure was not reported');
    $stopServer($server);
}

// Transport EOF after arm: cleanup must still run.
$server = $startServer(['--close-on-write=2']);
if ($server !== null) {
    $session = FunctionalHilSession::open($gate(), '127.0.0.1', $map, true, $server['port'], 1, 1000);
    $lifecycle = new FunctionalHilLifecycle($session);
    $result = $lifecycle->execute($plan, static function (FunctionalHilLifecycle $run): bool {
        $run->heartbeat('control.heartbeat', 1);
        return true;
    }, ['state.safe' => static fn(): bool => true]);
    usleep(20000);
    $assert(($result['status'] ?? null) === 'FAIL', 'transport exception did not fail lifecycle');
    $assert(in_array(($result['data']['transport_stage'] ?? null), ['tcp_eof', 'response_length'], true), 'transport exception stage mismatch');
    $assert(($result['data']['cleanup']['status'] ?? null) === 'PASS', 'cleanup after transport exception did not PASS');
    $assert($countWrites($server) === 4, 'transport exception cleanup write count mismatch');
    $stopServer($server);
}

// Post-cleanup verification is authoritative for final status.
$server = $startServer();
if ($server !== null) {
    $session = FunctionalHilSession::open($gate(), '127.0.0.1', $map, true, $server['port'], 1, 1000);
    $lifecycle = new FunctionalHilLifecycle($session);
    $result = $lifecycle->execute($plan, static fn(FunctionalHilLifecycle $run): bool => true, ['state.safe' => static fn(): bool => false]);
    $assert(($result['status'] ?? null) === 'FAIL', 'failed post-cleanup verifier did not fail lifecycle');
    $assert(($result['data']['cleanup']['status'] ?? null) === 'FAIL', 'failed post-cleanup verifier not reflected in cleanup');
    $stopServer($server);
}

if ($errors !== []) {
    fwrite(STDERR, "PLC HIL validation primitive tests failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}
echo "OK plc hil validation primitives and lifecycle\n";
