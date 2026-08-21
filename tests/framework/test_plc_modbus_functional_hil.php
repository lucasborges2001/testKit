#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/php/plc/bootstrap.php';

use Testkit\Core\Plc\ModbusTcpFunctionalHilClient;
use Testkit\Core\Plc\ModbusTcpFunctionalHilException;
use Testkit\Core\Plc\ModbusTcpReadOnlyClient;

$errors = [];
$assert = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$map = [
    'input.raw_known' => 1200,
    'input.raw_level' => 1201,
];

$disabled = new ModbusTcpFunctionalHilClient('127.0.0.1', $map, false, 502, 1, 500);
try {
    $disabled->writeStimulus('input.raw_known', 1);
    $assert(false, 'write-disabled Functional HIL client accepted a stimulus');
} catch (ModbusTcpFunctionalHilException $e) {
    $assert($e->stage() === 'write_disabled', 'write-disabled stage changed');
}

$enabled = new ModbusTcpFunctionalHilClient('127.0.0.1', $map, true, 502, 1, 500);
try {
    $enabled->writeStimulus('not.allowlisted', 1);
    $assert(false, 'unknown stimulus id was accepted');
} catch (ModbusTcpFunctionalHilException $e) {
    $assert($e->stage() === 'stimulus_not_allowed', 'unknown stimulus stage changed');
}

$request = $enabled->encodeWriteSingleRegisterRequest(0x1234, 1200, 1);
$assert(bin2hex($request) === '123400000006010604b00001', 'FC06 request encoding changed');
$enabled->decodeWriteSingleRegisterResponse($request, 0x1234, 1200, 1);

try {
    $enabled->decodeWriteSingleRegisterResponse(pack('nnnCCC', 0x1234, 0, 3, 1, 0x86, 2), 0x1234, 1200, 1);
    $assert(false, 'FC06 exception response was accepted');
} catch (ModbusTcpFunctionalHilException $e) {
    $assert($e->stage() === 'modbus_exception' && $e->getCode() === 2, 'FC06 exception evidence changed');
}

try {
    new ModbusTcpFunctionalHilClient('127.0.0.1', ['a' => 1200, 'b' => 1200], true);
    $assert(false, 'duplicate allowlist address was accepted');
} catch (InvalidArgumentException) {
    $assert(true, 'duplicate allowlist address rejected');
}

$readonlyMethods = array_map(
    static fn(ReflectionMethod $method): string => $method->getName(),
    (new ReflectionClass(ModbusTcpReadOnlyClient::class))->getMethods(ReflectionMethod::IS_PUBLIC)
);
$assert(!in_array('writeStimulus', $readonlyMethods, true), 'read-only client gained Functional HIL write method');
$assert(!in_array('writeSingleRegister', $readonlyMethods, true), 'read-only client gained raw register write method');

$functionalMethods = array_map(
    static fn(ReflectionMethod $method): string => $method->getName(),
    (new ReflectionClass(ModbusTcpFunctionalHilClient::class))->getMethods(ReflectionMethod::IS_PUBLIC)
);
$assert(in_array('writeStimulus', $functionalMethods, true), 'Functional HIL writeStimulus API missing');
$assert(!in_array('writeSingleRegister', $functionalMethods, true), 'Functional HIL exposes raw-address write API');
foreach ($functionalMethods as $method) {
    $assert(preg_match('/coil|multiple/i', $method) !== 1, 'Functional HIL exposes prohibited write family: ' . $method);
}

$ready = sys_get_temp_dir() . '/testkit-plc-functional-hil-' . bin2hex(random_bytes(6)) . '.port';
$fixture = __DIR__ . '/fixtures/fake_modbus_functional_hil_server.php';
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open([PHP_BINARY, $fixture, '--ready=' . $ready], $descriptors, $pipes);
if (!is_resource($process)) {
    $assert(false, 'unable to start fake Functional HIL Modbus server');
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
        $assert(false, 'fake Functional HIL server did not publish port');
    } else {
        $tcp = new ModbusTcpFunctionalHilClient('127.0.0.1', $map, true, $port, 1, 1000);
        try {
            $tcp->writeStimulus('input.raw_known', 1);
            $tcp->writeStimulus('input.raw_level', 0);
            $assert(true, 'allowlisted FC06 writes completed');
        } catch (Throwable $e) {
            $assert(false, 'allowlisted fake FC06 write failed: ' . $e->getMessage());
        }
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
    fwrite(STDERR, "PLC Functional HIL tests failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "OK plc functional hil allowlisted FC06 capability\n";
