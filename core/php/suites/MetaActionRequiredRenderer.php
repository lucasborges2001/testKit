<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Testkit\Core\Reporting\ReportSummary;

final class MetaActionRequiredRenderer
{
    /**
     * @param array<string,mixed> $meta
     */
    public static function render(array $meta): void
    {
        $failedSuites = self::failedSuites($meta);
        $delta = is_array($meta['regression_delta'] ?? null)
            ? $meta['regression_delta']
            : ReportSummary::regressionDelta($meta);
        $actions = is_array($meta['recommended_actions'] ?? null)
            ? array_values(array_filter($meta['recommended_actions'], 'is_array'))
            : ReportSummary::recommendedActions($meta);
        $firstFailure = is_array($meta['first_failure'] ?? null)
            ? $meta['first_failure']
            : ReportSummary::firstFailure($meta);

        $newFailures = count(array_filter((array)($delta['new_failures'] ?? []), 'is_string'));
        $resolvedFailures = count(array_filter((array)($delta['resolved_failures'] ?? []), 'is_string'));
        $statusTransitions = count(array_filter((array)($delta['status_transitions'] ?? []), 'is_array'));
        $suiteReruns = self::suiteRerunCommands($meta);

        echo "\n[Action Required]\n";
        if ($failedSuites !== []) {
            echo '  Suites con issues: ' . implode(', ', $failedSuites) . "\n";
        }
        echo '  Delta: new=' . $newFailures
            . ' resolved=' . $resolvedFailures
            . ' transitions=' . $statusTransitions
            . "\n";
        echo "  Reporte detallado: php scripts/report.php\n";

        if ($suiteReruns !== []) {
            echo "  rerun by suite:\n";
            foreach ($suiteReruns as $rerun) {
                echo '    - ' . $rerun['suite_id'] . ': ' . $rerun['command'];
                if ($rerun['reason'] !== '') {
                    echo ' (' . $rerun['reason'] . ')';
                }
                echo "\n";
            }
        } else {
            $rerunCommand = self::rerunFilteredCommand($firstFailure);
            if ($rerunCommand !== null) {
                echo '  rerun filtered: ' . $rerunCommand['command'];
                if ($rerunCommand['reason'] !== '') {
                    echo ' (' . $rerunCommand['reason'] . ')';
                }
                echo "\n";
            }
        }

        $rendered = 0;
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $kind = trim((string)($action['kind'] ?? 'action'));
            if (in_array($kind, ['rerun_filtered', 'aggregate_report'], true)) {
                continue;
            }

            $command = trim((string)($action['command'] ?? ''));
            if ($command === '') {
                continue;
            }

            $label = $kind !== '' ? str_replace('_', ' ', $kind) : 'action';
            $reason = trim((string)($action['reason'] ?? ''));

            echo '  ' . $label . ': ' . $command;
            if ($reason !== '') {
                echo ' (' . $reason . ')';
            }
            echo "\n";

            $rendered++;
            if ($rendered >= 2) {
                break;
            }
        }
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<int,string>
     */
    private static function failedSuites(array $meta): array
    {
        $failed = [];
        foreach ((array)($meta['suites'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = (int)($row['exit_code'] ?? 1);
            if ($code === 0 || $code === 2) {
                continue;
            }

            $failed[] = (string)($row['suite_id'] ?? 'suite');
        }

        return $failed;
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<int,array{suite_id:string,command:string,reason:string}>
     */
    private static function suiteRerunCommands(array $meta): array
    {
        $rows = [];
        foreach ((array)($meta['suites'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = (int)($row['exit_code'] ?? 1);
            if ($code === 0 || $code === 2) {
                continue;
            }

            $suiteId = trim((string)($row['suite_id'] ?? ''));
            if ($suiteId === '') {
                continue;
            }

            $command = '';
            $reason = '';
            $rerunPlan = is_array($row['rerun_plan'] ?? null) ? array_values(array_filter($row['rerun_plan'], 'is_array')) : [];
            if ($rerunPlan !== []) {
                $command = trim((string)($rerunPlan[0]['command'] ?? ''));
                $reason = trim((string)($rerunPlan[0]['reason'] ?? ''));
            }

            if ($command === '') {
                $command = 'php runTest.php ' . str_replace('_', '-', $suiteId);
                $reason = $reason !== '' ? $reason : 'rerun suite with issues';
            }

            $rows[] = [
                'suite_id' => $suiteId,
                'command' => $command,
                'reason' => $reason,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed>|null $firstFailure
     * @return array{command:string,reason:string}|null
     */
    private static function rerunFilteredCommand(?array $firstFailure): ?array
    {
        if (!is_array($firstFailure)) {
            return null;
        }

        $file = trim((string)($firstFailure['file'] ?? ''));
        $suiteId = trim((string)($firstFailure['suite_id'] ?? ''));
        if ($file === '' || $suiteId === '') {
            return null;
        }

        return [
            'command' => "TEST_MATCH='" . self::shellSingleQuote($file) . "' php runTest.php " . str_replace('_', '-', $suiteId),
            'reason' => 'aislar el primer archivo fallido',
        ];
    }

    private static function shellSingleQuote(string $value): string
    {
        return str_replace("'", "'\\''", $value);
    }
}
