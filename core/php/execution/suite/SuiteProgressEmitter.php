<?php
declare(strict_types=1);

namespace Testkit\Core\Execution\Suite;

use Testkit\Core\Common\Env;
use Testkit\Core\Reporting\ConsoleReporter;

final class SuiteProgressEmitter
{
    /**
     * @return array<string,int|string>
     */
    public static function progressPolicy(): array
    {
        $mode = strtolower(Env::string('TESTKIT_PROGRESS_MODE', 'heartbeat'));
        if (!in_array($mode, ['heartbeat', 'quiet', 'per_test'], true)) {
            $mode = 'heartbeat';
        }

        return [
            'mode' => $mode,
            'interval_sec' => max(1, Env::int('TESTKIT_PROGRESS_INTERVAL_SEC', 15)),
            'long_test_warn_sec' => max(1, Env::int('TESTKIT_LONG_TEST_WARN_SEC', 60)),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $tests
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function createState(array $tests, array $config, int $startedMs): array
    {
        $policy = self::progressPolicy();

        return [
            'suite_started_ms' => $startedMs,
            'total' => count($tests),
            'jobs' => max(1, (int)($config['jobs'] ?? 1)),
            'mode' => (string)$policy['mode'],
            'progress_interval_ms' => (int)$policy['interval_sec'] * 1000,
            'long_running_warn_sec' => (int)$policy['long_test_warn_sec'],
            'last_progress_emit_ms' => $startedMs,
            'long_running_warning_state' => [],
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> &$progressState
     * @param array<int,array<string,mixed>> $running
     */
    public static function emitExecutionSignals(array $result, array &$progressState, array $running): void
    {
        if ($running === []) {
            return;
        }

        $mode = (string)($progressState['mode'] ?? 'heartbeat');
        if ($mode === 'quiet') {
            return;
        }

        $now = self::nowMs();
        self::emitLongRunningWarningIfNeeded($progressState, $running, $now);

        if ($mode !== 'heartbeat') {
            return;
        }

        $lastEmitMs = (int)($progressState['last_progress_emit_ms'] ?? 0);
        $intervalMs = max(1, (int)($progressState['progress_interval_ms'] ?? 15000));
        if (($now - $lastEmitMs) < $intervalMs) {
            return;
        }

        ConsoleReporter::printSuiteProgress(self::buildProgressSnapshot($result, $progressState, $running, $now));
        $progressState['last_progress_emit_ms'] = $now;
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $progressState
     * @param array<string,mixed> $row
     * @param array<string,mixed> $entry
     * @param array<int,array<string,mixed>> $running
     */
    public static function emitPerTestProgressIfNeeded(
        array $result,
        array $progressState,
        array $row,
        array $entry,
        array $running
    ): void {
        if ((string)($progressState['mode'] ?? 'heartbeat') !== 'per_test') {
            return;
        }

        $now = self::nowMs();
        ConsoleReporter::printPerTestProgress(self::buildCompletedTestSnapshot($result, $progressState, $row, $entry, $running, $now));
    }

    /**
     * @param array<string,mixed> &$progressState
     * @param array<int,array<string,mixed>> $running
     */
    private static function emitLongRunningWarningIfNeeded(array &$progressState, array $running, int $nowMs): void
    {
        $current = self::selectCurrentRunning($running);
        if ($current === null) {
            return;
        }

        $warnSec = max(1, (int)($progressState['long_running_warn_sec'] ?? 60));
        $elapsedMs = max(0, $nowMs - (int)($current['job']['start_ms'] ?? $nowMs));
        $bucketSec = self::longRunningBucketSec($elapsedMs, $warnSec);
        if ($bucketSec === null) {
            return;
        }

        $key = self::runningKey($current);
        $state = is_array($progressState['long_running_warning_state'] ?? null)
            ? $progressState['long_running_warning_state']
            : [];
        $lastBucketSec = (int)($state[$key] ?? 0);
        if ($bucketSec <= $lastBucketSec) {
            return;
        }

        ConsoleReporter::printLongRunningTest([
            'elapsed_ms' => $elapsedMs,
            'rel' => (string)($current['test']['rel'] ?? ''),
            'worker' => (int)($current['worker'] ?? 0),
        ]);

        $state[$key] = $bucketSec;
        $progressState['long_running_warning_state'] = $state;
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $progressState
     * @param array<int,array<string,mixed>> $running
     * @return array<string,mixed>
     */
    private static function buildProgressSnapshot(array $result, array $progressState, array $running, int $nowMs): array
    {
        $completed = SuiteExecutionResult::completedTestCount(is_array($result['tests'] ?? null) ? $result['tests'] : []);
        $total = (int)($progressState['total'] ?? ($result['tests_total'] ?? 0));
        $elapsedMs = max(0, $nowMs - (int)($progressState['suite_started_ms'] ?? $nowMs));
        $avgMs = $completed > 0 ? (int)round($elapsedMs / $completed) : null;
        $remaining = max(0, $total - $completed);
        $etaMs = $avgMs !== null ? $avgMs * $remaining : null;

        $current = self::selectCurrentRunning($running);
        $currentRel = $current !== null ? trim((string)($current['test']['rel'] ?? '')) : '';
        $currentElapsedMs = $currentRel !== ''
            ? max(0, $nowMs - (int)($current['job']['start_ms'] ?? $nowMs))
            : null;

        return [
            'elapsed_ms' => $elapsedMs,
            'completed' => $completed,
            'total' => $total,
            'pass' => (int)($result['pass'] ?? 0),
            'fail' => (int)($result['fail'] ?? 0),
            'skip' => (int)($result['skip'] ?? 0),
            'timeout' => (int)($result['timeout'] ?? 0),
            'current_test_rel' => $currentRel,
            'current_elapsed_ms' => $currentElapsedMs,
            'avg_ms_per_test' => $avgMs,
            'eta_ms' => $etaMs,
            'jobs' => (int)($progressState['jobs'] ?? ($result['jobs'] ?? 1)),
            'workers' => self::buildWorkerSnapshots($running, $nowMs),
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $progressState
     * @param array<string,mixed> $row
     * @param array<string,mixed> $entry
     * @param array<int,array<string,mixed>> $running
     * @return array<string,mixed>
     */
    private static function buildCompletedTestSnapshot(
        array $result,
        array $progressState,
        array $row,
        array $entry,
        array $running,
        int $nowMs
    ): array {
        return [
            'status' => (string)($entry['status'] ?? 'fail'),
            'worker' => (int)($row['worker'] ?? 0),
            'rel' => (string)($entry['rel'] ?? ($row['test']['rel'] ?? '')),
            'duration_ms' => (int)($entry['duration_ms'] ?? 0),
            'elapsed_ms' => max(0, $nowMs - (int)($progressState['suite_started_ms'] ?? $nowMs)),
            'completed' => SuiteExecutionResult::completedTestCount(is_array($result['tests'] ?? null) ? $result['tests'] : []),
            'total' => (int)($progressState['total'] ?? ($result['tests_total'] ?? 0)),
            'pass' => (int)($result['pass'] ?? 0),
            'fail' => (int)($result['fail'] ?? 0),
            'skip' => (int)($result['skip'] ?? 0),
            'timeout' => (int)($result['timeout'] ?? 0),
            'jobs' => (int)($progressState['jobs'] ?? ($result['jobs'] ?? 1)),
            'workers' => self::buildWorkerSnapshots($running, $nowMs),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $running
     * @return array<int,array<string,mixed>>
     */
    private static function buildWorkerSnapshots(array $running, int $nowMs): array
    {
        $workers = [];
        foreach ($running as $row) {
            if (!is_array($row)) {
                continue;
            }

            $workers[] = [
                'worker' => (int)($row['worker'] ?? 0),
                'rel' => (string)($row['test']['rel'] ?? ''),
                'elapsed_ms' => max(0, $nowMs - (int)($row['job']['start_ms'] ?? $nowMs)),
            ];
        }

        usort(
            $workers,
            static fn(array $left, array $right): int => ((int)($left['worker'] ?? 0)) <=> ((int)($right['worker'] ?? 0))
        );

        return $workers;
    }

    /**
     * @param array<int,array<string,mixed>> $running
     * @return array<string,mixed>|null
     */
    private static function selectCurrentRunning(array $running): ?array
    {
        $current = null;

        foreach ($running as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($current === null) {
                $current = $row;
                continue;
            }

            $rowStartMs = (int)($row['job']['start_ms'] ?? PHP_INT_MAX);
            $currentStartMs = (int)($current['job']['start_ms'] ?? PHP_INT_MAX);
            if ($rowStartMs < $currentStartMs) {
                $current = $row;
                continue;
            }

            if ($rowStartMs > $currentStartMs) {
                continue;
            }

            $rowWorker = (int)($row['worker'] ?? PHP_INT_MAX);
            $currentWorker = (int)($current['worker'] ?? PHP_INT_MAX);
            if ($rowWorker < $currentWorker) {
                $current = $row;
                continue;
            }

            if ($rowWorker > $currentWorker) {
                continue;
            }

            $rowRel = (string)($row['test']['rel'] ?? '');
            $currentRel = (string)($current['test']['rel'] ?? '');
            if ($rowRel !== '' && ($currentRel === '' || strcmp($rowRel, $currentRel) < 0)) {
                $current = $row;
            }
        }

        return $current;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function runningKey(array $row): string
    {
        return (string)($row['test']['rel'] ?? '') . '#' . (int)($row['worker'] ?? 0);
    }

    private static function longRunningBucketSec(int $elapsedMs, int $warnSec): ?int
    {
        if ($warnSec <= 0) {
            return null;
        }

        $elapsedSec = (int)floor(max(0, $elapsedMs) / 1000);
        if ($elapsedSec < $warnSec) {
            return null;
        }

        $bucketSec = 0;
        $multiplier = 1;

        while (true) {
            foreach ([1, 2, 5] as $scale) {
                $candidate = $warnSec * $scale * $multiplier;
                if ($candidate <= $elapsedSec) {
                    $bucketSec = $candidate;
                    continue;
                }

                return $bucketSec > 0 ? $bucketSec : null;
            }

            $multiplier *= 10;
        }
    }

    private static function nowMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }
}
