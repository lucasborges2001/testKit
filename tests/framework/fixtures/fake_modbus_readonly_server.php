#!/usr/bin/env php
<?php
declare(strict_types=1);

$options = getopt('', ['ready:']);
$ready = isset($options['ready']) ? (string)$options['ready'] : '';
if ($ready === '') {
    fwrite(STDERR, "--ready is required\n");
    exit(2);
}

$errno = 0;
$errstr = '';
$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!is_resource($server)) {
    fwrite(STDERR, "server bind failed: {$errno} {$errstr}\n");
    exit(3);
}

$name = stream_socket_get_name($server, false);
if (!is_string($name) || ($pos = strrpos($name, ':')) === false) {
    fwrite(STDERR, "unable to determine fake server port\n");
    exit(4);
}
$port = (int)substr($name, $pos + 1);
file_put_contents($ready, (string)$port, LOCK_EX);

$deadline = microtime(true) + 15.0;
while (microtime(true) < $deadline) {
    $conn = @stream_socket_accept($server, 0.5);
    if (!is_resource($conn)) {
        continue;
    }

    try {
        $request = '';
        while (strlen($request) < 12) {
            $chunk = fread($conn, 12 - strlen($request));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $request .= $chunk;
        }

        if (strlen($request) !== 12) {
            continue;
        }

        $decoded = unpack('ntransaction/nprotocol/nlength/Cunit/Cfunction/nstart/nquantity', $request);
        if (!is_array($decoded)) {
            continue;
        }

        $tx = (int)$decoded['transaction'];
        $unit = (int)$decoded['unit'];
        $function = (int)$decoded['function'];
        $start = (int)$decoded['start'];
        $quantity = (int)$decoded['quantity'];

        if ((int)$decoded['protocol'] !== 0 || $function !== 3) {
            $response = pack('nnnCCC', $tx, 0, 3, $unit, 0x83, 1);
            fwrite($conn, $response);
            continue;
        }

        $words = match ([$start, $quantity]) {
            [0xFAA0, 3] => [0x1234, 0xAAAA, 0x5555],
            [0xFA0D, 1] => [2],
            [0xFA17, 1] => [3],
            [0xFA40, 6] => [0x7D00, 0x7D00, 0x8000, 0x7D00, 0x7D00, 0x8000],
            [0x0100, 2] => [0xBEEF, 0x1234],
            [0x0200, 3] => [1, 2, 3],
            default => null,
        };

        if ($words === null) {
            fwrite($conn, pack('nnnCCC', $tx, 0, 3, $unit, 0x83, 2));
            continue;
        }

        $payload = pack('n*', ...$words);
        $response = pack('nnnCC', $tx, 0, 3 + strlen($payload), $unit, 3)
            . chr(strlen($payload))
            . $payload;
        fwrite($conn, $response);
    } finally {
        fclose($conn);
    }
}

fclose($server);
