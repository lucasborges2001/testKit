<?php
declare(strict_types=1);

namespace Testkit\Core\Execution;

use Testkit\Core\Common\Env;

final class SuiteExecutor
{
    public const EXIT_PASS = 0;
    public const EXIT_FAIL = 1;
    public const EXIT_SKIP = 2;
    public const EXIT_ERROR = 3;

    /**
     * @param array<int,array<string,mixed>> $tests
     * @param array<string,mixed> $config
     * @param callable $buildCommand fn(array $test, int $workerId): array{cmd:array<int,string>, env:array<string,string>}
     * @return array<string,mixed>
     */
    public static function execute(array $tests, array $config, callable $buildCommand): array
    {
        $startedMs = self::nowMs();

        $result = [
            'suite_id' => (string)$config['suite_id'],
            'language' => (string)$config['language'],
            'scope' => (string)$config['scope'],
            'category' => (string)$config['category'],
            'tests_total' => count($tests),
            'pass' => 0,
            'fail' => 0,
            'skip' => 0,
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
            'jobs' => (int)$config['jobs'],
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

        $jobs = max(1, (int)$config['jobs']);
        $failFast = (bool)$config['fail_fast'];

        if ($jobs <= 1) {
            foreach ($tests as $test) {
                $entry = self::runSingle($test, 1, $buildCommand, $config);
                self::attach($result, $entry);

                if ($entry['status'] === 'fail' && $failFast) {
                    break;
                }
            }
        } else {
            self::runParallel($tests, $jobs, $failFast, $buildCommand, $config, $result);
        }

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
     */
    private static function runParallel(array $tests, int $jobs, bool $failFast, callable $buildCommand, array $config, array &$result): void
    {
        $queue = array_values($tests);
        $running = [];
        $freeWorkers = range(1, $jobs);
        $stopLaunch = false;

        while ($queue || $running) {
            while (!$stopLaunch && $queue && $freeWorkers) {
                /** @var array<string,mixed> $test */
                $test = array_shift($queue);
                $workerId = (int)array_shift($freeWorkers);

                $launch = $buildCommand($test, $workerId);
                $baseEnv = self::baseEnv();
                $env = array_merge($baseEnv, $launch['env'] ?? []);
                $env['TEST_WORKER_ID'] = (string)$workerId;

                $job = ProcessRunner::start($launch['cmd'], (string)($config['repo_root'] ?? Env::string('TK_REPO_ROOT', getcwd() ?: '.')), $env);
                $running[] = [
                    'test' => $test,
                    'worker' => $workerId,
                    'launch' => $launch,
                    'job' => $job,
                ];
            }

            $doneIndex = null;
            foreach ($running as $index => $row) {
                if (!ProcessRunner::isRunning($row['job'])) {
                    $doneIndex = $index;
                    break;
                }
            }

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
    private static function runSingle(array $test, int $workerId, callable $buildCommand, array $config): array
    {
        $launch = $buildCommand($test, $workerId);
        $baseEnv = self::baseEnv();
        $env = array_merge($baseEnv, $launch['env'] ?? []);
        $env['TEST_WORKER_ID'] = (string)$workerId;

        $job = ProcessRunner::start($launch['cmd'], (string)($config['repo_root'] ?? Env::string('TK_REPO_ROOT', getcwd() ?: '.')), $env);
        $finished = ProcessRunner::finish($job);

        return self::buildEntryFromJob($test, $launch, $finished, $config);
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
        $status = $exitCode === self::EXIT_PASS ? 'pass' : ($exitCode === self::EXIT_SKIP ? 'skip' : 'fail');
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
        return $result;
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
                $summary[$module] = ['pass' => 0, 'fail' => 0, 'skip' => 0, 'total' => 0];
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
