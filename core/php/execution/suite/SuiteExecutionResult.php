<?php
declare(strict_types=1);

namespace Testkit\Core\Execution\Suite;

use Testkit\Core\Execution\SuiteExecutor;

final class SuiteExecutionResult
{
    /**
     * @param array<int,array<string,mixed>> $tests
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function create(array $tests, array $config, int $jobs, int $startedMs): array
    {
        return [
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
            'exit_code' => SuiteExecutor::EXIT_PASS,
            'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'started_ms' => $startedMs,
            'duration_ms' => 0,
            'list_only' => (bool)$config['list_only'],
            'require_tests' => (bool)$config['require_tests'],
            'jobs' => $jobs,
        ];
    }

    /**
     * @param array<string,mixed> &$result
     * @param array<string,mixed> $entry
     */
    public static function attach(array &$result, array $entry): void
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
     */
    public static function resolveExitCode(array $result): int
    {
        if ($result['fail'] > 0) {
            return SuiteExecutor::EXIT_FAIL;
        }

        if ($result['pass'] === 0 && $result['skip'] > 0) {
            return SuiteExecutor::EXIT_SKIP;
        }

        return SuiteExecutor::EXIT_PASS;
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function finalize(array $result, array $config): array
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
        $result['progress_policy'] = SuiteProgressEmitter::progressPolicy();
        $result['execution_metrics'] = self::executionMetricsSnapshot($result);

        return $result;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,int|null>
     */
    public static function executionMetricsSnapshot(array $result): array
    {
        $selected = (int)($result['tests_total'] ?? 0);
        $completed = self::completedTestCount(is_array($result['tests'] ?? null) ? $result['tests'] : []);
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
     * @param array<int,array<string,mixed>> $tests
     */
    public static function completedTestCount(array $tests): int
    {
        $count = 0;
        foreach ($tests as $test) {
            if (!is_array($test)) {
                continue;
            }

            $status = (string)($test['status'] ?? '');
            if (in_array($status, ['pass', 'fail', 'skip', 'timeout'], true)) {
                $count++;
            }
        }

        return $count;
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
}
