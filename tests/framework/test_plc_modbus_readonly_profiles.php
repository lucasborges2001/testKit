<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/php/plc/bootstrap.php';

use Testkit\Core\Plc\ModbusTcpReadOnlyClient;
use Testkit\Core\Plc\ModbusTcpReadOnlyException;
use Testkit\Core\Plc\RuntimeProfileCatalog;
use Testkit\Core\Plc\RuntimeProfileDetector;

$errors = [];
$assert = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$client = new ModbusTcpReadOnlyClient('127.0.0.1', 502, 1, 1500);
$request = $client->encodeReadHoldingRegistersRequest(0x1234, 0x3080, 27);
$assert(bin2hex($request) === '12340000000601033080001b', 'FC03 request encoding changed');

$payload = '';
for ($i = 0; $i < 27; $i++) {
    $payload .= pack('n', 100 + $i);
}
$response = pack('nnnCC', 0x1234, 0, 3 + strlen($payload), 1, 3)
    . chr(strlen($payload))
    . $payload;
$words = $client->decodeReadHoldingRegistersResponse($response, 0x1234, 27);
$assert(count($words) === 27, 'FC03 decoder must return 27 words');
$assert($words[0] === 100 && $words[26] === 126, 'FC03 decoder changed word order');

try {
    $client->decodeReadHoldingRegistersResponse(pack('nnnCCC', 0x1234, 0, 3, 1, 0x83, 2), 0x1234, 27);
    $assert(false, 'Modbus exception response was accepted');
} catch (ModbusTcpReadOnlyException $e) {
    $assert($e->stage() === 'modbus_exception', 'Modbus exception stage changed');
    $assert($e->getCode() === 2, 'Modbus exception code must be retained');
}

$publicMethods = array_map(
    static fn(ReflectionMethod $method): string => strtolower($method->getName()),
    (new ReflectionClass(ModbusTcpReadOnlyClient::class))->getMethods(ReflectionMethod::IS_PUBLIC)
);
foreach ($publicMethods as $method) {
    $assert(!preg_match('/write|coil|multiple/', $method), 'Read-only client exposes write-like public method: ' . $method);
}

$clientSource = file_get_contents($root . '/core/php/plc/ModbusTcpReadOnlyClient.php');
$assert(is_string($clientSource), 'Unable to read ModbusTcpReadOnlyClient source');
if (is_string($clientSource)) {
    foreach (['FC_WRITE', 'writeRegister', 'writeRegisters', 'writeCoil', 'writeMultiple'] as $marker) {
        $assert(!str_contains($clientSource, $marker), 'Read-only client contains forbidden marker: ' . $marker);
    }
}

$profiles = RuntimeProfileCatalog::all();
$assert(isset($profiles[RuntimeProfileCatalog::WAGO_PFC200_CODESYS2]), 'Missing WAGO PFC200 CODESYS2 profile');
$assert(isset($profiles[RuntimeProfileCatalog::WAGO_PFC200_ERUNTIME]), 'Missing WAGO PFC200 e!RUNTIME profile');

$detector = new RuntimeProfileDetector();

$codesysReader = static function (int $address, int $quantity): array {
    return match ([$address, $quantity]) {
        [0x2002, 3] => [0x1234, 0xAAAA, 0x5555],
        [0x1040, 1] => [1],
        default => throw new ModbusTcpReadOnlyException('modbus_exception', 'Modbus exception for FC03: code=2', 2),
    };
};
$codesys = $detector->detect($codesysReader);
$assert(($codesys['status'] ?? null) === 'DETECTED', 'CODESYS2 fixture must be detected');
$assert(($codesys['detectedProfile'] ?? null) === RuntimeProfileCatalog::WAGO_PFC200_CODESYS2, 'Wrong CODESYS2 profile id');

$eruntimeReader = static function (int $address, int $quantity): array {
    return match ([$address, $quantity]) {
        [0xFAA0, 3] => [0x1234, 0xAAAA, 0x5555],
        [0xFA0D, 1] => [2],
        [0xFA17, 1] => [3],
        [0xFA40, 6] => [0x7D00, 0x7D00, 0x8000, 0x7D00, 0x7D00, 0x8000],
        default => throw new ModbusTcpReadOnlyException('modbus_exception', 'Modbus exception for FC03: code=2', 2),
    };
};
$eruntime = $detector->detect($eruntimeReader);
$assert(($eruntime['status'] ?? null) === 'DETECTED', 'e!RUNTIME fixture must be detected');
$assert(($eruntime['detectedProfile'] ?? null) === RuntimeProfileCatalog::WAGO_PFC200_ERUNTIME, 'Wrong e!RUNTIME profile id');

$mismatch = $detector->detect($eruntimeReader, RuntimeProfileCatalog::WAGO_PFC200_CODESYS2);
$assert(($mismatch['status'] ?? null) === 'PROFILE_MISMATCH', 'Explicit profile contradiction must fail closed');
$assert(($mismatch['detectedProfile'] ?? null) === RuntimeProfileCatalog::WAGO_PFC200_ERUNTIME, 'Mismatch should expose actual detected profile');

$unknownReader = static function (int $address, int $quantity): array {
    throw new ModbusTcpReadOnlyException('modbus_exception', 'Modbus exception for FC03: code=2', 2);
};
$unknown = $detector->detect($unknownReader);
$assert(($unknown['status'] ?? null) === 'UNKNOWN', 'Unsupported map must remain UNKNOWN');

$ambiguousReader = static function (int $address, int $quantity): array {
    return match ([$address, $quantity]) {
        [0x2002, 3], [0xFAA0, 3] => [0x1234, 0xAAAA, 0x5555],
        [0x1040, 1] => [1],
        [0xFA0D, 1] => [2],
        [0xFA17, 1] => [3],
        [0xFA40, 6] => [1, 1, 1, 1, 1, 1],
        default => throw new ModbusTcpReadOnlyException('modbus_exception', 'Modbus exception for FC03: code=2', 2),
    };
};
$ambiguous = $detector->detect($ambiguousReader);
$assert(($ambiguous['status'] ?? null) === 'AMBIGUOUS', 'Multiple profile matches must fail closed as AMBIGUOUS');
$assert(array_key_exists('detectedProfile', $ambiguous) && $ambiguous['detectedProfile'] === null, 'Ambiguous result must not select a profile');

if ($errors !== []) {
    fwrite(STDERR, "PLC Modbus read-only profile tests failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "OK plc modbus readonly profiles\n";
