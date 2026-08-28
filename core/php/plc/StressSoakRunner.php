<?php
declare(strict_types=1);

namespace Testkit\Core\Plc;

use InvalidArgumentException;
use Throwable;

final class StressSoakRunner
{
    public const SCHEMA = 'testkit.plc-stress-result.v1';
    public const MAX_ITERATIONS = 1000000;
    public const MAX_FAILURE_DETAILS = 100;

    /**
     * Repeatedly execute a caller-owned scenario. This class never discovers,
     * connects to, arms or writes hardware by itself.
     *
     * Options:
     *   policy: stop-on-first-fail|keep-going (default stop-on-first-fail)
     *   maxFailureDetails: 0..100
     *   readScan: optional callable():int
     *   scanCounterModulus: optional int >=2
     *   readMetrics: optional callable():array with watchdogCount/overrunCount/applicationErrorCount
     *   metadata: optional artifact metadata
     *
     * @param callable(int):mixed $scenario 1-based iteration number
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function run(int $iterations, callable $scenario, array $options = []): array
    {
        if ($iterations < 1 || $iterations > self::MAX_ITERATIONS) {
            throw new InvalidArgumentException(sprintf(
                'iterations must be between 1 and %d.',
                self::MAX_ITERATIONS
            ));
        }

        $policy = $options['policy'] ?? 'stop-on-first-fail';
        if (!is_string($policy) || !in_array($policy, ['stop-on-first-fail', 'keep-going'], true)) {
            throw new InvalidArgumentException('policy must be stop-on-first-fail or keep-going.');
        }

        $maxFailureDetails = $options['maxFailureDetails'] ?? 16;
        if (!is_int($maxFailureDetails) || $maxFailureDetails < 0 || $maxFailureDetails > self::MAX_FAILURE_DETAILS) {
            throw new InvalidArgumentException(sprintf(
                'maxFailureDetails must be between 0 and %d.',
                self::MAX_FAILURE_DETAILS
            ));
        }

        $readScan = $options['readScan'] ?? null;
        if ($readScan !== null && !is_callable($readScan)) {
            throw new InvalidArgumentException('readScan must be callable when provided.');
        }
        $modulus = $options['scanCounterModulus'] ?? null;
        if ($modulus !== null && (!is_int($modulus) || $modulus < 2)) {
            throw new InvalidArgumentException('scanCounterModulus must be an integer >=2 when provided.');
        }

        $readMetrics = $options['readMetrics'] ?? null;
        if ($readMetrics !== null && !is_callable($readMetrics)) {
            throw new InvalidArgumentException('readMetrics must be callable when provided.');
        }
        $metadata = $options['metadata'] ?? [];
        if (!is_array($metadata) || (array_is_list($metadata) && $metadata !== [])) {
            throw new InvalidArgumentException('stress metadata must be an object/associative array.');
        }

        $started = hrtime(true);
        $scanStart = null;
        $scanEnd = null;
        $scanDelta = null;
        $failures = 0;
        $transportErrors = 0;
        $cleanupFailures = 0;
        $snapshotInconsistencies = 0;
        $completed = 0;
        $failureDetails = [];
        $metrics = [];

        if ($readScan !== null) {
            try {
                $scanStart = self::scanValue($readScan(), $modulus);
            } catch (Throwable $e) {
                return PlcArtifact::build(self::SCHEMA, 'EXECUTED', 'FAIL', [
                    'outcome' => 'TRANSPORT_ERROR',
                    'iterations_requested' => $iterations,
                    'iterations_completed' => 0,
                    'failures' => 1,
                    'transport_errors' => 1,
                    'cleanup_failures' => 0,
                    'snapshot_inconsistencies' => 0,
                    'scan_start' => null,
                    'scan_end' => null,
                    'scan_delta' => null,
                    'elapsed_host_time_ms' => self::elapsedMs($started),
                    'failure_details' => [['iteration' => 0, 'stage' => 'scan_start', 'reason' => $e->getMessage()]],
                ], $metadata);
            }
        }

        for ($iteration = 1; $iteration <= $iterations; $iteration++) {
            $failed = false;
            $detail = null;

            try {
                $result = $scenario($iteration);
                $completed++;
                $normalized = self::normalizeScenarioResult($result);
                $failed = !$normalized['pass'];
                if ($normalized['transport_error']) {
                    $transportErrors++;
                }
                if ($normalized['cleanup_failure']) {
                    $cleanupFailures++;
                }
                if ($normalized['snapshot_inconsistent']) {
                    $snapshotInconsistencies++;
                }
                if ($failed) {
                    $detail = [
                        'iteration' => $iteration,
                        'outcome' => $normalized['outcome'],
                        'stage' => $normalized['stage'],
                        'reason' => $normalized['reason'],
                    ];
                }
            } catch (Throwable $e) {
                $failed = true;
                if ($e instanceof ModbusTcpFunctionalHilException || $e instanceof ModbusTcpReadOnlyException) {
                    $transportErrors++;
                }
                $detail = [
                    'iteration' => $iteration,
                    'outcome' => 'EXCEPTION',
                    'stage' => self::exceptionStage($e),
                    'reason' => $e->getMessage(),
                ];
            }

            if ($failed) {
                $failures++;
                if ($detail !== null && count($failureDetails) < $maxFailureDetails) {
                    $failureDetails[] = $detail;
                }
                if ($policy === 'stop-on-first-fail') {
                    break;
                }
            }
        }

        if ($readScan !== null) {
            try {
                $scanEnd = self::scanValue($readScan(), $modulus);
                $scanDelta = self::scanDelta($scanStart, $scanEnd, $modulus);
            } catch (Throwable $e) {
                $failures++;
                $transportErrors++;
                if (count($failureDetails) < $maxFailureDetails) {
                    $failureDetails[] = [
                        'iteration' => $completed,
                        'outcome' => 'TRANSPORT_ERROR',
                        'stage' => 'scan_end',
                        'reason' => $e->getMessage(),
                    ];
                }
            }
        }

        if ($readMetrics !== null) {
            try {
                $metrics = self::normalizeOptionalMetrics($readMetrics());
            } catch (Throwable $e) {
                $failures++;
                if (count($failureDetails) < $maxFailureDetails) {
                    $failureDetails[] = [
                        'iteration' => $completed,
                        'outcome' => 'METRICS_ERROR',
                        'stage' => 'metrics',
                        'reason' => $e->getMessage(),
                    ];
                }
            }
        }

        $data = [
            'outcome' => $failures === 0 ? 'PASS' : 'FAIL',
            'policy' => $policy,
            'iterations_requested' => $iterations,
            'iterations_completed' => $completed,
            'failures' => $failures,
            'transport_errors' => $transportErrors,
            'cleanup_failures' => $cleanupFailures,
            'snapshot_inconsistencies' => $snapshotInconsistencies,
            'scan_start' => $scanStart,
            'scan_end' => $scanEnd,
            'scan_delta' => $scanDelta,
            'elapsed_host_time_ms' => self::elapsedMs($started),
            'failure_details' => $failureDetails,
        ];
        foreach ($metrics as $key => $value) {
            $data[$key] = $value;
        }

        return PlcArtifact::build(
            self::SCHEMA,
            'EXECUTED',
            $failures === 0 ? 'PASS' : 'FAIL',
            $data,
            $metadata
        );
    }

    /** @return array{pass:bool,transport_error:bool,cleanup_failure:bool,snapshot_inconsistent:bool,outcome:?string,stage:?string,reason:?string} */
    private static function normalizeScenarioResult(mixed $result): array
    {
        $normalized = [
            'pass' => true,
            'transport_error' => false,
            'cleanup_failure' => false,
            'snapshot_inconsistent' => false,
            'outcome' => null,
            'stage' => null,
            'reason' => null,
        ];

        if ($result === null) {
            return $normalized;
        }
        if (is_bool($result)) {
            $normalized['pass'] = $result;
            $normalized['outcome'] = $result ? 'PASS' : 'FAIL';
            return $normalized;
        }
        if (!is_array($result)) {
            throw new InvalidArgumentException('Stress scenario must return null, bool, or an array result.');
        }

        $artifact = isset($result['artifact']) && is_array($result['artifact']) ? $result['artifact'] : null;
        $status = $result['status'] ?? ($artifact['status'] ?? null);
        $outcome = $result['outcome'] ?? ($artifact['data']['outcome'] ?? null);
        $stage = $result['stage'] ?? ($artifact['data']['failure_stage'] ?? null);
        $reason = $result['reason'] ?? ($artifact['data']['failure_reason'] ?? null);

        if (array_key_exists('pass', $result)) {
            if (!is_bool($result['pass'])) {
                throw new InvalidArgumentException('Stress scenario pass flag must be boolean.');
            }
            $normalized['pass'] = $result['pass'];
        } elseif (is_string($status)) {
            $normalized['pass'] = strtoupper($status) === 'PASS';
        }

        $outcomeUpper = is_string($outcome) ? strtoupper($outcome) : null;
        $normalized['outcome'] = $outcomeUpper;
        $normalized['stage'] = is_string($stage) ? $stage : null;
        $normalized['reason'] = is_string($reason) ? $reason : null;

        $normalized['transport_error'] = ($result['transport_error'] ?? false) === true
            || $outcomeUpper === 'TRANSPORT_ERROR'
            || (is_string($normalized['stage']) && preg_match('/^(?:tcp_|modbus_|transport)/i', $normalized['stage']) === 1);

        $cleanupStatus = $artifact['data']['cleanup']['status'] ?? null;
        $normalized['cleanup_failure'] = ($result['cleanup_failure'] ?? false) === true
            || $cleanupStatus === 'FAIL';

        $normalized['snapshot_inconsistent'] = ($result['snapshot_inconsistent'] ?? false) === true
            || $outcomeUpper === 'INCONSISTENT';

        return $normalized;
    }

    /** @return array<string,int|float> */
    private static function normalizeOptionalMetrics(mixed $metrics): array
    {
        if (!is_array($metrics) || array_is_list($metrics)) {
            throw new InvalidArgumentException('readMetrics must return an object/associative array.');
        }

        $allowed = ['watchdogCount', 'overrunCount', 'applicationErrorCount'];
        $normalized = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $metrics)) {
                continue;
            }
            $value = $metrics[$key];
            if ((!is_int($value) && !is_float($value)) || $value < 0) {
                throw new InvalidArgumentException($key . ' must be a non-negative numeric metric.');
            }
            $normalized[$key] = $value;
        }
        return $normalized;
    }

    private static function scanValue(mixed $value, ?int $modulus): int
    {
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException('Stress scan counter must be a non-negative integer.');
        }
        if ($modulus !== null && $value >= $modulus) {
            throw new InvalidArgumentException('Stress scan counter must be lower than scanCounterModulus.');
        }
        return $value;
    }

    private static function scanDelta(?int $start, ?int $end, ?int $modulus): ?int
    {
        if ($start === null || $end === null) {
            return null;
        }
        if ($end >= $start) {
            return $end - $start;
        }
        if ($modulus === null) {
            throw new InvalidArgumentException('Stress scan counter regressed without explicit modulus.');
        }
        return ($modulus - $start) + $end;
    }

    private static function exceptionStage(Throwable $e): string
    {
        if ($e instanceof ModbusTcpFunctionalHilException || $e instanceof ModbusTcpReadOnlyException) {
            return $e->stage();
        }
        return 'scenario_exception';
    }

    private static function elapsedMs(int $started): int
    {
        return (int)round((hrtime(true) - $started) / 1_000_000);
    }
}
