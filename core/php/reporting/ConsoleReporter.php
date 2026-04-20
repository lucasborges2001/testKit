<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Env;

final class ConsoleReporter
{
    private const MAX_FAILURES = 8;
    private const MAX_FAILURE_LINES = 4;
    private const MAX_SLOW_TESTS = 5;
    private const MAX_FRAGILITY = 5;
    private const MAX_TRIAGE_GROUPS = 4;
    private const MAX_TRIAGE_EXAMPLES = 1;
    private const MAX_PERF_VIOLATIONS = 5;
    private const MAX_ACTIONS = 4;
    private const MAX_REGRESSION_ITEMS = 3;
    private const MAX_PROGRESS_CURRENT_LEN = 28;
    private const MAX_PROGRESS_WORKER_PATH_LEN = 18;
    private const MAX_PROGRESS_WORKERS_LEN = 84;
    private const MAX_WARNING_REL_LEN = 40;
    private const MAX_TEST_REL_LEN = 28;

    public static function printSuiteStart(array $config, int $testsCount): void
    {
        $name = strtoupper(str_replace('_', ' ', (string)$config['suite_id']));
        UI::header($name);
        UI::section('Selection');

        echo '  tests: ' . UI::bold((string)$testsCount) . "\n";
        echo '  filters: '
            . UI::gray('scope=' . (string)$config['scope'])
            . ' '
            . UI::gray('category=' . (string)$config['category'])
            . ' '
            . UI::gray('fail_fast=' . ((bool)$config['fail_fast'] ? '1' : '0'))
            . ' '
            . UI::gray('jobs=' . (string)$config['jobs'])
            . "\n";

        if ((bool)$config['coverage']) {
            echo '  coverage: '
                . UI::success('on')
                . ' '
                . UI::gray('format=' . (string)$config['coverage_format'])
                . ' '
                . UI::gray('dir=' . (string)$config['coverage_dir'])
                . "\n";
        }

        if ((string)$config['match'] !== '') {
            echo '  match: ' . UI::info((string)$config['match']) . "\n";
        }
    }

    public static function printList(array $tests): void
    {
        foreach ($tests as $test) {
            echo '  ' . UI::gray((string)$test['rel']) . "\n";
        }
    }

    public static function printSuiteResult(array $result): void
    {
        $pass = (int)($result['pass'] ?? 0);
        $fail = (int)($result['fail'] ?? 0);
        $skip = (int)($result['skip'] ?? 0);
        $timeout = (int)($result['timeout'] ?? ($result['status_counts']['timeout'] ?? 0));
        $duration = (int)($result['duration_ms'] ?? 0);
        $diagnostics = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : ReportSummary::diagnostics($result);
        $outcome = strtoupper((string)($diagnostics['outcome_status'] ?? 'passed'));
        $phase = (string)($diagnostics['primary_phase'] ?? 'none');
        $domain = (string)($diagnostics['failure_domain'] ?? 'none');
        $cause = (string)($diagnostics['cause_code'] ?? 'none');
        $compactPassed = self::shouldUseCompactPassView($result, $diagnostics);

        $p = UI::success('PASS=' . $pass);
        $f = $fail > 0 ? UI::failure('FAIL=' . $fail) : UI::gray('FAIL=' . $fail);
        $s = $skip > 0 ? UI::warning('SKIP=' . $skip) : UI::gray('SKIP=' . $skip);
        $t = $timeout > 0 ? UI::warning('TIMEOUT=' . $timeout) : UI::gray('TIMEOUT=' . $timeout);

        UI::section('Result');
        echo '  summary: ' . $p . ' ' . $f . ' ' . $s . ' ' . $t . ' ' . UI::gray('time_ms=' . $duration) . "\n";
        echo '  outcome: ' . self::renderOutcome($outcome) . ' ' . UI::gray("phase={$phase} domain={$domain} cause={$cause}") . "\n";

        if (array_key_exists('evidence_valid', $result) && (bool)($result['evidence_valid'] ?? true) === false) {
            $reason = trim((string)($result['evidence_invalid_reason'] ?? 'runner_exception'));
            echo '  evidence: ' . UI::failure('invalid') . ' ' . UI::gray('reason=' . $reason) . "\n";
        }

        if (!$compactPassed) {
            self::printOperatorSummary($result, $diagnostics);
        }

        self::printDiagnostics($diagnostics, $compactPassed);
        self::printWarnings($result);
        self::printSelectionSummary($result);

        if (!$compactPassed) {
            self::printFirstFailure($result);
            self::printRecommendedActions($result, $diagnostics);
            self::printRegressionDelta($result);
        }

        if (self::shouldPrintModuleSummary($result, $compactPassed)) {
            self::printModuleSummary($result);
        }

        if (!$compactPassed) {
            self::printFailures($result);
            self::printTriage($result);
        }

        self::printSlow($result);

        if (!$compactPassed) {
            self::printFragility($result);
        }

        self::printPerfViolations($result);
    }

    public static function printSuiteProgress(array $snapshot): void
    {
        $avgMs = $snapshot['avg_ms_per_test'] ?? null;
        $etaMs = $snapshot['eta_ms'] ?? null;
        $jobs = (int)($snapshot['jobs'] ?? 1);

        $line = UI::info('[Progress]') . ' '
            . 'el=' . self::formatDurationMs((int)($snapshot['elapsed_ms'] ?? 0))
            . ' '
            . 'done=' . (int)($snapshot['completed'] ?? 0) . '/' . (int)($snapshot['total'] ?? 0)
            . ' '
            . 'p/f/s/to=' . (int)($snapshot['pass'] ?? 0)
            . '/' . (int)($snapshot['fail'] ?? 0)
            . '/' . (int)($snapshot['skip'] ?? 0)
            . '/' . (int)($snapshot['timeout'] ?? 0)
            . ' '
            . 'eta=' . (is_int($etaMs) ? self::formatDurationMs($etaMs) : 'n/a');

        if (self::progressDetail() === 'verbose') {
            $currentRel = trim((string)($snapshot['current_test_rel'] ?? ''));
            $currentElapsedMs = $currentRel !== '' ? (int)($snapshot['current_elapsed_ms'] ?? 0) : null;
            $currentLabel = $currentRel !== ''
                ? UI::gray(self::formatProgressPath($currentRel, self::MAX_PROGRESS_CURRENT_LEN))
                : 'n/a';

            $line .= ' '
                . 'cur=' . $currentLabel
                . ' '
                . 'cur_el=' . ($currentElapsedMs !== null ? self::formatDurationMs($currentElapsedMs) : 'n/a')
                . ' '
                . 'avg=' . self::formatAvgMs(is_int($avgMs) ? $avgMs : null)
                . ' '
                . 'jobs=' . $jobs;

            $workers = self::formatWorkersSummary($snapshot['workers'] ?? []);
            if ($workers !== '') {
                $line .= ' workers=' . UI::gray($workers);
            }
        } elseif ($jobs > 1) {
            $line .= ' jobs=' . $jobs;
        }

        echo $line . "\n";
    }

    public static function printPerTestProgress(array $snapshot): void
    {
        $rel = trim((string)($snapshot['rel'] ?? ''));
        $status = strtoupper(trim((string)($snapshot['status'] ?? 'done')));
        $status = $status !== '' ? $status : 'DONE';
        $jobs = (int)($snapshot['jobs'] ?? 1);

        $line = UI::info('[Test]') . ' '
            . 'status=' . self::renderTestStatus($status)
            . ' '
            . 'done=' . (int)($snapshot['completed'] ?? 0) . '/' . (int)($snapshot['total'] ?? 0)
            . ' '
            . 'dur=' . self::formatDurationMs((int)($snapshot['duration_ms'] ?? 0))
            . ' '
            . 'rel=' . UI::gray(self::formatProgressPath($rel, self::MAX_TEST_REL_LEN));

        if ($jobs > 1) {
            $line .= ' worker=' . (int)($snapshot['worker'] ?? 0);
        }

        if (self::progressDetail() === 'verbose') {
            $line .= ' '
                . 'el=' . self::formatDurationMs((int)($snapshot['elapsed_ms'] ?? 0))
                . ' '
                . 'p/f/s/to=' . (int)($snapshot['pass'] ?? 0)
                . '/' . (int)($snapshot['fail'] ?? 0)
                . '/' . (int)($snapshot['skip'] ?? 0)
                . '/' . (int)($snapshot['timeout'] ?? 0)
                . ' '
                . 'jobs=' . $jobs;

            $workers = self::formatWorkersSummary($snapshot['workers'] ?? []);
            if ($workers !== '') {
                $line .= ' active=' . UI::gray($workers);
            }
        }

        echo $line . "\n";
    }

    public static function printLongRunningTest(array $warning): void
    {
        $rel = trim((string)($warning['rel'] ?? ''));
        if ($rel === '') {
            return;
        }

        echo UI::warning('[WARN]') . ' '
            . 'long_running_test'
            . ' '
            . 'elapsed=' . self::formatDurationMs((int)($warning['elapsed_ms'] ?? 0))
            . ' '
            . 'rel=' . UI::gray(self::formatProgressPath($rel, self::MAX_WARNING_REL_LEN))
            . ' '
            . 'worker=' . (int)($warning['worker'] ?? 0)
            . "\n";
    }

    public static function printPhaseTimings(array $phaseTimings): void
    {
        UI::section('Phase Timings');
        echo '  discovery_ms=' . (int)($phaseTimings['discovery'] ?? 0) . "\n";
        echo '  admission_ms=' . (int)($phaseTimings['admission'] ?? 0) . "\n";
        echo '  execution_ms=' . (int)($phaseTimings['execution'] ?? 0) . "\n";
        echo '  reporting_ms=' . (int)($phaseTimings['reporting'] ?? 0) . "\n";
    }

    public static function printMeta(array $meta): void
    {
        UI::header('META SUMMARY');

        $suites = is_array($meta['suites'] ?? null) ? $meta['suites'] : [];
        $summary = is_array($meta['summary'] ?? null) ? $meta['summary'] : [];
        $failedFiles = array_values(array_filter((array)($meta['failed_files'] ?? []), 'is_string'));
        $diagnostics = is_array($meta['diagnostics'] ?? null) ? $meta['diagnostics'] : ReportSummary::diagnostics($meta);

        self::printMetaSummary($meta, $suites, $failedFiles, $diagnostics);

        if ($suites !== []) {
            UI::section('Suites');
            foreach ($suites as $suite) {
                $name = (string)($suite['suite_id'] ?? 'suite');
                $code = (int)($suite['exit_code'] ?? 1);
                $status = $code === 0 ? UI::success('OK') : ($code === 2 ? UI::warning('SKIP') : UI::failure('FAIL'));
                $tests = (int)($suite['selected_test_count'] ?? 0);
                $scope = trim((string)($suite['selected_module_scope'] ?? ''));
                $scopeLabel = $scope !== '' ? $scope : 'global';
                echo '  ' . str_pad($name, 24) . ' -> ' . $status . ' ' . UI::gray("(code={$code}, tests={$tests}, scope={$scopeLabel})") . "\n";
            }
        }

        UI::section('Meta');
        echo '  totals: '
            . UI::gray('total=' . (int)($summary['total'] ?? 0))
            . ' '
            . UI::gray('pass=' . (int)($summary['passed'] ?? 0))
            . ' '
            . ((int)($summary['failed'] ?? 0) > 0 ? UI::failure('fail=' . (int)($summary['failed'] ?? 0)) : UI::gray('fail=' . (int)($summary['failed'] ?? 0)))
            . ' '
            . UI::gray('skip=' . (int)($summary['skipped'] ?? 0))
            . ' '
            . UI::gray('time_ms=' . (int)($summary['duration_ms'] ?? $meta['duration_ms'] ?? 0))
            . "\n";
        echo '  outcome: ' . self::renderOutcome(strtoupper((string)($diagnostics['outcome_status'] ?? 'passed'))) . ' ' . UI::gray('phase=' . (string)($diagnostics['primary_phase'] ?? 'none') . ' cause=' . (string)($diagnostics['cause_code'] ?? 'none')) . "\n";
        echo '  selected_tests: ' . UI::gray((string)((int)($meta['selected_test_count'] ?? 0))) . ' ' . UI::gray('failed_files=' . count($failedFiles)) . "\n";
    }

    private static function printWarnings(array $result): void
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

    private static function printSelectionSummary(array $result): void
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

    private static function printFirstFailure(array $result): void
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

    private static function printOperatorSummary(array $result, array $diagnostics): void
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
        echo '  status: ' . self::renderOutcome((string)($agentSummary['status'] ?? strtoupper((string)($diagnostics['outcome_status'] ?? 'passed')))) . "\n";
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

    private static function printRecommendedActions(array $result, array $diagnostics): void
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
        foreach (array_slice($remaining, 0, self::MAX_ACTIONS) as $action) {
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

    private static function printRegressionDelta(array $result): void
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
            foreach (array_slice($newFailures, 0, self::MAX_REGRESSION_ITEMS) as $file) {
                echo '    - ' . $file . "\n";
            }
        }

        if ($resolvedFailures !== []) {
            echo '  resolved_failures: ' . UI::success((string)count($resolvedFailures)) . "\n";
            foreach (array_slice($resolvedFailures, 0, self::MAX_REGRESSION_ITEMS) as $file) {
                echo '    - ' . $file . "\n";
            }
        }

        if ($transitions !== []) {
            echo '  status_transitions: ' . UI::warning((string)count($transitions)) . "\n";
            foreach (array_slice($transitions, 0, self::MAX_REGRESSION_ITEMS) as $row) {
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

    private static function printModuleSummary(array $result): void
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

        usort($rows, [self::class, 'compareModuleSummaryRows']);

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

    private static function compareModuleSummaryRows(array $left, array $right): int
    {
        foreach (['fail', 'timeout', 'skip', 'total'] as $field) {
            $cmp = (int)$right[$field] <=> (int)$left[$field];
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return strcmp((string)$left['module'], (string)$right['module']);
    }

    private static function printFailures(array $result): void
    {
        $failures = ReportSummary::canonicalFailures($result);
        if ($failures === []) {
            return;
        }

        $visible = array_slice($failures, 0, self::MAX_FAILURES);
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

    private static function printFailureSnippet(array $failure): void
    {
        foreach (['message', 'assertion', 'trace_excerpt', 'stderr_excerpt', 'stdout_excerpt'] as $field) {
            $snippet = trim((string)($failure[$field] ?? ''));
            if ($snippet === '') {
                continue;
            }

            $allLines = preg_split('/\r\n|\r|\n/', $snippet) ?: [];
            $visibleLines = array_slice($allLines, 0, self::MAX_FAILURE_LINES);

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

    private static function printDiagnostics(array $diagnostics, bool $compactPassed = false): void
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

    private static function printTriage(array $result): void
    {
        $failures = ReportSummary::canonicalFailures($result);
        if ($failures === []) {
            return;
        }

        $summary = $result['triage_summary'] ?? null;
        if (!is_array($summary) || $summary === []) {
            $summary = FailureClassifier::summarize($failures, self::MAX_TRIAGE_GROUPS);
        }

        if ($summary === []) {
            return;
        }

        $visible = array_slice($summary, 0, self::MAX_TRIAGE_GROUPS);

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
            foreach (array_slice($examples, 0, self::MAX_TRIAGE_EXAMPLES) as $example) {
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

    private static function printSlow(array $result): void
    {
        $slow = $result['slow_tests'] ?? [];
        if (!is_array($slow) || !$slow) {
            return;
        }

        $visible = array_slice($slow, 0, self::MAX_SLOW_TESTS);

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

    private static function printFragility(array $result): void
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

        $visible = array_slice($flaky, 0, self::MAX_FRAGILITY);

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

    private static function printPerfViolations(array $result): void
    {
        $violations = $result['perf_violations'] ?? [];
        if (!is_array($violations) || !$violations) {
            return;
        }

        $visible = array_slice($violations, 0, self::MAX_PERF_VIOLATIONS);

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

    private static function shouldUseCompactPassView(array $result, array $diagnostics): bool
    {
        $outcome = strtoupper(trim((string)($diagnostics['outcome_status'] ?? '')));
        $fail = (int)($result['fail'] ?? 0);
        $skip = (int)($result['skip'] ?? 0);
        $timeout = (int)($result['timeout'] ?? ($result['status_counts']['timeout'] ?? 0));
        $perfViolations = is_array($result['perf_violations'] ?? null) ? count($result['perf_violations']) : 0;
        $evidenceValid = (bool)($result['evidence_valid'] ?? true);

        return in_array($outcome, ['PASSED', 'PASS', 'OK'], true)
            && $fail === 0
            && $skip === 0
            && $timeout === 0
            && $perfViolations === 0
            && $evidenceValid;
    }

    private static function shouldPrintModuleSummary(array $result, bool $compactPassed): bool
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

    private static function hasActionableDiagnostics(array $diagnostics): bool
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

    private static function renderOutcome(string $outcome): string
    {
        $normalized = strtoupper(trim($outcome));

        if (in_array($normalized, ['PASSED', 'PASS', 'OK'], true)) {
            return UI::success($normalized);
        }

        if (in_array($normalized, ['TIMEOUT', 'BLOCKED', 'PARTIAL', 'SKIPPED', 'SKIP', 'NO_TESTS', 'LISTED'], true)) {
            return UI::warning($normalized);
        }

        return UI::failure($normalized);
    }

    private static function renderTestStatus(string $status): string
    {
        if (in_array($status, ['PASS', 'DONE'], true)) {
            return UI::success($status);
        }

        if (in_array($status, ['SKIP', 'SKIPPED', 'TIMEOUT'], true)) {
            return UI::warning($status);
        }

        return UI::failure($status);
    }

    private static function formatDurationMs(int $durationMs): string
    {
        $totalSeconds = max(0, (int)floor($durationMs / 1000));
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $seconds = $totalSeconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    private static function formatAvgMs(?int $avgMs): string
    {
        if ($avgMs === null) {
            return 'n/a';
        }

        return sprintf('%.1fs/test', max(0, $avgMs) / 1000);
    }

    private static function formatProgressPath(string $rel, int $maxLen): string
    {
        $normalized = trim(str_replace('\\', '/', $rel));
        if ($normalized === '') {
            return '';
        }

        if (strlen($normalized) <= $maxLen) {
            return $normalized;
        }

        $parts = array_values(array_filter(explode('/', $normalized), static fn(string $part): bool => $part !== ''));
        if (count($parts) >= 4) {
            $variants = [
                implode('/', array_merge(array_slice($parts, 0, 2), ['...'], array_slice($parts, -2))),
                implode('/', array_merge([$parts[0], '...'], array_slice($parts, -2))),
                implode('/', array_merge(['...'], array_slice($parts, -2))),
            ];

            foreach ($variants as $variant) {
                if (strlen($variant) <= $maxLen) {
                    return $variant;
                }
            }

            return self::truncateMiddle((string)$variants[count($variants) - 1], $maxLen);
        }

        return self::truncateMiddle($normalized, $maxLen);
    }

    private static function formatWorkersSummary(mixed $workersValue): string
    {
        if (!is_array($workersValue) || $workersValue === []) {
            return '';
        }

        $rows = [];
        foreach ($workersValue as $worker) {
            if (!is_array($worker)) {
                continue;
            }

            $workerId = (int)($worker['worker'] ?? 0);
            if ($workerId <= 0) {
                continue;
            }

            $rows[] = [
                'worker' => $workerId,
                'rel' => (string)($worker['rel'] ?? ''),
                'elapsed_ms' => max(0, (int)($worker['elapsed_ms'] ?? 0)),
            ];
        }

        if ($rows === []) {
            return '';
        }

        usort($rows, static fn(array $left, array $right): int => ((int)$left['worker']) <=> ((int)$right['worker']));

        $pieces = [];
        $hidden = max(0, count($rows) - 3);
        foreach (array_slice($rows, 0, 3) as $row) {
            $pieces[] = 'w' . (int)$row['worker']
                . ':' . self::formatProgressPath((string)$row['rel'], self::MAX_PROGRESS_WORKER_PATH_LEN)
                . '@' . self::formatDurationMs((int)$row['elapsed_ms']);
        }

        if ($hidden > 0) {
            $pieces[] = '+' . $hidden;
        }

        return self::truncateMiddle(implode(', ', $pieces), self::MAX_PROGRESS_WORKERS_LEN);
    }

    private static function truncateMiddle(string $value, int $maxLen): string
    {
        if ($maxLen <= 0 || strlen($value) <= $maxLen) {
            return $value;
        }

        if ($maxLen <= 3) {
            return substr($value, 0, $maxLen);
        }

        $keepLeft = intdiv($maxLen - 3, 2);
        $keepRight = ($maxLen - 3) - $keepLeft;

        return substr($value, 0, $keepLeft) . '...' . substr($value, -$keepRight);
    }

    private static function progressDetail(): string
    {
        return strtolower(Env::string('TESTKIT_PROGRESS_DETAIL', 'compact')) === 'verbose'
            ? 'verbose'
            : 'compact';
    }

    /**
     * @return array<int,array{command:string,reason:string}>
     */
    private static function collectRecommendedActions(array $result, array $diagnostics): array
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
    private static function primaryActionCommand(array $actions): string
    {
        if ($actions === []) {
            return '';
        }

        return trim((string)($actions[0]['command'] ?? ''));
    }

    /**
     * @param array<int,array<string,mixed>> $suites
     * @param array<int,string> $failedFiles
     */
    private static function printMetaSummary(array $meta, array $suites, array $failedFiles, array $diagnostics): void
    {
        $failedSuites = array_values(array_filter(
            $suites,
            static fn(array $suite): bool => (int)($suite['exit_code'] ?? 0) !== 0
        ));
        $firstFailedSuite = $failedSuites[0] ?? null;
        $firstFailedFile = $failedFiles[0] ?? '';
        $focusSuite = is_array($firstFailedSuite) ? trim((string)($firstFailedSuite['suite_id'] ?? '')) : '';
        $focusFile = trim($firstFailedFile);
        $reportRoot = trim((string)($meta['report_scope_rel'] ?? $meta['report_root'] ?? ''));
        $nextAction = '';

        if ($focusFile !== '') {
            $target = self::guessTargetFromTestPath($focusFile);
            if ($target !== '') {
                $nextAction = CommandSuggestion::rerun($target, $focusFile);
            }
        }

        UI::section('Operator Summary');
        echo '  status: ' . self::renderOutcome(strtoupper((string)($diagnostics['outcome_status'] ?? 'passed'))) . "\n";
        echo '  failing_suites: ' . UI::gray(count($failedSuites) . '/' . max(1, count($suites))) . "\n";
        if ($focusSuite !== '') {
            echo '  focus_suite: ' . UI::gray($focusSuite) . "\n";
        }
        if ($focusFile !== '') {
            echo '  focus_file: ' . UI::gray($focusFile) . "\n";
        }
        if ($nextAction !== '') {
            echo '  next_action: ' . UI::info($nextAction) . "\n";
        }
        if ($reportRoot !== '') {
            echo '  report_root: ' . UI::gray($reportRoot) . "\n";
        }
        if (self::shouldSuggestConcreteSuite($suites, $failedSuites)) {
            echo '  hint: ' . UI::gray('start with one concrete suite before re-running the aggregate target') . "\n";
        }
    }

    /**
     * @param array<int,array<string,mixed>> $suites
     * @param array<int,array<string,mixed>> $failedSuites
     */
    private static function shouldSuggestConcreteSuite(array $suites, array $failedSuites): bool
    {
        return count($suites) > 1 && $failedSuites !== [];
    }

    private static function guessTargetFromTestPath(string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path));
        if ($normalized === '') {
            return '';
        }

        $isBack = str_starts_with($normalized, 'test/back/');
        $isFront = str_starts_with($normalized, 'test/front/');

        if ($isBack && str_ends_with($normalized, '.py')) {
            return 'back-python';
        }
        if ($isBack) {
            return 'back-php';
        }
        if ($isFront && str_ends_with($normalized, '.js')) {
            return 'front-js';
        }
        if ($isFront) {
            return 'front-php';
        }

        return '';
    }
}
