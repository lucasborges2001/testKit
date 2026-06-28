<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Console;

use Testkit\Core\Reporting\ReportSummary;
use Testkit\Core\Reporting\UI;

final class IssueSelectionRenderer
{
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

    private function __construct()
    {
    }
}
