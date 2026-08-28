<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic\Rules;

use Testkit\Core\SqlStatic\SqlPredicates;

final class LeadingWildcardLikeRule implements SqlRule
{
    public static function analyze(string $sql): array
    {
        foreach (SqlPredicates::segments($sql) as $predicate) {
            if (preg_match("/\\bLIKE\\s+(?:CONCAT\\s*\\(\\s*)?['\"]%/i", $predicate) === 1) {
                return [RuleFinding::make(
                    'leading_wildcard_like', 'warn', 'high',
                    'LIKE pattern starts with %, so a conventional prefix index is usually not selective.',
                    'Use a prefix-searchable pattern or a search strategy designed for contains matching.'
                )];
            }
        }
        return [];
    }
}
