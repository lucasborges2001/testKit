#!/usr/bin/env php
<?php
declare(strict_types=1);

$ready = '';
$countFile = '';
$allowedAddresses = [1200, 1201];
$failOnWrites = [];
$closeOnWrites = [];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--ready=')) {
        $ready = substr($arg, strlen('--ready='));
    } elseif (str_starts_with($arg, '--count=')) {
        $countFile = substr($arg, strlen('--count='));
    } elseif (str_starts_with($arg, '--allowed-addresses=')) {
        $raw = substr($arg, strlen('--allowed-addresses='));
        $allowedAddresses = $raw === '' ? [] : array_map('intval', explode(',', $raw));
    } elseif (str_starts_with($arg, '--fail-on-write=')) {
        $raw = substr($arg, strlen('--fail-on-write='));
        $failOnWrites = $raw === '' ? [] : array_map('intval', explode(',', $raw));
    } elseif (str_starts_with($arg, '--close-on-write=')) {
        $raw = substr($arg, strlen('--close-on-write='));
        $closeOnWrites = $raw === '' ? [] : array_map('intval', explode(',', $raw));
    }
}
if ($ready === '') {
    fwrite(STDERR, "--ready is required\n");
    exit(2);
}
if ($countFile !== '') {
    file_put_contents($countFile, '0', LOCK_EX);
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!is_resource($server)) {
    fwrite(STDERR, "server error: $errno $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
if (!is_string($name) || !str_contains($name, ':')) {
    exit(1);
}
$port = (int)substr(strrchr($name, ':'), 1);
file_put_contents($ready, (string)$port);

$writeCount = 0;
while (true) {
    $client = @stream_socket_accept($server, 1);
    if (!is_resource($client)) {
        continue;
    }
    $frame = '';
    while (strlen($frame) < 12) {
        $chunk = fread($client, 12 - strlen($frame));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $frame .= $chunk;
    }

    if (strlen($frame) === 12) {
        $decoded = unpack('ntransaction/nprotocol/nlength/Cunit/Cfunction/naddress/nvalue', $frame);
        if (is_array($decoded) && (int)$decoded['function'] === 6) {
            $writeCount++;
            if ($countFile !== '') {
                file_put_contents($countFile, (string)$writeCount, LOCK_EX);
            }

            if (in_array($writeCount, $closeOnWrites, true)) {
                fclose($client);
                continue;
            }

            if (in_array($writeCount, $failOnWrites, true)) {
                fwrite($client, pack('nnnCCC', (int)$decoded['transaction'], 0, 3, (int)$decoded['unit'], 0x86, 4));
            } elseif (in_array((int)$decoded['address'], $allowedAddresses, true)) {
                fwrite($client, $frame);
            } else {
                fwrite($client, pack('nnnCCC', (int)$decoded['transaction'], 0, 3, (int)$decoded['unit'], 0x86, 2));
            }
        } elseif (is_array($decoded)) {
            fwrite($client, pack('nnnCCC', (int)$decoded['transaction'], 0, 3, (int)$decoded['unit'], 0x86, 2));
        }
    }
    if (is_resource($client)) {
        fclose($client);
    }
}
