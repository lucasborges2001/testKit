#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/php/plc/bootstrap.php';

use Testkit\Core\Plc\FunctionalHilGate;
use Testkit\Core\Plc\FunctionalHilSession;
use Testkit\Core\Plc\ModbusTcpFunctionalHilException;

$errors = [];
$assert = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$gate = static function (
    string $runtime = 'PASS',
    string $application = 'PASS',
    string $bridge = 'PASS'
): array {
    return [
        'schema' => FunctionalHilGate::SCHEMA,
        'runtime' => ['status' => $runtime, 'id' => 'runtime.test', 'version' => '1'],
        'application' => ['status' => $application, 'id' => 'application.test', 'version' => '1'],
        'bridge' => ['status' => $bridge, 'id' => 'bridge.test', 'version' => '1'],
        'metadata' => ['consumer' => 'fixture'],
    ];
};

$map = [
    'input.raw_known' => 1200,
    'input.raw_level' => 1201,
];

$normalized = FunctionalHilGate::normalize($gate());
$assert($normalized['schema'] === FunctionalHilGate::SCHEMA, 'gate schema changed');
$assert($normalized['identities_pass'] === true, 'three PASS identities did not pass gate');

foreach (['FAIL', 'UNKNOWN', 'UNAVAILABLE'] as $status) {
    $blocked = FunctionalHilGate::normalize($gate('PASS', $status, 'PASS'));
    $assert(
        $blocked['identities_pass'] === false,
        sprintf('application status %s incorrectly passed identity gate', $status)
    );
}

try {
    FunctionalHilGate::normalize([
        'schema' => FunctionalHilGate::SCHEMA,
        'runtime' => ['status' => 'PASS'],
        'application' => ['status' => 'PASS'],
    ]);
    $assert(false, 'incomplete identity gate was accepted');
} catch (InvalidArgumentException) {
    $assert(true, 'incomplete identity gate rejected');
}

try {
    $unsafeMetadata = $gate();
    $unsafeMetadata['metadata']['api_token'] = 'not-allowed';
    FunctionalHilGate::normalize($unsafeMetadata);
    $assert(false, 'secret-like gate metadata key was accepted');
} catch (InvalidArgumentException) {
    $assert(true, 'secret-like gate metadata key rejected');
}

$optOut = FunctionalHilSession::open(
    $gate(),
    '127.0.0.1',
    $map,
    false,
    9,
    1,
    100
);
$assert($optOut->writesAllowed() === false, 'write opt-out enabled writes');
$assert($optOut->gateReport()['writes_allowed'] === false, 'gate report ignored write opt-out');
$assert($optOut->stimulusIds() === ['input.raw_known', 'input.raw_level'], 'session stimulus ids changed');
$assert(!array_key_exists('stimulus_registers', $optOut->gateReport()), 'gate report leaked register map');

try {
    FunctionalHilSession::open(
        $gate(),
        '127.0.0.1',
        ['a' => 1200, 'b' => 1200],
        true
    );
    $assert(false, 'session accepted duplicate stimulus register addresses');
} catch (InvalidArgumentException) {
    $assert(true, 'session preserved allowlist validation');
}

$ready = sys_get_temp_dir() . '/testkit-plc-functional-gate-' . bin2hex(random_bytes(6)) . '.port';
$countFile = sys_get_temp_dir() . '/testkit-plc-functional-gate-' . bin2hex(random_bytes(6)) . '.count';
$fixture = __DIR__ . '/fixtures/fake_modbus_functional_hil_server.php';
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open(
    [PHP_BINARY, $fixture, '--ready=' . $ready, '--count=' . $countFile],
    $descriptors,
    $pipes
);

if (!is_resource($process)) {
    $assert(false, 'unable to start Functional HIL gate fake server');
} else {
    fclose($pipes[0]);
    $port = 0;
    for ($i = 0; $i < 100; $i++) {
        if (is_file($ready)) {
            $port = (int)trim((string)file_get_contents($ready));
            if ($port > 0) {
                break;
            }
        }
        usleep(10000);
    }

    if ($port <= 0) {
        $assert(false, 'Functional HIL gate fake server did not publish port');
    } else {
        $blockedSession = FunctionalHilSession::open(
            $gate('PASS', 'FAIL', 'PASS'),
            '127.0.0.1',
            $map,
            true,
            $port,
            1,
            1000
        );
        $blockedReport = $blockedSession->gateReport();
        $assert($blockedSession->writesAllowed() === false, 'application FAIL enabled writes');
        $assert($blockedReport['write_requested'] === true, 'gate report lost explicit write request');
        $assert($blockedReport['writes_allowed'] === false, 'gate report allowed failed identity');

        try {
            $blockedSession->writeStimulus('input.raw_known', 1);
            $assert(false, 'blocked gate session accepted write');
        } catch (ModbusTcpFunctionalHilException $e) {
            $assert($e->stage() === 'write_disabled', 'blocked gate did not fail before transport');
        }

        usleep(20000);
        $blockedCount = is_file($countFile) ? (int)trim((string)file_get_contents($countFile)) : -1;
        $assert($blockedCount === 0, sprintf('failed identity produced FC06 writes: count=%d', $blockedCount));

        $allowedSession = FunctionalHilSession::open(
            $gate(),
            '127.0.0.1',
            $map,
            true,
            $port,
            1,
            1000
        );
        $assert($allowedSession->writesAllowed() === true, 'three PASS identities plus opt-in did not enable writes');
        $assert($allowedSession->gateReport()['writes_allowed'] === true, 'allowed gate report did not enable writes');

        try {
            $allowedSession->writeStimulus('input.raw_known', 1);
        } catch (Throwable $e) {
            $assert(false, 'allowed gated FC06 write failed: ' . $e->getMessage());
        }

        usleep(20000);
        $allowedCount = is_file($countFile) ? (int)trim((string)file_get_contents($countFile)) : -1;
        $assert($allowedCount === 1, sprintf('allowed identity did not produce exactly one FC06: count=%d', $allowedCount));
    }

    proc_terminate($process);
    foreach ([1, 2] as $fd) {
        if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
            stream_get_contents($pipes[$fd]);
            fclose($pipes[$fd]);
        }
    }
    proc_close($process);
}

@unlink($ready);
@unlink($countFile);

if ($errors !== []) {
    fwrite(STDERR, "PLC Functional HIL gate tests failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "OK plc functional hil identity gate\n";
