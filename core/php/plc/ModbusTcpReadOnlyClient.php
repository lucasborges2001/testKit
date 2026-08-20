<?php
declare(strict_types=1);

namespace Testkit\Core\Plc;

final class ModbusTcpReadOnlyClient
{
    public const FC_READ_HOLDING_REGISTERS = 3;

    private int $transactionId = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 502,
        private readonly int $unitId = 1,
        private readonly int $timeoutMs = 1500
    ) {
        if (trim($host) === '') {
            throw new \InvalidArgumentException('Modbus host must not be empty.');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Modbus port must be between 1 and 65535.');
        }
        if ($unitId < 0 || $unitId > 255) {
            throw new \InvalidArgumentException('Modbus unit id must be between 0 and 255.');
        }
        if ($timeoutMs < 1 || $timeoutMs > 60000) {
            throw new \InvalidArgumentException('Modbus timeout must be between 1 and 60000 ms.');
        }
    }

    /** @return array<int,int> */
    public function readHoldingRegisters(int $startAddress, int $quantity): array
    {
        $transactionId = $this->nextTransactionId();
        $request = $this->encodeReadHoldingRegistersRequest($transactionId, $startAddress, $quantity);
        $response = $this->sendReadRequest($request);

        return $this->decodeReadHoldingRegistersResponse(
            $response,
            $transactionId,
            $quantity
        );
    }

    public function encodeReadHoldingRegistersRequest(
        int $transactionId,
        int $startAddress,
        int $quantity
    ): string {
        if ($transactionId < 0 || $transactionId > 0xFFFF) {
            throw new \InvalidArgumentException('Transaction id must fit UINT16.');
        }
        if ($startAddress < 0 || $startAddress > 0xFFFF) {
            throw new \InvalidArgumentException('Start address must fit UINT16.');
        }
        if ($quantity < 1 || $quantity > 125) {
            throw new \InvalidArgumentException('FC03 quantity must be between 1 and 125 registers.');
        }
        if ($startAddress + $quantity - 1 > 0xFFFF) {
            throw new \InvalidArgumentException('FC03 range exceeds Modbus UINT16 address space.');
        }

        return pack(
            'nnnCCnn',
            $transactionId,
            0,
            6,
            $this->unitId,
            self::FC_READ_HOLDING_REGISTERS,
            $startAddress,
            $quantity
        );
    }

    /** @return array<int,int> */
    public function decodeReadHoldingRegistersResponse(
        string $response,
        int $expectedTransactionId,
        int $expectedQuantity
    ): array {
        if (strlen($response) < 9) {
            throw new ModbusTcpReadOnlyException('response_length', 'Modbus response is too short.');
        }

        $header = unpack('ntransaction/nprotocol/nlength/Cunit/Cfunction', substr($response, 0, 8));
        if (!is_array($header)) {
            throw new ModbusTcpReadOnlyException('response_decode', 'Unable to decode Modbus MBAP header.');
        }

        if ((int)$header['transaction'] !== $expectedTransactionId) {
            throw new ModbusTcpReadOnlyException('transaction_id', 'Unexpected Modbus transaction id.');
        }
        if ((int)$header['protocol'] !== 0) {
            throw new ModbusTcpReadOnlyException('protocol_id', 'Unexpected Modbus protocol id.');
        }
        if ((int)$header['unit'] !== $this->unitId) {
            throw new ModbusTcpReadOnlyException('unit_id', 'Unexpected Modbus unit id.');
        }

        $mbapLength = (int)$header['length'];
        $expectedFrameLength = 6 + $mbapLength;
        if ($mbapLength < 3 || strlen($response) !== $expectedFrameLength) {
            throw new ModbusTcpReadOnlyException('mbap_length', 'Modbus MBAP length does not match response frame.');
        }

        $function = (int)$header['function'];
        if (($function & 0x80) !== 0) {
            if (strlen($response) !== 9) {
                throw new ModbusTcpReadOnlyException('modbus_exception', 'Malformed Modbus exception response.');
            }
            $exceptionCode = ord($response[8]);
            throw new ModbusTcpReadOnlyException(
                'modbus_exception',
                sprintf('Modbus exception for FC03: code=%d', $exceptionCode),
                $exceptionCode
            );
        }
        if ($function !== self::FC_READ_HOLDING_REGISTERS) {
            throw new ModbusTcpReadOnlyException(
                'function_code',
                sprintf('Unexpected Modbus function code: %d', $function)
            );
        }

        $byteCount = ord($response[8]);
        $expectedByteCount = $expectedQuantity * 2;
        if ($byteCount !== $expectedByteCount) {
            throw new ModbusTcpReadOnlyException(
                'byte_count',
                sprintf('Unexpected FC03 byte count: got=%d expected=%d', $byteCount, $expectedByteCount)
            );
        }
        if (strlen($response) !== 9 + $byteCount) {
            throw new ModbusTcpReadOnlyException('response_length', 'FC03 payload length does not match byte count.');
        }

        $words = [];
        for ($offset = 0; $offset < $byteCount; $offset += 2) {
            $decoded = unpack('nvalue', substr($response, 9 + $offset, 2));
            if (!is_array($decoded)) {
                throw new ModbusTcpReadOnlyException('response_decode', 'Unable to decode FC03 register word.');
            }
            $words[] = (int)$decoded['value'];
        }

        if (count($words) !== $expectedQuantity) {
            throw new ModbusTcpReadOnlyException('register_count', 'Unexpected number of FC03 registers.');
        }

        return $words;
    }

    private function nextTransactionId(): int
    {
        $this->transactionId = ($this->transactionId + 1) & 0xFFFF;
        return $this->transactionId;
    }

    private function sendReadRequest(string $request): string
    {
        $timeoutSeconds = max(0.001, $this->timeoutMs / 1000);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($socket)) {
            throw new ModbusTcpReadOnlyException(
                'tcp_connect',
                sprintf('Unable to connect to Modbus TCP endpoint: errno=%d error=%s', $errno, $errstr)
            );
        }

        try {
            $seconds = intdiv($this->timeoutMs, 1000);
            $microseconds = ($this->timeoutMs % 1000) * 1000;
            stream_set_timeout($socket, $seconds, $microseconds);

            $written = 0;
            $requestLength = strlen($request);
            while ($written < $requestLength) {
                $chunk = @fwrite($socket, substr($request, $written));
                if ($chunk === false || $chunk === 0) {
                    throw new ModbusTcpReadOnlyException('tcp_write', 'Unable to transmit FC03 request.');
                }
                $written += $chunk;
            }

            $header = $this->readExact($socket, 7);
            $decoded = unpack('ntransaction/nprotocol/nlength/Cunit', $header);
            if (!is_array($decoded)) {
                throw new ModbusTcpReadOnlyException('response_decode', 'Unable to decode Modbus MBAP prefix.');
            }

            $remaining = (int)$decoded['length'] - 1;
            if ($remaining < 2 || $remaining > 255) {
                throw new ModbusTcpReadOnlyException('mbap_length', 'Invalid Modbus MBAP response length.');
            }

            return $header . $this->readExact($socket, $remaining);
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function readExact($socket, int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = @fread($socket, $length - strlen($buffer));
            if ($chunk === false) {
                throw new ModbusTcpReadOnlyException('tcp_read', 'Unable to read Modbus response.');
            }
            if ($chunk === '') {
                $meta = stream_get_meta_data($socket);
                if (($meta['timed_out'] ?? false) === true) {
                    throw new ModbusTcpReadOnlyException('tcp_timeout', 'Timed out while reading Modbus response.');
                }
                if (($meta['eof'] ?? false) === true) {
                    throw new ModbusTcpReadOnlyException('tcp_eof', 'Modbus peer closed the connection early.');
                }
                usleep(1000);
                continue;
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }
}
