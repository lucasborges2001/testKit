<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

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
        return MetaReportBuilder::build($target, $category, $suiteRows, $suiteReports, $reportRoot, $durationMs, $startedAt);
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
        return RecommendedActionBuilder::build($report, $diagnostics);
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed>|null $diagnostics
     * @return array<string,mixed>
     */
    public static function agentSummary(array $report, ?array $diagnostics = null): array
    {
        return AgentSummaryBuilder::build($report, $diagnostics);
    }

    /**
     * @param array<int,string> $roots
     * @return array<string,mixed>|null
     */
    public static function loadLatestSuiteReport(string $suiteId, array $roots = []): ?array
    {
        return ReportLocator::loadLatestSuiteReport($suiteId, $roots);
    }
}
