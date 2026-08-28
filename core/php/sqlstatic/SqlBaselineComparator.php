<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic;

use InvalidArgumentException;

final class SqlBaselineComparator
{
    /** @return array<string,mixed> */
    public static function compare(array $current, array $baseline): array
    {
        if (!isset($baseline['findings']) || !is_array($baseline['findings'])) {
            throw new InvalidArgumentException('SQL static baseline must contain a findings array.');
        }
        $currentCounts = self::counts((array)($current['findings'] ?? []));
        $baselineCounts = self::counts($baseline['findings']);
        $keys = array_values(array_unique(array_merge(array_keys($currentCounts), array_keys($baselineCounts))));
        sort($keys);
        $new = $resolved = $unchanged = 0;
        $changes = [];
        foreach ($keys as $key) {
            $now = $currentCounts[$key] ?? 0;
            $before = $baselineCounts[$key] ?? 0;
            $same = min($now, $before);
            $added = max(0, $now - $before);
            $removed = max(0, $before - $now);
            $new += $added;
            $resolved += $removed;
            $unchanged += $same;
            if ($added > 0 || $removed > 0) {
                $changes[] = ['stable_id' => $key, 'new' => $added, 'resolved' => $removed];
            }
        }
        return [
            'status' => 'compared',
            'new_findings' => $new,
            'resolved_findings' => $resolved,
            'unchanged_findings' => $unchanged,
            'changes' => $changes,
            'gate_enabled' => false,
        ];
    }

    /** @param array<int,array<string,mixed>> $findings @return array<string,int> */
    private static function counts(array $findings): array
    {
        $counts = [];
        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $key = (string)($finding['stable_id'] ?? self::deriveStableId($finding));
            $counts[$key] = (int)($counts[$key] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /** @param array<string,mixed> $finding */
    private static function deriveStableId(array $finding): string
    {
        $seed = (string)($finding['rule_id'] ?? 'unknown') . '|'
            . (string)($finding['path'] ?? '') . '|'
            . (string)($finding['fingerprint'] ?? '');
        return 'sql-static-stable.' . substr(sha1($seed), 0, 16);
    }
}
