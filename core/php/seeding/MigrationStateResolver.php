<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use PDO;
use RuntimeException;

final class MigrationStateResolver
{
    /**
     * Resuelve el estado de migraciones disponibles/aplicadas/pendientes.
     *
     * Modos:
     * - explicit: usa TEST_MIGRATION_APPLIED
     * - manifest_table: lee una tabla de control
     * - state_files: infiere por markers declarados en test/seeds/<driver>/migrations/<id>/state.json
     *
     * @return array<string,mixed>
     */
    public static function resolve(PDO $pdo, string $seedDir): array
    {
        $available = self::listAvailableMigrations($seedDir);
        $mode = self::detectMode($seedDir);
        $target = self::resolveTarget($available);

        $applied = match ($mode) {
            'explicit' => self::parseCsv((string)(getenv('TEST_MIGRATION_APPLIED') ?: '')),
            'manifest_table' => self::resolveFromManifestTable($pdo),
            default => self::resolveFromStateFiles($pdo, $seedDir, $available),
        };

        $applied = array_values(array_intersect($available, array_values(array_unique($applied))));
        $pending = self::resolvePending($available, $applied, $target);

        return [
            'mode' => $mode,
            'available' => $available,
            'applied' => $applied,
            'pending' => $pending,
            'target' => $target,
        ];
    }

    /**
     * @return array<int,string>
     */
    private static function listAvailableMigrations(string $seedDir): array
    {
        $root = rtrim($seedDir, '/\\') . '/migrations';
        if (!is_dir($root)) {
            return [];
        }

        $dirs = glob($root . '/*', GLOB_ONLYDIR) ?: [];
        $out = [];
        foreach ($dirs as $dir) {
            $id = basename($dir);
            if ($id !== '' && $id !== '.' && $id !== '..') {
                $out[] = $id;
            }
        }
        natcasesort($out);
        return array_values($out);
    }

    private static function detectMode(string $seedDir): string
    {
        $explicitMode = strtolower(trim((string)(getenv('TEST_MIGRATION_STATE_MODE') ?: '')));
        if (in_array($explicitMode, ['explicit', 'manifest_table', 'state_files'], true)) {
            return $explicitMode;
        }

        if (trim((string)(getenv('TEST_MIGRATION_APPLIED') ?: '')) !== '') {
            return 'explicit';
        }

        $table = trim((string)(getenv('TEST_MIGRATION_STATE_TABLE') ?: ''));
        if ($table !== '') {
            return 'manifest_table';
        }

        $root = rtrim($seedDir, '/\\') . '/migrations';
        foreach (glob($root . '/*/state.json') ?: [] as $stateFile) {
            if (is_file($stateFile)) {
                return 'state_files';
            }
        }

        return 'explicit';
    }

    /**
     * @return array<int,string>
     */
    private static function resolveFromManifestTable(PDO $pdo): array
    {
        $table = trim((string)(getenv('TEST_MIGRATION_STATE_TABLE') ?: 'migrations'));
        $column = trim((string)(getenv('TEST_MIGRATION_STATE_COLUMN') ?: 'version'));
        self::assertSafeIdentifier($table, 'tabla de estado de migración');
        self::assertSafeIdentifier($column, 'columna de estado de migración');

        $sql = sprintf('SELECT %s AS migration_id FROM %s ORDER BY %s', self::quoteIdentifier($column), self::quoteIdentifier($table), self::quoteIdentifier($column));
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

        $out = [];
        foreach ($rows as $row) {
            $value = trim((string)$row);
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<int,string> $available
     * @return array<int,string>
     */
    private static function resolveFromStateFiles(PDO $pdo, string $seedDir, array $available): array
    {
        $applied = [];
        foreach ($available as $migrationId) {
            $stateFile = rtrim($seedDir, '/\\') . '/migrations/' . $migrationId . '/state.json';
            if (!is_file($stateFile)) {
                continue;
            }

            $raw = file_get_contents($stateFile);
            $json = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($json)) {
                continue;
            }

            $markers = is_array($json['markers'] ?? null) ? $json['markers'] : [];
            if ($markers !== [] && self::allMarkersSatisfied($pdo, $markers)) {
                $applied[] = $migrationId;
            }
        }

        return array_values(array_unique($applied));
    }

    /**
     * @param array<int,array<string,mixed>> $markers
     */
    private static function allMarkersSatisfied(PDO $pdo, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (!is_array($marker)) {
                return false;
            }

            $type = strtolower(trim((string)($marker['type'] ?? '')));
            $name = trim((string)($marker['name'] ?? ''));
            $table = trim((string)($marker['table'] ?? ''));
            $column = trim((string)($marker['column'] ?? ''));

            $ok = match ($type) {
                'table' => self::tableExists($pdo, $name),
                'column' => self::columnExists($pdo, $table, $column),
                'routine' => self::routineExists($pdo, $name),
                default => false,
            };

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function routineExists(PDO $pdo, string $routine): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE() AND routine_name = ?');
        $stmt->execute([$routine]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * @param array<int,string> $available
     * @param array<int,string> $applied
     * @return array<int,string>
     */
    private static function resolvePending(array $available, array $applied, string $target): array
    {
        $targetReached = $target === '' ? true : false;
        $pending = [];

        foreach ($available as $migrationId) {
            if ($target !== '' && $migrationId === $target) {
                $targetReached = true;
            }

            if (!in_array($migrationId, $applied, true)) {
                $pending[] = $migrationId;
            }

            if ($target !== '' && $migrationId === $target) {
                break;
            }
        }

        if ($target !== '' && !$targetReached && !in_array($target, $available, true)) {
            throw new RuntimeException('TEST_MIGRATION_TARGET no existe en migrations/: ' . $target);
        }

        return $pending;
    }

    /**
     * @param array<int,string> $available
     */
    private static function resolveTarget(array $available): string
    {
        $target = trim((string)(getenv('TEST_MIGRATION_TARGET') ?: ''));
        if ($target === '' || strtolower($target) === 'latest') {
            return $available !== [] ? (string)end($available) : '';
        }

        return $target;
    }

    /**
     * @return array<int,string>
     */
    private static function parseCsv(string $value): array
    {
        $parts = array_map('trim', explode(',', $value));
        $parts = array_values(array_filter($parts, static fn(string $v): bool => $v !== ''));
        $parts = array_values(array_unique($parts));
        natcasesort($parts);
        return array_values($parts);
    }

    private static function assertSafeIdentifier(string $value, string $label): void
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1) {
            throw new RuntimeException('Identificador inválido para ' . $label . ': ' . $value);
        }
    }

    private static function quoteIdentifier(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }
}
