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
            'kind'           => self::entryKind($entry),
            'phase'          => (string)($entry['failure_phase'] ?? self::entryPhase($entry)),
            'failure_domain' => (string)($entry['failure_domain'] ?? self::entryDomain($entry)),
            'cause_code'     => (string)($entry['failure_cause_code'] ?? self::entryCauseCode($entry)),
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
            'phase' => (string)($context['phase'] ?? self::phaseFromKind((string)($context['kind'] ?? 'setup_failure'))),
            'failure_domain' => (string)($context['failure_domain'] ?? self::domainFromKind((string)($context['kind'] ?? 'setup_failure'))),
            'cause_code' => (string)($context['cause_code'] ?? self::causeCodeFromKind((string)($context['kind'] ?? 'setup_failure'))),
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
            'outcome_status_counts' => self::aggregateOutcomeStatusCounts($suiteReports),
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
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public static function enrichReport(array $report): array
    {
        $diagnostics = self::diagnostics($report);

        $report['outcome_status'] = $diagnostics['outcome_status'];
        $report['failure_phase'] = $diagnostics['primary_phase'];
        $report['failure_domain'] = $diagnostics['failure_domain'];
        $report['failure_cause_code'] = $diagnostics['cause_code'];
        $report['diagnostics'] = $diagnostics;
        $report['status_counts'] = is_array($diagnostics['status_counts'] ?? null) ? $diagnostics['status_counts'] : [];
        $report['phase_failure_counts'] = is_array($diagnostics['phase_failure_counts'] ?? null) ? $diagnostics['phase_failure_counts'] : [];
        $report['cause_counts'] = is_array($diagnostics['cause_counts'] ?? null) ? $diagnostics['cause_counts'] : [];

        $report['selection_manifest'] = self::selectionManifest($report);
        $report['regression_delta'] = self::regressionDelta($report);
        $report['phase_timeline'] = self::phaseTimeline($report, $diagnostics);
        $report['normalized_artifacts'] = self::normalizedArtifacts($report);
        $report['recommended_actions'] = self::recommendedActions($report, $diagnostics);
        $report['agent_summary'] = self::agentSummary($report, $diagnostics);

        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $summary['suite_status'] = (string)($report['suite_status'] ?? $summary['suite_status'] ?? '');
        $summary['outcome_status'] = $diagnostics['outcome_status'];
        $summary['status_counts'] = $report['status_counts'];
        $summary['phase_failure_counts'] = $report['phase_failure_counts'];
        $summary['cause_counts'] = $report['cause_counts'];
        $summary['timeouts'] = (int)($report['status_counts']['timeout'] ?? 0);
        $summary['infra_errors'] = (int)($report['status_counts']['infra_error'] ?? 0);
        $summary['contention_errors'] = (int)($report['status_counts']['contention'] ?? 0);
        $summary['selected_test_count'] = (int)($report['selected_test_count'] ?? $report['tests_total'] ?? 0);
        $summary['regression_new_failures'] = count((array)($report['regression_delta']['new_failures'] ?? []));
        $summary['regression_resolved_failures'] = count((array)($report['regression_delta']['resolved_failures'] ?? []));
        $report['summary'] = $summary;

        return $report;
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public static function diagnostics(array $report): array
    {
        $failures = self::canonicalFailures($report);
        $statusCounts = [
            'pass' => (int)($report['pass'] ?? 0),
            'fail' => (int)($report['fail'] ?? 0),
            'skip' => (int)($report['skip'] ?? 0),
            'timeout' => 0,
            'infra_error' => 0,
            'contention' => 0,
        ];
        $phaseCounts = [];
        $causeCounts = [];

        foreach ($failures as $failure) {
            $status = strtolower(trim((string)($failure['status'] ?? 'fail')));
            if ($status === 'timeout') {
                $statusCounts['timeout']++;
            }

            $phase = trim((string)($failure['phase'] ?? self::phaseFromKind((string)($failure['kind'] ?? ''))));
            if ($phase !== '') {
                $phaseCounts[$phase] = (int)($phaseCounts[$phase] ?? 0) + 1;
            }

            $cause = trim((string)($failure['cause_code'] ?? self::causeCodeFromKind((string)($failure['kind'] ?? ''))));
            if ($cause !== '') {
                $causeCounts[$cause] = (int)($causeCounts[$cause] ?? 0) + 1;
            }

            $domain = trim((string)($failure['failure_domain'] ?? self::domainFromKind((string)($failure['kind'] ?? ''))));
            if ($status !== 'timeout' && in_array($domain, ['infra', 'bootstrap', 'store', 'discovery', 'reporting', 'runner'], true)) {
                $statusCounts['infra_error']++;
            }

            if ($cause === 'shared_store_locked' || $cause === 'store_resource_locked') {
                $statusCounts['contention']++;
            }
        }

        $admission = is_array($report['concurrency_admission'] ?? null) ? $report['concurrency_admission'] : [];
        $admissionReason = trim((string)($admission['reason'] ?? ''));
        $primaryFailure = $failures !== [] ? $failures[0] : null;
        $primaryKind = is_array($primaryFailure) ? (string)($primaryFailure['kind'] ?? '') : '';
        $primaryPhase = is_array($primaryFailure) ? (string)($primaryFailure['phase'] ?? self::phaseFromKind($primaryKind)) : '';
        $failureDomain = is_array($primaryFailure) ? (string)($primaryFailure['failure_domain'] ?? self::domainFromKind($primaryKind)) : '';
        $causeCode = is_array($primaryFailure) ? (string)($primaryFailure['cause_code'] ?? self::causeCodeFromKind($primaryKind)) : '';

        $outcomeStatus = self::determineOutcomeStatus($report, $statusCounts, $failureDomain, $primaryPhase, $causeCode, $admissionReason);

        return [
            'outcome_status' => $outcomeStatus,
            'failure_domain' => $failureDomain !== '' ? $failureDomain : 'none',
            'primary_phase' => $primaryPhase !== '' ? $primaryPhase : 'none',
            'cause_code' => $causeCode !== '' ? $causeCode : ($admissionReason !== '' ? $admissionReason : 'none'),
            'status_counts' => $statusCounts,
            'phase_failure_counts' => $phaseCounts,
            'cause_counts' => $causeCounts,
            'has_timeout' => $statusCounts['timeout'] > 0,
            'has_contention' => $statusCounts['contention'] > 0 || in_array($admissionReason, ['shared_store_locked', 'store_resource_locked'], true),
            'resource' => (string)($admission['resource'] ?? ''),
            'lock_key' => (string)($admission['lock_key'] ?? ''),
            'lock_scope' => (string)($admission['lock_scope'] ?? ''),
            'lock_owner_run_id' => $admission['lock_owner_run_id'] ?? null,
            'lock_owner_meta_run_id' => $admission['lock_owner_meta_run_id'] ?? null,
            'lock_owner_hostname' => $admission['lock_owner_hostname'] ?? null,
            'lock_acquired_at' => $admission['lock_acquired_at'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed>|null $diagnostics
     * @return array<int,array<string,mixed>>
     */
    public static function phaseTimeline(array $report, ?array $diagnostics = null): array
    {
        $diagnostics ??= self::diagnostics($report);
        $primaryPhase = (string)($diagnostics['primary_phase'] ?? 'none');
        $outcome = (string)($diagnostics['outcome_status'] ?? 'passed');
        $testsTotal = (int)($report['tests_total'] ?? 0);
        $hasExecution = $testsTotal > 0 || (int)($report['pass'] ?? 0) > 0 || (int)($report['fail'] ?? 0) > 0 || (int)($report['skip'] ?? 0) > 0;
        $listOnly = (bool)($report['list_only'] ?? false);

        $rows = [];
        foreach (['discovery', 'admission', 'bootstrap', 'execution', 'reporting'] as $phase) {
            $status = 'ok';

            if ($primaryPhase === $phase) {
                $status = 'fail';
            } elseif ($phase === 'execution' && in_array($outcome, ['failed', 'partial', 'timeout'], true)) {
                $status = 'fail';
            } elseif ($phase === 'execution' && !$hasExecution) {
                $status = $listOnly ? 'listed' : 'not_started';
            } elseif ($phase === 'bootstrap' && in_array($primaryPhase, ['store_setup', 'bootstrap'], true)) {
                $status = 'fail';
            } elseif ($phase === 'admission' && $outcome === 'contention') {
                $status = 'fail';
            } elseif ($phase === 'reporting' && $outcome === 'reporting_error') {
                $status = 'fail';
            } elseif ($phase === 'execution' && $listOnly) {
                $status = 'listed';
            }

            $rows[] = [
                'name' => $phase,
                'status' => $status,
                'duration_ms' => $phase === 'execution' ? (int)($report['duration_ms'] ?? 0) : null,
                'is_primary_failure' => $primaryPhase === $phase || ($phase === 'bootstrap' && in_array($primaryPhase, ['store_setup', 'bootstrap'], true)),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public static function selectionManifest(array $report): array
    {
        $selection = $report['selection_manifest'] ?? null;
        if (is_array($selection)) {
            $selection['selected_test_files'] = array_values(array_filter((array)($selection['selected_test_files'] ?? []), 'is_string'));
            return $selection;
        }

        return [
            'suite_id' => (string)($report['suite_id'] ?? ''),
            'scope' => (string)($report['scope'] ?? ($report['filters']['scope'] ?? 'all')),
            'category' => (string)($report['category'] ?? ($report['filters']['category'] ?? 'all')),
            'match' => (string)($report['match'] ?? ($report['filters']['match'] ?? '')),
            'list_only' => (bool)($report['list_only'] ?? false),
            'selected_test_count' => (int)($report['selected_test_count'] ?? $report['tests_total'] ?? 0),
            'selected_module_scope' => (string)($report['selected_module_scope'] ?? ''),
            'selected_common_dir' => (string)($report['selected_common_dir'] ?? ''),
            'selected_test_files' => array_values(array_filter((array)($report['selected_test_files'] ?? []), 'is_string')),
            'source' => 'report_summary_fallback',
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public static function regressionDelta(array $report): array
    {
        $delta = $report['regression_delta'] ?? null;
        if (!is_array($delta)) {
            $delta = [];
        }

        $transitions = [];
        foreach ((array)($delta['status_transitions'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $test = trim((string)($row['test'] ?? ''));
            $from = trim((string)($row['from'] ?? ''));
            $to = trim((string)($row['to'] ?? ''));
            if ($test === '' || $from === '' || $to === '') {
                continue;
            }

            $transitions[] = [
                'test' => $test,
                'from' => $from,
                'to' => $to,
            ];
        }

        return [
            'new_failures' => array_values(array_filter((array)($delta['new_failures'] ?? []), 'is_string')),
            'resolved_failures' => array_values(array_filter((array)($delta['resolved_failures'] ?? []), 'is_string')),
            'status_transitions' => $transitions,
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @return array<int,array<string,mixed>>
     */
    public static function normalizedArtifacts(array $report): array
    {
        $items = [];

        $push = static function (array &$items, string $kind, mixed $path): void {
            if (!is_string($path) || trim($path) === '') {
                return;
            }

            $path = str_replace('\\', '/', trim($path));
            $items[] = [
                'kind' => $kind,
                'path' => $path,
                'exists' => null,
            ];
        };

        $push($items, 'report_root', $report['report_root'] ?? null);
        $push($items, 'history_file', $report['history_file'] ?? null);
        $push($items, 'manifest_path', $report['manifest_path'] ?? null);
        $push($items, 'snapshot_file', $report['snapshot_file'] ?? null);
        $push($items, 'coverage_json', $report['coverage_json'] ?? null);
        $push($items, 'coverage_lcov', $report['coverage_lcov'] ?? null);

        $reportLinks = $report['report_links'] ?? null;
        if (is_array($reportLinks)) {
            foreach ($reportLinks as $kind => $path) {
                $push($items, 'report_link:' . (string)$kind, $path);
            }
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed>|null $diagnostics
     * @return array<int,array<string,mixed>>
     */
    public static function recommendedActions(array $report, ?array $diagnostics = null): array
    {
        $diagnostics ??= self::diagnostics($report);
        $actions = [];

        $suiteId = trim((string)($report['suite_id'] ?? ''));
        $target = $suiteId !== '' ? str_replace('_', '-', $suiteId) : ((string)($report['target'] ?? 'all') ?: 'all');
        $firstFailure = is_array($report['first_failure'] ?? null) ? $report['first_failure'] : self::firstFailure($report);
        $firstFile = trim((string)($firstFailure['file'] ?? ''));

        if ($firstFile !== '') {
            $actions[] = [
                'kind' => 'rerun_filtered',
                'command' => "TEST_MATCH='{$firstFile}' php runTest.php {$target}",
                'reason' => 'aislar el primer archivo fallido',
            ];
        }

        $primaryPhase = (string)($diagnostics['primary_phase'] ?? 'none');
        if (in_array($primaryPhase, ['bootstrap', 'store_setup'], true)) {
            $actions[] = [
                'kind' => 'enable_seed_trace',
                'command' => "TESTKIT_TRACE_MIGRATIONS=1 php runTest.php {$target}",
                'reason' => 'ampliar evidencia en bootstrap/seeding',
            ];
        }

        if ($primaryPhase === 'discovery') {
            $actions[] = [
                'kind' => 'list_selection',
                'command' => "php runTest.php {$target} --list",
                'reason' => 'ver selección efectiva y validar filtros',
            ];
        }

        $reportRoot = trim((string)($report['report_scope_rel'] ?? $report['report_root'] ?? ''));
        if ($reportRoot !== '') {
            $actions[] = [
                'kind' => 'open_report_root',
                'command' => $reportRoot,
                'reason' => 'inspeccionar artefactos generados por la corrida',
            ];
        }

        $actions[] = [
            'kind' => 'aggregate_report',
            'command' => 'php scripts/report.php',
            'reason' => 'ver resumen consolidado de fallas y coverage',
        ];

        $unique = [];
        $deduped = [];
        foreach ($actions as $action) {
            $key = (string)($action['kind'] ?? '') . '::' . (string)($action['command'] ?? '');
            if (isset($unique[$key])) {
                continue;
            }
            $unique[$key] = true;
            $deduped[] = $action;
        }

        return array_slice($deduped, 0, 5);
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed>|null $diagnostics
     * @return array<string,mixed>
     */
    public static function agentSummary(array $report, ?array $diagnostics = null): array
    {
        $diagnostics ??= self::diagnostics($report);
        $selection = self::selectionManifest($report);
        $regression = self::regressionDelta($report);
        $firstFailure = is_array($report['first_failure'] ?? null) ? $report['first_failure'] : self::firstFailure($report);

        $primaryProblem = trim((string)($diagnostics['cause_code'] ?? ''));
        if (is_array($firstFailure)) {
            $message = trim((string)($firstFailure['message'] ?? ''));
            if ($message !== '') {
                $primaryProblem = $message;
            }
        }
        if ($primaryProblem === '') {
            $primaryProblem = 'no_primary_problem_detected';
        }

        $focus = [];
        $primaryPhase = (string)($diagnostics['primary_phase'] ?? 'none');
        $failureDomain = (string)($diagnostics['failure_domain'] ?? 'none');

        if ($primaryPhase !== 'none') {
            $focus[] = $primaryPhase;
        }
        if ($failureDomain !== 'none') {
            $focus[] = $failureDomain;
        }
        if ((int)count((array)($regression['new_failures'] ?? [])) > 0) {
            $focus[] = 'regression_delta';
        }
        if ((bool)($report['coverage'] ?? false) === true || isset($report['coverage_json']) || isset($report['coverage_lcov'])) {
            $focus[] = 'coverage';
        }
        if (is_array($report['seed_state'] ?? null)) {
            $focus[] = 'seed_state';
        }
        $focus[] = 'selection_manifest';

        return [
            'status' => strtoupper((string)($diagnostics['outcome_status'] ?? 'passed')),
            'primary_problem' => $primaryProblem,
            'confidence' => is_array($firstFailure) ? 'high' : 'medium',
            'selected_test_count' => (int)($selection['selected_test_count'] ?? 0),
            'suggested_focus' => array_values(array_unique($focus)),
        ];
    }

    /**
     * @param array<string,mixed> $report
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
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<string,int>
     */
    private static function aggregateOutcomeStatusCounts(array $suiteReports): array
    {
        $counts = [];
        foreach ($suiteReports as $report) {
            $outcome = trim((string)($report['outcome_status'] ?? ''));
            if ($outcome === '') {
                continue;
            }
            $counts[$outcome] = (int)($counts[$outcome] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,int> $statusCounts
     */
    private static function determineOutcomeStatus(
        array $report,
        array $statusCounts,
        string $failureDomain,
        string $primaryPhase,
        string $causeCode,
        string $admissionReason
    ): string {
        if ((bool)($report['list_only'] ?? false)) {
            return 'listed';
        }

        if (in_array($admissionReason, ['shared_store_locked', 'store_resource_locked'], true) || in_array($causeCode, ['shared_store_locked', 'store_resource_locked'], true)) {
            return 'contention';
        }

        $testsTotal = (int)($report['tests_total'] ?? 0);
        if ($testsTotal === 0) {
            return 'no_tests';
        }

        if ($statusCounts['timeout'] > 0) {
            return 'timeout';
        }

        if (in_array($primaryPhase, ['discovery', 'bootstrap', 'store_setup', 'reporting'], true) || in_array($failureDomain, ['infra', 'bootstrap', 'store', 'discovery', 'reporting', 'runner'], true)) {
            return match ($primaryPhase) {
                'discovery' => 'discovery_error',
                'bootstrap', 'store_setup' => 'bootstrap_error',
                'reporting' => 'reporting_error',
                default => 'infra_error',
            };
        }

        if ((int)($report['fail'] ?? 0) > 0) {
            return 'failed';
        }

        if ((int)($report['skip'] ?? 0) > 0 && (int)($report['pass'] ?? 0) === 0) {
            return 'skipped';
        }

        if ((int)($report['skip'] ?? 0) > 0) {
            return 'partial';
        }

        return 'passed';
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

    private static function phaseFromKind(string $kind): string
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

    private static function domainFromKind(string $kind): string
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

    private static function causeCodeFromKind(string $kind): string
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
