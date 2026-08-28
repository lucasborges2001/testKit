<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic\Rules;

final class OffsetPaginationRule implements SqlRule
{
    public static function analyze(string $sql): array
    {
        $syntax = null;
        if (preg_match('/\bLIMIT\s+[^\s,;]+\s+OFFSET\s+[^\s,;]+/i', $sql) === 1) {
            $syntax = 'limit_offset';
        } elseif (preg_match('/\bLIMIT\s+[^\s,;]+\s*,\s*[^\s,;]+/i', $sql) === 1) {
            $syntax = 'mysql_limit_offset_count';
        }
        if ($syntax === null) {
            return [];
        }
        return [RuleFinding::make(
            'offset_pagination', 'watch', 'medium',
            'OFFSET pagination can become progressively expensive for deep pages.',
            'Keep it for bounded pages or consider keyset/seek pagination when deep navigation is expected.',
            ['syntax' => $syntax]
        )];
    }
}
