<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Console;

use Testkit\Core\Reporting\CommandSuggestion;
use Testkit\Core\Reporting\ReportSummary;
use Testkit\Core\Reporting\UI;

final class IssueFailureRenderer
{
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
