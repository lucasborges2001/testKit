<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Console;

use Testkit\Core\Reporting\ReportSummary;
use Testkit\Core\Reporting\UI;

final class IssueRegressionRenderer
{
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

    private function __construct()
    {
    }
}
