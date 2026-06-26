<?php
declare(strict_types=1);

namespace Testkit\Core\Execution;

require_once __DIR__ . '/suite/SuiteExecutionResult.php';
require_once __DIR__ . '/suite/SuiteEntryFactory.php';
require_once __DIR__ . '/suite/SuiteProgressEmitter.php';
require_once __DIR__ . '/suite/SuiteWorkerPool.php';

use Testkit\Core\Execution\Suite\SuiteExecutionResult;
use Testkit\Core\Execution\Suite\SuiteEntryFactory;
use Testkit\Core\Execution\Suite\SuiteProgressEmitter;
use Testkit\Core\Execution\Suite\SuiteWorkerPool;

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
        return SuiteProgressEmitter::progressPolicy();
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,int|null>
     */
    public static function executionMetricsSnapshot(array $result): array
    {
        return SuiteExecutionResult::executionMetricsSnapshot($result);
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
        $result = SuiteExecutionResult::create($tests, $config, $jobs, $startedMs);

        if ((bool)$config['list_only']) {
            foreach ($tests as $test) {
                $result['tests'][] = SuiteEntryFactory::baseEntry($test, 'listed', 0, 0, '', '', []);
            }
            $result['exit_code'] = self::EXIT_PASS;
            $result['duration_ms'] = max(0, self::nowMs() - $startedMs);
            return SuiteExecutionResult::finalize($result, $config);
        }

        if (!$tests) {
            $result['exit_code'] = (bool)$config['require_tests'] ? self::EXIT_FAIL : self::EXIT_SKIP;
            $result['duration_ms'] = max(0, self::nowMs() - $startedMs);
            return SuiteExecutionResult::finalize($result, $config);
        }

        $progressState = SuiteProgressEmitter::createState($tests, $config, $startedMs);
        SuiteWorkerPool::run(
            $tests,
            $jobs,
            (bool)$config['fail_fast'],
            $buildCommand,
            $config,
            $result,
            $progressState
        );

        $result['duration_ms'] = max(0, self::nowMs() - $startedMs);
        $result['exit_code'] = SuiteExecutionResult::resolveExitCode($result);

        return SuiteExecutionResult::finalize($result, $config);
    }

    private static function nowMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }
}
