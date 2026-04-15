<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class RegressionDeltaBuilder
{
    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public static function build(array $report): array
    {
        $delta = $report['regression_delta'] ?? null;
        if (!is_array($delta)) {
            $delta = [];
        }

        $transitions = [];
        foreach ((array)($delta['status_transitions'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $test = trim((string)($row['test'] ?? ''));
            $from = trim((string)($row['from'] ?? ''));
            $to = trim((string)($row['to'] ?? ''));
            if ($test === '' || $from === '' || $to === '') {
                continue;
            }

            $transitions[] = [
                'test' => $test,
                'from' => $from,
                'to' => $to,
            ];
        }

        return [
            'new_failures' => array_values(array_filter((array)($delta['new_failures'] ?? []), 'is_string')),
            'resolved_failures' => array_values(array_filter((array)($delta['resolved_failures'] ?? []), 'is_string')),
            'status_transitions' => $transitions,
        ];
    }
}
