<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Throwable;

final class FailureNormalizer
{
    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    public static function buildFailureEntry(array $entry): array
    {
        $stdout = (string)($entry['stdout'] ?? '');
        $stderr = (string)($entry['stderr'] ?? '');

        $message = FailureExcerpt::extractFirstMessage($stderr) ?? FailureExcerpt::extractFirstMessage($stdout);
        $traceExcerpt = FailureExcerpt::extractTrace($stderr !== '' ? $stderr : $stdout, 10);
        $stdoutExcerpt = FailureExcerpt::textExcerpt($stdout, 15);
        $stderrExcerpt = FailureExcerpt::textExcerpt($stderr, 15);
        $testName = self::inferTestName($entry);

        $tags = array_values((array)($entry['tags'] ?? []));
        $scopeTokens = array_values(array_filter($tags, fn(string $tag): bool => in_array($tag, ['unit', 'integration', 'e2e'], true)));
        $categoryTokens = array_values(array_filter($tags, fn(string $tag): bool => !in_array($tag, ['unit', 'integration', 'e2e'], true)));

        return [
            'test_id' => (string)($entry['rel'] ?? $entry['file'] ?? ''),
            'test_name' => $testName,
            'case' => $testName,
            'suite_id' => (string)($entry['suite_id'] ?? $entry['suite'] ?? $entry['module'] ?? ''),
            'suite' => (string)($entry['module'] ?? $entry['suite'] ?? ''),
            'scope' => implode(',', $scopeTokens),
            'file' => (string)($entry['rel'] ?? $entry['file'] ?? ''),
            'line' => null,
            'category' => implode(',', $categoryTokens),
            'status' => (string)($entry['status'] ?? 'fail'),
            'duration_ms' => (int)($entry['duration_ms'] ?? 0),
            'error_type' => self::inferErrorType($entry),
            'exception_class' => null,
            'kind' => self::entryKind($entry),
            'phase' => (string)($entry['failure_phase'] ?? self::entryPhase($entry)),
            'failure_domain' => (string)($entry['failure_domain'] ?? self::entryDomain($entry)),
            'cause_code' => (string)($entry['failure_cause_code'] ?? self::entryCauseCode($entry)),
            'message' => $message,
            'assertion' => null,
            'diff_excerpt' => null,
            'trace_excerpt' => $traceExcerpt,
            'stdout_excerpt' => $stdoutExcerpt,
            'stderr_excerpt' => $stderrExcerpt,
            'artifact_path' => null,
        ];
    }

    /**
     * @param Throwable $error
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function buildThrowableFailure(Throwable $error, array $context = []): array
    {
        $suiteId = trim((string)($context['suite_id'] ?? $context['suite'] ?? 'suite'));
        $testName = trim((string)($context['test_name'] ?? $context['case'] ?? ($suiteId . '.bootstrap')));
        $traceLines = preg_split('/\R/', trim($error->getTraceAsString())) ?: [];
        $traceLines = array_values(array_filter(array_map('trim', $traceLines), static fn(string $line): bool => $line !== ''));
        $traceExcerpt = $traceLines === [] ? null : implode("\n", array_slice($traceLines, 0, 10));

        return [
            'test_id' => (string)($context['test_id'] ?? $testName),
            'test_name' => $testName,
            'case' => (string)($context['case'] ?? $testName),
            'suite_id' => $suiteId,
            'suite' => (string)($context['suite'] ?? $suiteId),
            'scope' => (string)($context['scope'] ?? ''),
            'file' => (string)($context['file'] ?? ''),
            'line' => $error->getLine() > 0 ? $error->getLine() : null,
            'category' => (string)($context['category'] ?? ''),
            'status' => 'fail',
            'duration_ms' => (int)($context['duration_ms'] ?? 0),
            'error_type' => (string)($context['error_type'] ?? self::throwableClass($error)),
            'exception_class' => self::throwableClass($error),
            'kind' => (string)($context['kind'] ?? 'setup_failure'),
            'phase' => (string)($context['phase'] ?? self::phaseFromKind((string)($context['kind'] ?? 'setup_failure'))),
            'failure_domain' => (string)($context['failure_domain'] ?? self::domainFromKind((string)($context['kind'] ?? 'setup_failure'))),
            'cause_code' => (string)($context['cause_code'] ?? self::causeCodeFromKind((string)($context['kind'] ?? 'setup_failure'))),
            'message' => trim($error->getMessage()) !== '' ? trim($error->getMessage()) : self::throwableClass($error),
            'assertion' => null,
            'diff_excerpt' => null,
            'trace_excerpt' => $traceExcerpt,
            'stdout_excerpt' => null,
            'stderr_excerpt' => trim($error->getMessage()) !== '' ? trim($error->getMessage()) : null,
            'artifact_path' => $context['artifact_path'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @return array<int,array<string,mixed>>
     */
    public static function canonicalFailures(array $report): array
    {
        $failures = $report['failures'] ?? null;
        if (is_array($failures) && $failures !== []) {
            return array_values(array_filter($failures, 'is_array'));
        }

        $legacy = $report['failed_tests'] ?? [];
        if (!is_array($legacy) || $legacy === []) {
            return [];
        }

        return array_values(array_map(
            static fn(array $entry): array => self::buildFailureEntry($entry),
            array_values(array_filter($legacy, 'is_array'))
        ));
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>|null
     */
    public static function firstFailure(array $report): ?array
    {
        $failures = self::canonicalFailures($report);
        if ($failures === []) {
            return null;
        }

        return self::summarizeFailure($failures[0]);
    }

    /**
     * @param array<string,mixed> $failure
     * @return array<string,mixed>
     */
    public static function summarizeFailure(array $failure): array
    {
        $stack = FailureExcerpt::traceToLines((string)($failure['trace_excerpt'] ?? ''), 5);
        $kind = trim((string)($failure['kind'] ?? ''));
        if ($kind === '') {
            $kind = self::inferFailureKind($failure);
        }

        $exceptionClass = trim((string)($failure['exception_class'] ?? ''));
        if ($exceptionClass === '') {
            $exceptionClass = trim((string)($failure['error_type'] ?? ''));
        }

        $artifactPath = $failure['artifact_path'] ?? null;
        if (is_string($artifactPath) && $artifactPath !== '') {
            $artifactPath = str_replace('\\', '/', $artifactPath);
        } elseif (!is_string($artifactPath)) {
            $artifactPath = null;
        }

        return [
            'file' => (string)($failure['file'] ?? $failure['test_id'] ?? ''),
            'suite_id' => (string)($failure['suite_id'] ?? $failure['suite'] ?? ''),
            'case' => (string)($failure['case'] ?? $failure['test_name'] ?? ''),
            'kind' => $kind,
            'phase' => (string)($failure['phase'] ?? self::phaseFromKind($kind)),
            'failure_domain' => (string)($failure['failure_domain'] ?? self::domainFromKind($kind)),
            'cause_code' => (string)($failure['cause_code'] ?? self::causeCodeFromKind($kind)),
            'status' => (string)($failure['status'] ?? 'fail'),
            'exception_class' => $exceptionClass !== '' ? $exceptionClass : null,
            'message' => (string)($failure['message'] ?? ''),
            'stack_excerpt' => $stack,
            'artifact_path' => $artifactPath,
        ];
    }

    public static function phaseFromKind(string $kind): string
    {
        return match (strtolower(trim($kind))) {
            'timeout', 'test_failure' => 'execution',
            'environment_conflict' => 'store_setup',
            'discovery_failure' => 'discovery',
            'bootstrap_failure' => 'bootstrap',
            'reporting_failure' => 'reporting',
            default => 'bootstrap',
        };
    }

    public static function domainFromKind(string $kind): string
    {
        return match (strtolower(trim($kind))) {
            'timeout' => 'runner',
            'test_failure' => 'test',
            'environment_conflict' => 'store',
            'discovery_failure' => 'discovery',
            'bootstrap_failure' => 'bootstrap',
            'reporting_failure' => 'reporting',
            default => 'infra',
        };
    }

    public static function causeCodeFromKind(string $kind): string
    {
        return match (strtolower(trim($kind))) {
            'timeout' => 'process_timeout',
            'environment_conflict' => 'shared_store_locked',
            'discovery_failure' => 'discovery_failed',
            'bootstrap_failure' => 'bootstrap_failed',
            'reporting_failure' => 'report_write_failed',
            default => 'runner_exception',
        };
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function inferTestName(array $entry): string
    {
        $base = basename((string)($entry['rel'] ?? $entry['file'] ?? ''));
        $base = preg_replace('/\.test\.(php|mjs|js|ts|py)$/i', '', $base) ?? $base;
        return $base;
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function inferErrorType(array $entry): string
    {
        $errorType = trim((string)($entry['error_type'] ?? ''));
        if ($errorType !== '') {
            return $errorType;
        }

        return 'exit_code_' . (int)($entry['exit_code'] ?? 1);
    }

    /**
     * @param array<string,mixed> $failure
     */
    private static function inferFailureKind(array $failure): string
    {
        $status = strtolower(trim((string)($failure['status'] ?? '')));
        $errorType = strtolower(trim((string)($failure['error_type'] ?? '')));
        $file = trim((string)($failure['file'] ?? ''));

        if ($status === 'timeout' || $errorType === 'process_timeout') {
            return 'timeout';
        }

        if ($file === '' || $file === 'migration_contract' || $errorType === 'runtime_exception' || $errorType === 'error') {
            return 'setup_failure';
        }

        if ($errorType === 'environment_conflict' || $errorType === 'shared_store_locked') {
            return 'environment_conflict';
        }

        return 'test_failure';
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function entryKind(array $entry): string
    {
        $status = strtolower(trim((string)($entry['status'] ?? 'fail')));
        if ($status === 'timeout' || (bool)($entry['timeout'] ?? false)) {
            return 'timeout';
        }

        return 'test_failure';
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function entryPhase(array $entry): string
    {
        if ((bool)($entry['timeout'] ?? false)) {
            return 'execution';
        }

        return 'execution';
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function entryDomain(array $entry): string
    {
        if ((bool)($entry['timeout'] ?? false)) {
            return 'runner';
        }

        return 'test';
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function entryCauseCode(array $entry): string
    {
        if ((bool)($entry['timeout'] ?? false)) {
            return 'process_timeout';
        }

        return self::inferErrorType($entry);
    }

    private static function throwableClass(Throwable $error): string
    {
        return ltrim(get_class($error), '\\');
    }
}
