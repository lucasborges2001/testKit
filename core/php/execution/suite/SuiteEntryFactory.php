<?php
declare(strict_types=1);

namespace Testkit\Core\Execution\Suite;

use Testkit\Core\Execution\ProcessRunner;
use Testkit\Core\Execution\SuiteExecutor;

final class SuiteEntryFactory
{
    /**
     * @param array<string,mixed> $test
     * @param array<string,mixed> $launch
     * @param array<string,mixed> $finished
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function fromJob(array $test, array $launch, array $finished, array $config): array
    {
        $exitCode = (int)($finished['code'] ?? 127);
        $timedOut = (bool)($finished['timeout'] ?? false);
        $status = $timedOut
            ? 'timeout'
            : ($exitCode === SuiteExecutor::EXIT_PASS ? 'pass' : ($exitCode === SuiteExecutor::EXIT_SKIP ? 'skip' : 'fail'));
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
            $entry['exit_code'] = SuiteExecutor::EXIT_FAIL;
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
    public static function baseEntry(array $test, string $status, int $exitCode, int $durationMs, string $stdout, string $stderr, array $command): array
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
}
