<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use Testkit\Core\Common\Trace;
use Testkit\Core\Store\StoreRegistry;

require_once __DIR__ . '/../store/bootstrap.php';
require_once __DIR__ . '/BaselineManifest.php';
require_once __DIR__ . '/BaselineModeResolver.php';
require_once __DIR__ . '/BaselineReuseDecider.php';
require_once __DIR__ . '/BackupkitArtifactResolver.php';
require_once __DIR__ . '/FlatSeedMaterializer.php';
require_once __DIR__ . '/LayeredSeedMaterializer.php';
require_once __DIR__ . '/ManifestPlanBuilder.php';
require_once __DIR__ . '/MigrationCatalog.php';
require_once __DIR__ . '/MigrationPlanResolver.php';
require_once __DIR__ . '/MigrationStateResolver.php';
require_once __DIR__ . '/SeedFailure.php';
require_once __DIR__ . '/SeedMaterializer.php';
require_once __DIR__ . '/SeedRuntimeContext.php';
require_once __DIR__ . '/SnapshotSeedMaterializer.php';
require_once __DIR__ . '/SqlFailureHintResolver.php';
require_once __DIR__ . '/SqlSeedExecutor.php';

final class SeedPipeline
{
    public static function run(string $driver, string $projectRoot): int
    {
        $driver = StoreRegistry::normalizeDriver($driver);
        $projectRoot = rtrim($projectRoot, "/\\");
        $seedDir = $projectRoot . '/test/seeds/' . $driver;
        $baselineMode = BaselineModeResolver::mode();
        $adapter = StoreRegistry::fromDriver($driver);

        try {
            $databaseName = trim((string)$adapter->resolveDatabaseName());
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo resolver el nombre de base para el seed.', [
                'stage' => 'database_name',
                'driver' => $driver,
                'db_driver' => $driver,
                'seed_dir' => self::realPathOrOriginal($seedDir),
                'project_root' => self::realPathOrOriginal($projectRoot),
                'hint' => 'Definí correctamente las variables de DB en test/.env.test o DB_ENV_PATH antes de bootstrapping.',
            ]);
        }

        $manifestPath = BaselineManifest::pathFor($projectRoot, $driver, $databaseName);

        try {
            $resolvedSnapshot = $baselineMode === 'snapshot' ? BackupkitArtifactResolver::resolveFromEnv() : null;
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo resolver el snapshot baseline para el seed.', [
                'stage' => 'snapshot_resolve',
                'driver' => $driver,
                'db_driver' => $driver,
                'db_name' => $databaseName,
                'label' => 'snapshot baseline',
                'seed_dir' => self::realPathOrOriginal($seedDir),
                'hint' => 'Definí TEST_BASELINE_SNAPSHOT_FILE o metadata/report de backupkit válido para modo snapshot.',
            ]);
        }

        self::traceBootstrapContext($driver, $projectRoot, $seedDir, $baselineMode, $databaseName, $manifestPath, $resolvedSnapshot);

        if (!is_dir($seedDir)) {
            throw new SeedFailure('No existe el directorio de seeds requerido para el bootstrap.', [
                'stage' => 'seed_dir',
                'driver' => $driver,
                'db_driver' => $driver,
                'db_name' => $databaseName,
                'file' => self::realPathOrOriginal($seedDir),
                'hint' => 'Creá test/seeds/' . $driver . ' o corregí TESTKIT_PROJECT_ROOT/TK_REPO_ROOT.',
            ]);
        }

        try {
            $manifestPlan = ManifestPlanBuilder::build(
                $driver,
                $seedDir,
                $projectRoot,
                $baselineMode,
                $databaseName,
                $manifestPath,
                $resolvedSnapshot
            );
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo construir el plan del baseline de seed.', [
                'stage' => 'manifest_plan',
                'driver' => $driver,
                'db_driver' => $driver,
                'db_name' => $databaseName,
                'label' => $baselineMode,
                'hint' => 'Revisá configuración de baseline, migraciones y materialización del estado previo al seed.',
            ]);
        }

        if (BaselineReuseDecider::canReuse($driver, $databaseName, $manifestPath, $manifestPlan)) {
            echo "Baseline reutilizado desde manifest\n";
            return 0;
        }

        $context = new SeedRuntimeContext(
            $driver,
            $seedDir,
            $projectRoot,
            $baselineMode,
            $adapter,
            $databaseName,
            $resolvedSnapshot
        );

        $materializer = self::resolveMaterializer($context);
        $result = $materializer->run($context);

        self::writeManifest(
            $manifestPath,
            $manifestPlan,
            $driver,
            $databaseName,
            $baselineMode,
            $projectRoot,
            $seedDir,
            $resolvedSnapshot
        );

        return $result;
    }

    private static function hasLayeredLayout(string $seedDir): bool
    {
        return is_dir($seedDir . '/schema') && is_dir($seedDir . '/base');
    }

    private static function resolveMaterializer(SeedRuntimeContext $context): SeedMaterializer
    {
        if ($context->baselineMode() === 'snapshot') {
            return new SnapshotSeedMaterializer();
        }

        if (self::hasLayeredLayout($context->seedDir())) {
            return new LayeredSeedMaterializer();
        }

        return new FlatSeedMaterializer();
    }

    /**
     * @param array<string,mixed>|null $resolvedSnapshot
     */
    private static function writeManifest(
        string $manifestPath,
        array $manifestPlan,
        string $driver,
        string $databaseName,
        string $baselineMode,
        string $projectRoot,
        string $seedDir,
        ?array $resolvedSnapshot
    ): void {
        $payload = [
            'status' => 'ready',
            'driver' => $driver,
            'db_name' => $databaseName,
            'baseline_mode' => $baselineMode,
            'baseline_fingerprint' => (string)($manifestPlan['fingerprint'] ?? ''),
            'generated_at' => gmdate(DATE_ATOM),
            'project_root' => self::realPathOrOriginal($projectRoot),
            'seed_dir' => self::realPathOrOriginal($seedDir),
            'manifest_path' => self::realPathOrOriginal($manifestPath),
            'resolved_snapshot' => $resolvedSnapshot,
            'migration_state' => $manifestPlan['migration_state'] ?? null,
            'plan' => $manifestPlan,
        ];

        BaselineManifest::save($manifestPath, $payload);

        Trace::log('baseline.manifest.write', [
            'driver' => $driver,
            'db' => $databaseName,
            'manifest_path' => self::realPathOrOriginal($manifestPath),
            'resolved_snapshot' => $resolvedSnapshot,
            'baseline_mode' => $baselineMode,
            'fingerprint' => (string)($manifestPlan['fingerprint'] ?? ''),
        ]);
    }

    /**
     * @param array<string,mixed>|null $resolvedSnapshot
     */
    private static function traceBootstrapContext(
        string $driver,
        string $projectRoot,
        string $seedDir,
        string $baselineMode,
        string $databaseName,
        string $manifestPath,
        ?array $resolvedSnapshot
    ): void {
        Trace::log('seed.bootstrap.context', [
            'driver' => $driver,
            'project_root' => self::realPathOrOriginal($projectRoot),
            'seed_dir' => self::realPathOrOriginal($seedDir),
            'baseline_mode' => $baselineMode,
            'db_name' => $databaseName,
            'baseline_reuse' => BaselineModeResolver::reuseEnabled(),
            'baseline_invalidate' => BaselineModeResolver::invalidateRequested(),
            'baseline_manifest_path' => self::realPathOrOriginal($manifestPath),
            'resolved_snapshot' => $resolvedSnapshot,
            'DB_ENV_PATH' => (string)(getenv('DB_ENV_PATH') ?: ''),
            'TESTKIT_PROJECT_ROOT' => (string)(getenv('TESTKIT_PROJECT_ROOT') ?: ''),
            'TK_REPO_ROOT' => (string)(getenv('TK_REPO_ROOT') ?: ''),
            'TEST_MATCH' => (string)(getenv('TEST_MATCH') ?: ''),
        ]);
    }

    private static function realPathOrOriginal(string $path): string
    {
        $real = realpath($path);
        return $real !== false ? str_replace('\\', '/', $real) : str_replace('\\', '/', $path);
    }
}
