<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

final class MysqlExplainPlanParser
{
    /**
     * @param string|array<mixed> $payload
     * @return array<string,mixed>
     */
    public static function parseJson(string|array $payload, int $highRowsExamined = 10000): array
    {
        $decoded = is_array($payload) ? $payload : json_decode($payload, true);
        if (!is_array($decoded)) {
            return self::emptySummary(['invalid_json_plan']);
        }

        $tables = [];
        $flags = [];
        $estimatedRows = 0;
        $cost = null;

        self::walkJson($decoded, $tables, $flags, $estimatedRows, $cost, $highRowsExamined);

        return self::normalizeSummary($tables, $flags, $estimatedRows, $cost);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public static function parseTableRows(array $rows, int $highRowsExamined = 10000): array
    {
        $tables = [];
        $flags = [];
        $estimatedRows = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $table = [
                'table_name' => (string)($row['table'] ?? ''),
                'access_type' => (string)($row['type'] ?? ''),
                'possible_keys' => self::splitKeys($row['possible_keys'] ?? null),
                'key' => (string)($row['key'] ?? ''),
                'rows_examined_per_scan' => self::intValue($row['rows'] ?? 0),
                'rows_produced_per_join' => null,
                'filtered' => is_numeric($row['filtered'] ?? null) ? (float)$row['filtered'] : null,
                'using_temporary_table' => false,
                'using_filesort' => false,
                'attached_condition' => '',
                'cost' => null,
            ];

            $extra = strtolower((string)($row['Extra'] ?? $row['extra'] ?? ''));
            if (str_contains($extra, 'using filesort')) {
                $table['using_filesort'] = true;
                $flags[] = 'filesort';
            }
            if (str_contains($extra, 'using temporary')) {
                $table['using_temporary_table'] = true;
                $flags[] = 'temporary_table';
            }

            self::tableFlags($table, $flags, $highRowsExamined);
            $estimatedRows += (int)($table['rows_examined_per_scan'] ?? 0);
            $tables[] = $table;
        }

        return self::normalizeSummary($tables, $flags, $estimatedRows, null);
    }

    /**
     * @param array<mixed> $node
     * @param array<int,array<string,mixed>> $tables
     * @param array<int,string> $flags
     */
    private static function walkJson(array $node, array &$tables, array &$flags, int &$estimatedRows, ?float &$cost, int $highRowsExamined): void
    {
        if (isset($node['query_cost']) && is_numeric($node['query_cost'])) {
            $cost = $cost === null ? (float)$node['query_cost'] : max($cost, (float)$node['query_cost']);
        }
        if (isset($node['cost_info']) && is_array($node['cost_info']) && isset($node['cost_info']['query_cost']) && is_numeric($node['cost_info']['query_cost'])) {
            $cost = $cost === null ? (float)$node['cost_info']['query_cost'] : max($cost, (float)$node['cost_info']['query_cost']);
        }

        foreach ($node as $key => $value) {
            $keyLower = strtolower((string)$key);
            if (($keyLower === 'using_filesort' || $keyLower === 'filesort') && (bool)$value) {
                $flags[] = 'filesort';
            }
            if (($keyLower === 'using_temporary_table' || $keyLower === 'using_temporary') && (bool)$value) {
                $flags[] = 'temporary_table';
            }
            if (str_contains($keyLower, 'dependent') && (bool)$value) {
                $flags[] = 'dependent_subquery';
            }
        }

        if (isset($node['table']) && is_array($node['table'])) {
            $tableNode = $node['table'];
            $table = [
                'table_name' => (string)($tableNode['table_name'] ?? $tableNode['table'] ?? ''),
                'access_type' => (string)($tableNode['access_type'] ?? $tableNode['type'] ?? ''),
                'possible_keys' => self::splitKeys($tableNode['possible_keys'] ?? null),
                'key' => (string)($tableNode['key'] ?? $tableNode['key_used'] ?? ''),
                'rows_examined_per_scan' => self::intValue($tableNode['rows_examined_per_scan'] ?? $tableNode['rows'] ?? 0),
                'rows_produced_per_join' => self::intValueOrNull($tableNode['rows_produced_per_join'] ?? null),
                'filtered' => is_numeric($tableNode['filtered'] ?? null) ? (float)$tableNode['filtered'] : null,
                'using_temporary_table' => (bool)($tableNode['using_temporary_table'] ?? false),
                'using_filesort' => (bool)($tableNode['using_filesort'] ?? false),
                'attached_condition' => (string)($tableNode['attached_condition'] ?? ''),
                'cost' => self::costFromNode($tableNode),
            ];
            if ((bool)$table['using_filesort']) {
                $flags[] = 'filesort';
            }
            if ((bool)$table['using_temporary_table']) {
                $flags[] = 'temporary_table';
            }
            if (in_array(strtolower((string)$table['access_type']), ['range', 'index_merge'], true)) {
                $flags[] = 'range_or_index_merge';
            }
            self::tableFlags($table, $flags, $highRowsExamined);
            $estimatedRows += (int)($table['rows_examined_per_scan'] ?? 0);
            $tables[] = $table;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                self::walkJson($value, $tables, $flags, $estimatedRows, $cost, $highRowsExamined);
            }
        }
    }

    /** @param array<string,mixed> $table @param array<int,string> $flags */
    private static function tableFlags(array $table, array &$flags, int $highRowsExamined): void
    {
        $accessType = strtolower((string)($table['access_type'] ?? ''));
        $possibleKeys = is_array($table['possible_keys'] ?? null) ? $table['possible_keys'] : [];
        $key = trim((string)($table['key'] ?? ''));
        $rows = (int)($table['rows_examined_per_scan'] ?? 0);

        if ($accessType === 'all') {
            $flags[] = 'full_table_scan';
        }
        if ($possibleKeys === []) {
            $flags[] = 'no_possible_keys';
        }
        if ($key === '') {
            $flags[] = 'no_key_used';
        }
        if ($rows >= $highRowsExamined) {
            $flags[] = 'high_rows_examined';
        }
    }

    /** @param array<int,array<string,mixed>> $tables @param array<int,string> $flags */
    private static function normalizeSummary(array $tables, array $flags, int $estimatedRows, ?float $cost): array
    {
        $flags = array_values(array_unique(array_filter($flags, static fn(string $v): bool => $v !== '')));

        return [
            'plan_summary' => [
                'tables' => array_values($tables),
                'access_types' => self::uniqueColumn($tables, 'access_type'),
                'keys_used' => self::uniqueColumn($tables, 'key'),
                'possible_keys' => self::uniquePossibleKeys($tables),
                'estimated_rows' => $estimatedRows,
                'estimated_cost' => $cost,
            ],
            'flags' => $flags,
            'severity' => self::severity($flags),
            'recommendation' => self::recommend($flags),
        ];
    }

    /** @param array<int,string> $flags */
    private static function emptySummary(array $flags = []): array
    {
        return self::normalizeSummary([], $flags, 0, null);
    }

    /** @param array<int,string> $flags */
    private static function severity(array $flags): string
    {
        $warn = ['full_table_scan', 'no_key_used', 'filesort', 'temporary_table', 'high_rows_examined', 'dependent_subquery'];
        foreach ($warn as $flag) {
            if (in_array($flag, $flags, true)) {
                return 'warn';
            }
        }
        if (in_array('no_possible_keys', $flags, true)) {
            return 'watch';
        }
        return 'info';
    }

    /** @param array<int,string> $flags */
    private static function recommend(array $flags): string
    {
        if (in_array('full_table_scan', $flags, true) || in_array('no_key_used', $flags, true)) {
            return 'Revisar filtros y joins: el plan no usa índice o accede por ALL. Validar cardinalidad e índices existentes manualmente.';
        }
        if (in_array('filesort', $flags, true) || in_array('temporary_table', $flags, true)) {
            return 'Revisar ORDER BY/GROUP BY y cardinalidad: el plan usa filesort o tabla temporal.';
        }
        if (in_array('high_rows_examined', $flags, true)) {
            return 'Revisar selectividad: el plan estima demasiadas filas examinadas.';
        }
        if (in_array('dependent_subquery', $flags, true)) {
            return 'Revisar subqueries dependientes; pueden escalar mal con cardinalidad alta.';
        }
        return 'Sin señales críticas del plan. Mantener como baseline y contrastar con métricas de runtime.';
    }

    /** @param array<int,array<string,mixed>> $tables */
    private static function uniqueColumn(array $tables, string $key): array
    {
        $values = [];
        foreach ($tables as $table) {
            $value = trim((string)($table[$key] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }
        return array_values(array_unique($values));
    }

    /** @param array<int,array<string,mixed>> $tables */
    private static function uniquePossibleKeys(array $tables): array
    {
        $values = [];
        foreach ($tables as $table) {
            foreach ((array)($table['possible_keys'] ?? []) as $key) {
                $key = trim((string)$key);
                if ($key !== '') {
                    $values[] = $key;
                }
            }
        }
        return array_values(array_unique($values));
    }

    private static function splitKeys(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $items = explode(',', $value);
        } else {
            $items = [];
        }
        return array_values(array_filter(array_map(static fn(mixed $v): string => trim((string)$v), $items), static fn(string $v): bool => $v !== ''));
    }

    private static function intValue(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int)$value) : 0;
    }

    private static function intValueOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int)$value) : null;
    }

    /** @param array<string,mixed> $node */
    private static function costFromNode(array $node): ?float
    {
        if (isset($node['cost_info']) && is_array($node['cost_info'])) {
            foreach (['prefix_cost', 'read_cost', 'eval_cost', 'query_cost'] as $key) {
                if (isset($node['cost_info'][$key]) && is_numeric($node['cost_info'][$key])) {
                    return (float)$node['cost_info'][$key];
                }
            }
        }
        return null;
    }
}
