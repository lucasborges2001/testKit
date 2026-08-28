<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic;

final class SqlRuleSet
{
    /** @return array<int,array<string,mixed>> */
    public static function analyze(string $sql): array
    {
        $sql = SqlText::inspectable($sql);
        if ($sql === '' || preg_match('/\bSELECT\b/i', $sql) !== 1) {
            return [];
        }

        $findings = [];
        if (preg_match('/\bSELECT\s+(?:DISTINCT\s+)?(?:[`"A-Za-z_][`"A-Za-z0-9_$]*\.)?\*(?=\s|,|\bFROM\b)/i', $sql) === 1) {
            $findings[] = self::finding(
                'select_star', 'warn', 'high',
                'SELECT * reads every projected column and may prevent covering-index plans.',
                'Project only the columns required by the caller.'
            );
        }

        if (self::isUnboundedSelect($sql)) {
            $findings[] = self::finding(
                'unbounded_select', 'watch', 'medium',
                'Table SELECT has no WHERE, GROUP BY, HAVING, LIMIT or UNION bound.',
                'Confirm the full read is intentional; otherwise add a selective predicate or explicit bound.'
            );
        }

        foreach (self::nonSargableFunctions($sql) as $row) {
            $findings[] = self::finding(
                'non_sargable_predicate', 'warn', 'high',
                'Function applied to a predicate column can block normal B-tree index use.',
                'Prefer a range/comparison on the raw column or verify a compatible functional index.',
                ['function' => $row['function'], 'column' => $row['column']]
            );
        }

        if (preg_match("/\\bLIKE\\s+(?:CONCAT\\s*\\(\\s*)?['\"]%/i", $sql) === 1) {
            $findings[] = self::finding(
                'leading_wildcard_like', 'warn', 'high',
                'LIKE pattern starts with %, so a conventional prefix index is usually not selective.',
                'Use a prefix-searchable pattern or a search strategy designed for contains matching.'
            );
        }

        return $findings;
    }

    private static function isUnboundedSelect(string $sql): bool
    {
        if (preg_match('/\bFROM\s+[`"A-Za-z_][`"A-Za-z0-9_$.]*/i', $sql) !== 1) {
            return false;
        }
        if (preg_match('/\b(?:WHERE|GROUP\s+BY|HAVING|LIMIT|UNION)\b/i', $sql) === 1) {
            return false;
        }
        return preg_match('/\bSELECT\s+(?:COUNT|MIN|MAX|AVG|SUM)\s*\(/i', $sql) !== 1;
    }

    /** @return array<int,array{function:string,column:string}> */
    private static function nonSargableFunctions(string $sql): array
    {
        if (preg_match('/\b(?:WHERE|ON)\b/i', $sql) !== 1) {
            return [];
        }
        $matches = [];
        preg_match_all(
            '/\b(YEAR|DATE|MONTH|DAY|LOWER|UPPER|TRIM)\s*\(\s*([`"]?[A-Za-z_][A-Za-z0-9_$.`"]*)\s*\)/i',
            $sql,
            $matches,
            PREG_SET_ORDER
        );
        $rows = [];
        foreach ($matches as $match) {
            $rows[] = ['function' => strtoupper($match[1]), 'column' => trim($match[2], '`"')];
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    private static function finding(
        string $ruleId,
        string $severity,
        string $confidence,
        string $summary,
        string $recommendation,
        array $evidence = []
    ): array {
        return compact('ruleId', 'severity', 'confidence', 'summary', 'recommendation', 'evidence');
    }
}
