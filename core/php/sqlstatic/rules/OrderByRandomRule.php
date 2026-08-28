<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic\Rules;

final class OrderByRandomRule implements SqlRule
{
    public static function analyze(string $sql): array
    {
        if (preg_match('/\bORDER\s+BY\s+(?:RAND|RANDOM)\s*\(\s*\)/i', $sql) !== 1) {
            return [];
        }
        return [RuleFinding::make(
            'order_by_random', 'watch', 'high',
            'Random ordering can require evaluating and sorting a large candidate set.',
            'Verify table/cardinality cost at runtime and prefer a bounded sampling strategy when scale matters.'
        )];
    }
}
