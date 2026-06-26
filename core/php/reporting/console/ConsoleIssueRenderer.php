<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Console;

use Testkit\Core\Reporting\CommandSuggestion;
use Testkit\Core\Reporting\FailureClassifier;
use Testkit\Core\Reporting\ReportSummary;
use Testkit\Core\Reporting\StructuredWarnings;
use Testkit\Core\Reporting\UI;

final class ConsoleIssueRenderer
{
    public static function printWarnings(array $result): void
    {
        $warnings = StructuredWarnings::canonicalize($result['warnings'] ?? ($result['parallel_policy']['warnings'] ?? null));
        if ($warnings === []) {
            return;
        }

        UI::section('Warnings');
        foreach ($warnings as $warning) {
            $severity = strtoupper((string)($warning['severity'] ?? 'WARN'));
            $code = (string)($warning['code'] ?? 'WARNING');
            $summary = (string)($warning['summary'] ?? '');
            echo "  - [{$severity}] {$code}: {$summary}\n";
        }
    }

    public static function printSelectionSummary(array $result): void
    {
        $selection = is_array($result['selection_manifest'] ?? null)
            ? $result['selection_manifest']
            : ReportSummary::selectionManifest($result);

        UI::section('Selection Summary');
        echo '  count: ' . UI::gray((string)($selection['selected_test_count'] ?? 0)) . "\n";

        $moduleScope = trim((string)($selection['selected_module_scope'] ?? ''));
        if ($moduleScope !== '') {
            echo '  module_scope: ' . UI::gray($moduleScope) . "\n";
        }

        $commonDir = trim((string)($selection['selected_common_dir'] ?? ''));
        if ($commonDir !== '') {
            echo '  common_dir: ' . UI::gray($commonDir) . "\n";
        }

        $match = trim((string)($selection['match'] ?? ''));
        if ($match !== '') {
            echo '  match: ' . UI::info($match) . "\n";
        }
    }

    public static function printFirstFailure(array $result): void
    {
        $firstFailure = $result['first_failure'] ?? null;
        if (!is_array($firstFailure)) {
            return;
        }

        $kind = trim((string)($firstFailure['kind'] ?? ''));
        $message = trim((string)($firstFailure['message'] ?? ''));
        $file = trim((string)($firstFailure['file'] ?? ''));
        $case = trim((string)($firstFailure['case'] ?? ''));
        $where = $file !== '' ? $file : ($case !== '' ? $case : 'n/a');

        UI::section('First Failure');
        echo '  target: ' . ($kind !== '' ? UI::warning($kind) . ' ' : '') . $where . "\n";
        if ($message !== '') {
            echo '  message: ' . $message . "\n";
        }
    }

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

    public static function printRegressionDelta(array $result): void
    {
        $delta = is_array($result['regression_delta'] ?? null)
            ? $result['regression_delta']
            : ReportSummary::regressionDelta($result);

        $newFailures = array_values(array_filter((array)($delta['new_failures'] ?? []), 'is_string'));
        $resolvedFailures = array_values(array_filter((array)($delta['resolved_failures'] ?? []), 'is_string'));
        $transitions = array_values(array_filter((array)($delta['status_transitions'] ?? []), 'is_array'));

        if ($newFailures === [] && $resolvedFailures === [] && $transitions === []) {
            return;
        }

        UI::section('Regression Delta');

        if ($newFailures !== []) {
            echo '  new_failures: ' . UI::failure((string)count($newFailures)) . "\n";
            foreach (array_slice($newFailures, 0, ConsoleRenderLimits::MAX_REGRESSION_ITEMS) as $file) {
                echo '    - ' . $file . "\n";
            }
        }

        if ($resolvedFailures !== []) {
            echo '  resolved_failures: ' . UI::success((string)count($resolvedFailures)) . "\n";
            foreach (array_slice($resolvedFailures, 0, ConsoleRenderLimits::MAX_REGRESSION_ITEMS) as $file) {
                echo '    - ' . $file . "\n";
            }
        }

        if ($transitions !== []) {
            echo '  status_transitions: ' . UI::warning((string)count($transitions)) . "\n";
            foreach (array_slice($transitions, 0, ConsoleRenderLimits::MAX_REGRESSION_ITEMS) as $row) {
                $test = trim((string)($row['test'] ?? ''));
                $from = trim((string)($row['from'] ?? ''));
                $to = trim((string)($row['to'] ?? ''));
                if ($test === '' || $from === '' || $to === '') {
                    continue;
                }
                echo '    - ' . $test . ' ' . UI::gray($from . ' -> ' . $to) . "\n";
            }
        }
    }

    public static function shouldPrintModuleSummary(array $result, bool $compactPassed): bool
    {
        $summary = $result['module_summary'] ?? [];
        if (!is_array($summary) || $summary === []) {
            return false;
        }

        if (!$compactPassed) {
            return true;
        }

        foreach ($summary as $stat) {
            if (!is_array($stat)) {
                continue;
            }

            if ((int)($stat['fail'] ?? 0) > 0 || (int)($stat['skip'] ?? 0) > 0 || (int)($stat['timeout'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    public static function printModuleSummary(array $result): void
    {
        $summary = $result['module_summary'] ?? [];
        if (!is_array($summary) || !$summary) {
            return;
        }

        $rows = [];
        foreach ($summary as $module => $stat) {
            $rows[] = [
                'module' => (string)$module,
                'total' => (int)($stat['total'] ?? 0),
                'pass' => (int)($stat['pass'] ?? 0),
                'fail' => (int)($stat['fail'] ?? 0),
                'skip' => (int)($stat['skip'] ?? 0),
                'timeout' => (int)($stat['timeout'] ?? 0),
            ];
        }

        usort($rows, [ConsoleTableFormatter::class, 'compareModuleSummaryRows']);

        $issueRows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => (int)$row['fail'] > 0 || (int)$row['skip'] > 0 || (int)$row['timeout'] > 0
        ));
        $visibleRows = $issueRows !== [] ? $issueRows : $rows;
        $hiddenHealthy = max(0, count($rows) - count($visibleRows));

        UI::section('Module Summary');
        echo UI::gray(str_pad('Module', 30) . ' | Total | Pass | Fail | Skip | Timeout') . "\n";
        UI::separator();

        foreach ($visibleRows as $row) {
            $moduleStr = str_pad((string)$row['module'], 30);
            $fail = (int)$row['fail'];
            $timeout = (int)$row['timeout'];
            $skip = (int)$row['skip'];

            $failStr = $fail > 0 ? UI::failure(sprintf('%4d', $fail)) : sprintf('%4d', $fail);
            $skipStr = $skip > 0 ? UI::warning(sprintf('%4d', $skip)) : sprintf('%4d', $skip);
            $timeoutStr = $timeout > 0 ? UI::warning(sprintf('%7d', $timeout)) : sprintf('%7d', $timeout);

            echo sprintf(
                "%s | %5d | %4d | %s | %s | %s\n",
                $moduleStr,
                (int)$row['total'],
                (int)$row['pass'],
                $failStr,
                $skipStr,
                $timeoutStr
            );
        }

        if ($hiddenHealthy > 0) {
            echo '  ' . UI::gray('+ ' . $hiddenHealthy . ' modules without issues hidden') . "\n";
        }
    }

    public static function printFailures(array $result): void
    {
        $failures = ReportSummary::canonicalFailures($result);
        if ($failures === []) {
            return;
        }

        $visible = array_slice($failures, 0, ConsoleRenderLimits::MAX_FAILURES);
        UI::section('Failed Tests (' . count($failures) . ')');

        foreach ($visible as $failure) {
            $rel = (string)($failure['file'] ?? $failure['test_id'] ?? 'unknown');
            $code = (string)($failure['error_type'] ?? 'fail');
            $phase = (string)($failure['phase'] ?? 'execution');
            $cause = (string)($failure['cause_code'] ?? $code);

            echo '  ' . UI::failure('X') . " {$rel} " . UI::gray("({$code}, phase={$phase}, cause={$cause})") . "\n";
            self::printFailureSnippet($failure);
        }

        $hidden = count($failures) - count($visible);
        if ($hidden > 0) {
            echo '  ' . UI::gray('... ' . $hidden . ' more failures hidden; use ' . CommandSuggestion::report() . ' for full detail') . "\n";
        }
    }

    public static function printDiagnostics(array $diagnostics, bool $compactPassed = false): void
    {
        $phaseCounts = is_array($diagnostics['phase_failure_counts'] ?? null) ? $diagnostics['phase_failure_counts'] : [];
        $causeCounts = is_array($diagnostics['cause_counts'] ?? null) ? $diagnostics['cause_counts'] : [];
        $resource = trim((string)($diagnostics['resource'] ?? ''));
        $lockKey = trim((string)($diagnostics['lock_key'] ?? ''));
        $lockOwnerRunId = trim((string)($diagnostics['lock_owner_run_id'] ?? ''));
        $lockOwnerMetaRunId = trim((string)($diagnostics['lock_owner_meta_run_id'] ?? ''));
        $lockOwnerHost = trim((string)($diagnostics['lock_owner_hostname'] ?? ''));

        if ($compactPassed && !self::hasActionableDiagnostics($diagnostics)) {
            return;
        }

        if ($phaseCounts === [] && $causeCounts === [] && $resource === '' && $lockKey === '') {
            return;
        }

        UI::section('Diagnostics');

        if ($phaseCounts !== []) {
            $parts = [];
            foreach ($phaseCounts as $phase => $count) {
                $parts[] = $phase . '=' . (int)$count;
            }
            echo '  phases: ' . implode(', ', $parts) . "\n";
        }

        if ($causeCounts !== []) {
            $parts = [];
            foreach ($causeCounts as $cause => $count) {
                $parts[] = $cause . '=' . (int)$count;
            }
            echo '  causes: ' . implode(', ', $parts) . "\n";
        }

        if ($resource !== '') {
            echo '  resource: ' . $resource . "\n";
        }

        if ($lockKey !== '') {
            echo '  lock: ' . $lockKey;
            if ($lockOwnerRunId !== '') {
                echo ' owner_run=' . $lockOwnerRunId;
            }
            if ($lockOwnerMetaRunId !== '') {
                echo ' owner_meta=' . $lockOwnerMetaRunId;
            }
            if ($lockOwnerHost !== '') {
                echo ' owner_host=' . $lockOwnerHost;
            }
            echo "\n";
        }
    }

    public static function printTriage(array $result): void
    {
        $failures = ReportSummary::canonicalFailures($result);
        if ($failures === []) {
            return;
        }

        $summary = $result['triage_summary'] ?? null;
        if (!is_array($summary) || $summary === []) {
            $summary = FailureClassifier::summarize($failures, ConsoleRenderLimits::MAX_TRIAGE_GROUPS);
        }

        if ($summary === []) {
            return;
        }

        $visible = array_slice($summary, 0, ConsoleRenderLimits::MAX_TRIAGE_GROUPS);

        UI::section('Triage Summary');
        foreach ($visible as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = (string)($row['label'] ?? $row['family'] ?? 'unknown');
            $count = (int)($row['count'] ?? 0);
            $next = trim((string)($row['next_step'] ?? ''));

            echo '  - ' . UI::warning($label) . " x{$count}\n";

            $examples = is_array($row['examples'] ?? null) ? $row['examples'] : [];
            foreach (array_slice($examples, 0, ConsoleRenderLimits::MAX_TRIAGE_EXAMPLES) as $example) {
                if (!is_array($example)) {
                    continue;
                }

                $file = trim((string)($example['file'] ?? ''));
                $message = trim((string)($example['message'] ?? ''));
                if ($file !== '') {
                    echo '      * ' . $file;
                    if ($message !== '') {
                        echo ' -> ' . $message;
                    }
                    echo "\n";
                }
            }

            if ($next !== '') {
                echo '      ' . UI::gray('action: ' . $next) . "\n";
            }
        }

        $hidden = count($summary) - count($visible);
        if ($hidden > 0) {
            echo '  ' . UI::gray('... ' . $hidden . ' more triage groups hidden') . "\n";
        }
    }

    public static function printSlow(array $result): void
    {
        $slow = $result['slow_tests'] ?? [];
        if (!is_array($slow) || !$slow) {
            return;
        }

        $visible = array_slice($slow, 0, ConsoleRenderLimits::MAX_SLOW_TESTS);

        UI::section('Slow Tests');
        foreach ($visible as $test) {
            echo sprintf(
                '  ' . UI::warning('!') . " %6d ms  %s\n",
                (int)$test['duration_ms'],
                (string)($test['rel'] ?? $test['file'] ?? 'unknown')
            );
        }

        $hidden = count($slow) - count($visible);
        if ($hidden > 0) {
            echo '  ' . UI::gray('... ' . $hidden . ' more slow tests hidden') . "\n";
        }
    }

    public static function printFragility(array $result): void
    {
        $hints = $result['fragility_hints'] ?? [];
        if (!is_array($hints) || !$hints) {
            return;
        }

        $flaky = array_values(array_filter(
            $hints,
            static fn(array $hint): bool => ($hint['type'] ?? '') === 'flaky'
        ));

        if ($flaky === []) {
            return;
        }

        usort(
            $flaky,
            static function (array $left, array $right): int {
                $cmp = (int)($right['fail_count'] ?? 0) <=> (int)($left['fail_count'] ?? 0);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcmp((string)($left['test'] ?? ''), (string)($right['test'] ?? ''));
            }
        );

        $visible = array_slice($flaky, 0, ConsoleRenderLimits::MAX_FRAGILITY);

        UI::section('Possible Flaky Tests (heuristic)');
        foreach ($visible as $hint) {
            echo '  - '
                . (string)$hint['test']
                . ' '
                . UI::gray('(pass=' . (int)($hint['pass_count'] ?? 0) . ', fail=' . (int)($hint['fail_count'] ?? 0) . ')')
                . "\n";
        }

        $hidden = count($flaky) - count($visible);
        if ($hidden > 0) {
            echo '  ' . UI::gray('... ' . $hidden . ' more heuristic hints hidden') . "\n";
        }
    }

    public static function printPerfViolations(array $result): void
    {
        $violations = $result['perf_violations'] ?? [];
        if (!is_array($violations) || !$violations) {
            return;
        }

        $visible = array_slice($violations, 0, ConsoleRenderLimits::MAX_PERF_VIOLATIONS);

        UI::section('Performance Threshold Violations');
        foreach ($visible as $entry) {
            $violation = is_array($entry['perf_violation'] ?? null) ? $entry['perf_violation'] : [];
            echo '  - '
                . (string)($entry['rel'] ?? 'unknown')
                . ' actual=' . (int)($violation['actual_ms'] ?? 0)
                . 'ms max=' . (int)($violation['max_ms'] ?? 0)
                . "ms\n";
        }

        $hidden = count($violations) - count($visible);
        if ($hidden > 0) {
            echo '  ' . UI::gray('... ' . $hidden . ' more perf violations hidden') . "\n";
        }
    }

    public static function hasActionableDiagnostics(array $diagnostics): bool
    {
        $phaseCounts = is_array($diagnostics['phase_failure_counts'] ?? null) ? $diagnostics['phase_failure_counts'] : [];
        $causeCounts = is_array($diagnostics['cause_counts'] ?? null) ? $diagnostics['cause_counts'] : [];

        if ($phaseCounts !== [] || $causeCounts !== []) {
            return true;
        }

        foreach (['lock_owner_run_id', 'lock_owner_meta_run_id', 'lock_owner_hostname'] as $field) {
            if (trim((string)($diagnostics[$field] ?? '')) !== '') {
                return true;
            }
        }

        $cause = trim((string)($diagnostics['cause_code'] ?? ''));
        return in_array($cause, ['shared_store_locked', 'store_resource_locked'], true);
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

    private static function printFailureSnippet(array $failure): void
    {
        foreach (['message', 'assertion', 'trace_excerpt', 'stderr_excerpt', 'stdout_excerpt'] as $field) {
            $snippet = trim((string)($failure[$field] ?? ''));
            if ($snippet === '') {
                continue;
            }

            $allLines = preg_split('/\r\n|\r|\n/', $snippet) ?: [];
            $visibleLines = array_slice($allLines, 0, ConsoleRenderLimits::MAX_FAILURE_LINES);

            foreach ($visibleLines as $line) {
                echo '    ' . UI::gray('|') . ' ' . $line . "\n";
            }

            echo '    ' . UI::gray("+-- ({$field})") . "\n";

            $hidden = count($allLines) - count($visibleLines);
            if ($hidden > 0) {
                echo '    ' . UI::gray('... ' . $hidden . ' more lines') . "\n";
            }

            break;
        }
    }

    private function __construct()
    {
    }
}
