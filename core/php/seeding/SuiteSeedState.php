<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use Throwable;
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
            try {
                $seedState = self::capture($repoRoot);
            } catch (Throwable $e) {
                $seedState = self::unavailableState(
                    'capture_failed',
                    'No se pudo capturar seed_state durante el enriquecimiento del reporte.',
                    $e
                );
            }
        }

        if (!is_array($seedState) || $seedState === []) {
            $seedState = self::unavailableState(
                'not_applicable',
                'Seed/bootstrap no aplica para esta corrida.'
            );
        }

        $seedState = self::normalizeSeedState($seedState);
        $report['seed_state'] = $seedState;
        return self::mirrorTopLevelFields($report, $seedState);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function capture(string $repoRoot): ?array
    {
        $repoRoot = Paths::normalize($repoRoot);
        $driver = self::safeDetectDriver();
        $strategy = self::normalizeStrategy(Env::string('TEST_DB_STRATEGY', 'shared'));

        [$explicitManifestPath, $explicitSourceKind] = self::explicitManifestSource();
        if ($explicitManifestPath !== '') {
            $manifest = BaselineManifest::load($explicitManifestPath);
            if (is_array($manifest)) {
                return self::fromManifest(
                    $repoRoot,
                    $driver,
                    self::resolveDbNameFromManifest($manifest),
                    $strategy,
                    $explicitManifestPath,
                    $explicitSourceKind,
                    $manifest
                );
            }
        }

        $baseDb = self::resolveDatabaseNameFromEnv($driver);
        if ($baseDb === '') {
            return self::fallbackFromEnv($repoRoot, $driver, '', '', 'no_store');
        }

        [$manifestPath, $sourceKind] = self::resolveManifestSource($repoRoot, $driver, $baseDb, $strategy);
        $manifest = $manifestPath !== '' ? BaselineManifest::load($manifestPath) : null;
        if (!is_array($manifest)) {
            return self::fallbackFromEnv($repoRoot, $driver, $baseDb, $manifestPath, $sourceKind);
        }

        return self::fromManifest($repoRoot, $driver, $baseDb, $strategy, $manifestPath, $sourceKind, $manifest);
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private static function fromManifest(
        string $repoRoot,
        string $driver,
        string $baseDb,
        string $strategy,
        string $manifestPath,
        string $sourceKind,
        array $manifest
    ): array {
        if ($baseDb === '') {
            $baseDb = self::resolveDbNameFromManifest($manifest);
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

        $applied = self::arrayOfStrings($migrationState['applied'] ?? ($manifest['applied_migrations'] ?? []));
        $pending = self::arrayOfStrings($migrationState['pending'] ?? ($manifest['pending_migrations'] ?? []));

        return self::normalizeSeedState([
            'available' => true,
            'contract_version' => 1,
            'source' => 'baseline_manifest',
            'driver' => $driver,
            'db_name' => (string)($manifest['db_name'] ?? $baseDb),
            'baseline' => $driver,
            'baseline_mode' => (string)($manifest['baseline_mode'] ?? ($manifest['plan']['baseline_mode'] ?? Env::string('TEST_BASELINE_MODE', 'layered'))),
            'profile' => self::deriveProfile($requestedOptIns),
            'source_kind' => $sourceKind,
            'store_strategy' => $strategy,
            'manifest_path' => self::normalizeArtifactPath($manifestPath),
            'snapshot_file' => self::normalizeArtifactPath($snapshotFile),
            'requested_migrations' => $requestedOptIns,
            'applied_migrations' => $applied,
            'pending_migrations' => $pending,
            'historical_absorbed' => self::arrayOfStrings($migrationState['historical_absorbed'] ?? ($catalog['historical_absorbed'] ?? [])),
            'migration_state' => self::normalizeMigrationState($migrationState, 'baseline_manifest', $applied, $pending, $catalog),
            'resolved_snapshot' => $resolvedSnapshot,
            'warnings' => self::legacyWarnings($requestedOptIns, $sourceKind, 'baseline_manifest'),
        ]);
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
        $report['requested_migrations'] = self::arrayOfStrings($seedState['requested_migrations'] ?? ($report['requested_migrations'] ?? []));
        $report['applied_migrations'] = self::arrayOfStrings($seedState['applied_migrations'] ?? ($report['applied_migrations'] ?? []));
        $report['pending_migrations'] = self::arrayOfStrings($seedState['pending_migrations'] ?? ($report['pending_migrations'] ?? []));
        $report['historical_absorbed'] = self::arrayOfStrings($seedState['historical_absorbed'] ?? ($report['historical_absorbed'] ?? []));
        $report['resolved_snapshot'] = $seedState['resolved_snapshot'] ?? ($report['resolved_snapshot'] ?? null);
        $report['seed_profile'] = (string)($seedState['profile'] ?? ($report['seed_profile'] ?? ''));
        $report['seed_driver'] = (string)($seedState['driver'] ?? ($report['seed_driver'] ?? ''));
        $report['seed_db_name'] = (string)($seedState['db_name'] ?? ($report['seed_db_name'] ?? ''));
        return $report;
    }

    /** @return array{0:string,1:string} */
    private static function explicitManifestSource(): array
    {
        $override = trim(Env::string('TEST_BASELINE_MANIFEST_PATH', ''));
        return $override === '' ? ['', ''] : [Paths::normalize($override), 'explicit_manifest'];
    }

    private static function safeDetectDriver(): string
    {
        try {
            return StoreRegistry::detectDriver('mysql');
        } catch (Throwable) {
            return 'mysql';
        }
    }

    private static function resolveDatabaseNameFromEnv(string $driver): string
    {
        if ($driver === 'pgsql') {
            return self::firstEnv(['PG_DB', 'TEST_PG_DB']);
        }
        return self::firstEnv(['DB_NAME', 'TEST_MYSQL_DB', 'MYSQL_DATABASE']);
    }

    /** @param array<string,mixed> $manifest */
    private static function resolveDbNameFromManifest(array $manifest): string
    {
        return trim((string)($manifest['db_name'] ?? $manifest['database'] ?? $manifest['plan']['db_name'] ?? ''));
    }

    /** @param array<int,string> $keys */
    private static function firstEnv(array $keys): string
    {
        foreach ($keys as $key) {
            $value = getenv($key);
            if ($value === false) {
                continue;
            }
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /** @return array{0:string,1:string} */
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

    /** @return array<string,mixed>|null */
    private static function fallbackFromEnv(string $repoRoot, string $driver, string $baseDb, string $manifestPath, string $sourceKind): ?array
    {
        $seedDir = $repoRoot . '/test/seeds/' . $driver;
        $catalog = is_dir($seedDir) ? MigrationCatalog::load($seedDir) : self::emptyCatalog();
        $requestedOptIns = self::optionalOnly(self::parseCsv(Env::string('TEST_SEED_MIGRATIONS', '')), $catalog);
        $baselineMode = Env::string('TEST_BASELINE_MODE', 'layered');
        $snapshotFile = Env::string('TEST_BASELINE_SNAPSHOT_FILE', '');

        if ($requestedOptIns === [] && $snapshotFile === '' && $manifestPath === '' && !is_dir($seedDir)) {
            return self::unavailableState('not_applicable', 'Seed/bootstrap no aplica: no hay store DB, manifest ni seeds configurados.');
        }

        return self::normalizeSeedState([
            'available' => true,
            'contract_version' => 1,
            'source' => 'env_fallback',
            'driver' => $driver,
            'db_name' => $baseDb,
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
            'migration_state' => self::normalizeMigrationState(null, 'env_fallback', [], [], $catalog),
            'resolved_snapshot' => null,
            'warnings' => self::legacyWarnings($requestedOptIns, $sourceKind, 'env_fallback'),
        ]);
    }

    /** @return array<string,mixed> */
    private static function unavailableState(string $reason, string $summary, ?Throwable $error = null): array
    {
        $warnings = [];
        if ($error !== null) {
            $warnings[] = [
                'code' => 'SEED_STATE_CAPTURE_FAILED',
                'severity' => 'warn',
                'classification' => 'operational',
                'blocking' => false,
                'summary' => $summary . ' ' . $error->getMessage(),
            ];
        }

        return [
            'available' => false,
            'contract_version' => 1,
            'source' => $reason,
            'source_kind' => $reason,
            'reason' => $reason,
            'reason_summary' => $summary,
            'driver' => '',
            'db_name' => '',
            'baseline' => '',
            'baseline_mode' => '',
            'profile' => '',
            'store_strategy' => self::normalizeStrategy(Env::string('TEST_DB_STRATEGY', 'shared')),
            'manifest_path' => '',
            'snapshot_file' => '',
            'requested_migrations' => [],
            'applied_migrations' => [],
            'pending_migrations' => [],
            'historical_absorbed' => [],
            'migration_state' => self::normalizeMigrationState(null, $reason, [], [], self::emptyCatalog()),
            'resolved_snapshot' => null,
            'warnings' => $warnings,
        ];
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private static function normalizeSeedState(array $state): array
    {
        $driver = trim((string)($state['driver'] ?? $state['baseline'] ?? ''));
        $applied = self::arrayOfStrings($state['applied_migrations'] ?? ($state['migration_state']['applied'] ?? []));
        $pending = self::arrayOfStrings($state['pending_migrations'] ?? ($state['migration_state']['pending'] ?? []));
        $historical = self::arrayOfStrings($state['historical_absorbed'] ?? ($state['migration_state']['historical_absorbed'] ?? []));
        $migrationState = is_array($state['migration_state'] ?? null) ? $state['migration_state'] : [];

        $migrationState = [
            'source' => (string)($migrationState['source'] ?? $state['source'] ?? $state['source_kind'] ?? ''),
            'mode' => (string)($migrationState['mode'] ?? ''),
            'available' => self::arrayOfStrings($migrationState['available'] ?? []),
            'applied' => self::arrayOfStrings($migrationState['applied'] ?? $applied),
            'pending' => self::arrayOfStrings($migrationState['pending'] ?? $pending),
            'target' => self::arrayOfStrings($migrationState['target'] ?? []),
            'historical_absorbed' => self::arrayOfStrings($migrationState['historical_absorbed'] ?? $historical),
        ];

        return [
            'available' => (bool)($state['available'] ?? true),
            'contract_version' => max(1, (int)($state['contract_version'] ?? 1)),
            'source' => (string)($state['source'] ?? $state['source_kind'] ?? ''),
            'driver' => $driver,
            'db_name' => (string)($state['db_name'] ?? $state['database'] ?? ''),
            'baseline' => (string)($state['baseline'] ?? $driver),
            'baseline_mode' => (string)($state['baseline_mode'] ?? ''),
            'profile' => (string)($state['profile'] ?? ''),
            'source_kind' => (string)($state['source_kind'] ?? $state['source'] ?? ''),
            'reason' => (string)($state['reason'] ?? ''),
            'reason_summary' => (string)($state['reason_summary'] ?? ''),
            'store_strategy' => (string)($state['store_strategy'] ?? ''),
            'manifest_path' => self::normalizeArtifactPath((string)($state['manifest_path'] ?? '')),
            'snapshot_file' => self::normalizeArtifactPath((string)($state['snapshot_file'] ?? '')),
            'requested_migrations' => self::arrayOfStrings($state['requested_migrations'] ?? []),
            'applied_migrations' => self::arrayOfStrings($state['applied_migrations'] ?? $migrationState['applied']),
            'pending_migrations' => self::arrayOfStrings($state['pending_migrations'] ?? $migrationState['pending']),
            'historical_absorbed' => $historical,
            'migration_state' => $migrationState,
            'resolved_snapshot' => is_array($state['resolved_snapshot'] ?? null) ? $state['resolved_snapshot'] : null,
            'warnings' => self::canonicalWarnings($state['warnings'] ?? []),
        ];
    }

    /**
     * @param array<string,mixed>|null $state
     * @param array<int,string> $fallbackApplied
     * @param array<int,string> $fallbackPending
     * @param array<string,mixed> $catalog
     * @return array<string,mixed>
     */
    private static function normalizeMigrationState(?array $state, string $source, array $fallbackApplied, array $fallbackPending, array $catalog): array
    {
        $state = is_array($state) ? $state : [];
        return [
            'source' => (string)($state['source'] ?? $source),
            'mode' => (string)($state['mode'] ?? ''),
            'available' => self::arrayOfStrings($state['available'] ?? ($catalog['executable'] ?? [])),
            'applied' => self::arrayOfStrings($state['applied'] ?? $fallbackApplied),
            'pending' => self::arrayOfStrings($state['pending'] ?? $fallbackPending),
            'target' => self::arrayOfStrings($state['target'] ?? []),
            'historical_absorbed' => self::arrayOfStrings($state['historical_absorbed'] ?? ($catalog['historical_absorbed'] ?? [])),
        ];
    }

    /** @param array<string,mixed> $catalog @param array<int,string> $selected @return array<int,string> */
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
        return in_array($strategy, ['shared', 'clean', 'per_worker'], true) ? $strategy : 'shared';
    }

    private static function normalizeArtifactPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $normalized = Paths::normalize($path);
        $repoRoot = Paths::repoRoot() . '/';
        return str_starts_with($normalized, $repoRoot) ? Paths::relativeToRepo($normalized) : $normalized;
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private static function normalizeResolvedSnapshot(array $snapshot): array
    {
        foreach (['path', 'source_path', 'metadata_path', 'report_path'] as $key) {
            if (isset($snapshot[$key]) && is_string($snapshot[$key])) {
                $snapshot[$key] = self::normalizeArtifactPath((string)$snapshot[$key]);
            }
        }
        return $snapshot;
    }

    /** @param mixed $value @return array<int,string> */
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

    /** @return array<int,string> */
    private static function parseCsv(string $value): array
    {
        return trim($value) === '' ? [] : self::arrayOfStrings(explode(',', $value));
    }

    /** @return array<string,mixed> */
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

    /** @param array<int,string> $requestedOptIns @return array<int,array<string,mixed>> */
    private static function legacyWarnings(array $requestedOptIns, string $sourceKind, string $source): array
    {
        $raw = trim(Env::string('TEST_SEED_MIGRATIONS', ''));
        if ($raw === '' && !str_contains($sourceKind, 'env_fallback') && $source !== 'env_fallback') {
            return [];
        }
        return [[
            'code' => 'LEGACY_TEST_SEED_MIGRATIONS_FALLBACK',
            'severity' => 'warn',
            'classification' => 'configuration',
            'blocking' => false,
            'summary' => 'TEST_SEED_MIGRATIONS queda soportado como compatibilidad legacy/deprecated; el contrato canónico para consumidores es seed_state/canonical_report.seed_state.',
            'count' => 1,
            'context' => [
                'source' => $source,
                'source_kind' => $sourceKind,
                'requested_migrations' => array_values($requestedOptIns),
            ],
        ]];
    }

    /** @param mixed $warnings @return array<int,array<string,mixed>> */
    private static function canonicalWarnings(mixed $warnings): array
    {
        if (!is_array($warnings)) {
            return [];
        }
        $rows = [];
        foreach ($warnings as $warning) {
            if (!is_array($warning)) {
                continue;
            }
            $blocking = (bool)($warning['blocking'] ?? false);
            $severity = strtolower(trim((string)($warning['severity'] ?? ($blocking ? 'error' : 'warn'))));
            if ($severity === 'warning') {
                $severity = 'warn';
            }
            if (!in_array($severity, ['info', 'warn', 'error'], true)) {
                $severity = $blocking ? 'error' : 'warn';
            }
            $rows[] = [
                'code' => strtoupper(trim((string)($warning['code'] ?? 'GENERIC_WARNING'))) ?: 'GENERIC_WARNING',
                'severity' => $severity,
                'classification' => trim((string)($warning['classification'] ?? '')) !== ''
                    ? strtolower(trim((string)$warning['classification']))
                    : ($blocking || $severity === 'error' ? 'blocking' : 'configuration'),
                'blocking' => $blocking,
                'summary' => trim((string)($warning['summary'] ?? 'warning')) ?: 'warning',
                'count' => max(1, (int)($warning['count'] ?? 1)),
                'context' => is_array($warning['context'] ?? null) ? $warning['context'] : [],
            ];
        }
        return array_values($rows);
    }
}
