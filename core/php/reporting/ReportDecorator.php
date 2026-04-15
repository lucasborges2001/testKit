<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class ReportDecorator
{
    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $previous
     * @return array<string,mixed>
     */
    public static function decorate(
        array $report,
        array $previous,
        string $latestPath,
        string $tsPath,
        int $reportKeep,
        int $runsIndexKeep,
        string $kind
    ): array {
        $runId = trim((string)($report['run_id'] ?? ''));
        if ($runId === '') {
            $runId = self::buildRunId();
        }
        $report['run_id'] = $runId;

        $metaRunId = trim((string)($report['meta_run_id'] ?? ''));
        if ($kind === 'meta') {
            $metaRunId = $metaRunId !== '' ? $metaRunId : $runId;
            $report['meta_run_id'] = $metaRunId;
        } elseif ($metaRunId !== '') {
            $report['meta_run_id'] = $metaRunId;
        }

        $previousRunId = trim((string)($previous['run_id'] ?? $previous['meta_run_id'] ?? ''));
        $report['previous_run_id'] = $previousRunId !== '' ? $previousRunId : null;

        $delta = FailureDelta::diff($previous, $report);
        $report['new_failures'] = $delta['new_failures'];
        $report['resolved_failures'] = $delta['resolved_failures'];
        $report['new_failures_count'] = count($delta['new_failures']);
        $report['resolved_failures_count'] = count($delta['resolved_failures']);

        $failures = ReportSummary::canonicalFailures($report);
        $triageSummary = FailureClassifier::summarize($failures, 5);
        $report['triage_summary'] = $triageSummary;
        $report['dominant_failure_family'] = $triageSummary[0]['family'] ?? null;
        $report['first_failure'] = ReportSummary::firstFailure($report);

        $report['report_keep'] = $reportKeep;
        $report['runs_index_keep'] = $runsIndexKeep;
        $report['report_links'] = [
            'latest' => basename($latestPath),
            'timestamped' => basename($tsPath),
            'runs_index' => 'runs_latest.json',
        ];

        $report = ReportSummary::enrichReport($report);
        return CanonicalReport::enrich($report);
    }

    private static function buildRunId(): string
    {
        try {
            $suffix = bin2hex(random_bytes(3));
        } catch (\Throwable) {
            $suffix = substr((string)sha1(uniqid('', true)), 0, 6);
        }

        return gmdate('Ymd\THis\Z') . '_' . $suffix;
    }
}
