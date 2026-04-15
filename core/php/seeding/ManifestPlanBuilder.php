<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

require_once __DIR__ . '/SeedManifestPlanInput.php';
require_once __DIR__ . '/SeedRuntimeContext.php';

final class ManifestPlanBuilder
{
    /**
     * @return array<string,mixed>
     */
    public static function build(
        SeedRuntimeContext $context,
        string $manifestPath,
        SeedManifestPlanInput $manifestInput
    ): array {
        $requestedMigrations = $manifestInput->requestedMigrations();

        $plan = [
            'driver' => $context->driver(),
            'db_name' => $context->databaseName(),
            'baseline_mode' => $context->baselineMode(),
            'project_root' => $context->realPathOrOriginal($context->projectRoot()),
            'seed_dir' => $context->realPathOrOriginal($context->seedDir()),
            'manifest_path' => $context->realPathOrOriginal($manifestPath),
            'resolved_snapshot' => $context->resolvedSnapshot(),
            'requested_migrations' => $requestedMigrations,
            'migration_state' => $manifestInput->migrationState(),
            'skip_validations_after_extras' => $manifestInput->skipPostValidations(),
            'layers' => [
                'schema' => self::directoryDescriptor($context->seedDir() . '/schema'),
                'base' => self::directoryDescriptor($context->seedDir() . '/base'),
                'validations' => self::directoryDescriptor($context->seedDir() . '/validations'),
            ],
        ];

        if ($context->baselineMode() === 'snapshot') {
            $snapshotFile = trim((string)($context->resolvedSnapshot()['path'] ?? ''));
            $plan['snapshot'] = self::fileDescriptor($snapshotFile);
            $plan['snapshot_resolved_source'] = is_array($context->resolvedSnapshot()) ? $context->resolvedSnapshot() : [];
        }

        if ($requestedMigrations !== []) {
            $plan['migration_dirs'] = [];
            foreach ($requestedMigrations as $migration) {
                $plan['migration_dirs'][$migration] = self::directoryDescriptor(
                    $context->seedDir() . '/migrations/' . $migration
                );
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
}
