<?php
declare(strict_types=1);

namespace Testkit\Core\Execution;

require_once __DIR__ . '/ExitCode.php';
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
    public const EXIT_PASS = ExitCode::OK;
    public const EXIT_FAIL = ExitCode::TEST_FAILURE;
    public const EXIT_INVALID_REQUEST = ExitCode::INVALID_REQUEST;
    public const EXIT_ERROR = ExitCode::OPERATIONAL_ERROR;
    public const EXIT_EVIDENCE_INCOMPLETE = ExitCode::EVIDENCE_INCOMPLETE;
    public const EXIT_POLICY_BLOCKED = ExitCode::POLICY_BLOCKED;
    public const EXIT_NO_TESTS = ExitCode::NO_TESTS;
    public const EXIT_CONTENTION = ExitCode::CONTENTION;
    public const EXIT_TIMEOUT = ExitCode::TIMEOUT;

    /**
     * Child-test protocol only. A child process may still use 2 to mean skip;
     * process-level TestKit exit code 2 means INVALID_REQUEST.
     */
    public const CHILD_EXIT_SKIP = 2;

    /**
     * @deprecated Process-level SKIP was removed by I8. Kept temporarily as an
     * internal compatibility name for NO_TESTS while callers migrate.
     */
    public const EXIT_SKIP = self::EXIT_NO_TESTS;

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
            // I8-A introduces explicit NO_TESTS for tolerated empty selections.
            // require_tests remains a policy bridge that promotes the condition to
            // TEST_FAILURE until the caller-policy cutover is completed in I8-B.
            $result['exit_code'] = (bool)$config['require_tests'] ? self::EXIT_FAIL : self::EXIT_NO_TESTS;
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
