#!/usr/bin/env php
<?php
declare(strict_types=1);

$ready = '';
$countFile = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--ready=')) {
        $ready = substr($arg, strlen('--ready='));
    } elseif (str_starts_with($arg, '--count=')) {
        $countFile = substr($arg, strlen('--count='));
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
            if ($countFile !== '') {
                $count = is_file($countFile) ? (int)trim((string)file_get_contents($countFile)) : 0;
                file_put_contents($countFile, (string)($count + 1), LOCK_EX);
            }
            if (in_array((int)$decoded['address'], [1200, 1201], true)) {
                fwrite($client, $frame);
            } else {
                fwrite($client, pack('nnnCCC', (int)$decoded['transaction'], 0, 3, (int)$decoded['unit'], 0x86, 2));
            }
        } elseif (is_array($decoded)) {
            fwrite($client, pack('nnnCCC', (int)$decoded['transaction'], 0, 3, (int)$decoded['unit'], 0x86, 2));
        }
    }
    fclose($client);
}
