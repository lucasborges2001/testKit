<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic;

final class SqlPredicates
{
    /** @return array<int,string> */
    public static function segments(string $sql): array
    {
        $matches = [];
        preg_match_all(
            '/\b(?:WHERE|ON)\b(.*?)(?=\b(?:JOIN|WHERE|GROUP\s+BY|HAVING|ORDER\s+BY|LIMIT|UNION)\b|$)/is',
            $sql,
            $matches
        );
        return array_values(array_filter(
            array_map('trim', $matches[1] ?? []),
            static fn(string $part): bool => $part !== ''
        ));
    }
}
