<?php
declare(strict_types=1);

namespace Testkit\Core\Plc;

final class FunctionalHilSession
{
    private function __construct(
        private readonly ModbusTcpFunctionalHilClient $client,
        /** @var array<string,mixed> */
        private readonly array $gate,
        private readonly bool $writeRequested,
        private readonly bool $writesAllowed
    ) {
    }

    /**
     * Open a Functional HIL session from explicit host-owned identity evidence.
     *
     * The stimulus map is validated by ModbusTcpFunctionalHilClient before the
     * session is returned. No network connection is opened here.
     *
     * @param array<string,mixed> $gateEvidence
     * @param array<string,int> $stimulusRegisters
     */
    public static function open(
        array $gateEvidence,
        string $host,
        array $stimulusRegisters,
        bool $writeRequested,
        int $port = 502,
        int $unitId = 1,
        int $timeoutMs = 1500
    ): self {
        $gate = FunctionalHilGate::normalize($gateEvidence);
        $writesAllowed = $writeRequested && ($gate['identities_pass'] ?? false) === true;

        $client = new ModbusTcpFunctionalHilClient(
            $host,
            $stimulusRegisters,
            $writesAllowed,
            $port,
            $unitId,
            $timeoutMs
        );

        return new self($client, $gate, $writeRequested, $writesAllowed);
    }

    /** @return array<int,int> */
    public function readHoldingRegisters(int $startAddress, int $quantity): array
    {
        return $this->client->readHoldingRegisters($startAddress, $quantity);
    }

    public function writeStimulus(string $stimulusId, int $value): void
    {
        $this->client->writeStimulus($stimulusId, $value);
    }

    public function writesAllowed(): bool
    {
        return $this->writesAllowed;
    }

    /**
     * Sanitized machine-readable decision envelope. It intentionally excludes
     * the physical register map.
     *
     * @return array<string,mixed>
     */
    public function gateReport(): array
    {
        return [
            'schema' => FunctionalHilGate::SCHEMA,
            'runtime' => $this->gate['runtime'],
            'application' => $this->gate['application'],
            'bridge' => $this->gate['bridge'],
            'metadata' => $this->gate['metadata'],
            'write_requested' => $this->writeRequested,
            'writes_allowed' => $this->writesAllowed,
        ];
    }

    /** @return list<string> */
    public function stimulusIds(): array
    {
        $ids = array_keys($this->client->stimulusRegisters());
        sort($ids);
        return $ids;
    }
}
