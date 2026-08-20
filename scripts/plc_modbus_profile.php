#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/php/common/Env.php';
require_once $root . '/core/php/common/Paths.php';
require_once $root . '/core/php/reporting/AtomicJsonWriter.php';
require_once $root . '/core/php/plc/bootstrap.php';

use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;
use Testkit\Core\Plc\ModbusTcpReadOnlyClient;
use Testkit\Core\Plc\RuntimeProfileCatalog;
use Testkit\Core\Plc\RuntimeProfileDetector;
use Testkit\Core\Reporting\AtomicJsonWriter;

$options = getopt('', ['profile:', 'json', 'no-artifact']);
$requestedProfile = isset($options['profile'])
    ? trim((string)$options['profile'])
    : Env::string('TESTKIT_PLC_PROFILE', RuntimeProfileCatalog::AUTO);
$emitJson = array_key_exists('json', $options);
$writeArtifact = !array_key_exists('no-artifact', $options);

$host = Env::string('TESTKIT_PLC_HOST');
$port = Env::int('TESTKIT_PLC_PORT', 502);
$unitId = Env::int('TESTKIT_PLC_UNIT_ID', 1);
$timeoutMs = Env::int('TESTKIT_PLC_TIMEOUT_MS', 1500);
$enabledRaw = getenv('TESTKIT_PLC_ENABLED');
$enabled = $enabledRaw !== false && trim((string)$enabledRaw) === '1';

$artifact = [
    'schema' => 'testkit.plc-modbus-readonly-profile.v1',
    'timestampUtc' => gmdate('c'),
    'ok' => false,
    'status' => 'BLOCKED',
    'mode' => 'readonly',
    'transport' => 'modbus-tcp',
    'target' => [
        'host' => $host !== '' ? $host : '[not configured]',
        'port' => $port,
        'unitId' => $unitId,
        'timeoutMs' => $timeoutMs,
    ],
    'result' => [
        'hostResolved' => false,
        'transportReachable' => false,
        'readonlyInvariant' => true,
        'requestedProfile' => $requestedProfile,
        'detectionStatus' => null,
        'detectedProfile' => null,
    ],
];

$artifactPath = Paths::artifactsRoot() . '/plc/modbus-readonly-profile/latest.json';

$finish = static function (array $payload, int $exitCode) use ($emitJson, $writeArtifact, $artifactPath): never {
    if ($writeArtifact) {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            AtomicJsonWriter::writeFileAtomic($artifactPath, $json . PHP_EOL);
        }
    }

    if ($emitJson) {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        echo (is_string($json) ? $json : '{"ok":false,"status":"FAIL","reason":"json_encode"}') . PHP_EOL;
    } else {
        $detected = $payload['result']['detectedProfile'] ?? null;
        printf(
            "%s plc_modbus_profile detection=%s profile=%s mode=readonly\n",
            (string)($payload['status'] ?? 'FAIL'),
            (string)($payload['result']['detectionStatus'] ?? 'n/a'),
            is_string($detected) && $detected !== '' ? $detected : 'none'
        );
        if (isset($payload['reason'])) {
            fwrite(STDERR, 'reason: ' . (string)$payload['reason'] . PHP_EOL);
        }
        if ($writeArtifact) {
            echo 'artifact: ' . $artifactPath . PHP_EOL;
        }
    }

    exit($exitCode);
};

if (!$enabled) {
    $artifact['reason'] = 'TESTKIT_PLC_ENABLED must be exactly 1 for a real PLC probe.';
    $finish($artifact, 2);
}

try {
    if ($host === '') {
        throw new InvalidArgumentException('TESTKIT_PLC_HOST is required when PLC probing is enabled.');
    }
    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException('TESTKIT_PLC_PORT must be between 1 and 65535.');
    }
    if ($unitId < 0 || $unitId > 255) {
        throw new InvalidArgumentException('TESTKIT_PLC_UNIT_ID must be between 0 and 255.');
    }
    if ($timeoutMs < 1 || $timeoutMs > 60000) {
        throw new InvalidArgumentException('TESTKIT_PLC_TIMEOUT_MS must be between 1 and 60000.');
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $artifact['result']['hostResolved'] = true;
    } else {
        $resolved = @gethostbynamel($host);
        if ($resolved === false || $resolved === []) {
            throw new RuntimeException('Unable to resolve TESTKIT_PLC_HOST.');
        }
        $artifact['result']['hostResolved'] = true;
    }

    $client = new ModbusTcpReadOnlyClient($host, $port, $unitId, $timeoutMs);
    $detector = new RuntimeProfileDetector();
    $detection = $detector->detect(
        static fn(int $address, int $quantity): array => $client->readHoldingRegisters($address, $quantity),
        $requestedProfile
    );

    $artifact['detection'] = $detection;
    $artifact['result']['detectionStatus'] = $detection['status'] ?? null;
    $artifact['result']['detectedProfile'] = $detection['detectedProfile'] ?? null;

    foreach (($detection['profiles'] ?? []) as $profile) {
        if (!is_array($profile)) {
            continue;
        }
        foreach (($profile['probes'] ?? []) as $probe) {
            if (!is_array($probe)) {
                continue;
            }
            if (($probe['passed'] ?? false) === true) {
                $artifact['result']['transportReachable'] = true;
                break 2;
            }
            $stage = $probe['errorStage'] ?? null;
            if (is_string($stage) && !in_array($stage, ['tcp_connect', 'tcp_timeout', 'tcp_eof'], true)) {
                $artifact['result']['transportReachable'] = true;
            }
        }
    }

    if (($detection['status'] ?? null) === 'DETECTED') {
        $artifact['ok'] = true;
        $artifact['status'] = 'PASS';
        $finish($artifact, 0);
    }

    $artifact['status'] = 'FAIL';
    $artifact['reason'] = match ($detection['status'] ?? null) {
        'PROFILE_MISMATCH' => 'Explicit PLC profile does not match detected runtime map.',
        'AMBIGUOUS' => 'Multiple PLC runtime profiles matched; refusing to guess.',
        default => 'No supported PLC runtime profile matched the read-only probes.',
    };
    $finish($artifact, 1);
} catch (Throwable $e) {
    $artifact['status'] = 'FAIL';
    $artifact['reason'] = $e->getMessage();
    $artifact['failureStage'] = 'configuration_or_resolution';
    $finish($artifact, 1);
}
