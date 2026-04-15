<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class AgentSummaryBuilder
{
    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed>|null $diagnostics
     * @return array<string,mixed>
     */
    public static function build(array $report, ?array $diagnostics = null): array
    {
        $diagnostics ??= OutcomeDiagnostics::diagnostics($report);
        $selection = SelectionManifestBuilder::build($report);
        $regression = RegressionDeltaBuilder::build($report);
        $firstFailure = is_array($report['first_failure'] ?? null) ? $report['first_failure'] : FailureNormalizer::firstFailure($report);

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
}
