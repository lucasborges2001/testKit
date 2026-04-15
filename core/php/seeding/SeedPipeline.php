<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use Testkit\Core\Store\StoreRegistry;

require_once __DIR__ . '/../store/bootstrap.php';
require_once __DIR__ . '/BaselineManifest.php';
require_once __DIR__ . '/BaselineManifestWriter.php';
require_once __DIR__ . '/BaselineModeResolver.php';
require_once __DIR__ . '/BaselineReuseDecider.php';
require_once __DIR__ . '/BackupkitArtifactResolver.php';
require_once __DIR__ . '/ManifestPlanBuilder.php';
require_once __DIR__ . '/SeedBootstrapTracer.php';
require_once __DIR__ . '/SeedFailure.php';
require_once __DIR__ . '/SeedManifestPlanInput.php';
require_once __DIR__ . '/SeedManifestPlanInputResolver.php';
require_once __DIR__ . '/SeedMaterializer.php';
require_once __DIR__ . '/SeedMaterializerResolver.php';
require_once __DIR__ . '/SeedRuntimeContext.php';

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

        $context = new SeedRuntimeContext(
            $driver,
            $seedDir,
            $projectRoot,
            $baselineMode,
            $adapter,
            $databaseName,
            $resolvedSnapshot
        );

        SeedBootstrapTracer::trace($context, $manifestPath);

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
            $manifestInput = SeedManifestPlanInputResolver::resolve($context);
            $manifestPlan = ManifestPlanBuilder::build($context, $manifestPath, $manifestInput);
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

        $materializer = SeedMaterializerResolver::resolve($context);
        $result = $materializer->run($context);

        BaselineManifestWriter::write($manifestPath, $manifestPlan, $context);

        return $result;
    }

    private static function realPathOrOriginal(string $path): string
    {
        $real = realpath($path);
        return $real !== false ? str_replace('\\', '/', $real) : str_replace('\\', '/', $path);
    }
}
