<?php
declare(strict_types=1);

namespace Testkit\Core\Execution\Suite;

use Testkit\Core\Common\Env;
use Testkit\Core\Execution\ProcessRunner;

final class SuiteWorkerPool
{
    /**
     * @param array<int,array<string,mixed>> $tests
     * @param callable $buildCommand
     * @param array<string,mixed> $config
     * @param array<string,mixed> &$result
     * @param array<string,mixed> &$progressState
     */
    public static function run(
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

            SuiteProgressEmitter::emitExecutionSignals($result, $progressState, $running);

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
            $entry = SuiteEntryFactory::fromJob($row['test'], $row['launch'], $finished, $config);
            SuiteExecutionResult::attach($result, $entry);
            SuiteProgressEmitter::emitPerTestProgressIfNeeded($result, $progressState, $row, $entry, $running);

            $freeWorkers[] = (int)$row['worker'];
            sort($freeWorkers);

            if ($entry['status'] === 'fail' && $failFast) {
                $stopLaunch = true;
            }
        }
    }

    /**
     * @param array<string,mixed> $test
     * @param callable $buildCommand
     * @param array<string,mixed> $config
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
}
