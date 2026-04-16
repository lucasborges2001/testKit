<?php
declare(strict_types=1);

namespace Testkit\Core\Execution;

use Testkit\Core\Common\Env;
use Testkit\Core\Reporting\ConsoleReporter;

final class SuiteExecutor
{
    public const EXIT_PASS = 0;
    public const EXIT_FAIL = 1;
    public const EXIT_SKIP = 2;
    public const EXIT_ERROR = 3;

    /**
     * @return array<string,int|string>
     */
    public static function progressPolicy(): array
    {
        $mode = strtolower(Env::string('TESTKIT_PROGRESS_MODE', 'heartbeat'));
        if (!in_array($mode, ['heartbeat', 'quiet'], true)) {
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
     * @param callable $buildCommand fn(array $test, int $workerId): array{cmd:array<int,string>, env:array<string,string>}
     * @return array<string,mixed>
     */
    public static function execute(array $tests, array $config, callable $buildCommand): array
    {
        $startedMs = self::nowMs();
        $jobs = max(1, (int)$config['jobs']);

        $result = [
            'suite_id' => (string)$config['suite_id'],
            'language' => (string)$config['language'],
            'scope' => (string)$config['scope'],
            'category' => (string)$config['category'],
            'tests_total' => count($tests),
            'pass' => 0,
            'fail' => 0,
            'skip' => 0,
            'timeout' => 0,
            'tests' => [],
            'failed_tests' => [],
            'slow_tests' => [],
            'perf_violations' => [],
            'exit_code' => self::EXIT_PASS,
            'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'started_ms' => $startedMs,
            'duration_ms' => 0,
            'list_only' => (bool)$config['list_only'],
            'require_tests' => (bool)$config['require_tests'],
            'jobs' => $jobs,
        ];

        if ((bool)$config['list_only']) {
            foreach ($tests as $test) {
                $result['tests'][] = self::baseEntry($test, 'listed', 0, 0, '', '', []);
            }
            $result['exit_code'] = self::EXIT_PASS;
            $result['duration_ms'] = max(0, self::nowMs() - $startedMs);
            return self::finalize($result, $config);
        }

        if (!$tests) {
            $result['exit_code'] = (bool)$config['require_tests'] ? self::EXIT_FAIL : self::EXIT_SKIP;
            $result['duration_ms'] = max(0, self::nowMs() - $startedMs);
            return self::finalize($result, $config);
        }

        $failFast = (bool)$config['fail_fast'];
        $progressState = self::createProgressState($tests, $config, $startedMs);

        self::runParallel($tests, $jobs, $failFast, $buildCommand, $config, $result, $progressState);

        $result['duration_ms'] = max(0, self::nowMs() - $startedMs);
        if ($result['fail'] > 0) {
            $result['exit_code'] = self::EXIT_FAIL;
        } elseif ($result['pass'] === 0 && $result['skip'] > 0) {
            $result['exit_code'] = self::EXIT_SKIP;
        } else {
            $result['exit_code'] = self::EXIT_PASS;
        }

        return self::finalize($result, $config);
    }

    /**
     * @param array<int,array<string,mixed>> $tests
     * @param array<string,mixed> $config
     * @param callable $buildCommand
     * @param array<string,mixed> &$result
     * @param array<string,mixed> &$progressState
     */
    private static function runParallel(
        array $tests,
        int $jobs,
        bool $failFast,
        callable $buildCommand,
        array $config,
        array &$result,
        array &$progressState
    ): void {
        $queue = array_values($tests);
        $running = [];
        $freeWorkers = range(1, $jobs);
        $stopLaunch = false;

        while ($queue || $running) {
            while (!$stopLaunch && $queue && $freeWorkers) {
                /** @var array<string,mixed> $test */
                $test = array_shift($queue);
                $workerId = (int)array_shift($freeWorkers);
                $running[] = self::startJob($test, $workerId, $buildCommand, $config);
            }

            self::emitExecutionSignals($result, $progressState, $running);

            $doneIndex = null;
            foreach ($running as $index => &$row) {
                if (!ProcessRunner::isRunning($row['job'])) {
                    $doneIndex = $index;
                    break;
                }
            }
            unset($row);

            if ($doneIndex === null) {
                usleep(20000);
                continue;
            }

            $row = $running[$doneIndex];
            array_splice($running, $doneIndex, 1);

            $finished = ProcessRunner::finish($row['job']);
            $entry = self::buildEntryFromJob($row['test'], $row['launch'], $finished, $config);
            self::attach($result, $entry);

            $freeWorkers[] = (int)$row['worker'];
            sort($freeWorkers);

            if ($entry['status'] === 'fail' && $failFast) {
                $stopLaunch = true;
            }
        }
    }

    /**
     * @param array<string,mixed> $test
     * @param array<string,mixed> $config
     * @param callable $buildCommand
     * @return array<string,mixed>
     */
    private static function startJob(array $test, int $workerId, callable $buildCommand, array $config): array
    {
        $launch = $buildCommand($test, $workerId);
        $baseEnv = self::baseEnv();
        $env = array_merge($baseEnv, $launch['env'] ?? []);
        $env['TEST_WORKER_ID'] = (string)$workerId;

        $timeoutSec = max(0, (int)($config['test_timeout_sec'] ?? 0));
        $job = ProcessRunner::start(
            $launch['cmd'],
            (string)($config['repo_root'] ?? Env::string('TK_REPO_ROOT', getcwd() ?: '.')),
            $env,
            $timeoutSec
        );

        return [
            'test' => $test,
            'worker' => $workerId,
            'launch' => $launch,
            'job' => $job,
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> &$progressState
     * @param array<int,array<string,mixed>> $running
     */
    private static function emitExecutionSignals(array $result, array &$progressState, array $running): void
    {
        if ($running === []) {
            return;
        }

        if ((string)($progressState['mode'] ?? 'heartbeat') !== 'heartbeat') {
            return;
        }

        $now = self::nowMs();

        self::emitLongRunningWarningIfNeeded($progressState, $running, $now);

        $lastEmitMs = (int)($progressState['last_progress_emit_ms'] ?? 0);
        $intervalMs = max(1, (int)($progressState['progress_interval_ms'] ?? 15000));
        if (($now - $lastEmitMs) < $intervalMs) {
            return;
        }

        ConsoleReporter::printSuiteProgress(self::buildProgressSnapshot($result, $progressState, $running, $now));
        $progressState['last_progress_emit_ms'] = $now;
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
        $completed = count($result['tests']);
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
        ];
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

    /**
     * @param array<int,array<string,mixed>> $tests
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function createProgressState(array $tests, array $config, int $startedMs): array
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
     * @param array<string,mixed> $test
     * @param array<string,mixed> $launch
     * @param array<string,mixed> $finished
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function buildEntryFromJob(array $test, array $launch, array $finished, array $config): array
    {
        $exitCode = (int)($finished['code'] ?? 127);
        $timedOut = (bool)($finished['timeout'] ?? false);
        $status = $timedOut
            ? 'timeout'
            : ($exitCode === self::EXIT_PASS ? 'pass' : ($exitCode === self::EXIT_SKIP ? 'skip' : 'fail'));
        $durationMs = (int)($finished['duration_ms'] ?? 0);

        $entry = self::baseEntry(
            $test,
            $status,
            $exitCode,
            $durationMs,
            (string)($finished['stdout'] ?? ''),
            (string)($finished['stderr'] ?? ''),
            $launch['cmd'] ?? []
        );

        if ($timedOut) {
            $entry['timeout'] = true;
            $entry['error_type'] = 'process_timeout';
            $entry['failure_phase'] = 'execution';
            $entry['failure_domain'] = 'runner';
            $entry['failure_cause_code'] = 'process_timeout';
        } elseif ($status === 'fail') {
            $entry['error_type'] = 'exit_code_' . $exitCode;
            $entry['failure_phase'] = 'execution';
            $entry['failure_domain'] = 'test';
        }

        $perfMax = (int)($config['thresholds']['perf_max_ms'] ?? 0);
        $category = (string)($config['category'] ?? 'all');
        $tags = $entry['tags'];

        if ($perfMax > 0 && $durationMs > $perfMax && ($category === 'perf' || $category === 'stress' || in_array('perf', $tags, true) || in_array('stress', $tags, true))) {
            $entry['status'] = 'fail';
            $entry['exit_code'] = self::EXIT_FAIL;
            $entry['perf_violation'] = [
                'max_ms' => $perfMax,
                'actual_ms' => $durationMs,
                'message' => 'Tiempo excede threshold de performance.',
            ];
        }

        $warnMs = (int)($config['thresholds']['perf_warn_ms'] ?? 0);
        if ($warnMs > 0 && $durationMs > $warnMs) {
            $entry['perf_warning'] = [
                'warn_ms' => $warnMs,
                'actual_ms' => $durationMs,
            ];
        }

        return $entry;
    }

    /**
     * @param array<string,mixed> $test
     * @param array<int,string> $command
     * @return array<string,mixed>
     */
    private static function baseEntry(array $test, string $status, int $exitCode, int $durationMs, string $stdout, string $stderr, array $command): array
    {
        return [
            'file' => (string)$test['file'],
            'rel' => (string)$test['rel'],
            'module' => (string)$test['module'],
            'tags' => array_values($test['tags'] ?? []),
            'status' => $status,
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'command' => ProcessRunner::joinCommand($command),
        ];
    }

    /**
     * @param array<string,mixed> &$result
     * @param array<string,mixed> $entry
     */
    private static function attach(array &$result, array $entry): void
    {
        $result['tests'][] = $entry;
        if ($entry['status'] === 'pass') {
            $result['pass']++;
        } elseif ($entry['status'] === 'skip') {
            $result['skip']++;
        } elseif ($entry['status'] === 'timeout') {
            $result['timeout']++;
            $result['fail']++;
            $result['failed_tests'][] = $entry;
        } else {
            $result['fail']++;
            $result['failed_tests'][] = $entry;
        }

        if (isset($entry['perf_violation'])) {
            $result['perf_violations'][] = $entry;
        }
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function finalize(array $result, array $config): array
    {
        $result['finished_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $result['module_summary'] = self::moduleSummary($result['tests']);
        $result['suite_status'] = self::suiteStatus($result);
        $result['no_tests_reason'] = self::noTestsReason($result);

        $tests = $result['tests'];
        usort($tests, static fn(array $a, array $b): int => ((int)$b['duration_ms']) <=> ((int)$a['duration_ms']));
        $slowTop = max(1, (int)($config['thresholds']['slow_top'] ?? 10));
        $slowThreshold = max(1, (int)($config['thresholds']['slow_ms'] ?? 1500));

        $slow = [];
        foreach ($tests as $entry) {
            if ((int)$entry['duration_ms'] >= $slowThreshold) {
                $slow[] = $entry;
            }
            if (count($slow) >= $slowTop) {
                break;
            }
        }

        $result['slow_tests'] = $slow;
        $result['progress_policy'] = self::progressPolicy();
        $result['execution_metrics'] = self::buildExecutionMetrics($result);

        return $result;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,int|null>
     */
    private static function buildExecutionMetrics(array $result): array
    {
        $selected = (int)($result['tests_total'] ?? 0);
        $completed = is_array($result['tests'] ?? null) ? count($result['tests']) : 0;
        $durationMs = isset($result['duration_ms']) ? max(0, (int)$result['duration_ms']) : null;
        $avgMs = ($durationMs !== null && $completed > 0)
            ? (int)round($durationMs / $completed)
            : null;
        $estimatedTotalMs = $avgMs !== null ? $avgMs * $selected : null;

        return [
            'selected_test_count' => $selected,
            'completed_test_count' => $completed,
            'avg_test_ms' => $avgMs,
            'estimated_total_ms' => $estimatedTotalMs,
        ];
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function suiteStatus(array $result): string
    {
        if ((bool)($result['list_only'] ?? false)) {
            return 'listed';
        }

        if ((int)($result['tests_total'] ?? 0) === 0) {
            return 'no_tests';
        }

        if ((int)($result['fail'] ?? 0) > 0) {
            return 'failed';
        }

        if ((int)($result['skip'] ?? 0) > 0 && (int)($result['pass'] ?? 0) === 0) {
            return 'skipped';
        }

        if ((int)($result['skip'] ?? 0) > 0) {
            return 'partial';
        }

        return 'passed';
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function noTestsReason(array $result): ?string
    {
        if ((int)($result['tests_total'] ?? 0) !== 0) {
            return null;
        }

        if ((bool)($result['list_only'] ?? false)) {
            return null;
        }

        return (bool)($result['require_tests'] ?? false)
            ? 'require_tests_enabled'
            : 'discovery_empty';
    }

    /**
     * @param array<int,array<string,mixed>> $tests
     * @return array<string,array<string,int>>
     */
    private static function moduleSummary(array $tests): array
    {
        $summary = [];
        foreach ($tests as $test) {
            $module = (string)($test['module'] ?? 'unknown');
            if (!isset($summary[$module])) {
                $summary[$module] = ['pass' => 0, 'fail' => 0, 'skip' => 0, 'timeout' => 0, 'total' => 0];
            }

            $summary[$module]['total']++;
            $status = (string)($test['status'] ?? 'fail');
            if (isset($summary[$module][$status])) {
                $summary[$module][$status]++;
            }
        }

        ksort($summary);
        return $summary;
    }

    /**
     * @return array<string,string>
     */
    private static function baseEnv(): array
    {
        $env = [];

        $raw = getenv();
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                if (!is_string($k) || $k === '' || !is_scalar($v)) {
                    continue;
                }
                $env[$k] = (string)$v;
            }
        }

        foreach (array_merge($_SERVER, $_ENV) as $k => $v) {
            if (!is_string($k) || $k === '' || !is_scalar($v)) {
                continue;
            }
            if (!array_key_exists($k, $env)) {
                $env[$k] = (string)$v;
            }
        }

        return $env;
    }

    private static function nowMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }
}
