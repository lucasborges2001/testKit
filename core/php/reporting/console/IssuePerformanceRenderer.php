<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Console;

use Testkit\Core\Reporting\UI;

final class IssuePerformanceRenderer
{
    public static function printSlow(array $result): void
    {
        $slow = $result['slow_tests'] ?? [];
        if (!is_array($slow) || !$slow) {
            return;
        }

        $visible = array_slice($slow, 0, ConsoleRenderLimits::MAX_SLOW_TESTS);

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

    public static function printFragility(array $result): void
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

        $visible = array_slice($flaky, 0, ConsoleRenderLimits::MAX_FRAGILITY);

        UI::section('Possible Flaky Tests (heuristic)');
        foreach ($visible as $hint) {
            echo '  - '
                . (string)$hint['test']
                . ' '
                . UI::gray('(pass=' . (int)($hint['pass_count'] ?? 0) . ', fail=' . (int)($hint['fail_count'] ?? 0) . ')')
                . "\n";
        }

        $hidden = count($flaky) - count($visible);
        if ($hidden > 0) {
            echo '  ' . UI::gray('... ' . $hidden . ' more heuristic hints hidden') . "\n";
        }
    }

    public static function printPerfViolations(array $result): void
    {
        $violations = $result['perf_violations'] ?? [];
        if (!is_array($violations) || !$violations) {
            return;
        }

        $visible = array_slice($violations, 0, ConsoleRenderLimits::MAX_PERF_VIOLATIONS);

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

    private function __construct()
    {
    }
}
