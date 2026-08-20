<?php
declare(strict_types=1);

namespace Testkit\Core\Plc;

use Closure;
use Throwable;

final class ReadOnlyApplicationMapProbe
{
    /** @var Closure(int,int):array<int,int> */
    private Closure $reader;

    /** @param callable(int,int):array<int,int> $reader */
    public function __construct(
        callable $reader,
        private readonly RuntimeProfileDetector $detector
    ) {
        $this->reader = Closure::fromCallable($reader);
    }

    public static function fromClient(
        ModbusTcpReadOnlyClient $client,
        RuntimeProfileDetector $detector
    ): self {
        return new self(
            static fn(int $address, int $quantity): array => $client->readHoldingRegisters($address, $quantity),
            $detector
        );
    }

    /**
     * Execute a host-owned read plan. Raw application values are returned only in-memory
     * under valuesByWindow; evidence never contains application register dumps.
     *
     * @param array<string,mixed> $applicationMap
     * @return array{evidence:array<string,mixed>,valuesByWindow:array<string,array<int,int>>}
     */
    public function run(
        array $applicationMap,
        string $requestedRuntimeProfile = RuntimeProfileCatalog::AUTO
    ): array {
        $started = hrtime(true);
        $valuesByWindow = [];

        try {
            $plan = ReadOnlyApplicationMapValidator::normalize($applicationMap);
        } catch (Throwable $e) {
            return $this->finish([
                'status' => 'FAIL',
                'mode' => 'readonly',
                'transport' => 'modbus-tcp',
                'readonlyInvariant' => true,
                'runtime' => null,
                'plan' => null,
                'windows' => [],
                'failureStage' => 'plan_validation',
                'failureReason' => $e->getMessage(),
            ], $valuesByWindow, $started);
        }

        try {
            $detection = $this->detector->detect($this->reader, $requestedRuntimeProfile);
        } catch (Throwable $e) {
            return $this->finish([
                'status' => 'FAIL',
                'mode' => 'readonly',
                'transport' => 'modbus-tcp',
                'readonlyInvariant' => true,
                'runtime' => null,
                'plan' => $this->planEvidence($plan),
                'windows' => [],
                'failureStage' => 'runtime_detection',
                'failureReason' => $e->getMessage(),
            ], $valuesByWindow, $started);
        }

        $status = (string)($detection['status'] ?? 'UNKNOWN');
        $detectedProfile = $detection['detectedProfile'] ?? null;
        if ($status !== 'DETECTED' || !is_string($detectedProfile) || $detectedProfile === '') {
            return $this->finish([
                'status' => 'FAIL',
                'mode' => 'readonly',
                'transport' => 'modbus-tcp',
                'readonlyInvariant' => true,
                'runtime' => $detection,
                'plan' => $this->planEvidence($plan),
                'windows' => [],
                'failureStage' => 'runtime_detection',
                'failureReason' => match ($status) {
                    'PROFILE_MISMATCH' => 'Configured runtime profile contradicts detected Modbus runtime map.',
                    'AMBIGUOUS' => 'Multiple runtime profiles matched; refusing to execute an application map.',
                    default => 'No supported runtime profile was detected; application map was not executed.',
                },
            ], $valuesByWindow, $started);
        }

        if (!in_array($detectedProfile, $plan['supportedRuntimeProfiles'], true)) {
            return $this->finish([
                'status' => 'BLOCKED',
                'mode' => 'readonly',
                'transport' => 'modbus-tcp',
                'readonlyInvariant' => true,
                'runtime' => $detection,
                'plan' => $this->planEvidence($plan),
                'windows' => [],
                'failureStage' => 'runtime_gate',
                'failureReason' => sprintf(
                    'Detected runtime profile "%s" is not supported by application map "%s".',
                    $detectedProfile,
                    $plan['id']
                ),
            ], $valuesByWindow, $started);
        }

        $windowEvidence = [];
        $windows = $plan['windows'];
        foreach ($windows as $index => $window) {
            $windowStarted = hrtime(true);
            $base = [
                'id' => $window['id'],
                'function' => $window['function'],
                'startAddress' => $window['startAddress'],
                'endAddress' => $window['endAddress'],
                'quantity' => $window['quantity'],
                'registerCount' => 0,
                'valid' => false,
                'failureStage' => null,
                'durationMs' => 0,
            ];

            try {
                $words = ($this->reader)($window['startAddress'], $window['quantity']);
                if (!is_array($words) || count($words) !== $window['quantity']) {
                    throw new ModbusTcpReadOnlyException(
                        'register_count',
                        sprintf('Window "%s" returned unexpected register count.', $window['id'])
                    );
                }
                foreach ($words as $word) {
                    if (!is_int($word) || $word < 0 || $word > 0xFFFF) {
                        throw new ModbusTcpReadOnlyException(
                            'register_value',
                            sprintf('Window "%s" returned a value outside UINT16.', $window['id'])
                        );
                    }
                }

                $valuesByWindow[$window['id']] = array_values($words);
                $base['registerCount'] = count($words);
                $base['valid'] = true;
                $base['durationMs'] = self::elapsedMs($windowStarted);
                $windowEvidence[] = $base;
            } catch (Throwable $e) {
                $base['failureStage'] = $e instanceof ModbusTcpReadOnlyException ? $e->stage() : 'reader_exception';
                $base['durationMs'] = self::elapsedMs($windowStarted);
                $windowEvidence[] = $base;

                return $this->finish([
                    'status' => 'FAIL',
                    'mode' => 'readonly',
                    'transport' => 'modbus-tcp',
                    'readonlyInvariant' => true,
                    'runtime' => $detection,
                    'plan' => $this->planEvidence($plan),
                    'windows' => $windowEvidence,
                    'failureStage' => $base['failureStage'],
                    'failureReason' => $e->getMessage(),
                ], $valuesByWindow, $started);
            }

            if ($plan['interRequestDelayMs'] > 0 && $index < count($windows) - 1) {
                usleep($plan['interRequestDelayMs'] * 1000);
            }
        }

        return $this->finish([
            'status' => 'PASS',
            'mode' => 'readonly',
            'transport' => 'modbus-tcp',
            'readonlyInvariant' => true,
            'runtime' => $detection,
            'plan' => $this->planEvidence($plan),
            'windows' => $windowEvidence,
            'failureStage' => null,
            'failureReason' => null,
        ], $valuesByWindow, $started);
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function planEvidence(array $plan): array
    {
        return [
            'id' => $plan['id'],
            'supportedRuntimeProfiles' => $plan['supportedRuntimeProfiles'],
            'windowCount' => $plan['windowCount'],
            'totalRegisters' => $plan['totalRegisters'],
            'interRequestDelayMs' => $plan['interRequestDelayMs'],
            'budgets' => $plan['budgets'],
        ];
    }

    /**
     * @param array<string,mixed> $evidence
     * @param array<string,array<int,int>> $valuesByWindow
     * @return array{evidence:array<string,mixed>,valuesByWindow:array<string,array<int,int>>}
     */
    private function finish(array $evidence, array $valuesByWindow, int $started): array
    {
        $evidence['durationMs'] = self::elapsedMs($started);
        return ['evidence' => $evidence, 'valuesByWindow' => $valuesByWindow];
    }

    private static function elapsedMs(int $started): int
    {
        return (int)round((hrtime(true) - $started) / 1_000_000);
    }
}
