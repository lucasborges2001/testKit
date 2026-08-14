<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

require_once __DIR__ . '/ConsoleMode.php';
require_once __DIR__ . '/CompactBatchReporter.php';
require_once __DIR__ . '/console/ConsoleRenderLimits.php';
require_once __DIR__ . '/console/ConsoleTableFormatter.php';
require_once __DIR__ . '/console/ConsoleProgressRenderer.php';
require_once __DIR__ . '/console/ConsoleIssueRenderer.php';
require_once __DIR__ . '/console/ConsoleMetaRenderer.php';

use Testkit\Core\Reporting\Console\ConsoleIssueRenderer;
use Testkit\Core\Reporting\Console\ConsoleMetaRenderer;
use Testkit\Core\Reporting\Console\ConsoleProgressRenderer;
use Testkit\Core\Reporting\Console\ConsoleTableFormatter;

final class ConsoleReporter
{
    public static function printSuiteStart(array $config, int $testsCount): void
    {
        if (ConsoleMode::isCompact()) {
            return;
        }

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

        if (ConsoleMode::isCompact() && $compactPassed) {
            CompactBatchReporter::printCheck([
                'label' => str_replace('_', '-', (string)($result['suite_id'] ?? 'suite')),
                'total' => $pass,
                'passed' => $pass,
                'failed' => 0,
                'skipped' => 0,
                'duration_ms' => $duration,
            ]);
            ConsoleIssueRenderer::printWarnings($result);
            ConsoleIssueRenderer::printSlow($result);
            return;
        }

        $p = UI::success('PASS=' . $pass);
        $f = $fail > 0 ? UI::failure('FAIL=' . $fail) : UI::gray('FAIL=' . $fail);
        $s = $skip > 0 ? UI::warning('SKIP=' . $skip) : UI::gray('SKIP=' . $skip);
        $t = $timeout > 0 ? UI::warning('TIMEOUT=' . $timeout) : UI::gray('TIMEOUT=' . $timeout);

        UI::section('Result');
        echo '  summary: ' . $p . ' ' . $f . ' ' . $s . ' ' . $t . ' ' . UI::gray('time_ms=' . $duration) . "\n";
        echo '  outcome: ' . ConsoleTableFormatter::renderOutcome($outcome) . ' ' . UI::gray("phase={$phase} domain={$domain} cause={$cause}") . "\n";

        if (array_key_exists('evidence_valid', $result) && (bool)($result['evidence_valid'] ?? true) === false) {
            $reason = trim((string)($result['evidence_invalid_reason'] ?? 'runner_exception'));
            echo '  evidence: ' . UI::failure('invalid') . ' ' . UI::gray('reason=' . $reason) . "\n";
        }

        if (!$compactPassed) {
            ConsoleIssueRenderer::printOperatorSummary($result, $diagnostics);
        }

        ConsoleIssueRenderer::printDiagnostics($diagnostics, $compactPassed);
        ConsoleIssueRenderer::printWarnings($result);
        ConsoleIssueRenderer::printSelectionSummary($result);

        if (!$compactPassed) {
            ConsoleIssueRenderer::printFirstFailure($result);
            ConsoleIssueRenderer::printRecommendedActions($result, $diagnostics);
            ConsoleIssueRenderer::printRegressionDelta($result);
        }

        if (ConsoleIssueRenderer::shouldPrintModuleSummary($result, $compactPassed)) {
            ConsoleIssueRenderer::printModuleSummary($result);
        }

        if (!$compactPassed) {
            ConsoleIssueRenderer::printFailures($result);
            ConsoleIssueRenderer::printTriage($result);
        }

        ConsoleIssueRenderer::printSlow($result);

        if (!$compactPassed) {
            ConsoleIssueRenderer::printFragility($result);
        }

        ConsoleIssueRenderer::printPerfViolations($result);
    }

    public static function printSuiteProgress(array $snapshot): void
    {
        ConsoleProgressRenderer::printSuiteProgress($snapshot);
    }

    public static function printPerTestProgress(array $snapshot): void
    {
        ConsoleProgressRenderer::printPerTestProgress($snapshot);
    }

    public static function printLongRunningTest(array $warning): void
    {
        ConsoleProgressRenderer::printLongRunningTest($warning);
    }

    public static function printPhaseTimings(array $phaseTimings): void
    {
        ConsoleProgressRenderer::printPhaseTimings($phaseTimings);
    }

    public static function printMeta(array $meta): void
    {
        ConsoleMetaRenderer::printMeta($meta);
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
}
