<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

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

    /**
     * @param array<string,mixed> $config
     */
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

    /**
     * @param array<int,array<string,mixed>> $tests
     */
    public static function printList(array $tests): void
    {
        foreach ($tests as $test) {
            echo '  ' . UI::gray((string)$test['rel']) . "\n";
        }
    }

    /**
     * @param array<string,mixed> $result
     */
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

        self::printDiagnostics($diagnostics, $compactPassed);
        self::printWarnings($result);
        self::printSelectionSummary($result);

        if (!$compactPassed) {
            self::printFirstFailure($result);
            self::printDecision($result, $diagnostics);
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

    /**
     * @param array<string,mixed> $snapshot
     */
    public static function printSuiteProgress(array $snapshot): void
    {
        $currentRel = trim((string)($snapshot['current_test_rel'] ?? ''));
        $currentElapsedMs = $currentRel !== '' ? (int)($snapshot['current_elapsed_ms'] ?? 0) : null;
        $avgMs = $snapshot['avg_ms_per_test'] ?? null;
        $etaMs = $snapshot['eta_ms'] ?? null;

        echo UI::info('[Progress]') . ' '
            . 'elapsed=' . self::formatDurationMs((int)($snapshot['elapsed_ms'] ?? 0))
            . ' '
            . 'done=' . (int)($snapshot['completed'] ?? 0) . '/' . (int)($snapshot['total'] ?? 0)
            . ' '
            . 'pass=' . (int)($snapshot['pass'] ?? 0)
            . ' '
            . 'fail=' . (int)($snapshot['fail'] ?? 0)
            . ' '
            . 'skip=' . (int)($snapshot['skip'] ?? 0)
            . ' '
            . 'timeout=' . (int)($snapshot['timeout'] ?? 0)
            . ' '
            . 'current=' . ($currentRel !== '' ? UI::gray($currentRel) : 'n/a')
            . ' '
            . 'current_elapsed=' . ($currentElapsedMs !== null ? self::formatDurationMs($currentElapsedMs) : 'n/a')
            . ' '
            . 'avg=' . self::formatAvgMs(is_int($avgMs) ? $avgMs : null)
            . ' '
            . 'eta=' . (is_int($etaMs) ? self::formatDurationMs($etaMs) : 'n/a')
            . ' '
            . 'jobs=' . (int)($snapshot['jobs'] ?? 1)
            . "\n";
    }

    /**
     * @param array<string,mixed> $warning
     */
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
            . 'rel=' . UI::gray($rel)
            . ' '
            . 'worker=' . (int)($warning['worker'] ?? 0)
            . "\n";
    }

    /**
     * @param array<string,int> $phaseTimings
     */
    public static function printPhaseTimings(array $phaseTimings): void
    {
        UI::section('Phase Timings');
        echo '  discovery_ms=' . (int)($phaseTimings['discovery'] ?? 0) . "\n";
        echo '  admission_ms=' . (int)($phaseTimings['admission'] ?? 0) . "\n";
        echo '  execution_ms=' . (int)($phaseTimings['execution'] ?? 0) . "\n";
        echo '  reporting_ms=' . (int)($phaseTimings['reporting'] ?? 0) . "\n";
    }

    /**
     * @param array<string,mixed> $meta
     */
    public static function printMeta(array $meta): void
    {
        UI::header('META SUMMARY');

        $suites = is_array($meta['suites'] ?? null) ? $meta['suites'] : [];
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

        $summary = is_array($meta['summary'] ?? null) ? $meta['summary'] : [];
        $failedFiles = is_array($meta['failed_files'] ?? null) ? $meta['failed_files'] : [];
        $diagnostics = is_array($meta['diagnostics'] ?? null) ? $meta['diagnostics'] : ReportSummary::diagnostics($meta);

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
        echo '  report_root: ' . UI::gray((string)($meta['report_scope_rel'] ?? $meta['report_root'] ?? '')) . "\n";
        echo '  selected_tests: ' . UI::gray((string)((int)($meta['selected_test_count'] ?? 0))) . ' ' . UI::gray('failed_files=' . count($failedFiles)) . "\n";
    }

    /**
     * @param array<string,mixed> $result
     */
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

    /**
     * @param array<string,mixed> $result
     */
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

    /**
     * @param array<string,mixed> $result
     */
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

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $diagnostics
     */
    private static function printDecision(array $result, array $diagnostics): void
    {
        $agentSummary = is_array($result['agent_summary'] ?? null)
            ? $result['agent_summary']
            : ReportSummary::agentSummary($result, $diagnostics);
        $firstFailure = is_array($result['first_failure'] ?? null)
            ? $result['first_failure']
            : ReportSummary::firstFailure($result);

        UI::section('Decision');

        echo '  status: ' . self::renderOutcome((string)($agentSummary['status'] ?? strtoupper((string)($diagnostics['outcome_status'] ?? 'passed')))) . "\n";
        echo '  primary_problem: ' . UI::gray((string)($agentSummary['primary_problem'] ?? (string)($diagnostics['cause_code'] ?? 'none'))) . "\n";
        echo '  focus: ' . UI::gray(implode(', ', array_values((array)($agentSummary['suggested_focus'] ?? [])))) . "\n";

        if (is_array($firstFailure)) {
            $file = trim((string)($firstFailure['file'] ?? ''));
            if ($file !== '') {
                echo '  failing_file: ' . UI::gray($file) . "\n";
            }
        }

        $reportRoot = trim((string)($result['report_scope_rel'] ?? $result['report_root'] ?? ''));
        if ($reportRoot !== '') {
            echo '  report_root: ' . UI::gray($reportRoot) . "\n";
        }
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $diagnostics
     */
    private static function printRecommendedActions(array $result, array $diagnostics): void
    {
        $actions = is_array($result['recommended_actions'] ?? null)
            ? array_values(array_filter($result['recommended_actions'], 'is_array'))
            : ReportSummary::recommendedActions($result, $diagnostics);

        if ($actions === []) {
            return;
        }

        UI::section('Recommended Actions');
        foreach (array_slice($actions, 0, self::MAX_ACTIONS) as $action) {
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
     * @param array<string,mixed> $result
     */
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

    /**
     * @param array<string,mixed> $result
     */
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

        UI::section('Module Summary');
        echo UI::gray(str_pad('Module', 30) . ' | Total | Pass | Fail | Skip | Timeout') . "\n";
        UI::separator();

        foreach ($rows as $row) {
            $moduleStr = str_pad((string)$row['module'], 30);
            $fail = (int)$row['fail'];
            $timeout = (int)$row['timeout'];

            $failStr = $fail > 0 ? UI::failure(sprintf('%4d', $fail)) : sprintf('%4d', $fail);
            $timeoutStr = $timeout > 0 ? UI::warning(sprintf('%7d', $timeout)) : sprintf('%7d', $timeout);

            echo sprintf(
                "%s | %5d | %4d | %s | %4d | %s\n",
                $moduleStr,
                (int)$row['total'],
                (int)$row['pass'],
                $failStr,
                (int)$row['skip'],
                $timeoutStr
            );
        }
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
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

    /**
     * @param array<string,mixed> $result
     */
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
            echo '  ' . UI::gray('... ' . $hidden . ' more failures hidden; use php scripts/report.php for full detail') . "\n";
        }

        $first = $failures[0];
        $firstFile = (string)($first['file'] ?? $first['test_id'] ?? '');
        if ($firstFile !== '') {
            $suiteId = (string)($result['suite_id'] ?? '');
            $target = str_replace('_', '-', $suiteId);

            UI::section('Next Step');
            echo '  isolate first failing file: ' . UI::info("TEST_MATCH='{$firstFile}' php runTest.php {$target}") . "\n";
            echo '  full aggregated report: ' . UI::info('php scripts/report.php') . "\n";
        }
    }

    /**
     * @param array<string,mixed> $failure
     */
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

    /**
     * @param array<string,mixed> $diagnostics
     */
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

    /**
     * @param array<string,mixed> $result
     */
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

    /**
     * @param array<string,mixed> $result
     */
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

    /**
     * @param array<string,mixed> $result
     */
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

        UI::section('Fragility Hints');
        foreach ($visible as $hint) {
            echo '  - flaky: '
                . (string)$hint['test']
                . ' (pass=' . (int)($hint['pass_count'] ?? 0)
                . ', fail=' . (int)($hint['fail_count'] ?? 0)
                . ")\n";
        }

        $hidden = count($flaky) - count($visible);
        if ($hidden > 0) {
            echo '  ' . UI::gray('... ' . $hidden . ' more fragility hints hidden') . "\n";
        }
    }

    /**
     * @param array<string,mixed> $result
     */
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

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $diagnostics
     */
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

    /**
     * @param array<string,mixed> $result
     */
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

    /**
     * @param array<string,mixed> $diagnostics
     */
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
}
