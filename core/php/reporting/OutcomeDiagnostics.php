<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class OutcomeDiagnostics
{
    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public static function diagnostics(array $report): array
    {
        $failures = FailureNormalizer::canonicalFailures($report);
        $statusCounts = [
            'pass' => self::metric($report, 'pass', 'passed'),
            'fail' => self::metric($report, 'fail', 'failed'),
            'skip' => self::metric($report, 'skip', 'skipped'),
            'timeout' => (int)($report['timeout'] ?? ($report['status_counts']['timeout'] ?? 0)),
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

            $phase = trim((string)($failure['phase'] ?? ''));
            if ($phase !== '') {
                $phaseCounts[$phase] = (int)($phaseCounts[$phase] ?? 0) + 1;
            }

            $cause = trim((string)($failure['cause_code'] ?? ''));
            if ($cause !== '') {
                $causeCounts[$cause] = (int)($causeCounts[$cause] ?? 0) + 1;
            }

            $domain = trim((string)($failure['failure_domain'] ?? ''));
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
        $primaryPhase = is_array($primaryFailure) ? trim((string)($primaryFailure['phase'] ?? '')) : '';
        $failureDomain = is_array($primaryFailure) ? trim((string)($primaryFailure['failure_domain'] ?? '')) : '';
        $causeCode = is_array($primaryFailure) ? trim((string)($primaryFailure['cause_code'] ?? '')) : '';

        $outcomeStatus = self::determineOutcomeStatus(
            $report,
            $statusCounts,
            $failureDomain,
            $primaryPhase,
            $causeCode,
            $admissionReason
        );

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
        $testsTotal = self::testsTotal($report);
        $hasExecution = $testsTotal > 0
            || self::metric($report, 'pass', 'passed') > 0
            || self::metric($report, 'fail', 'failed') > 0
            || self::metric($report, 'skip', 'skipped') > 0;
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
     * @param array<string,mixed> $report
     */
    private static function testsTotal(array $report): int
    {
        if (array_key_exists('tests_total', $report)) {
            return (int)$report['tests_total'];
        }

        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        if (array_key_exists('total', $summary)) {
            return (int)$summary['total'];
        }

        $selected = (int)($report['selected_test_count'] ?? 0);
        if ($selected > 0) {
            return $selected;
        }

        return self::metric($report, 'pass', 'passed')
            + self::metric($report, 'fail', 'failed')
            + self::metric($report, 'skip', 'skipped');
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

        if (in_array($admissionReason, ['shared_store_locked', 'store_resource_locked'], true)
            || in_array($causeCode, ['shared_store_locked', 'store_resource_locked'], true)) {
            return 'contention';
        }

        if ($statusCounts['timeout'] > 0) {
            return 'timeout';
        }

        if (in_array($primaryPhase, ['discovery', 'bootstrap', 'store_setup', 'reporting'], true)
            || in_array($failureDomain, ['infra', 'bootstrap', 'store', 'discovery', 'reporting', 'runner'], true)) {
            return match ($primaryPhase) {
                'discovery' => 'discovery_error',
                'bootstrap', 'store_setup' => 'bootstrap_error',
                'reporting' => 'reporting_error',
                default => 'infra_error',
            };
        }

        if ($statusCounts['fail'] > 0) {
            return 'failed';
        }

        $testsTotal = self::testsTotal($report);
        if ($testsTotal === 0) {
            return 'no_tests';
        }

        if ($statusCounts['skip'] > 0 && $statusCounts['pass'] === 0) {
            return 'skipped';
        }

        if ($statusCounts['skip'] > 0) {
            return 'partial';
        }

        $suiteStatusCounts = is_array($report['suite_status_counts'] ?? null) ? $report['suite_status_counts'] : [];
        if ($suiteStatusCounts !== [] && count($suiteStatusCounts) === 1 && isset($suiteStatusCounts['listed'])) {
            return 'listed';
        }

        return 'passed';
    }
}
