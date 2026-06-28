<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Console;

use Testkit\Core\Reporting\FailureClassifier;
use Testkit\Core\Reporting\ReportSummary;
use Testkit\Core\Reporting\UI;

final class IssueTriageRenderer
{
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

    private function __construct()
    {
    }
}
