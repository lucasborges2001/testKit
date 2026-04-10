<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Throwable;
use Testkit\Core\Common\Paths;

final class ReportSummary
{
    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    public static function buildFailureEntry(array $entry): array
    {
        $stdout = (string)($entry['stdout'] ?? '');
        $stderr = (string)($entry['stderr'] ?? '');

        $message       = self::extractFirstMessage($stderr) ?? self::extractFirstMessage($stdout);
        $traceExcerpt  = self::extractTrace($stderr !== '' ? $stderr : $stdout, 10);
        $stdoutExcerpt = self::textExcerpt($stdout, 15);
        $stderrExcerpt = self::textExcerpt($stderr, 15);
        $testName      = self::inferTestName($entry);

        $tags        = array_values((array)($entry['tags'] ?? []));
        $scopeTokens = array_values(array_filter($tags, fn(string $t): bool => in_array($t, ['unit', 'integration', 'e2e'], true)));
        $catTokens   = array_values(array_filter($tags, fn(string $t): bool => !in_array($t, ['unit', 'integration', 'e2e'], true)));

        return [
            'test_id'        => (string)($entry['rel'] ?? $entry['file'] ?? ''),
            'test_name'      => $testName,
            'case'           => $testName,
            'suite_id'       => (string)($entry['suite_id'] ?? $entry['suite'] ?? $entry['module'] ?? ''),
            'suite'          => (string)($entry['module'] ?? $entry['suite'] ?? ''),
            'scope'          => implode(',', $scopeTokens),
            'file'           => (string)($entry['rel'] ?? $entry['file'] ?? ''),
            'line'           => null,
            'category'       => implode(',', $catTokens),
            'status'         => (string)($entry['status'] ?? 'fail'),
            'duration_ms'    => (int)($entry['duration_ms'] ?? 0),
            'error_type'     => self::inferErrorType($entry),
            'exception_class'=> null,
            'kind'           => 'test_failure',
            'message'        => $message,
            'assertion'      => null,
            'diff_excerpt'   => null,
            'trace_excerpt'  => $traceExcerpt,
            'stdout_excerpt' => $stdoutExcerpt,
            'stderr_excerpt' => $stderrExcerpt,
            'artifact_path'  => null,
        ];
    }

    /**
     * @param Throwable $e
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function buildThrowableFailure(Throwable $e, array $context = []): array
    {
        $suiteId = trim((string)($context['suite_id'] ?? $context['suite'] ?? 'suite'));
        $testName = trim((string)($context['test_name'] ?? $context['case'] ?? ($suiteId . '.bootstrap')));
        $traceLines = preg_split('/\R/', trim($e->getTraceAsString())) ?: [];
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
            'line' => $e->getLine() > 0 ? $e->getLine() : null,
            'category' => (string)($context['category'] ?? ''),
            'status' => 'fail',
            'duration_ms' => (int)($context['duration_ms'] ?? 0),
            'error_type' => (string)($context['error_type'] ?? self::throwableClass($e)),
            'exception_class' => self::throwableClass($e),
            'kind' => (string)($context['kind'] ?? 'setup_failure'),
            'message' => trim($e->getMessage()) !== '' ? trim($e->getMessage()) : self::throwableClass($e),
            'assertion' => null,
            'diff_excerpt' => null,
            'trace_excerpt' => $traceExcerpt,
            'stdout_excerpt' => null,
            'stderr_excerpt' => trim($e->getMessage()) !== '' ? trim($e->getMessage()) : null,
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
        $stack = self::traceToLines((string)($failure['trace_excerpt'] ?? ''), 5);
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
            'case' => (string)($failure['case'] ?? $failure['test_name'] ?? ''),
            'kind' => $kind,
            'exception_class' => $exceptionClass !== '' ? $exceptionClass : null,
            'message' => (string)($failure['message'] ?? ''),
            'stack_excerpt' => $stack,
            'artifact_path' => $artifactPath,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $failures
     * @return array<string,mixed>
     */
    public static function groupFailures(array $failures): array
    {
        $byFile      = [];
        $byErrorType = [];
        $byMessage   = [];

        foreach ($failures as $f) {
            $testId    = (string)($f['test_id'] ?? $f['file'] ?? 'unknown');
            $file      = (string)($f['file'] ?? 'unknown');
            $errorType = (string)($f['error_type'] ?? 'unknown');
            $msg       = (string)($f['message'] ?? '');

            $byFile[$file][]           = $testId;
            $byErrorType[$errorType][] = $testId;

            if ($msg !== '') {
                $norm = substr((string)preg_replace('/\s+/', ' ', $msg), 0, 160);
                $byMessage[$norm][] = $testId;
            }
        }

        ksort($byFile);
        ksort($byErrorType);
        ksort($byMessage);

        return [
            'by_file'       => $byFile,
            'by_error_type' => $byErrorType,
            'by_message'    => $byMessage,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $failures
     * @return array<int,string>
     */
    public static function failedFiles(array $failures): array
    {
        $files = [];
        foreach ($failures as $failure) {
            $file = trim((string)($failure['file'] ?? ''));
            if ($file !== '') {
                $files[$file] = true;
            }
        }
        $out = array_keys($files);
        sort($out);
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $failures
     * @return array<int,array<string,mixed>>
     */
    public static function topFailureMessages(array $failures, int $limit = 5): array
    {
        $agg = [];
        foreach ($failures as $failure) {
            $message = trim((string)($failure['message'] ?? ''));
            if ($message === '') {
                continue;
            }

            $key = substr((string)preg_replace('/\s+/', ' ', $message), 0, 200);
            if (!isset($agg[$key])) {
                $agg[$key] = [
                    'message'   => $key,
                    'count'     => 0,
                    'files'     => [],
                    'suite_ids' => [],
                ];
            }

            $agg[$key]['count']++;

            $file = trim((string)($failure['file'] ?? ''));
            if ($file !== '') {
                $agg[$key]['files'][$file] = true;
            }

            $suite = trim((string)($failure['suite_id'] ?? $failure['suite'] ?? ''));
            if ($suite !== '') {
                $agg[$key]['suite_ids'][$suite] = true;
            }
        }

        $rows = array_values(array_map(
            static function (array $row): array {
                $row['files'] = array_values(array_keys($row['files']));
                sort($row['files']);
                $row['suite_ids'] = array_values(array_keys($row['suite_ids']));
                sort($row['suite_ids']);
                return $row;
            },
            $agg
        ));

        usort($rows, static function (array $a, array $b): int {
            $countCmp = ((int)$b['count']) <=> ((int)$a['count']);
            if ($countCmp !== 0) {
                return $countCmp;
            }
            return strcmp((string)$a['message'], (string)$b['message']);
        });

        return array_slice($rows, 0, max(0, $limit));
    }

    /**
     * @param array<int,array<string,mixed>> $suiteRows
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<string,mixed>
     */
    public static function buildMetaReport(
        string $target,
        string $category,
        array $suiteRows,
        array $suiteReports,
        string $reportRoot,
        int $durationMs,
        string $startedAt
    ): array {
        $summary = [
            'total'       => 0,
            'passed'      => 0,
            'failed'      => 0,
            'skipped'     => 0,
            'duration_ms' => $durationMs,
        ];

        $selectedTestCount = 0;
        $reportScopeValues = [];
        $moduleScopeValues = [];
        $canonicalFailures = [];
        $suiteStatusCounts = [];
        $evidenceValid = true;
        $evidenceInvalidReason = null;

        foreach ($suiteReports as $report) {
            $reportSummary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
            $summary['total'] += (int)($report['tests_total'] ?? $reportSummary['total'] ?? 0);
            $summary['passed'] += (int)($report['pass'] ?? $reportSummary['passed'] ?? 0);
            $summary['failed'] += (int)($report['fail'] ?? $reportSummary['failed'] ?? 0);
            $summary['skipped'] += (int)($report['skip'] ?? $reportSummary['skipped'] ?? 0);
            $selectedTestCount += (int)($report['selected_test_count'] ?? $report['tests_total'] ?? $reportSummary['total'] ?? 0);

            $scopeRel = trim((string)($report['report_scope_rel'] ?? ''));
            if ($scopeRel !== '') {
                $reportScopeValues[$scopeRel] = true;
            }

            $moduleScope = trim((string)($report['selected_module_scope'] ?? ''));
            if ($moduleScope !== '') {
                $moduleScopeValues[$moduleScope] = true;
            }

            $suiteStatus = (string)($report['suite_status'] ?? 'passed');
            if ($suiteStatus !== '') {
                $suiteStatusCounts[$suiteStatus] = (int)($suiteStatusCounts[$suiteStatus] ?? 0) + 1;
            }

            if ((bool)($report['evidence_valid'] ?? true) === false) {
                $evidenceValid = false;
                if ($evidenceInvalidReason === null) {
                    $reason = trim((string)($report['evidence_invalid_reason'] ?? ''));
                    $evidenceInvalidReason = $reason !== '' ? $reason : 'child_invalid_evidence';
                }
            }

            foreach (self::canonicalFailures($report) as $failure) {
                $failure['suite_id'] = (string)($report['suite_id'] ?? $failure['suite_id'] ?? '');
                $canonicalFailures[] = $failure;
            }
        }

        $reportScopeRel = count($reportScopeValues) === 1
            ? (string)array_key_first($reportScopeValues)
            : Paths::relativeToRepo($reportRoot);

        $selectedModuleScope = count($moduleScopeValues) === 1
            ? (string)array_key_first($moduleScopeValues)
            : '';

        return [
            'target'                => $target,
            'category'              => $category,
            'started_at'            => $startedAt,
            'duration_ms'           => $durationMs,
            'report_root'           => $reportRoot,
            'report_scope_rel'      => $reportScopeRel,
            'selected_module_scope' => $selectedModuleScope,
            'selected_test_count'   => $selectedTestCount,
            'suite_status_counts'   => $suiteStatusCounts,
            'summary'               => $summary,
            'failures'              => $canonicalFailures,
            'failure_contract'      => [
                'canonical' => 'failures',
                'legacy_fallback' => 'suites[].has_failures',
            ],
            'first_failure'         => $canonicalFailures !== [] ? self::summarizeFailure($canonicalFailures[0]) : null,
            'evidence_valid'        => $evidenceValid,
            'evidence_invalid_reason' => $evidenceInvalidReason,
            'failed_files'          => self::failedFiles($canonicalFailures),
            'top_failure_messages'  => self::topFailureMessages($canonicalFailures, 5),
            'suite_ids'             => array_values(array_map(static fn(array $row): string => (string)($row['suite_id'] ?? ''), $suiteRows)),
            'has_failures'          => $summary['failed'] > 0 || $canonicalFailures !== [],
            'suites'                => $suiteRows,
        ];
    }

    /**
     * @param array<int,string> $roots
     * @return array<string,mixed>|null
     */
    public static function loadLatestSuiteReport(string $suiteId, array $roots = []): ?array
    {
        $safeSuite = preg_replace('/[^a-z0-9._-]+/i', '_', strtolower($suiteId)) ?: 'suite';
        $candidateRoots = [];

        foreach ($roots as $root) {
            $root = trim($root);
            if ($root !== '') {
                $candidateRoots[$root] = true;
            }
        }

        foreach (Paths::suiteReportRoots() as $root) {
            if ($root !== '') {
                $candidateRoots[$root] = true;
            }
        }

        $candidateRoots[Paths::reportsRoot()] = true;

        foreach (array_keys($candidateRoots) as $root) {
            $canonicalFile = rtrim($root, '/\\') . '/' . $safeSuite . '_latest.json';
            $loaded = self::loadReportFile($canonicalFile);
            if ($loaded !== null) {
                return $loaded;
            }

            $pattern = rtrim($root, '/\\') . '/' . $safeSuite . '__*_latest.json';
            $matches = glob($pattern) ?: [];
            usort($matches, static fn(string $a, string $b): int => @filemtime($b) <=> @filemtime($a));

            foreach ($matches as $file) {
                $loaded = self::loadReportFile($file);
                if ($loaded !== null) {
                    return $loaded;
                }
            }
        }

        return null;
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
        $errorType = strtolower(trim((string)($failure['error_type'] ?? '')));
        $file = trim((string)($failure['file'] ?? ''));

        if ($file === '' || $file === 'migration_contract' || $errorType === 'runtime_exception' || $errorType === 'error') {
            return 'setup_failure';
        }

        if ($errorType === 'environment_conflict' || $errorType === 'shared_store_locked') {
            return 'environment_conflict';
        }

        return 'test_failure';
    }

    /**
     * @return array<int,string>
     */
    private static function traceToLines(string $text, int $maxLines): array
    {
        if (trim($text) === '') {
            return [];
        }

        $lines = preg_split('/\R/', $text) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
        return array_slice($lines, 0, $maxLines);
    }

    private static function throwableClass(Throwable $e): string
    {
        return ltrim(get_class($e), '\\');
    }

    private static function extractFirstMessage(string $text): ?string
    {
        if ($text === '') {
            return null;
        }
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(#\d+\s|Stack trace:|at\s+|\w.*\.(php|mjs|js|ts|py):\d+$)/', $line)) {
                continue;
            }
            return substr($line, 0, 200);
        }
        return null;
    }

    private static function extractTrace(string $text, int $maxLines): ?string
    {
        if ($text === '') {
            return null;
        }
        $traceLines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $text) ?: [],
            static fn(string $line): bool => (bool)preg_match('/^\s*(#\d+|Stack trace:|at\s+|\w.*\.(php|mjs|js|ts|py):\d+)/', $line)
        ));
        if ($traceLines === []) {
            return null;
        }
        return implode("\n", array_slice($traceLines, 0, $maxLines));
    }

    private static function textExcerpt(string $text, int $maxLines): ?string
    {
        if ($text === '') {
            return null;
        }
        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $text) ?: [],
            static fn(string $line): bool => trim($line) !== ''
        ));
        if ($lines === []) {
            return null;
        }
        return implode("\n", array_slice($lines, 0, $maxLines));
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function loadReportFile(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return null;
        }

        $json['_source_file'] = $file;
        return $json;
    }
}
