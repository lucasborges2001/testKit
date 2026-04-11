<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;
use Testkit\Core\Store\StoreRegistry;

require_once __DIR__ . '/../store/bootstrap.php';
require_once __DIR__ . '/BaselineManifest.php';
require_once __DIR__ . '/MigrationCatalog.php';

final class SuiteSeedState
{
    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public static function attachToReport(array $report, string $repoRoot): array
    {
        $seedState = $report['seed_state'] ?? null;
        if (!is_array($seedState) || $seedState === []) {
            $seedState = self::capture($repoRoot);
        }

        if (!is_array($seedState) || $seedState === []) {
            return $report;
        }

        $report['seed_state'] = $seedState;
        return self::mirrorTopLevelFields($report, $seedState);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function capture(string $repoRoot): ?array
    {
        $repoRoot = Paths::normalize($repoRoot);
        $driver = StoreRegistry::detectDriver('mysql');
        $strategy = self::normalizeStrategy(Env::string('TEST_DB_STRATEGY', 'shared'));

        $adapter = StoreRegistry::fromDriver($driver);
        $baseDb = trim((string)$adapter->resolveDatabaseName());
        if ($baseDb === '') {
            return self::fallbackFromEnv($repoRoot, $driver, '', 'env_only');
        }

        [$manifestPath, $sourceKind] = self::resolveManifestSource($repoRoot, $driver, $baseDb, $strategy);
        $manifest = $manifestPath !== '' ? BaselineManifest::load($manifestPath) : null;

        if (!is_array($manifest)) {
            return self::fallbackFromEnv($repoRoot, $driver, $manifestPath, $sourceKind);
        }

        $seedDir = $repoRoot . '/test/seeds/' . $driver;
        $catalog = is_dir($seedDir) ? MigrationCatalog::load($seedDir) : self::emptyCatalog();

        $migrationState = $manifest['migration_state'] ?? ($manifest['plan']['migration_state'] ?? null);
        $migrationState = is_array($migrationState) ? $migrationState : null;

        $requestedRaw = self::arrayOfStrings(
            $manifest['plan']['requested_migrations'] ?? ($manifest['requested_migrations'] ?? [])
        );
        $requestedOptIns = self::optionalOnly($requestedRaw, $catalog);

        $resolvedSnapshot = $manifest['resolved_snapshot'] ?? ($manifest['plan']['resolved_snapshot'] ?? null);
        $resolvedSnapshot = is_array($resolvedSnapshot) ? self::normalizeResolvedSnapshot($resolvedSnapshot) : null;

        $snapshotFile = '';
        if (is_array($resolvedSnapshot)) {
            $snapshotFile = trim((string)($resolvedSnapshot['path'] ?? ''));
        }
        if ($snapshotFile === '') {
            $snapshotPlan = $manifest['plan']['snapshot'] ?? null;
            if (is_array($snapshotPlan)) {
                $snapshotFile = trim((string)($snapshotPlan['path'] ?? ''));
            }
        }

        return [
            'baseline' => $driver,
            'baseline_mode' => (string)($manifest['baseline_mode'] ?? ($manifest['plan']['baseline_mode'] ?? Env::string('TEST_BASELINE_MODE', 'layered'))),
            'profile' => self::deriveProfile($requestedOptIns),
            'source_kind' => $sourceKind,
            'store_strategy' => $strategy,
            'manifest_path' => self::normalizeArtifactPath($manifestPath),
            'snapshot_file' => self::normalizeArtifactPath($snapshotFile),
            'requested_migrations' => $requestedOptIns,
            'applied_migrations' => self::arrayOfStrings(
                $migrationState['applied'] ?? ($manifest['applied_migrations'] ?? [])
            ),
            'pending_migrations' => self::arrayOfStrings(
                $migrationState['pending'] ?? ($manifest['pending_migrations'] ?? [])
            ),
            'historical_absorbed' => self::arrayOfStrings(
                $migrationState['historical_absorbed'] ?? ($catalog['historical_absorbed'] ?? [])
            ),
            'migration_state' => $migrationState,
            'resolved_snapshot' => $resolvedSnapshot,
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $seedState
     * @return array<string,mixed>
     */
    private static function mirrorTopLevelFields(array $report, array $seedState): array
    {
        $report['baseline_mode'] = (string)($seedState['baseline_mode'] ?? $report['baseline_mode'] ?? '');
        $report['snapshot_file'] = (string)($seedState['snapshot_file'] ?? $report['snapshot_file'] ?? '');
        $report['manifest_path'] = (string)($seedState['manifest_path'] ?? $report['manifest_path'] ?? '');
        $report['migration_state'] = $seedState['migration_state'] ?? ($report['migration_state'] ?? null);
        $report['requested_migrations'] = self::arrayOfStrings(
            $seedState['requested_migrations'] ?? ($report['requested_migrations'] ?? [])
        );
        $report['applied_migrations'] = self::arrayOfStrings(
            $seedState['applied_migrations'] ?? ($report['applied_migrations'] ?? [])
        );
        $report['pending_migrations'] = self::arrayOfStrings(
            $seedState['pending_migrations'] ?? ($report['pending_migrations'] ?? [])
        );
        $report['historical_absorbed'] = self::arrayOfStrings(
            $seedState['historical_absorbed'] ?? ($report['historical_absorbed'] ?? [])
        );
        $report['resolved_snapshot'] = $seedState['resolved_snapshot'] ?? ($report['resolved_snapshot'] ?? null);
        $report['seed_profile'] = (string)($seedState['profile'] ?? ($report['seed_profile'] ?? ''));
        return $report;
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function resolveManifestSource(string $repoRoot, string $driver, string $baseDb, string $strategy): array
    {
        if ($baseDb === '') {
            return ['', 'env_only'];
        }

        if ($strategy === 'per_worker') {
            if (Env::bool('TEST_BASELINE_CLONE_PER_WORKER', false)) {
                $baselineDb = self::resolveBaselineDatabaseName($baseDb);
                return [BaselineManifest::pathFor($repoRoot, $driver, $baselineDb), 'per_worker_baseline'];
            }

            $workerDb = self::buildWorkerDatabaseName($baseDb, 1);
            return [BaselineManifest::pathFor($repoRoot, $driver, $workerDb), 'per_worker_worker_1'];
        }

        return [BaselineManifest::pathFor($repoRoot, $driver, $baseDb), 'current_db_manifest'];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function fallbackFromEnv(string $repoRoot, string $driver, string $manifestPath, string $sourceKind): ?array
    {
        $seedDir = $repoRoot . '/test/seeds/' . $driver;
        $catalog = is_dir($seedDir) ? MigrationCatalog::load($seedDir) : self::emptyCatalog();

        $requestedOptIns = self::optionalOnly(
            self::parseCsv(Env::string('TEST_SEED_MIGRATIONS', '')),
            $catalog
        );

        $baselineMode = Env::string('TEST_BASELINE_MODE', 'layered');
        $snapshotFile = Env::string('TEST_BASELINE_SNAPSHOT_FILE', '');

        if ($requestedOptIns === [] && $snapshotFile === '' && $manifestPath === '' && !is_dir($seedDir)) {
            return null;
        }

        return [
            'baseline' => $driver,
            'baseline_mode' => $baselineMode,
            'profile' => self::deriveProfile($requestedOptIns),
            'source_kind' => $sourceKind . '_env_fallback',
            'store_strategy' => self::normalizeStrategy(Env::string('TEST_DB_STRATEGY', 'shared')),
            'manifest_path' => self::normalizeArtifactPath($manifestPath),
            'snapshot_file' => self::normalizeArtifactPath($snapshotFile),
            'requested_migrations' => $requestedOptIns,
            'applied_migrations' => [],
            'pending_migrations' => [],
            'historical_absorbed' => self::arrayOfStrings($catalog['historical_absorbed'] ?? []),
            'migration_state' => null,
            'resolved_snapshot' => null,
        ];
    }

    /**
     * @param array<string,mixed> $catalog
     * @param array<int,string> $selected
     * @return array<int,string>
     */
    private static function optionalOnly(array $selected, array $catalog): array
    {
        $selected = self::arrayOfStrings($selected);
        if ($selected === []) {
            return [];
        }

        $active = self::arrayOfStrings($catalog['active'] ?? []);
        $executable = self::arrayOfStrings($catalog['executable'] ?? []);

        $out = [];
        foreach ($selected as $id) {
            if ($executable !== [] && !in_array($id, $executable, true)) {
                continue;
            }
            if (in_array($id, $active, true)) {
                continue;
            }
            $out[] = $id;
        }

        return self::arrayOfStrings($out);
    }

    private static function deriveProfile(array $requestedOptIns): string
    {
        return $requestedOptIns === [] ? 'baseline_pure' : 'baseline_plus_optins';
    }

    private static function resolveBaselineDatabaseName(string $baseDb): string
    {
        $explicit = trim(Env::string('TEST_BASELINE_DB_NAME', ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $suffix = trim(Env::string('TEST_BASELINE_DB_SUFFIX', '_baseline'));
        if ($suffix === '' || preg_match('/^[A-Za-z0-9._-]+$/', $suffix) !== 1) {
            $suffix = '_baseline';
        }

        return $baseDb . $suffix;
    }

    private static function buildWorkerDatabaseName(string $baseDb, int $workerId): string
    {
        $format = Env::string('TEST_DB_WORKER_SUFFIX_FORMAT', '_w%02d');
        if (preg_match('/^[A-Za-z0-9_%._-]+$/', $format) !== 1) {
            $format = '_w%02d';
        }

        $suffix = @sprintf($format, $workerId);
        if (!is_string($suffix) || $suffix === '' || preg_match('/^[A-Za-z0-9._-]+$/', $suffix) !== 1) {
            $suffix = '_w' . $workerId;
        }

        return $baseDb . $suffix;
    }

    private static function normalizeStrategy(string $strategy): string
    {
        $strategy = strtolower(trim($strategy));
        if (!in_array($strategy, ['shared', 'clean', 'per_worker'], true)) {
            return 'shared';
        }

        return $strategy;
    }

    private static function normalizeArtifactPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $normalized = Paths::normalize($path);
        $repoRoot = Paths::repoRoot() . '/';
        if (str_starts_with($normalized, $repoRoot)) {
            return Paths::relativeToRepo($normalized);
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private static function normalizeResolvedSnapshot(array $snapshot): array
    {
        foreach (['path', 'source_path', 'metadata_path', 'report_path'] as $key) {
            if (isset($snapshot[$key]) && is_string($snapshot[$key])) {
                $snapshot[$key] = self::normalizeArtifactPath((string)$snapshot[$key]);
            }
        }

        return $snapshot;
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private static function arrayOfStrings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $out[$item] = true;
            }
        }

        $rows = array_keys($out);
        natcasesort($rows);
        return array_values($rows);
    }

    /**
     * @return array<int,string>
     */
    private static function parseCsv(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return self::arrayOfStrings(explode(',', $value));
    }

    /**
     * @return array<string,mixed>
     */
    private static function emptyCatalog(): array
    {
        return [
            'entries' => [],
            'executable' => [],
            'active' => [],
            'optional' => [],
            'historical_absorbed' => [],
        ];
    }
}
