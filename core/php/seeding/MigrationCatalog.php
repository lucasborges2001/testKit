<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use RuntimeException;

final class MigrationCatalog
{
    public const KIND_OPTIONAL = 'optional_migration';
    public const KIND_ACTIVE = 'active_migration';
    public const KIND_HISTORICAL_ABSORBED = 'historical_absorbed_change';

    /**
     * @return array<string,mixed>
     */
    public static function load(string $seedDir): array
    {
        $root = rtrim($seedDir, '/\\') . '/migrations';
        if (!is_dir($root)) {
            return [
                'entries' => [],
                'executable' => [],
                'active' => [],
                'optional' => [],
                'historical_absorbed' => [],
            ];
        }

        $dirs = glob($root . '/*', GLOB_ONLYDIR) ?: [];
        $entries = [];
        foreach ($dirs as $dir) {
            $id = basename($dir);
            if ($id === '' || $id === '.' || $id === '..') {
                continue;
            }

            $entries[] = self::loadEntry($dir, $id);
        }

        usort(
            $entries,
            static fn(array $left, array $right): int => strnatcasecmp((string)$left['id'], (string)$right['id'])
        );

        $executable = [];
        $active = [];
        $optional = [];
        $historicalAbsorbed = [];
        foreach ($entries as $entry) {
            $id = (string)$entry['id'];
            $kind = (string)$entry['kind'];
            if (self::isExecutableKind($kind)) {
                $executable[] = $id;
                if ($kind === self::KIND_ACTIVE) {
                    $active[] = $id;
                }
                if ($kind === self::KIND_OPTIONAL) {
                    $optional[] = $id;
                }
                continue;
            }

            if ($kind === self::KIND_HISTORICAL_ABSORBED) {
                $historicalAbsorbed[] = $id;
            }
        }

        return [
            'entries' => $entries,
            'executable' => $executable,
            'active' => $active,
            'optional' => $optional,
            'historical_absorbed' => $historicalAbsorbed,
        ];
    }

    public static function isExecutableKind(string $kind): bool
    {
        return in_array($kind, [self::KIND_OPTIONAL, self::KIND_ACTIVE], true);
    }

    /**
     * @param array<int,string> $selected
     * @return array<int,string>
     */
    public static function normalizeSelectedExecutables(string $seedDir, array $selected): array
    {
        $catalog = self::load($seedDir);
        return self::normalizeSelectedExecutablesFromCatalog($catalog, $selected);
    }

    /**
     * @param array<string,mixed> $catalog
     * @param array<int,string> $selected
     * @return array<int,string>
     */
    public static function normalizeSelectedExecutablesFromCatalog(array $catalog, array $selected): array
    {
        $normalized = self::normalizeIds($selected);
        if ($normalized === []) {
            return [];
        }

        $executable = array_values((array)($catalog['executable'] ?? []));
        $historicalAbsorbed = array_values((array)($catalog['historical_absorbed'] ?? []));

        $invalid = [];
        foreach ($normalized as $id) {
            if (!in_array($id, $executable, true)) {
                $invalid[] = $id;
            }
        }

        if ($invalid !== []) {
            $historical = array_values(array_intersect($invalid, $historicalAbsorbed));
            $unknown = array_values(array_diff($invalid, $historical));
            $parts = [];
            if ($historical !== []) {
                $parts[] = 'historical_absorbed_change=' . implode(',', $historical);
            }
            if ($unknown !== []) {
                $parts[] = 'unknown=' . implode(',', $unknown);
            }

            throw new RuntimeException(
                'TEST_SEED_MIGRATIONS contiene migraciones no ejecutables. ' .
                implode(' ', $parts)
            );
        }

        return $normalized;
    }

    /**
     * @return array<int,string>
     */
    private static function normalizeIds(array $selected): array
    {
        $normalized = [];
        foreach ($selected as $item) {
            $value = trim((string)$item);
            if ($value === '') {
                continue;
            }
            $normalized[] = $value;
        }

        $normalized = array_values(array_unique($normalized));
        natcasesort($normalized);
        return array_values($normalized);
    }

    /**
     * @return array<string,mixed>
     */
    private static function loadEntry(string $dir, string $id): array
    {
        $metaPath = $dir . '/migration.json';
        if (!is_file($metaPath)) {
            throw new RuntimeException('Falta migration.json para la migración de test: ' . $id);
        }

        $raw = file_get_contents($metaPath);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($json) || array_is_list($json)) {
            throw new RuntimeException('migration.json inválido para la migración de test: ' . $id);
        }

        $unknown = array_values(array_diff(array_keys($json), ['id', 'kind']));
        if ($unknown !== []) {
            throw new RuntimeException(
                'migration.json declara campos no soportados para la migración de test '
                . $id . ': ' . implode(',', $unknown)
            );
        }

        $declaredId = trim((string)($json['id'] ?? ''));
        if ($declaredId === '') {
            throw new RuntimeException('migration.json debe declarar id para la migración de test: ' . $id);
        }

        if ($declaredId !== $id) {
            throw new RuntimeException(
                'migration.json declara id distinto al directorio para la migración de test '
                . $id . ': ' . $declaredId
            );
        }

        $kind = strtolower(trim((string)($json['kind'] ?? '')));
        if (!in_array($kind, self::supportedKinds(), true)) {
            throw new RuntimeException(
                'migration.json declara kind inválido para la migración de test ' . $id . ': ' . $kind
            );
        }

        $statePath = $dir . '/state.json';
        if ($kind === self::KIND_HISTORICAL_ABSORBED && is_file($statePath)) {
            throw new RuntimeException(
                'state.json no está permitido para la migración historical_absorbed_change ' . $id
            );
        }

        if (self::isExecutableKind($kind) && self::listSqlFiles($dir) === []) {
            throw new RuntimeException(
                'migration.json declara migración ejecutable sin SQL para la migración de test: ' . $id
            );
        }

        return [
            'id' => $id,
            'kind' => $kind,
            'dir' => str_replace('\\', '/', $dir),
            'metadata_path' => str_replace('\\', '/', $metaPath),
            'state_path' => is_file($statePath) ? str_replace('\\', '/', $statePath) : null,
        ];
    }

    /**
     * @return array<int,string>
     */
    private static function supportedKinds(): array
    {
        return [
            self::KIND_OPTIONAL,
            self::KIND_ACTIVE,
            self::KIND_HISTORICAL_ABSORBED,
        ];
    }

    /**
     * @return array<int,string>
     */
    private static function listSqlFiles(string $dir): array
    {
        $files = glob(rtrim($dir, '/\\') . '/*.sql') ?: [];
        natcasesort($files);
        return array_values($files);
    }
}
