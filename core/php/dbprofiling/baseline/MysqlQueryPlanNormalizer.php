<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Baseline;

use Testkit\Core\DbProfiling\InstrumentationContext;

final class MysqlQueryPlanNormalizer
{
    private const BAD_FLAGS = [
        'full_table_scan',
        'no_possible_keys',
        'no_key_used',
        'filesort',
        'temporary_table',
        'high_rows_examined',
        'dependent_subquery',
        'invalid_json_plan',
    ];

    /** @param array<string,mixed>|null $finding @return array<string,mixed> */
    public static function normalize(?array $finding): array
    {
        if (!is_array($finding) || (string)($finding['explain_status'] ?? '') !== 'analyzed') {
            return [
                'status' => 'unavailable',
                'signature' => '',
                'flags' => [],
                'access_types' => [],
                'keys_used' => [],
                'possible_keys' => [],
                'estimated_rows' => null,
                'tables' => [],
                'severity' => '',
                'policy_violations' => [],
            ];
        }

        $summary = is_array($finding['plan_summary'] ?? null) ? $finding['plan_summary'] : [];
        $tables = [];
        foreach ((array)($summary['tables'] ?? []) as $table) {
            if (!is_array($table)) {
                continue;
            }
            $tables[] = [
                'table_name' => self::safeName((string)($table['table_name'] ?? $table['table'] ?? ''), 160),
                'access_type' => strtolower(self::safeName((string)($table['access_type'] ?? $table['type'] ?? ''), 40)),
                'key' => self::safeName((string)($table['key'] ?? ''), 160),
                'possible_keys' => self::sortedStrings((array)($table['possible_keys'] ?? []), 100),
                'estimated_rows' => self::nullableNumber(
                    $table['rows_examined_per_scan'] ?? $table['estimated_rows'] ?? $table['rows'] ?? null
                ),
            ];
        }
        usort($tables, static function (array $a, array $b): int {
            return [$a['table_name'], $a['access_type'], $a['key']]
                <=> [$b['table_name'], $b['access_type'], $b['key']];
        });

        $accessTypes = self::sortedStrings((array)($summary['access_types'] ?? []), 100, true);
        $keysUsed = self::sortedStrings((array)($summary['keys_used'] ?? []), 100);
        $possibleKeys = self::sortedStrings((array)($summary['possible_keys'] ?? []), 100);
        if ($tables !== []) {
            foreach ($tables as $table) {
                if ($table['access_type'] !== '') {
                    $accessTypes[] = $table['access_type'];
                }
                if ($table['key'] !== '') {
                    $keysUsed[] = $table['key'];
                }
                $possibleKeys = array_merge($possibleKeys, $table['possible_keys']);
            }
            $accessTypes = self::sortedStrings($accessTypes, 100, true);
            $keysUsed = self::sortedStrings($keysUsed, 100);
            $possibleKeys = self::sortedStrings($possibleKeys, 100);
        }

        $normalized = [
            'status' => 'available',
            'flags' => self::sortedStrings((array)($finding['flags'] ?? []), 100, true),
            'access_types' => $accessTypes,
            'keys_used' => $keysUsed,
            'possible_keys' => $possibleKeys,
            'estimated_rows' => self::nullableNumber($summary['estimated_rows'] ?? null),
            'tables' => $tables,
            'severity' => strtolower(self::safeName((string)($finding['severity'] ?? ''), 40)),
            'policy_violations' => self::sortedStrings((array)($finding['policy_violations'] ?? []), 100, true),
        ];
        $normalized['signature'] = hash('sha256', self::canonicalJson([
            'flags' => $normalized['flags'],
            'access_types' => $normalized['access_types'],
            'keys_used' => $normalized['keys_used'],
            'possible_keys' => $normalized['possible_keys'],
            'estimated_rows' => $normalized['estimated_rows'],
            'tables' => $normalized['tables'],
        ]));
        return $normalized;
    }

    /**
     * @param array<string,mixed> $baseline
     * @param array<string,mixed> $current
     * @param array<string,mixed> $tolerances
     * @return array<string,mixed>
     */
    public static function compare(array $baseline, array $current, array $tolerances): array
    {
        if (($baseline['status'] ?? '') !== 'available' || ($current['status'] ?? '') !== 'available') {
            return [
                'status' => 'insufficient_data',
                'baseline_signature' => (string)($baseline['signature'] ?? ''),
                'current_signature' => (string)($current['signature'] ?? ''),
                'reason' => 'explain_unavailable',
            ];
        }

        $addedFlags = array_values(array_diff((array)$current['flags'], (array)$baseline['flags']));
        $removedFlags = array_values(array_diff((array)$baseline['flags'], (array)$current['flags']));
        $addedKeys = array_values(array_diff((array)$current['keys_used'], (array)$baseline['keys_used']));
        $removedKeys = array_values(array_diff((array)$baseline['keys_used'], (array)$current['keys_used']));
        sort($addedFlags);
        sort($removedFlags);
        sort($addedKeys);
        sort($removedKeys);

        $accessChanges = self::accessTypeChanges(
            is_array($baseline['tables'] ?? null) ? $baseline['tables'] : [],
            is_array($current['tables'] ?? null) ? $current['tables'] : []
        );
        $rows = self::rowsDelta($baseline['estimated_rows'] ?? null, $current['estimated_rows'] ?? null);

        $badAdded = array_values(array_intersect($addedFlags, self::BAD_FLAGS));
        $badRemoved = array_values(array_intersect($removedFlags, self::BAD_FLAGS));
        $worseAccess = array_filter($accessChanges, static fn(array $change): bool => ($change['direction'] ?? '') === 'regressed');
        $betterAccess = array_filter($accessChanges, static fn(array $change): bool => ($change['direction'] ?? '') === 'improved');
        $rowsDirection = self::rowsDirection($rows, $tolerances);

        if (
            (string)($baseline['signature'] ?? '') === (string)($current['signature'] ?? '')
            && $rowsDirection === 'unchanged'
        ) {
            $status = 'unchanged';
        } elseif ($badAdded !== [] || $removedKeys !== [] || $worseAccess !== [] || $rowsDirection === 'regressed') {
            $status = 'regressed';
        } elseif ($badRemoved !== [] || $addedKeys !== [] || $betterAccess !== [] || $rowsDirection === 'improved') {
            $status = 'improved';
        } else {
            $status = 'plan_changed';
        }

        return [
            'status' => $status,
            'baseline_signature' => (string)($baseline['signature'] ?? ''),
            'current_signature' => (string)($current['signature'] ?? ''),
            'added_flags' => $addedFlags,
            'removed_flags' => $removedFlags,
            'added_keys' => $addedKeys,
            'removed_keys' => $removedKeys,
            'access_type_changes' => array_values($accessChanges),
            'estimated_rows' => $rows,
            'baseline_policy_violations' => array_values((array)($baseline['policy_violations'] ?? [])),
            'current_policy_violations' => array_values((array)($current['policy_violations'] ?? [])),
        ];
    }

    /** @param array<int,array<string,mixed>> $baseline @param array<int,array<string,mixed>> $current */
    private static function accessTypeChanges(array $baseline, array $current): array
    {
        $left = [];
        $right = [];
        foreach ($baseline as $table) {
            if (is_array($table) && (string)($table['table_name'] ?? '') !== '') {
                $left[(string)$table['table_name']] = strtolower((string)($table['access_type'] ?? ''));
            }
        }
        foreach ($current as $table) {
            if (is_array($table) && (string)($table['table_name'] ?? '') !== '') {
                $right[(string)$table['table_name']] = strtolower((string)($table['access_type'] ?? ''));
            }
        }
        $names = array_values(array_unique(array_merge(array_keys($left), array_keys($right))));
        sort($names);
        $out = [];
        foreach ($names as $name) {
            $before = $left[$name] ?? '';
            $after = $right[$name] ?? '';
            if ($before === $after) {
                continue;
            }
            $beforeRank = self::accessRank($before);
            $afterRank = self::accessRank($after);
            $direction = $beforeRank === $afterRank
                ? 'changed'
                : ($afterRank > $beforeRank ? 'regressed' : 'improved');
            $out[] = [
                'table' => $name,
                'baseline' => $before,
                'current' => $after,
                'direction' => $direction,
            ];
        }
        return $out;
    }

    private static function accessRank(string $type): int
    {
        $order = [
            'system' => 0, 'const' => 1, 'eq_ref' => 2, 'ref' => 3,
            'fulltext' => 4, 'ref_or_null' => 5, 'index_merge' => 6,
            'unique_subquery' => 7, 'index_subquery' => 8, 'range' => 9,
            'index' => 10, 'all' => 11, '' => 12,
        ];
        return $order[strtolower($type)] ?? 12;
    }

    private static function rowsDelta(mixed $baseline, mixed $current): array
    {
        if (!is_numeric($baseline) || !is_numeric($current)) {
            return [
                'baseline' => is_numeric($baseline) ? (float)$baseline : null,
                'current' => is_numeric($current) ? (float)$current : null,
                'delta' => null,
                'delta_pct' => null,
                'reason' => 'missing_metric',
            ];
        }
        $before = (float)$baseline;
        $after = (float)$current;
        $delta = $after - $before;
        return [
            'baseline' => $before,
            'current' => $after,
            'delta' => $delta,
            'delta_pct' => $before == 0.0 ? null : round(($delta / $before) * 100, 3),
            'reason' => $before == 0.0 ? 'baseline_zero' : '',
        ];
    }

    /** @param array<string,mixed> $rows @param array<string,mixed> $tolerances */
    private static function rowsDirection(array $rows, array $tolerances): string
    {
        if (!is_numeric($rows['delta'] ?? null)) {
            return 'insufficient_data';
        }
        $delta = (float)$rows['delta'];
        if ($delta == 0.0) {
            return 'unchanged';
        }
        $abs = (float)($tolerances['rows_regression_abs'] ?? 100.0);
        $pct = (float)($tolerances['rows_regression_pct'] ?? 25.0);
        $deltaPct = $rows['delta_pct'];
        $large = abs($delta) >= $abs
            && ($deltaPct === null || abs((float)$deltaPct) >= $pct);
        if (!$large) {
            return 'unchanged';
        }
        return $delta > 0 ? 'regressed' : 'improved';
    }

    /** @param array<int,mixed> $values @return array<int,string> */
    private static function sortedStrings(array $values, int $limit, bool $lower = false): array
    {
        $set = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $item = self::safeName((string)$value, 160);
            if ($lower) {
                $item = strtolower($item);
            }
            if ($item !== '') {
                $set[$item] = true;
            }
            if (count($set) >= $limit) {
                break;
            }
        }
        $out = array_keys($set);
        sort($out, SORT_STRING);
        return $out;
    }

    private static function safeName(string $value, int $limit): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? '';
        if (
            preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', $value) === 1
            || preg_match('/\b(?:mysql|pgsql|sqlsrv):/i', $value) === 1
            || preg_match('/\b(?:bearer\s+)[A-Za-z0-9._-]+/i', $value) === 1
        ) {
            return '[redacted]';
        }
        $value = preg_replace('/[^A-Za-z0-9_.$:\/-]+/', '_', $value) ?? '';
        return substr(trim($value, '_'), 0, max(1, $limit));
    }

    private static function nullableNumber(mixed $value): int|float|null
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0) {
            return null;
        }
        return floor($number) === $number ? (int)$number : $number;
    }

    /** @param array<string,mixed> $value */
    private static function canonicalJson(array $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
