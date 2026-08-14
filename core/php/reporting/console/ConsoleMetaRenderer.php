<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Console;

use Testkit\Core\Reporting\CommandSuggestion;
use Testkit\Core\Reporting\CompactBatchReporter;
use Testkit\Core\Reporting\ConsoleMode;
use Testkit\Core\Reporting\ReportSummary;
use Testkit\Core\Reporting\UI;

final class ConsoleMetaRenderer
{
    public static function printMeta(array $meta): void
    {
        $suites = is_array($meta['suites'] ?? null) ? $meta['suites'] : [];
        $summary = is_array($meta['summary'] ?? null) ? $meta['summary'] : [];
        $failedFiles = array_values(array_filter((array)($meta['failed_files'] ?? []), 'is_string'));
        $diagnostics = is_array($meta['diagnostics'] ?? null) ? $meta['diagnostics'] : ReportSummary::diagnostics($meta);

        if (ConsoleMode::isCompact() && self::isCleanPass($suites, $summary, $failedFiles, $diagnostics)) {
            $total = (int)($summary['total'] ?? $meta['selected_test_count'] ?? 0);
            $passed = (int)($summary['passed'] ?? $total);
            CompactBatchReporter::printCheck([
                'label' => 'meta ' . str_replace('_', '-', (string)($meta['target'] ?? 'all')),
                'total' => $total,
                'passed' => $passed,
                'failed' => 0,
                'skipped' => 0,
                'duration_ms' => (int)($summary['duration_ms'] ?? $meta['duration_ms'] ?? 0),
            ]);
            return;
        }

        UI::header('META SUMMARY');
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
        echo '  outcome: ' . ConsoleTableFormatter::renderOutcome(strtoupper((string)($diagnostics['outcome_status'] ?? 'passed'))) . ' ' . UI::gray('phase=' . (string)($diagnostics['primary_phase'] ?? 'none') . ' cause=' . (string)($diagnostics['cause_code'] ?? 'none')) . "\n";
        echo '  selected_tests: ' . UI::gray((string)((int)($meta['selected_test_count'] ?? 0))) . ' ' . UI::gray('failed_files=' . count($failedFiles)) . "\n";
    }

    /** @param array<int,array<string,mixed>> $suites @param array<int,string> $failedFiles */
    private static function isCleanPass(array $suites, array $summary, array $failedFiles, array $diagnostics): bool
    {
        $outcome = strtoupper(trim((string)($diagnostics['outcome_status'] ?? '')));
        foreach ($suites as $suite) {
            if ((int)($suite['exit_code'] ?? 1) !== 0) {
                return false;
            }
        }
        return in_array($outcome, ['PASSED', 'PASS', 'OK'], true)
            && (int)($summary['failed'] ?? 0) === 0
            && (int)($summary['skipped'] ?? 0) === 0
            && $failedFiles === [];
    }

    /** @param array<int,array<string,mixed>> $suites @param array<int,string> $failedFiles */
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
        echo '  status: ' . ConsoleTableFormatter::renderOutcome(strtoupper((string)($diagnostics['outcome_status'] ?? 'passed'))) . "\n";
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

    /** @param array<int,array<string,mixed>> $suites @param array<int,array<string,mixed>> $failedSuites */
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
        if ($isBack && str_ends_with($normalized, '.py')) return 'back-python';
        if ($isBack) return 'back-php';
        if ($isFront && str_ends_with($normalized, '.js')) return 'front-js';
        if ($isFront) return 'front-php';
        return '';
    }

    private function __construct()
    {
    }
}
