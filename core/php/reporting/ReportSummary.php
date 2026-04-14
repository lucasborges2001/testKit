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

        $message = self::extractFirstMessage($stderr) ?? self::extractFirstMessage($stdout);
        $traceExcerpt = self::extractTrace($stderr !== '' ? $stderr : $stdout, 10);
        $stdoutExcerpt = self::textExcerpt($stdout, 15);
        $stderrExcerpt = self::textExcerpt($stderr, 15);
        $testName = self::inferTestName($entry);

        $tags = array_values((array)($entry['tags'] ?? []));
        $scopeTokens = array_values(array_filter($tags, fn(string $t): bool => in_array($t, ['unit', 'integration', 'e2e'], true)));
        $catTokens = array_values(array_filter($tags, fn(string $t): bool => !in_array($t, ['unit', 'integration', 'e2e'], true)));

        return [
            'test_id' => (string)($entry['rel'] ?? $entry['file'] ?? ''),
            'test_name' => $testName,
            'case' => $testName,
            'suite_id' => (string)($entry['suite_id'] ?? $entry['suite'] ?? $entry['module'] ?? ''),
            'suite' => (string)($entry['module'] ?? $entry['suite'] ?? ''),
            'scope' => implode(',', $scopeTokens),
            'file' => (string)($entry['rel'] ?? $entry['file'] ?? ''),
            'line' => null,
            'category' => implode(',', $catTokens),
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
        $byFile = [];
        $byErrorType = [];
        $byMessage = [];

        foreach ($failures as $f) {
            $testId = (string)($f['test_id'] ?? $f['file'] ?? 'unknown');
            $file = (string)($f['file'] ?? 'unknown');
            $errorType = (string)($f['error_type'] ?? 'unknown');
            $msg = (string)($f['message'] ?? '');

            $byFile[$file][] = $testId;
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
            'by_file' => $byFile,
            'by_error_type' => $byErrorType,
            'by_message' => $byMessage,
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
                    'message' => $key,
                    'count' => 0,
                    'files' => [],
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
            'total' => 0,
            'passed' => 0,
            'failed' => 0,
            'skipped' => 0,
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
            'target' => $target,
            'category' => $category,
            'started_at' => $startedAt,
            'duration_ms' => $durationMs,
            'report_root' => $reportRoot,
            'report_scope_rel' => $reportScopeRel,
            'selected_module_scope' => $selectedModuleScope,
            'selected_test_count' => $selectedTestCount,
            'suite_status_counts' => $suiteStatusCounts,
            'outcome_status_counts' => self::aggregateOutcomeStatusCounts($suiteReports),
            'summary' => $summary,
            'failures' => $canonicalFailures,
            'failure_contract' => [
                'canonical' => 'failures',
                'legacy_fallback' => 'suites[].has_failures',
            ],
            'first_failure' => $canonicalFailures !== [] ? self::summarizeFailure($canonicalFailures[0]) : null,
            'evidence_valid' => $evidenceValid,
            'evidence_invalid_reason' => $evidenceInvalidReason,
            'failed_files' => self::failedFiles($canonicalFailures),
            'top_failure_messages' => self::topFailureMessages($canonicalFailures, 5),
            'suite_ids' => array_values(array_map(static fn(array $row): string => (string)($row['suite_id'] ?? ''), $suiteRows)),
            'has_failures' => $summary['failed'] > 0 || $canonicalFailures !== [],
            'suites' => $suiteRows,
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

        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $summary['suite_status'] = (string)($report['suite_status'] ?? $summary['suite_status'] ?? '');
        $summary['outcome_status'] = $diagnostics['outcome_status'];
        $summary['status_counts'] = $report['status_counts'];
        $summary['phase_failure_counts'] = $report['phase_failure_counts'];
        $summary['cause_counts'] = $report['cause_counts'];
        $summary['timeouts'] = (int)($report['status_counts']['timeout'] ?? 0);
        $summary['infra_errors'] = (int)($report['status_counts']['infra_error'] ?? 0);
        $summary['contention_errors'] = (int)($report['status_counts']['contention'] ?? 0);
        $report['summary'] = $summary;

        $report['failure_clusters'] = self::failureClusters($report);
        $report['phase_timeline'] = self::phaseTimeline($report, $diagnostics);
        $report['rerun_plan'] = self::rerunPlan($report);
        $report['recommended_actions'] = self::recommendedActions($report, $diagnostics);
        $report['run_delta'] = self::runDelta($report);
        $report['agent_summary'] = self::agentSummary($report, $diagnostics);

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
        $isMeta = is_array($report['suites'] ?? null);

        $phases = $isMeta
            ? ['admission', 'execution', 'reporting']
            : ['discovery', 'admission', 'bootstrap', 'execution', 'reporting'];

        $order = array_flip($phases);
        $failureIndex = array_key_exists($primaryPhase, $order) ? (int)$order[$primaryPhase] : null;
        $timeline = [];

        foreach ($phases as $index => $phase) {
            $status = 'not_started';
            if ($failureIndex === null) {
                $status = 'completed';
            } elseif ($index < $failureIndex) {
                $status = 'completed';
            } elseif ($index === $failureIndex) {
                $status = in_array($outcome, ['passed', 'partial', 'skipped', 'listed', 'no_tests'], true) ? 'completed' : 'failed';
            }

            $timeline[] = [
                'phase' => $phase,
                'status' => $status,
                'primary' => $phase === $primaryPhase,
            ];
        }

        if ($failureIndex === null && $timeline !== []) {
            $last = array_key_last($timeline);
            if ($last !== null) {
                $timeline[$last]['primary'] = true;
            }
        }

        return $timeline;
    }

    /**
     * @param array<string,mixed> $report
     * @return array<int,array<string,mixed>>
     */
    public static function failureClusters(array $report): array
    {
        $failures = self::canonicalFailures($report);
        if ($failures === []) {
            return [];
        }

        $clusters = [];
        foreach ($failures as $failure) {
            $family = self::failureFamily($failure);
            $phase = (string)($failure['phase'] ?? 'execution');
            $domain = (string)($failure['failure_domain'] ?? 'test');
            $cause = (string)($failure['cause_code'] ?? 'unknown');
            $messageKey = self::normalizeMessageKey((string)($failure['message'] ?? ''));
            $fingerprint = substr(sha1($family . '|' . $phase . '|' . $domain . '|' . $cause . '|' . $messageKey), 0, 12);

            if (!isset($clusters[$fingerprint])) {
                $clusters[$fingerprint] = [
                    'cluster_id' => 'cluster_' . (count($clusters) + 1),
                    'fingerprint' => $fingerprint,
                    'family' => $family,
                    'count' => 0,
                    'phase' => $phase,
                    'failure_domain' => $domain,
                    'likely_shared_cause' => $cause,
                    'representative_failure' => self::summarizeFailure($failure),
                    'affected_tests' => [],
                    'affected_files' => [],
                    'affected_modules' => [],
                    'suite_ids' => [],
                ];
            }

            $clusters[$fingerprint]['count']++;
            $testId = trim((string)($failure['test_id'] ?? ''));
            if ($testId !== '') {
                $clusters[$fingerprint]['affected_tests'][$testId] = true;
            }
            $file = trim((string)($failure['file'] ?? ''));
            if ($file !== '') {
                $clusters[$fingerprint]['affected_files'][$file] = true;
            }
            $module = self::moduleFromFailure($failure);
            if ($module !== '') {
                $clusters[$fingerprint]['affected_modules'][$module] = true;
            }
            $suiteId = trim((string)($failure['suite_id'] ?? $failure['suite'] ?? ''));
            if ($suiteId !== '') {
                $clusters[$fingerprint]['suite_ids'][$suiteId] = true;
            }
        }

        $rows = [];
        foreach ($clusters as $row) {
            $row['affected_tests'] = array_values(array_keys($row['affected_tests']));
            $row['affected_files'] = array_values(array_keys($row['affected_files']));
            $row['affected_modules'] = array_values(array_keys($row['affected_modules']));
            $row['suite_ids'] = array_values(array_keys($row['suite_ids']));
            sort($row['affected_tests']);
            sort($row['affected_files']);
            sort($row['affected_modules']);
            sort($row['suite_ids']);
            $rows[] = $row;
        }

        usort($rows, static function (array $left, array $right): int {
            $cmp = (int)$right['count'] <=> (int)$left['count'];
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string)$left['fingerprint'], (string)$right['fingerprint']);
        });

        return $rows;
    }

    /**
     * @param array<string,mixed> $report
     * @return array<int,array<string,mixed>>
     */
    public static function rerunPlan(array $report): array
    {
        $plan = [];
        $suiteId = trim((string)($report['suite_id'] ?? ''));
        $target = $suiteId !== '' ? str_replace('_', '-', $suiteId) : trim((string)($report['target'] ?? ''));
        $failures = self::canonicalFailures($report);
        $clusters = self::failureClusters($report);

        if ($failures !== []) {
            $first = $failures[0];
            $firstFile = trim((string)($first['file'] ?? $first['test_id'] ?? ''));
            if ($firstFile !== '' && $target !== '') {
                $plan[] = [
                    'type' => 'rerun_single_test',
                    'label' => 'isolate first failing file',
                    'command' => "TEST_MATCH='{$firstFile}' php runTest.php {$target}",
                    'reason' => 'Reduce search space to the first reproducible failing file.',
                ];
            }
        }

        if ($clusters !== []) {
            $firstCluster = $clusters[0];
            $module = (string)($firstCluster['affected_modules'][0] ?? '');
            if ($module !== '' && $target !== '') {
                $plan[] = [
                    'type' => 'rerun_cluster_scope',
                    'label' => 'rerun dominant failure cluster',
                    'command' => "TEST_MATCH='{$module}' php runTest.php {$target}",
                    'reason' => 'Dominant cluster usually carries the shared root cause.',
                ];
            }
        }

        if ($target !== '') {
            $plan[] = [
                'type' => 'rerun_suite',
                'label' => 'rerun current suite',
                'command' => "php runTest.php {$target}",
                'reason' => 'Confirm whether the issue is isolated or suite-wide.',
            ];
        }

        if (is_array($report['suites'] ?? null)) {
            foreach ((array)$report['suites'] as $suite) {
                if (!is_array($suite)) {
                    continue;
                }
                $suiteCode = (int)($suite['exit_code'] ?? 0);
                $suiteName = trim((string)($suite['suite_id'] ?? ''));
                if ($suiteCode === 0 || $suiteCode === 2 || $suiteName === '') {
                    continue;
                }

                $plan[] = [
                    'type' => 'rerun_failed_suite',
                    'label' => 'rerun first failed suite',
                    'command' => 'php runTest.php ' . str_replace('_', '-', $suiteName),
                    'reason' => 'Meta run should collapse quickly to the first failing suite.',
                ];
                break;
            }
        }

        $plan[] = [
            'type' => 'open_report',
            'label' => 'full aggregated report',
            'command' => 'php scripts/report.php',
            'reason' => 'Open the structured report when console output is not enough.',
        ];

        return self::dedupePlan($plan);
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public static function runDelta(array $report): array
    {
        $previousRunId = trim((string)($report['previous_run_id'] ?? ''));
        $newFailures = (int)($report['new_failures_count'] ?? 0);
        $resolvedFailures = (int)($report['resolved_failures_count'] ?? 0);
        $persistentFailures = max(0, count(self::canonicalFailures($report)) - $newFailures);

        if (is_array($report['suites'] ?? null)) {
            $newFailures = 0;
            $resolvedFailures = 0;
            $persistentFailures = 0;
            foreach ((array)$report['suites'] as $suiteRow) {
                if (!is_array($suiteRow)) {
                    continue;
                }
                $newFailures += (int)($suiteRow['new_failures_count'] ?? 0);
                $resolvedFailures += (int)($suiteRow['resolved_failures_count'] ?? 0);
                $suiteFailed = (int)($suiteRow['summary']['failed'] ?? 0);
                $persistentFailures += max(0, $suiteFailed - (int)($suiteRow['new_failures_count'] ?? 0));
                if ($previousRunId === '') {
                    $previousRunId = trim((string)($suiteRow['previous_run_id'] ?? ''));
                }
            }
        }

        return [
            'previous_run_id' => $previousRunId !== '' ? $previousRunId : null,
            'new_failures_count' => $newFailures,
            'resolved_failures_count' => $resolvedFailures,
            'persistent_failures_count' => $persistentFailures,
            'changed' => $newFailures > 0 || $resolvedFailures > 0,
        ];
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
        foreach (self::rerunPlan($report) as $priority => $step) {
            if (!is_array($step)) {
                continue;
            }
            $actions[] = [
                'priority' => $priority + 1,
                'type' => (string)($step['type'] ?? 'action'),
                'command' => (string)($step['command'] ?? ''),
                'reason' => (string)($step['reason'] ?? ''),
            ];
        }

        $cause = (string)($diagnostics['cause_code'] ?? '');
        if ($cause === 'shared_store_locked' || $cause === 'store_resource_locked') {
            array_unshift($actions, [
                'priority' => 1,
                'type' => 'release_contention',
                'command' => 'Retry when the shared store lock is free.',
                'reason' => 'Current failure is contention, not test logic.',
            ]);
        }

        foreach ($actions as $index => $action) {
            $actions[$index]['priority'] = $index + 1;
        }

        return $actions;
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed>|null $diagnostics
     * @return array<string,mixed>
     */
    public static function agentSummary(array $report, ?array $diagnostics = null): array
    {
        $diagnostics ??= self::diagnostics($report);
        $clusters = self::failureClusters($report);
        $delta = self::runDelta($report);
        $actions = self::recommendedActions($report, $diagnostics);
        $topCluster = $clusters[0] ?? null;

        $primaryProblem = (string)($diagnostics['cause_code'] ?? 'none');
        if (is_array($topCluster)) {
            $primaryProblem = (string)($topCluster['family'] ?? $primaryProblem);
        }

        $suggestedFocus = [];
        if (is_array($topCluster)) {
            $suggestedFocus = array_slice(
                array_merge(
                    array_map(static fn(mixed $v): string => (string)$v, (array)($topCluster['affected_modules'] ?? [])),
                    array_map(static fn(mixed $v): string => (string)$v, (array)($topCluster['affected_files'] ?? []))
                ),
                0,
                4
            );
        }

        $likelyRootCauses = [];
        if (is_array($topCluster)) {
            $likelyRootCauses[] = [
                'family' => (string)($topCluster['family'] ?? 'unknown'),
                'confidence' => min(0.95, 0.45 + ((int)($topCluster['count'] ?? 0) * 0.05)),
                'evidence' => ['failure_clusters', 'first_failure', 'diagnostics'],
            ];
        }

        return [
            'verdict' => (string)($diagnostics['outcome_status'] ?? 'passed'),
            'primary_problem' => $primaryProblem,
            'suggested_focus' => array_values(array_unique(array_filter($suggestedFocus, static fn(string $v): bool => $v !== ''))),
            'run_changed' => (bool)($delta['changed'] ?? false),
            'likely_root_causes' => $likelyRootCauses,
            'next_best_action' => $actions[0] ?? null,
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
        if ($testsTotal === 0 && !is_array($report['suites'] ?? null)) {
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

        if ((int)($report['fail'] ?? 0) > 0 || (!empty($report['summary']['failed'] ?? 0))) {
            return 'failed';
        }

        if ((int)($report['skip'] ?? 0) > 0 && (int)($report['pass'] ?? 0) === 0) {
            return 'skipped';
        }

        if ((int)($report['skip'] ?? 0) > 0) {
            return 'partial';
        }

        if (is_array($report['suites'] ?? null) && ((int)($report['summary']['failed'] ?? 0) > 0)) {
            return 'failed';
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

    /**
     * @param array<string,mixed> $failure
     */
    private static function failureFamily(array $failure): string
    {
        $cause = strtolower(trim((string)($failure['cause_code'] ?? '')));
        $message = strtolower(trim((string)($failure['message'] ?? '')));
        $domain = strtolower(trim((string)($failure['failure_domain'] ?? '')));

        if ($cause === 'shared_store_locked' || $cause === 'store_resource_locked') {
            return 'store_contention';
        }
        if ($cause === 'process_timeout' || str_contains($message, 'timeout')) {
            return 'timeout';
        }
        if (str_contains($message, 'duplicate entry')) {
            return 'seed_drift';
        }
        if (str_contains($message, 'sql setup fallo') || str_contains($message, 'fallo aplicando sql')) {
            return 'seed_sql_failure';
        }
        if (str_contains($message, 'debe') || str_contains($message, 'expected') || $domain === 'test') {
            return 'assertion_failure';
        }
        if ($domain !== '') {
            return $domain . '_failure';
        }

        return 'unknown_failure';
    }

    private static function normalizeMessageKey(string $message): string
    {
        $message = strtolower(trim($message));
        if ($message === '') {
            return 'no_message';
        }
        $message = (string)preg_replace('/\s+/', ' ', $message);
        $message = (string)preg_replace('/\b\d+\b/', '#', $message);
        return substr($message, 0, 120);
    }

    /**
     * @param array<string,mixed> $failure
     */
    private static function moduleFromFailure(array $failure): string
    {
        $file = str_replace('\\', '/', trim((string)($failure['file'] ?? '')));
        if ($file === '') {
            return '';
        }
        $parts = array_values(array_filter(explode('/', $file), static fn(string $p): bool => $p !== ''));
        if (count($parts) >= 3 && $parts[0] === 'test') {
            return $parts[1] . '/' . $parts[2];
        }
        return count($parts) >= 2 ? ($parts[0] . '/' . $parts[1]) : $file;
    }

    /**
     * @param array<int,array<string,mixed>> $plan
     * @return array<int,array<string,mixed>>
     */
    private static function dedupePlan(array $plan): array
    {
        $seen = [];
        $out = [];
        foreach ($plan as $row) {
            if (!is_array($row)) {
                continue;
            }
            $command = trim((string)($row['command'] ?? ''));
            if ($command === '') {
                continue;
            }
            if (isset($seen[$command])) {
                continue;
            }
            $seen[$command] = true;
            $out[] = $row;
        }
        return $out;
    }
}
