<?php
declare(strict_types=1);

namespace Testkit\Core\Plc;

use InvalidArgumentException;
use Throwable;

final class ScanDrivenWait
{
    public const SCHEMA = 'testkit.plc-scan-wait.v1';
    public const MAX_ATTEMPTS = 100000;

    /**
     * Wait for actual scan-counter progress. No cycle duration is assumed.
     *
     * @param callable():int $readScan
     * @param callable(int,int):void|null $onPoll receives attempt number and accumulated scan delta
     * @return array<string,mixed>
     */
    public static function waitUntilScanDelta(
        callable $readScan,
        int $expectedDelta,
        int $maxAttempts = 64,
        ?int $counterModulus = null,
        ?callable $onPoll = null
    ): array {
        if ($expectedDelta < 1) {
            throw new InvalidArgumentException('expectedDelta must be >= 1.');
        }
        self::validateBudgets($maxAttempts, $counterModulus);

        $started = hrtime(true);
        try {
            $previous = self::normalizeCounter($readScan(), $counterModulus);
        } catch (Throwable $e) {
            return self::failure('TRANSPORT_ERROR', 0, 0, 0, $started, $e);
        }

        $scanStart = $previous;
        $scanDelta = 0;
        $stalledReads = 0;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($onPoll !== null) {
                try {
                    $onPoll($attempt, $scanDelta);
                } catch (Throwable $e) {
                    return self::failure('FAIL', $attempt, $scanDelta, $stalledReads, $started, $e, 'poll_callback');
                }
            }

            try {
                $current = self::normalizeCounter($readScan(), $counterModulus);
            } catch (Throwable $e) {
                return self::failure('TRANSPORT_ERROR', $attempt, $scanDelta, $stalledReads, $started, $e);
            }

            try {
                $step = self::stepDelta($previous, $current, $counterModulus);
            } catch (Throwable $e) {
                return self::failure('FAIL', $attempt, $scanDelta, $stalledReads, $started, $e, 'counter_regression');
            }

            if ($step === 0) {
                $stalledReads++;
            } else {
                $scanDelta += $step;
            }
            $previous = $current;

            if ($scanDelta >= $expectedDelta) {
                return PlcArtifact::build(self::SCHEMA, 'EXECUTED', 'PASS', [
                    'outcome' => 'PASS',
                    'mode' => 'scan_delta',
                    'attempts' => $attempt,
                    'scan_start' => $scanStart,
                    'scan_end' => $current,
                    'scan_delta' => $scanDelta,
                    'expected_delta' => $expectedDelta,
                    'stalled_reads' => $stalledReads,
                    'counter_modulus' => $counterModulus,
                    'duration_ms' => self::elapsedMs($started),
                ]);
            }
        }

        return PlcArtifact::build(self::SCHEMA, 'EXECUTED', 'FAIL', [
            'outcome' => 'TIMEOUT',
            'reason' => $scanDelta === 0 ? 'stalled_scan' : 'attempt_budget_exhausted',
            'mode' => 'scan_delta',
            'attempts' => $maxAttempts,
            'scan_start' => $scanStart,
            'scan_end' => $previous,
            'scan_delta' => $scanDelta,
            'expected_delta' => $expectedDelta,
            'stalled_reads' => $stalledReads,
            'counter_modulus' => $counterModulus,
            'duration_ms' => self::elapsedMs($started),
        ]);
    }

    /**
     * Evaluate a predicate only against observed scan positions and stop on a
     * finite scan or host-attempt budget.
     *
     * @param callable():int $readScan
     * @param callable(int,int):bool $predicate receives current scan and accumulated delta
     * @param callable(int,int):void|null $onPoll
     * @return array<string,mixed>
     */
    public static function waitUntilPredicateByScan(
        callable $readScan,
        callable $predicate,
        int $maxScans,
        int $maxAttempts = 128,
        ?int $counterModulus = null,
        ?callable $onPoll = null
    ): array {
        if ($maxScans < 1) {
            throw new InvalidArgumentException('maxScans must be >= 1.');
        }
        self::validateBudgets($maxAttempts, $counterModulus);

        $started = hrtime(true);
        try {
            $previous = self::normalizeCounter($readScan(), $counterModulus);
        } catch (Throwable $e) {
            return self::failure('TRANSPORT_ERROR', 0, 0, 0, $started, $e);
        }

        $scanStart = $previous;
        $scanDelta = 0;
        $stalledReads = 0;

        try {
            if ($predicate($previous, 0) === true) {
                return PlcArtifact::build(self::SCHEMA, 'EXECUTED', 'PASS', [
                    'outcome' => 'PASS',
                    'mode' => 'predicate',
                    'attempts' => 0,
                    'scan_start' => $scanStart,
                    'scan_end' => $previous,
                    'scan_delta' => 0,
                    'max_scans' => $maxScans,
                    'stalled_reads' => 0,
                    'counter_modulus' => $counterModulus,
                    'duration_ms' => self::elapsedMs($started),
                ]);
            }
        } catch (Throwable $e) {
            return self::failure('FAIL', 0, 0, 0, $started, $e, 'predicate');
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($onPoll !== null) {
                try {
                    $onPoll($attempt, $scanDelta);
                } catch (Throwable $e) {
                    return self::failure('FAIL', $attempt, $scanDelta, $stalledReads, $started, $e, 'poll_callback');
                }
            }

            try {
                $current = self::normalizeCounter($readScan(), $counterModulus);
            } catch (Throwable $e) {
                return self::failure('TRANSPORT_ERROR', $attempt, $scanDelta, $stalledReads, $started, $e);
            }

            try {
                $step = self::stepDelta($previous, $current, $counterModulus);
            } catch (Throwable $e) {
                return self::failure('FAIL', $attempt, $scanDelta, $stalledReads, $started, $e, 'counter_regression');
            }

            if ($step === 0) {
                $stalledReads++;
                $previous = $current;
                continue;
            }

            $scanDelta += $step;
            $previous = $current;

            if ($scanDelta > $maxScans) {
                return PlcArtifact::build(self::SCHEMA, 'EXECUTED', 'FAIL', [
                    'outcome' => 'TIMEOUT',
                    'reason' => 'scan_budget_exhausted',
                    'mode' => 'predicate',
                    'attempts' => $attempt,
                    'scan_start' => $scanStart,
                    'scan_end' => $current,
                    'scan_delta' => $scanDelta,
                    'max_scans' => $maxScans,
                    'stalled_reads' => $stalledReads,
                    'counter_modulus' => $counterModulus,
                    'duration_ms' => self::elapsedMs($started),
                ]);
            }

            try {
                if ($predicate($current, $scanDelta) === true) {
                    return PlcArtifact::build(self::SCHEMA, 'EXECUTED', 'PASS', [
                        'outcome' => 'PASS',
                        'mode' => 'predicate',
                        'attempts' => $attempt,
                        'scan_start' => $scanStart,
                        'scan_end' => $current,
                        'scan_delta' => $scanDelta,
                        'max_scans' => $maxScans,
                        'stalled_reads' => $stalledReads,
                        'counter_modulus' => $counterModulus,
                        'duration_ms' => self::elapsedMs($started),
                    ]);
                }
            } catch (Throwable $e) {
                return self::failure('FAIL', $attempt, $scanDelta, $stalledReads, $started, $e, 'predicate');
            }
        }

        return PlcArtifact::build(self::SCHEMA, 'EXECUTED', 'FAIL', [
            'outcome' => 'TIMEOUT',
            'reason' => $scanDelta === 0 ? 'stalled_scan' : 'attempt_budget_exhausted',
            'mode' => 'predicate',
            'attempts' => $maxAttempts,
            'scan_start' => $scanStart,
            'scan_end' => $previous,
            'scan_delta' => $scanDelta,
            'max_scans' => $maxScans,
            'stalled_reads' => $stalledReads,
            'counter_modulus' => $counterModulus,
            'duration_ms' => self::elapsedMs($started),
        ]);
    }

    private static function validateBudgets(int $maxAttempts, ?int $counterModulus): void
    {
        if ($maxAttempts < 1 || $maxAttempts > self::MAX_ATTEMPTS) {
            throw new InvalidArgumentException(sprintf(
                'maxAttempts must be between 1 and %d.',
                self::MAX_ATTEMPTS
            ));
        }
        if ($counterModulus !== null && $counterModulus < 2) {
            throw new InvalidArgumentException('counterModulus must be >= 2 when provided.');
        }
    }

    private static function normalizeCounter(mixed $value, ?int $modulus): int
    {
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException('Scan counter must be a non-negative integer.');
        }
        if ($modulus !== null && $value >= $modulus) {
            throw new InvalidArgumentException('Scan counter must be lower than counterModulus.');
        }
        return $value;
    }

    private static function stepDelta(int $previous, int $current, ?int $modulus): int
    {
        if ($current >= $previous) {
            return $current - $previous;
        }
        if ($modulus === null) {
            throw new InvalidArgumentException('Scan counter regressed without an explicit wrap modulus.');
        }
        return ($modulus - $previous) + $current;
    }

    /** @return array<string,mixed> */
    private static function failure(
        string $outcome,
        int $attempts,
        int $scanDelta,
        int $stalledReads,
        int $started,
        Throwable $error,
        ?string $failureStage = null
    ): array {
        if ($error instanceof ModbusTcpReadOnlyException || $error instanceof ModbusTcpFunctionalHilException) {
            $failureStage = $error->stage();
        }

        return PlcArtifact::build(self::SCHEMA, 'EXECUTED', 'FAIL', [
            'outcome' => $outcome,
            'attempts' => $attempts,
            'scan_delta' => $scanDelta,
            'stalled_reads' => $stalledReads,
            'failure_stage' => $failureStage,
            'failure_reason' => $error->getMessage(),
            'duration_ms' => self::elapsedMs($started),
        ]);
    }

    private static function elapsedMs(int $started): int
    {
        return (int)round((hrtime(true) - $started) / 1_000_000);
    }
}
