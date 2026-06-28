<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Console;

use Testkit\Core\Reporting\UI;

final class IssueModuleSummaryRenderer
{
    public static function shouldPrint(array $result, bool $compactPassed): bool
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

    private function __construct()
    {
    }
}
