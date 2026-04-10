<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use PDO;
use RuntimeException;

require_once __DIR__ . '/MigrationCatalog.php';
require_once __DIR__ . '/MigrationStateDefinition.php';

final class MigrationStateResolver
{
    /**
     * Falla de forma explícita si no existe ninguna fuente confiable de estado de migraciones.
     *
     * Debe llamarse antes de intentar auto-detectar pendientes en modo snapshot.
     * En snapshot la DB ya viene restaurada: asumir "todo pendiente" cuando no hay
     * fuente es peligroso porque aplicaría migraciones sobre una DB ya migrada.
     *
     * Fuentes aceptadas:
     * - TEST_MIGRATION_APPLIED        — lista explícita de migraciones ya aplicadas
     * - TEST_MIGRATION_STATE_TABLE    — tabla de control en la DB restaurada
     * - TEST_MIGRATION_STATE_MODE     — modo declarado explícitamente
     * - state.json en algún directorio de migración
     *
     * Si ninguna fuente está disponible lanza RuntimeException con opciones claras.
     */
    public static function assertHasReliableStateSource(string $seedDir): void
    {
        $explicitMode = strtolower(trim((string)(getenv('TEST_MIGRATION_STATE_MODE') ?: '')));

        if ($explicitMode === 'explicit') {
            if (trim((string)(getenv('TEST_MIGRATION_APPLIED') ?: '')) === '') {
                throw new RuntimeException(
                    'TEST_MIGRATION_STATE_MODE=explicit declarado pero TEST_MIGRATION_APPLIED está vacío. '
                    . 'El modo explicit requiere una lista de migraciones ya aplicadas en el snapshot '
                    . '(ej: TEST_MIGRATION_APPLIED=id1,id2,...). Sin esa lista testkit no puede determinar '
                    . 'qué pendientes quedan y fallaría aplicando migraciones ya presentes en la DB restaurada.'
                );
            }
            return;
        }

        if (in_array($explicitMode, ['manifest_table', 'state_files'], true)) {
            return;
        }

        if (trim((string)(getenv('TEST_MIGRATION_APPLIED') ?: '')) !== '') {
            return;
        }

        if (trim((string)(getenv('TEST_MIGRATION_STATE_TABLE') ?: '')) !== '') {
            return;
        }

        $catalog = MigrationCatalog::load($seedDir);
        foreach ((array)($catalog['entries'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $kind = (string)($entry['kind'] ?? '');
            $statePath = trim((string)($entry['state_path'] ?? ''));
            if (MigrationCatalog::isExecutableKind($kind) && $statePath !== '') {
                return;
            }
        }

        throw new RuntimeException(
            'snapshot + TEST_MIGRATION_AUTO_PENDING: no hay fuente confiable de estado de migraciones. '
            . 'En modo snapshot la DB ya viene restaurada desde un dump; testkit no puede inferir '
            . 'que todas las migraciones están pendientes sin una fuente explícita, porque eso '
            . 'aplicaría upgrades incorrectos sobre una DB que ya los tiene. '
            . 'Opciones válidas: '
            . '(1) TEST_MIGRATION_APPLIED=id1,id2,... — declará las migraciones ya aplicadas en el snapshot; '
            . '(2) TEST_MIGRATION_STATE_TABLE=nombre_tabla — usá una tabla de control en la DB restaurada; '
            . '(3) state.json con markers en cada directorio de migración (TEST_MIGRATION_STATE_MODE=state_files); '
            . '(4) TEST_SEED_MIGRATIONS=id1,id2 — declaración directa sin auto-detección.'
        );
    }

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
        $catalog = MigrationCatalog::load($seedDir);
        $available = array_values((array)($catalog['executable'] ?? []));
        $mode = self::detectMode($seedDir);
        $target = self::resolveTarget($available);

        if ($mode === 'explicit' && trim((string)(getenv('TEST_MIGRATION_APPLIED') ?: '')) === '') {
            throw new RuntimeException(
                'TEST_MIGRATION_STATE_MODE=explicit declarado pero TEST_MIGRATION_APPLIED está vacío. '
                . 'El modo explicit requiere una lista de migraciones ya aplicadas '
                . '(ej: TEST_MIGRATION_APPLIED=id1,id2,...).'
            );
        }

        $applied = match ($mode) {
            'explicit' => self::parseCsv((string)(getenv('TEST_MIGRATION_APPLIED') ?: '')),
            'manifest_table' => self::resolveFromManifestTable($pdo),
            default => self::resolveFromStateFiles($pdo, $catalog),
        };

        $applied = array_values(array_intersect($available, array_values(array_unique($applied))));
        $pending = self::resolvePending($available, $applied, $target);

        return [
            'mode' => $mode,
            'available' => $available,
            'applied' => $applied,
            'pending' => $pending,
            'target' => $target,
            'historical_absorbed' => array_values((array)($catalog['historical_absorbed'] ?? [])),
        ];
    }

    /**
     * Estado derivado del baseline que el runner materializa en modo layered.
     *
     * En layered la DB se resetea antes de aplicar schema/base/migrations, por lo
     * que leer el estado previo desde la conexión actual no describe el baseline
     * resultante. Este helper construye el estado directamente desde el catálogo
     * ejecutable y las migraciones explícitamente pedidas para esta corrida.
     *
     * @param array<int,string> $requestedMigrations
     * @return array<string,mixed>
     */
    public static function resolveLayeredBaseline(string $seedDir, array $requestedMigrations): array
    {
        $catalog = MigrationCatalog::load($seedDir);
        $available = array_values((array)($catalog['executable'] ?? []));
        $applied = MigrationCatalog::normalizeSelectedExecutablesFromCatalog($catalog, $requestedMigrations);
        $target = self::resolveTarget($available);
        $pending = self::resolvePending($available, $applied, $target);

        return [
            'mode' => 'layered_baseline',
            'available' => $available,
            'applied' => $applied,
            'pending' => $pending,
            'target' => $target,
            'historical_absorbed' => array_values((array)($catalog['historical_absorbed'] ?? [])),
        ];
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

        $catalog = MigrationCatalog::load($seedDir);
        foreach ((array)($catalog['entries'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $kind = (string)($entry['kind'] ?? '');
            $statePath = trim((string)($entry['state_path'] ?? ''));
            if (MigrationCatalog::isExecutableKind($kind) && $statePath !== '') {
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
     * @return array<int,string>
     */
    private static function resolveFromStateFiles(PDO $pdo, array $catalog): array
    {
        $applied = [];
        foreach ((array)($catalog['entries'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $migrationId = trim((string)($entry['id'] ?? ''));
            $kind = trim((string)($entry['kind'] ?? ''));
            $stateFile = trim((string)($entry['state_path'] ?? ''));
            if ($migrationId === '' || !MigrationCatalog::isExecutableKind($kind) || $stateFile === '') {
                continue;
            }

            $definition = MigrationStateDefinition::load($stateFile, $migrationId);
            $markers = $definition['markers'] ?? [];
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
