<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use RuntimeException;
use Testkit\Core\Store\StoreRegistry;

require_once __DIR__ . '/../store/bootstrap.php';
require_once __DIR__ . '/MigrationPlanResolver.php';

final class ManifestPlanBuilder
{
    /**
     * @param array<string,mixed>|null $resolvedSnapshot
     * @return array<string,mixed>
     */
    public static function build(
        string $driver,
        string $seedDir,
        string $projectRoot,
        string $baselineMode,
        string $databaseName,
        string $manifestPath,
        ?array $resolvedSnapshot
    ): array {
        $migrationState = null;

        try {
            $adapter = StoreRegistry::fromDriver($driver);
            $pdo = $adapter->connect();
            $migrationPlan = MigrationPlanResolver::resolve($pdo, $seedDir, $baselineMode);
            $migrations = $migrationPlan->migrations();
            $skipPostValidations = $migrationPlan->skipPostValidations();
            $migrationState = $migrationPlan->migrationState();
        } catch (\Throwable $e) {
            if ($baselineMode === 'snapshot' && $e instanceof RuntimeException) {
                throw $e;
            }

            $migrations = self::parseCsvEnv('TEST_SEED_MIGRATIONS');
            $skipPostValidations = self::envBool('TEST_SEED_SKIP_VALIDATIONS_AFTER_EXTRAS', false);
        }

        $plan = [
            'driver' => $driver,
            'db_name' => $databaseName,
            'baseline_mode' => $baselineMode,
            'project_root' => self::realPathOrOriginal($projectRoot),
            'seed_dir' => self::realPathOrOriginal($seedDir),
            'manifest_path' => self::realPathOrOriginal($manifestPath),
            'resolved_snapshot' => $resolvedSnapshot,
            'requested_migrations' => $migrations,
            'migration_state' => is_array($migrationState) ? $migrationState : null,
            'skip_validations_after_extras' => $skipPostValidations,
            'layers' => [
                'schema' => self::directoryDescriptor($seedDir . '/schema'),
                'base' => self::directoryDescriptor($seedDir . '/base'),
                'validations' => self::directoryDescriptor($seedDir . '/validations'),
            ],
        ];

        if ($baselineMode === 'snapshot') {
            $snapshotFile = trim((string)($resolvedSnapshot['path'] ?? ''));
            $plan['snapshot'] = self::fileDescriptor($snapshotFile);
            $plan['snapshot_resolved_source'] = is_array($resolvedSnapshot) ? $resolvedSnapshot : [];
        }

        if ($migrations !== []) {
            $plan['migration_dirs'] = [];
            foreach ($migrations as $migration) {
                $plan['migration_dirs'][$migration] = self::directoryDescriptor($seedDir . '/migrations/' . $migration);
            }
        }

        $plan['fingerprint'] = hash(
            'sha256',
            (string)json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $plan;
    }

    /**
     * @return array<string,mixed>
     */
    private static function directoryDescriptor(string $dir): array
    {
        if (!is_dir($dir)) {
            return [
                'path' => self::realPathOrOriginal($dir),
                'exists' => false,
                'files' => [],
                'fingerprint' => null,
            ];
        }

        $files = self::listSqlFiles($dir);
        $fileDescriptors = [];
        foreach ($files as $file) {
            $fileDescriptors[] = self::fileDescriptor($file);
        }

        return [
            'path' => self::realPathOrOriginal($dir),
            'exists' => true,
            'files' => $fileDescriptors,
            'fingerprint' => hash(
                'sha256',
                (string)json_encode($fileDescriptors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function fileDescriptor(string $path): array
    {
        $normalized = self::realPathOrOriginal($path);
        if (!is_file($path)) {
            return [
                'path' => $normalized,
                'exists' => false,
                'size_bytes' => null,
                'sha256' => null,
            ];
        }

        $sha = hash_file('sha256', $path);

        return [
            'path' => $normalized,
            'exists' => true,
            'size_bytes' => filesize($path) ?: 0,
            'sha256' => is_string($sha) ? $sha : null,
        ];
    }

    /**
     * @return array<int,string>
     */
    private static function listSqlFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob(rtrim($dir, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        return array_values($files);
    }

    private static function realPathOrOriginal(string $path): string
    {
        $real = realpath($path);
        return $real !== false ? str_replace('\\', '/', $real) : str_replace('\\', '/', $path);
    }

    /**
     * @return array<int,string>
     */
    private static function parseCsvEnv(string $name): array
    {
        $raw = trim((string)(getenv($name) ?: ''));
        if ($raw === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $raw));
        $parts = array_values(array_filter($parts, static fn(string $value): bool => $value !== ''));
        return array_values(array_unique($parts));
    }

    private static function envBool(string $name, bool $default = false): bool
    {
        $raw = getenv($name);
        if ($raw === false) {
            return $default;
        }

        return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
    }
}
