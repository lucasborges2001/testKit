<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Console;

use Testkit\Core\Reporting\StructuredWarnings;
use Testkit\Core\Reporting\UI;

final class IssueWarningsRenderer
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

    private function __construct()
    {
    }
}
