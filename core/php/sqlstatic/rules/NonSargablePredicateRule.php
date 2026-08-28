<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic\Rules;

use Testkit\Core\SqlStatic\SqlPredicates;

final class NonSargablePredicateRule implements SqlRule
{
    public static function analyze(string $sql): array
    {
        $findings = [];
        foreach (SqlPredicates::segments($sql) as $predicate) {
            $matches = [];
            preg_match_all(
                '/\b(YEAR|DATE|MONTH|DAY|LOWER|UPPER|TRIM|CAST|CONVERT|SUBSTRING|COALESCE)\s*\(\s*([`"]?[A-Za-z_][A-Za-z0-9_$.`"]*)/i',
                $predicate,
                $matches,
                PREG_SET_ORDER
            );
            foreach ($matches as $match) {
                $findings[] = RuleFinding::make(
                    'non_sargable_predicate', 'warn', 'high',
                    'Function applied to a predicate column can block normal B-tree index use.',
                    'Prefer a range/comparison on the raw column or verify a compatible functional index.',
                    ['function' => strtoupper($match[1]), 'column' => trim($match[2], '`"')]
                );
            }
        }
        return $findings;
    }
}
