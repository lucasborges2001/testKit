#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/php/plc/bootstrap.php';

use Testkit\Core\Plc\ModbusTcpReadOnlyClient;
use Testkit\Core\Plc\ModbusTcpReadOnlyException;
use Testkit\Core\Plc\ReadOnlyApplicationMapProbe;
use Testkit\Core\Plc\ReadOnlyApplicationMapValidator;
use Testkit\Core\Plc\RuntimeProfileCatalog;
use Testkit\Core\Plc\RuntimeProfileDetector;

$errors = [];
$assert = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$plan = static function (array $windows, array $profiles = [RuntimeProfileCatalog::WAGO_PFC200_CODESYS2], int $delayMs = 0): array {
    return [
        'id' => 'example-app-map-v1',
        'supportedRuntimeProfiles' => $profiles,
        'windows' => $windows,
        'interRequestDelayMs' => $delayMs,
    ];
};

$window = static fn(string $id, int $start, int $quantity, int $function = 3): array => [
    'id' => $id,
    'function' => $function,
    'startAddress' => $start,
    'quantity' => $quantity,
];

$codesysReader = static function (int $address, int $quantity): array {
    return match ([$address, $quantity]) {
        [0x2002, 3] => [0x1234, 0xAAAA, 0x5555],
        [0x1040, 1] => [2],
        default => array_fill(0, $quantity, 0xBEEF),
    };
};
$eruntimeReader = static function (int $address, int $quantity): array {
    return match ([$address, $quantity]) {
        [0xFAA0, 3] => [0x1234, 0xAAAA, 0x5555],
        [0xFA0D, 1] => [2],
        [0xFA17, 1] => [3],
        [0xFA40, 6] => [0x7D00, 0x7D00, 0x8000, 0x7D00, 0x7D00, 0x8000],
        default => throw new ModbusTcpReadOnlyException('modbus_exception', 'Modbus exception for FC03: code=2', 2),
    };
};
$unknownReader = static function (int $address, int $quantity): array {
    throw new ModbusTcpReadOnlyException('modbus_exception', 'Modbus exception for FC03: code=2', 2);
};
$ambiguousReader = static function (int $address, int $quantity): array {
    return match ([$address, $quantity]) {
        [0x2002, 3], [0xFAA0, 3] => [0x1234, 0xAAAA, 0x5555],
        [0x1040, 1] => [1],
        [0xFA0D, 1] => [2],
        [0xFA17, 1] => [3],
        [0xFA40, 6] => [1, 1, 1, 1, 1, 1],
        default => array_fill(0, $quantity, 7),
    };
};


$clientFixture = new ModbusTcpReadOnlyClient('127.0.0.1', 502, 1, 1500);
$request = $clientFixture->encodeReadHoldingRegistersRequest(0x1234, 0x3080, 27);
$assert(bin2hex($request) === '12340000000601033080001b', 'FC03 request encoding changed');

$payload = pack('n*', ...range(100, 126));
$response = pack('nnnCC', 0x1234, 0, 3 + strlen($payload), 1, 3) . chr(strlen($payload)) . $payload;
$decodedWords = $clientFixture->decodeReadHoldingRegistersResponse($response, 0x1234, 27);
$assert(count($decodedWords) === 27 && $decodedWords[0] === 100 && $decodedWords[26] === 126, 'FC03 decoder changed');

try {
    $clientFixture->decodeReadHoldingRegistersResponse(pack('nnnCCC', 0x1234, 0, 3, 1, 0x83, 2), 0x1234, 27);
    $assert(false, 'Modbus exception response was accepted');
} catch (ModbusTcpReadOnlyException $e) {
    $assert($e->stage() === 'modbus_exception' && $e->getCode() === 2, 'Modbus exception evidence changed');
}

$profileIds = RuntimeProfileCatalog::ids();
$assert(in_array(RuntimeProfileCatalog::WAGO_PFC200_CODESYS2, $profileIds, true), 'missing CODESYS2 profile');
$assert(in_array(RuntimeProfileCatalog::WAGO_PFC200_ERUNTIME, $profileIds, true), 'missing e!RUNTIME profile');

$detector = new RuntimeProfileDetector();
$probe = new ReadOnlyApplicationMapProbe($codesysReader, $detector);

$one = $probe->run($plan([$window('one', 0x0100, 1)]));
$assert(($one['evidence']['status'] ?? null) === 'PASS', 'one-window plan must PASS');
$assert(($one['evidence']['windows'][0]['registerCount'] ?? null) === 1, 'one-window register count mismatch');
$assert(($one['valuesByWindow']['one'][0] ?? null) === 0xBEEF, 'one-window in-memory values missing');

$multiple = $probe->run($plan([
    $window('a', 0x0100, 2),
    $window('b', 0x0200, 3),
]));
$assert(($multiple['evidence']['status'] ?? null) === 'PASS', 'multi-window plan must PASS');
$assert(count($multiple['evidence']['windows'] ?? []) === 2, 'multi-window evidence count mismatch');
$assert(count($multiple['valuesByWindow']['a'] ?? []) === 2, 'multi-window values A mismatch');
$assert(count($multiple['valuesByWindow']['b'] ?? []) === 3, 'multi-window values B mismatch');

$maxRequest = $probe->run($plan([$window('max-request', 0x1000, 125)]));
$assert(($maxRequest['evidence']['status'] ?? null) === 'PASS', 'quantity=125 must PASS');
$assert(($maxRequest['evidence']['windows'][0]['registerCount'] ?? null) === 125, 'quantity=125 register count mismatch');

$evidenceJson = json_encode($multiple['evidence']);
$assert(is_string($evidenceJson) && !str_contains($evidenceJson, 'valuesByWindow'), 'neutral evidence must not persist application valuesByWindow');
foreach (($multiple['evidence']['windows'] ?? []) as $windowEvidence) {
    $assert(!array_key_exists('values', $windowEvidence), 'neutral window evidence must not contain values');
    $assert(!array_key_exists('observed', $windowEvidence), 'neutral window evidence must not contain observed application values');
}
$assert(($multiple['evidence']['readonlyInvariant'] ?? false) === true, 'readonly invariant must be true');

$expectInvalid = static function (array $candidate, string $label) use ($assert): void {
    try {
        ReadOnlyApplicationMapValidator::normalize($candidate);
        $assert(false, $label . ' must be rejected');
    } catch (InvalidArgumentException) {
        $assert(true, $label . ' rejected');
    }
};

$expectInvalid($plan([$window('fc06', 1, 1, 6)]), 'FC06');
$expectInvalid($plan([$window('fc16', 1, 1, 16)]), 'FC16');
$expectInvalid($plan([$window('unknown-fc', 1, 1, 99)]), 'unknown function');
$expectInvalid($plan([$window('negative', -1, 1)]), 'negative address');
$expectInvalid($plan([$window('overflow', 65535, 2)]), 'address overflow');
$expectInvalid($plan([$window('zero', 1, 0)]), 'quantity zero');
$expectInvalid($plan([$window('too-many', 1, 126)]), 'quantity >125');
$expectInvalid($plan([$window('bad-profile', 1, 1)], ['missing-runtime-profile']), 'unknown runtime profile');
$expectInvalid($plan([$window('dup', 1, 1), $window('dup', 2, 1)]), 'duplicate window ids');

$budgetWindows = [];
for ($i = 0; $i < 9; $i++) {
    $budgetWindows[] = $window('budget-' . $i, $i * 125, 125);
}
$expectInvalid($plan($budgetWindows), 'total register budget');

$tooManyWindows = [];
for ($i = 0; $i < ReadOnlyApplicationMapValidator::MAX_WINDOWS + 1; $i++) {
    $tooManyWindows[] = $window('window-' . $i, $i, 1);
}
$expectInvalid($plan($tooManyWindows), 'window count budget');

$unsupportedProbe = new ReadOnlyApplicationMapProbe($eruntimeReader, $detector);
$unsupported = $unsupportedProbe->run($plan([$window('codesys-only', 0x0100, 1)]));
$assert(($unsupported['evidence']['status'] ?? null) === 'BLOCKED', 'detected unsupported runtime must BLOCK');
$assert(($unsupported['evidence']['failureStage'] ?? null) === 'runtime_gate', 'unsupported runtime failureStage mismatch');
$assert(($unsupported['valuesByWindow'] ?? null) === [], 'blocked runtime must execute zero application windows');

$unknownProbe = new ReadOnlyApplicationMapProbe($unknownReader, $detector);
$unknown = $unknownProbe->run($plan([$window('never', 1, 1)]));
$assert(($unknown['evidence']['status'] ?? null) === 'FAIL', 'UNKNOWN runtime must FAIL');
$assert(($unknown['evidence']['runtime']['status'] ?? null) === 'UNKNOWN', 'UNKNOWN runtime status missing');

$ambiguousProbe = new ReadOnlyApplicationMapProbe($ambiguousReader, $detector);
$ambiguous = $ambiguousProbe->run($plan([$window('never', 1, 1)]));
$assert(($ambiguous['evidence']['status'] ?? null) === 'FAIL', 'AMBIGUOUS runtime must FAIL');
$assert(($ambiguous['evidence']['runtime']['status'] ?? null) === 'AMBIGUOUS', 'AMBIGUOUS runtime status missing');

$mismatch = $unsupportedProbe->run(
    $plan([$window('never', 1, 1)]),
    RuntimeProfileCatalog::WAGO_PFC200_CODESYS2
);
$assert(($mismatch['evidence']['status'] ?? null) === 'FAIL', 'PROFILE_MISMATCH must FAIL');
$assert(($mismatch['evidence']['runtime']['status'] ?? null) === 'PROFILE_MISMATCH', 'PROFILE_MISMATCH status missing');

foreach ([ReadOnlyApplicationMapValidator::class, ReadOnlyApplicationMapProbe::class, ModbusTcpReadOnlyClient::class] as $class) {
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $assert(
            preg_match('/write|coil|multiple/i', $method->getName()) !== 1,
            $class . ' exposes write-like public method ' . $method->getName()
        );
    }
}

// Real socket/MBAP path against a local fake e!RUNTIME server.
$ready = sys_get_temp_dir() . '/testkit-plc-fake-' . bin2hex(random_bytes(6)) . '.port';
$fixture = __DIR__ . '/fixtures/fake_modbus_readonly_server.php';
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open([PHP_BINARY, $fixture, '--ready=' . $ready], $descriptors, $pipes);
if (!is_resource($process)) {
    $assert(false, 'unable to start fake Modbus TCP server');
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
        $assert(false, 'fake Modbus TCP server did not publish port');
    } else {
        $client = new ModbusTcpReadOnlyClient('127.0.0.1', $port, 1, 1000);
        $tcpProbe = ReadOnlyApplicationMapProbe::fromClient($client, new RuntimeProfileDetector());
        $tcpPlan = $plan([
            $window('tcp-a', 0x0100, 2),
            $window('tcp-b', 0x0200, 3),
        ], [RuntimeProfileCatalog::WAGO_PFC200_ERUNTIME]);
        $tcp = $tcpProbe->run($tcpPlan);
        $assert(($tcp['evidence']['status'] ?? null) === 'PASS', 'fake TCP/MBAP application plan must PASS');
        $assert(($tcp['evidence']['runtime']['detectedProfile'] ?? null) === RuntimeProfileCatalog::WAGO_PFC200_ERUNTIME, 'fake TCP runtime detection mismatch');
        $assert(($tcp['valuesByWindow']['tcp-a'] ?? null) === [0xBEEF, 0x1234], 'fake TCP application values A mismatch');
        $assert(($tcp['valuesByWindow']['tcp-b'] ?? null) === [1, 2, 3], 'fake TCP application values B mismatch');
    }

    proc_terminate($process);
    foreach ([1, 2] as $fd) {
        if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
            stream_get_contents($pipes[$fd]);
            fclose($pipes[$fd]);
        }
    }
    proc_close($process);
    @unlink($ready);
}

if ($errors !== []) {
    fwrite(STDERR, "PLC Modbus read-only application-map tests failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "OK plc modbus readonly profiles and application maps\n";
