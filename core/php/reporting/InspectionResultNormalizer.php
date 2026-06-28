<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

final class InspectionResultNormalizer
{
    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<string,mixed>
     */
    public static function metaSummary(?array $meta, array $suiteReports): array
    {
        if (is_array($meta)) {
            $canonical = is_array($meta['canonical_report'] ?? null) ? $meta['canonical_report'] : [];
            $selection = AgentDecisionBuilder::deriveSelection($meta, $suiteReports);
            return [
                'final_status' => (string)($canonical['final_status'] ?? $meta['final_status'] ?? ''),
                'outcome_status' => (string)($meta['outcome_status'] ?? ''),
                'summary' => is_array($canonical['summary'] ?? null) ? $canonical['summary'] : (is_array($meta['summary'] ?? null) ? $meta['summary'] : []),
                'selection' => $selection,
            ];
        }

        $total = ['total' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0, 'timeouts' => 0, 'duration_ms' => 0];
        foreach ($suiteReports as $report) {
            $summary = self::summaryFromReport($report);
            $total['total'] += (int)($summary['total'] ?? 0);
            $total['passed'] += (int)($summary['passed'] ?? 0);
            $total['failed'] += (int)($summary['failed'] ?? 0);
            $total['skipped'] += (int)($summary['skipped'] ?? 0);
            $total['timeouts'] += (int)($summary['timeouts'] ?? 0);
            $total['duration_ms'] += (int)($summary['duration_ms'] ?? 0);
        }

        return ['summary' => $total, 'selection' => AgentDecisionBuilder::deriveSelection(null, $suiteReports)];
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    public static function suiteSummary(array $report): array
    {
        $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
        $selection = AgentDecisionBuilder::deriveSelection(null, [$report]);
        $summary = self::summaryFromReport($report);
        $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];

        return [
            'suite_id' => (string)($report['suite_id'] ?? $selection['primary_suite_id'] ?? ''),
            'suite_status' => (string)($report['suite_status'] ?? $canonical['final_status'] ?? ''),
            'outcome_status' => (string)($report['outcome_status'] ?? ''),
            'final_status' => (string)($canonical['final_status'] ?? ''),
            'selected_test_count' => (int)($selection['selected_test_count'] ?? 0),
            'selected_module_scope' => (string)($selection['selected_module_scope'] ?? ''),
            'pass' => (int)($report['pass'] ?? $summary['passed'] ?? 0),
            'fail' => (int)($report['fail'] ?? $summary['failed'] ?? 0),
            'skip' => (int)($report['skip'] ?? $summary['skipped'] ?? 0),
            'duration_ms' => (int)($summary['duration_ms'] ?? $report['duration_ms'] ?? 0),
            'warnings' => self::normalizeWarnings($canonical['warnings'] ?? $report['warnings'] ?? []),
            'evidence_valid' => (bool)($evidence['valid'] ?? $report['evidence_valid'] ?? true),
            'artifact_path' => Paths::relativeToRepo((string)($report['_source_file'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    public static function summaryFromReport(array $report): array
    {
        $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
        $summary = is_array($canonical['summary'] ?? null) ? $canonical['summary'] : (is_array($report['summary'] ?? null) ? $report['summary'] : []);
        return [
            'total' => (int)($summary['total'] ?? $report['tests_total'] ?? 0),
            'passed' => (int)($summary['passed'] ?? $report['pass'] ?? 0),
            'failed' => (int)($summary['failed'] ?? $report['fail'] ?? 0),
            'skipped' => (int)($summary['skipped'] ?? $report['skip'] ?? 0),
            'timeouts' => (int)($summary['timeouts'] ?? $report['timeout'] ?? 0),
            'duration_ms' => (int)($summary['duration_ms'] ?? $report['duration_ms'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<int,array<string,mixed>>
     */
    public static function collectWarnings(?array $meta, array $suiteReports): array
    {
        $warnings = [];
        $reports = [];
        if (is_array($meta)) {
            $reports[] = $meta;
        }
        foreach ($suiteReports as $report) {
            $reports[] = $report;
        }
        foreach ($reports as $report) {
            $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
            foreach ([$canonical['warnings'] ?? null, $report['warnings'] ?? null] as $source) {
                if (!is_array($source)) {
                    continue;
                }
                foreach ($source as $row) {
                    if (is_array($row)) {
                        $warnings[] = $row;
                    } elseif (is_scalar($row)) {
                        $warnings[] = ['code' => 'GENERIC_WARNING', 'summary' => (string)$row];
                    }
                }
            }
        }
        return self::normalizeWarnings($warnings);
    }

    /** @param array<string,mixed> $report */
    public static function reportEvidenceValid(array $report): bool
    {
        $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
        $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];
        return (bool)($evidence['valid'] ?? $report['evidence_valid'] ?? true);
    }

    /** @return array<int,array<string,mixed>> */
    public static function normalizeWarnings(mixed $warnings): array
    {
        if (class_exists(StructuredWarnings::class)) {
            return StructuredWarnings::canonicalize($warnings);
        }
        return is_array($warnings) ? array_values(array_filter($warnings, 'is_array')) : [];
    }
}
