<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Console;

use Testkit\Core\Reporting\CommandSuggestion;
use Testkit\Core\Reporting\ReportSummary;
use Testkit\Core\Reporting\UI;

final class RecommendedActionPresenter
{
    public static function printOperatorSummary(array $result, array $diagnostics): void
    {
        $agentSummary = is_array($result['agent_summary'] ?? null)
            ? $result['agent_summary']
            : ReportSummary::agentSummary($result, $diagnostics);
        $firstFailure = is_array($result['first_failure'] ?? null)
            ? $result['first_failure']
            : ReportSummary::firstFailure($result);

        $focus = '';
        if (is_array($firstFailure)) {
            $focus = trim((string)($firstFailure['file'] ?? $firstFailure['case'] ?? ''));
        }

        $primaryProblem = trim((string)($agentSummary['primary_problem'] ?? ''));
        if ($primaryProblem === '' && is_array($firstFailure)) {
            $primaryProblem = trim((string)($firstFailure['message'] ?? ''));
        }
        if ($primaryProblem === '') {
            $primaryProblem = trim((string)($diagnostics['cause_code'] ?? 'none'));
        }

        $primaryAction = self::primaryActionCommand(self::collectRecommendedActions($result, $diagnostics));
        if ($primaryAction === '' && $focus !== '') {
            $suiteId = str_replace('_', '-', (string)($result['suite_id'] ?? ''));
            if ($suiteId !== '') {
                $primaryAction = CommandSuggestion::rerun($suiteId, $focus);
            }
        }

        $reportRoot = trim((string)($result['report_scope_rel'] ?? $result['report_root'] ?? ''));

        UI::section('Operator Summary');
        echo '  status: ' . ConsoleTableFormatter::renderOutcome((string)($agentSummary['status'] ?? strtoupper((string)($diagnostics['outcome_status'] ?? 'passed')))) . "\n";
        if ($primaryProblem !== '') {
            echo '  primary_problem: ' . UI::gray($primaryProblem) . "\n";
        }
        if ($focus !== '') {
            echo '  focus: ' . UI::gray($focus) . "\n";
        }
        if ($primaryAction !== '') {
            echo '  next_action: ' . UI::info($primaryAction) . "\n";
        }
        if ($reportRoot !== '') {
            echo '  report_root: ' . UI::gray($reportRoot) . "\n";
        }
    }

    public static function printRecommendedActions(array $result, array $diagnostics): void
    {
        $actions = self::collectRecommendedActions($result, $diagnostics);
        if ($actions === []) {
            return;
        }

        $primaryCommand = self::primaryActionCommand($actions);
        $remaining = [];
        foreach ($actions as $action) {
            $command = trim((string)($action['command'] ?? ''));
            if ($command === '' || $command === $primaryCommand) {
                continue;
            }
            $remaining[] = $action;
        }

        if ($remaining === []) {
            return;
        }

        UI::section('Recommended Actions');
        foreach (array_slice($remaining, 0, ConsoleRenderLimits::MAX_ACTIONS) as $action) {
            $command = trim((string)($action['command'] ?? ''));
            $reason = trim((string)($action['reason'] ?? ''));
            if ($command === '') {
                continue;
            }

            echo '  - ' . UI::info($command);
            if ($reason !== '') {
                echo ' ' . UI::gray('(' . $reason . ')');
            }
            echo "\n";
        }
    }

    /**
     * @return array<int,array{command:string,reason:string}>
     */
    public static function collectRecommendedActions(array $result, array $diagnostics): array
    {
        $actions = is_array($result['recommended_actions'] ?? null)
            ? array_values(array_filter($result['recommended_actions'], 'is_array'))
            : ReportSummary::recommendedActions($result, $diagnostics);

        $normalized = [];
        $seen = [];
        foreach ($actions as $action) {
            $command = trim((string)($action['command'] ?? ''));
            $reason = trim((string)($action['reason'] ?? ''));
            if ($command === '' || isset($seen[$command])) {
                continue;
            }
            $seen[$command] = true;
            $normalized[] = [
                'command' => $command,
                'reason' => $reason,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int,array{command:string,reason:string}> $actions
     */
    public static function primaryActionCommand(array $actions): string
    {
        if ($actions === []) {
            return '';
        }

        return trim((string)($actions[0]['command'] ?? ''));
    }

    private function __construct()
    {
    }
}
