<?php
declare(strict_types=1);

namespace Testkit\Core\Plc;

final class ModbusTcpFunctionalHilClient
{
    public const FC_WRITE_SINGLE_REGISTER = 6;
    public const MAX_STIMULUS_REGISTERS = 64;

    private int $transactionId = 0;

    /** @var array<string,int> */
    private array $stimulusRegisters;

    private ModbusTcpReadOnlyClient $reader;

    /**
     * @param array<string,int> $stimulusRegisters host-owned logical stimulus id => exact Modbus register
     */
    public function __construct(
        private readonly string $host,
        array $stimulusRegisters,
        private readonly bool $writeEnabled,
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
        if ($stimulusRegisters === []) {
            throw new \InvalidArgumentException('Functional HIL stimulus allowlist must not be empty.');
        }
        if (count($stimulusRegisters) > self::MAX_STIMULUS_REGISTERS) {
            throw new \InvalidArgumentException('Functional HIL stimulus allowlist exceeds safety budget.');
        }

        $normalized = [];
        $seenAddresses = [];
        foreach ($stimulusRegisters as $id => $address) {
            if (!is_string($id) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $id) !== 1) {
                throw new \InvalidArgumentException('Functional HIL stimulus id is invalid.');
            }
            if (!is_int($address) || $address < 0 || $address > 0xFFFF) {
                throw new \InvalidArgumentException('Functional HIL stimulus address must fit UINT16.');
            }
            if (isset($seenAddresses[$address])) {
                throw new \InvalidArgumentException('Functional HIL stimulus addresses must be unique.');
            }
            $normalized[$id] = $address;
            $seenAddresses[$address] = true;
        }

        $this->stimulusRegisters = $normalized;
        $this->reader = new ModbusTcpReadOnlyClient($host, $port, $unitId, $timeoutMs);
    }

    /** @return array<int,int> */
    public function readHoldingRegisters(int $startAddress, int $quantity): array
    {
        return $this->reader->readHoldingRegisters($startAddress, $quantity);
    }

    public function writeStimulus(string $stimulusId, int $value): void
    {
        if (!$this->writeEnabled) {
            throw new ModbusTcpFunctionalHilException(
                'write_disabled',
                'Functional HIL write capability is disabled.'
            );
        }
        if (!array_key_exists($stimulusId, $this->stimulusRegisters)) {
            throw new ModbusTcpFunctionalHilException(
                'stimulus_not_allowed',
                'Functional HIL stimulus id is not allowlisted.'
            );
        }
        if ($value < 0 || $value > 0xFFFF) {
            throw new \InvalidArgumentException('Functional HIL stimulus value must fit UINT16.');
        }

        $transactionId = $this->nextTransactionId();
        $address = $this->stimulusRegisters[$stimulusId];
        $request = $this->encodeWriteSingleRegisterRequest($transactionId, $address, $value);
        $response = $this->sendRequest($request);
        $this->decodeWriteSingleRegisterResponse($response, $transactionId, $address, $value);
    }

    /** @return array<string,int> */
    public function stimulusRegisters(): array
    {
        return $this->stimulusRegisters;
    }

    public function writeEnabled(): bool
    {
        return $this->writeEnabled;
    }

    public function encodeWriteSingleRegisterRequest(
        int $transactionId,
        int $address,
        int $value
    ): string {
        if ($transactionId < 0 || $transactionId > 0xFFFF) {
            throw new \InvalidArgumentException('Transaction id must fit UINT16.');
        }
        if ($address < 0 || $address > 0xFFFF) {
            throw new \InvalidArgumentException('FC06 address must fit UINT16.');
        }
        if ($value < 0 || $value > 0xFFFF) {
            throw new \InvalidArgumentException('FC06 value must fit UINT16.');
        }

        return pack(
            'nnnCCnn',
            $transactionId,
            0,
            6,
            $this->unitId,
            self::FC_WRITE_SINGLE_REGISTER,
            $address,
            $value
        );
    }

    public function decodeWriteSingleRegisterResponse(
        string $response,
        int $expectedTransactionId,
        int $expectedAddress,
        int $expectedValue
    ): void {
        if (strlen($response) < 9) {
            throw new ModbusTcpFunctionalHilException('response_length', 'Modbus response is too short.');
        }

        $header = unpack('ntransaction/nprotocol/nlength/Cunit/Cfunction', substr($response, 0, 8));
        if (!is_array($header)) {
            throw new ModbusTcpFunctionalHilException('response_decode', 'Unable to decode Modbus MBAP header.');
        }
        if ((int)$header['transaction'] !== $expectedTransactionId) {
            throw new ModbusTcpFunctionalHilException('transaction_id', 'Unexpected Modbus transaction id.');
        }
        if ((int)$header['protocol'] !== 0) {
            throw new ModbusTcpFunctionalHilException('protocol_id', 'Unexpected Modbus protocol id.');
        }
        if ((int)$header['unit'] !== $this->unitId) {
            throw new ModbusTcpFunctionalHilException('unit_id', 'Unexpected Modbus unit id.');
        }

        $function = (int)$header['function'];
        if (($function & 0x80) !== 0) {
            if (strlen($response) !== 9) {
                throw new ModbusTcpFunctionalHilException('modbus_exception', 'Malformed Modbus exception response.');
            }
            $exceptionCode = ord($response[8]);
            throw new ModbusTcpFunctionalHilException(
                'modbus_exception',
                sprintf('Modbus exception for FC06: code=%d', $exceptionCode),
                $exceptionCode
            );
        }
        if ($function !== self::FC_WRITE_SINGLE_REGISTER) {
            throw new ModbusTcpFunctionalHilException(
                'function_code',
                sprintf('Unexpected Modbus function code: %d', $function)
            );
        }
        if ((int)$header['length'] !== 6 || strlen($response) !== 12) {
            throw new ModbusTcpFunctionalHilException('mbap_length', 'FC06 response length is invalid.');
        }

        $echo = unpack('naddress/nvalue', substr($response, 8, 4));
        if (!is_array($echo)) {
            throw new ModbusTcpFunctionalHilException('response_decode', 'Unable to decode FC06 echo.');
        }
        if ((int)$echo['address'] !== $expectedAddress) {
            throw new ModbusTcpFunctionalHilException('address_echo', 'FC06 response echoed a different address.');
        }
        if ((int)$echo['value'] !== $expectedValue) {
            throw new ModbusTcpFunctionalHilException('value_echo', 'FC06 response echoed a different value.');
        }
    }

    private function nextTransactionId(): int
    {
        $this->transactionId = ($this->transactionId + 1) & 0xFFFF;
        return $this->transactionId;
    }

    private function sendRequest(string $request): string
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
            throw new ModbusTcpFunctionalHilException(
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
                    throw new ModbusTcpFunctionalHilException('tcp_write', 'Unable to transmit FC06 request.');
                }
                $written += $chunk;
            }

            $header = $this->readExact($socket, 7);
            $decoded = unpack('ntransaction/nprotocol/nlength/Cunit', $header);
            if (!is_array($decoded)) {
                throw new ModbusTcpFunctionalHilException('response_decode', 'Unable to decode Modbus MBAP prefix.');
            }
            $remaining = (int)$decoded['length'] - 1;
            if ($remaining < 2 || $remaining > 255) {
                throw new ModbusTcpFunctionalHilException('mbap_length', 'Invalid Modbus MBAP response length.');
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
                throw new ModbusTcpFunctionalHilException('tcp_read', 'Unable to read Modbus response.');
            }
            if ($chunk === '') {
                $meta = stream_get_meta_data($socket);
                if (($meta['timed_out'] ?? false) === true) {
                    throw new ModbusTcpFunctionalHilException('tcp_timeout', 'Timed out while reading Modbus response.');
                }
                if (($meta['eof'] ?? false) === true) {
                    throw new ModbusTcpFunctionalHilException('tcp_eof', 'Modbus peer closed the connection early.');
                }
                usleep(1000);
                continue;
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }
}
