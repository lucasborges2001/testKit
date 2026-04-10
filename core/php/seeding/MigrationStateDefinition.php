<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use JsonException;
use RuntimeException;

final class MigrationStateDefinition
{
    /**
     * @return array<string,mixed>|null
     */
    public static function loadOptional(string $path, string $migrationId): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        return self::load($path, $migrationId);
    }

    /**
     * @return array{markers:array<int,array<string,string>>}
     */
    public static function load(string $path, string $migrationId): array
    {
        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException(
                'state.json vacío para la migración de test ' . $migrationId . ': ' . self::normalizePath($path)
            );
        }

        try {
            $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                'state.json inválido para la migración de test ' . $migrationId . ': ' . $e->getMessage()
            );
        }

        if (!is_array($json) || array_is_list($json)) {
            throw new RuntimeException(
                'state.json debe declarar un objeto JSON para la migración de test ' . $migrationId
            );
        }

        $unknown = array_values(array_diff(array_keys($json), ['markers']));
        if ($unknown !== []) {
            throw new RuntimeException(
                'state.json declara campos no soportados para la migración de test '
                . $migrationId . ': ' . implode(',', $unknown)
            );
        }

        $markers = $json['markers'] ?? null;
        if (!is_array($markers) || !array_is_list($markers) || $markers === []) {
            throw new RuntimeException(
                'state.json debe declarar markers[] no vacío para la migración de test ' . $migrationId
            );
        }

        $normalized = [];
        $seen = [];
        foreach ($markers as $index => $marker) {
            if (!is_array($marker) || array_is_list($marker)) {
                throw new RuntimeException(
                    'state.json marker[' . $index . '] inválido para la migración de test ' . $migrationId
                );
            }

            $type = strtolower(trim((string)($marker['type'] ?? '')));
            $normalizedMarker = match ($type) {
                'table' => self::normalizeTableMarker($marker, $migrationId, $index),
                'column' => self::normalizeColumnMarker($marker, $migrationId, $index),
                'routine' => self::normalizeRoutineMarker($marker, $migrationId, $index),
                default => throw new RuntimeException(
                    'state.json marker[' . $index . '] declara type inválido para la migración de test '
                    . $migrationId . ': ' . $type
                ),
            };

            $fingerprint = (string)json_encode($normalizedMarker, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (isset($seen[$fingerprint])) {
                throw new RuntimeException(
                    'state.json repite markers equivalentes para la migración de test '
                    . $migrationId . ' en marker[' . $index . ']'
                );
            }

            $seen[$fingerprint] = true;
            $normalized[] = $normalizedMarker;
        }

        return [
            'markers' => $normalized,
        ];
    }

    /**
     * @param array{markers:array<int,array<string,string>>} $definition
     * @return array{tables:array<int,string>,columns_by_table:array<string,array<int,string>>}
     */
    public static function describeStructuralContract(array $definition): array
    {
        $tables = [];

        foreach ($definition['markers'] as $marker) {
            if (($marker['type'] ?? '') === 'table') {
                $name = (string)$marker['name'];
                $tables[$name] ??= [];
                continue;
            }

            if (($marker['type'] ?? '') === 'column') {
                $table = (string)$marker['table'];
                $column = (string)$marker['column'];
                $tables[$table] ??= [];
                $tables[$table][$column] = true;
            }
        }

        ksort($tables, SORT_NATURAL | SORT_FLAG_CASE);

        $columnsByTable = [];
        foreach ($tables as $table => $columns) {
            $columnNames = array_keys($columns);
            natcasesort($columnNames);
            $columnsByTable[$table] = array_values($columnNames);
        }

        return [
            'tables' => array_keys($columnsByTable),
            'columns_by_table' => $columnsByTable,
        ];
    }

    /**
     * @param array{markers:array<int,array<string,string>>} $definition
     * @param array{tables:array<string,bool>,columns:array<string,array<string,bool>>} $snapshot
     * @return array<string,mixed>
     */
    public static function evaluateTableColumnSnapshot(string $migrationId, array $definition, array $snapshot): array
    {
        $contract = self::describeStructuralContract($definition);
        $tables = [];
        $hasAnyPresence = false;
        $allSatisfied = true;

        foreach ($contract['columns_by_table'] as $table => $columns) {
            $exists = (bool)($snapshot['tables'][$table] ?? false);
            if ($exists) {
                $hasAnyPresence = true;
            } else {
                $allSatisfied = false;
            }

            $columnStates = [];
            $missingColumns = [];
            foreach ($columns as $column) {
                $present = (bool)($snapshot['columns'][$table][$column] ?? false);
                $columnStates[$column] = $present;
                if ($present) {
                    $hasAnyPresence = true;
                    continue;
                }

                $missingColumns[] = $column;
                $allSatisfied = false;
            }

            $tables[$table] = [
                'exists' => $exists,
                'columns' => $columnStates,
                'missing_columns' => $missingColumns,
            ];
        }

        $status = 'partially_applied';
        if (!$hasAnyPresence) {
            $status = 'not_applied';
        } elseif ($allSatisfied) {
            $status = 'applied';
        }

        return [
            'migration' => $migrationId,
            'status' => $status,
            'tables' => $tables,
            'findings' => self::buildFindings($status, $tables),
        ];
    }

    /**
     * @param array<string,mixed> $marker
     * @return array{type:string,name:string}
     */
    private static function normalizeTableMarker(array $marker, string $migrationId, int $index): array
    {
        self::assertAllowedKeys($marker, ['type', 'name'], 'state.json', $migrationId, $index);
        $name = trim((string)($marker['name'] ?? ''));
        self::assertIdentifier($name, 'state.json marker[' . $index . '].name', $migrationId);

        return [
            'type' => 'table',
            'name' => $name,
        ];
    }

    /**
     * @param array<string,mixed> $marker
     * @return array{type:string,table:string,column:string}
     */
    private static function normalizeColumnMarker(array $marker, string $migrationId, int $index): array
    {
        self::assertAllowedKeys($marker, ['type', 'table', 'column'], 'state.json', $migrationId, $index);
        $table = trim((string)($marker['table'] ?? ''));
        $column = trim((string)($marker['column'] ?? ''));

        self::assertIdentifier($table, 'state.json marker[' . $index . '].table', $migrationId);
        self::assertIdentifier($column, 'state.json marker[' . $index . '].column', $migrationId);

        return [
            'type' => 'column',
            'table' => $table,
            'column' => $column,
        ];
    }

    /**
     * @param array<string,mixed> $marker
     * @return array{type:string,name:string}
     */
    private static function normalizeRoutineMarker(array $marker, string $migrationId, int $index): array
    {
        self::assertAllowedKeys($marker, ['type', 'name'], 'state.json', $migrationId, $index);
        $name = trim((string)($marker['name'] ?? ''));
        self::assertIdentifier($name, 'state.json marker[' . $index . '].name', $migrationId);

        return [
            'type' => 'routine',
            'name' => $name,
        ];
    }

    /**
     * @param array<string,mixed> $values
     * @param array<int,string> $allowed
     */
    private static function assertAllowedKeys(array $values, array $allowed, string $label, string $migrationId, int $index): void
    {
        $unknown = array_values(array_diff(array_keys($values), $allowed));
        if ($unknown === []) {
            return;
        }

        throw new RuntimeException(
            $label . ' marker[' . $index . '] declara campos no soportados para la migración de test '
            . $migrationId . ': ' . implode(',', $unknown)
        );
    }

    private static function assertIdentifier(string $value, string $label, string $migrationId): void
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1) {
            throw new RuntimeException(
                $label . ' inválido para la migración de test ' . $migrationId . ': ' . $value
            );
        }
    }

    /**
     * @param array<string,array<string,mixed>> $tables
     * @return array<int,array<string,mixed>>
     */
    private static function buildFindings(string $status, array $tables): array
    {
        if ($status === 'not_applied' || $status === 'applied') {
            return [];
        }

        $findings = [];
        foreach ($tables as $table => $state) {
            if (($state['exists'] ?? false) !== true) {
                $findings[] = [
                    'table' => $table,
                    'message' => "La tabla {$table} no existe para el contrato mínimo de la migración.",
                ];
                continue;
            }

            /** @var array<int,string> $missingColumns */
            $missingColumns = $state['missing_columns'] ?? [];
            if ($missingColumns !== []) {
                $findings[] = [
                    'table' => $table,
                    'message' => "La tabla {$table} existe pero le faltan columnas mínimas: " . implode(', ', $missingColumns) . '.',
                ];
            }
        }

        return $findings;
    }

    private static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
