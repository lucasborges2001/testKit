<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

final class MetaReportBuilder
{
    /**
     * @param array<int,array<string,mixed>> $suiteRows
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<string,mixed>
     */
    public static function build(
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
        $warnings = [];

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

            $warnings = self::mergeWarnings(
                $warnings,
                StructuredWarnings::canonicalize($report['warnings'] ?? ($report['parallel_policy']['warnings'] ?? []))
            );

            foreach (FailureNormalizer::canonicalFailures($report) as $failure) {
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
            'warnings' => $warnings,
            'failures' => $canonicalFailures,
            'failure_contract' => [
                'canonical' => 'failures',
                'legacy_fallback' => 'suites[].has_failures',
            ],
            'first_failure' => $canonicalFailures !== [] ? FailureNormalizer::summarizeFailure($canonicalFailures[0]) : null,
            'evidence_valid' => $evidenceValid,
            'evidence_invalid_reason' => $evidenceInvalidReason,
            'failed_files' => FailureGrouping::failedFiles($canonicalFailures),
            'top_failure_messages' => FailureGrouping::topFailureMessages($canonicalFailures, 5),
            'suite_ids' => array_values(array_map(static fn(array $row): string => (string)($row['suite_id'] ?? ''), $suiteRows)),
            'has_failures' => $summary['failed'] > 0 || $canonicalFailures !== [],
        ];
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

    /**
     * @param array<int,array<string,mixed>> $left
     * @param array<int,array<string,mixed>> $right
     * @return array<int,array<string,mixed>>
     */
    private static function mergeWarnings(array $left, array $right): array
    {
        $merged = [];
        $seen = [];

        foreach (array_merge($left, $right) as $warning) {
            if (!is_array($warning)) {
                continue;
            }

            $code = (string)($warning['code'] ?? 'GENERIC_WARNING');
            $summary = (string)($warning['summary'] ?? 'warning');
            $key = $code . '|' . $summary;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = $warning;
        }

        return array_values($merged);
    }
}
