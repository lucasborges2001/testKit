<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic\Rules;

final class UnboundedSelectRule implements SqlRule
{
    public static function analyze(string $sql): array
    {
        if (preg_match('/\bFROM\s+[`"A-Za-z_][`"A-Za-z0-9_$.]*/i', $sql) !== 1) {
            return [];
        }
        if (preg_match('/\b(?:WHERE|GROUP\s+BY|HAVING|LIMIT|UNION)\b/i', $sql) === 1) {
            return [];
        }
        if (preg_match('/\bSELECT\s+(?:COUNT|MIN|MAX|AVG|SUM)\s*\(/i', $sql) === 1) {
            return [];
        }
        return [RuleFinding::make(
            'unbounded_select', 'watch', 'medium',
            'Table SELECT has no WHERE, GROUP BY, HAVING, LIMIT or UNION bound.',
            'Confirm the full read is intentional; otherwise add a selective predicate or explicit bound.'
        )];
    }
}
