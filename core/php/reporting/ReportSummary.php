<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

final class ReportSummary
{
    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    public static function buildFailureEntry(array $entry): array
    {
        return FailureNormalizer::buildFailureEntry($entry);
    }

    /**
     * @param \Throwable $e
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function buildThrowableFailure(\Throwable $e, array $context = []): array
    {
        return FailureNormalizer::buildThrowableFailure($e, $context);
    }

    /**
     * @param array<string,mixed> $report
     * @return array<int,array<string,mixed>>
     */
    public static function canonicalFailures(array $report): array
    {
        return FailureNormalizer::canonicalFailures($report);
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>|null
     */
    public static function firstFailure(array $report): ?array
    {
        return FailureNormalizer::firstFailure($report);
    }

    /**
     * @param array<string,mixed> $failure
     * @return array<string,mixed>
     */
    public static function summarizeFailure(array $failure): array
    {
        return FailureNormalizer::summarizeFailure($failure);
    }

    /**
     * @param array<int,array<string,mixed>> $failures
     * @return array<string,mixed>
     */
    public static function groupFailures(array $failures): array
    {
        return FailureGrouping::groupFailures($failures);
    }

    /**
     * @param array<int,array<string,mixed>> $failures
     * @return array<int,string>
     */
    public static function failedFiles(array $failures): array
    {
        return FailureGrouping::failedFiles($failures);
    }

    /**
     * @param array<int,array<string,mixed>> $failures
     * @return array<int,array<string,mixed>>
     */
    public static function topFailureMessages(array $failures, int $limit = 5): array
    {
        return FailureGrouping::topFailureMessages($failures, $limit);
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

        $topLevelPass = 0;
        $topLevelFail = 0;
        $topLevelSkip = 0;
        $topLevelTimeout = 0;
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

            $topLevelPass += self::metric($report, 'pass', 'passed');
            $topLevelFail += self::metric($report, 'fail', 'failed');
            $topLevelSkip += self::metric($report, 'skip', 'skipped');
            $topLevelTimeout += (int)($report['timeout'] ?? ($report['status_counts']['timeout'] ?? 0));

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
            'tests_total' => $summary['total'],
            'pass' => $topLevelPass,
            'fail' => $topLevelFail,
            'skip' => $topLevelSkip,
            'timeout' => $topLevelTimeout,
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
        return OutcomeDiagnostics::diagnostics($report);
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed>|null $diagnostics
     * @return array<int,array<string,mixed>>
     */
    public static function phaseTimeline(array $report, ?array $diagnostics = null): array
    {
        return OutcomeDiagnostics::phaseTimeline($report, $diagnostics);
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public static function selectionManifest(array $report): array
    {
        return SelectionManifestBuilder::build($report);
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public static function regressionDelta(array $report): array
    {
        return RegressionDeltaBuilder::build($report);
    }

    /**
     * @param array<string,mixed> $report
     * @return array<int,array<string,mixed>>
     */
    public static function normalizedArtifacts(array $report): array
    {
        return ArtifactNormalizer::normalize($report);
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
     * @param array<string,mixed> $report
     */
    private static function metric(array $report, string $key, string $summaryKey): int
    {
        if (array_key_exists($key, $report)) {
            return (int)$report[$key];
        }

        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        if (array_key_exists($summaryKey, $summary)) {
            return (int)$summary[$summaryKey];
        }

        return 0;
    }
}
