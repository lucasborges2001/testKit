<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use Testkit\Core\Common\Trace;

require_once __DIR__ . '/BackupkitArtifactResolver.php';
require_once __DIR__ . '/MigrationPlanResolver.php';
require_once __DIR__ . '/SeedFailure.php';
require_once __DIR__ . '/SeedMaterializer.php';
require_once __DIR__ . '/SeedMigrationPlan.php';
require_once __DIR__ . '/SeedRuntimeContext.php';
require_once __DIR__ . '/SqlSeedExecutor.php';

final class SnapshotSeedMaterializer implements SeedMaterializer
{
    public function run(SeedRuntimeContext $context): int
    {
        try {
            $pdo = $context->adapter()->connect();
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo conectar a la DB para bootstrap snapshot.', [
                'stage' => 'connect',
                'driver' => $context->driver(),
                'db_driver' => $context->driver(),
                'label' => 'snapshot',
                'db_name' => $context->connectionSummary()['db'] ?? '',
                'hint' => 'Revisá credenciales y que la base objetivo exista o pueda provisionarse.',
            ]);
        }

        try {
            $resolvedSnapshot = $context->resolvedSnapshot() ?? BackupkitArtifactResolver::resolveFromEnv();
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo resolver el artifact snapshot durante el bootstrap.', [
                'stage' => 'snapshot_resolve',
                'driver' => $context->driver(),
                'db_driver' => $context->driver(),
                'db_name' => $context->currentDatabaseName($pdo),
                'label' => 'snapshot',
                'hint' => 'Validá path del dump o metadata/report de backupkit antes de restaurar.',
            ]);
        }

        $snapshotFile = trim((string)($resolvedSnapshot['path'] ?? ''));
        if ($snapshotFile === '') {
            throw new SeedFailure('TEST_BASELINE_MODE=snapshot requiere un artifact snapshot resoluble.', [
                'stage' => 'snapshot_resolve',
                'driver' => $context->driver(),
                'db_driver' => $context->driver(),
                'db_name' => $context->currentDatabaseName($pdo),
                'label' => 'snapshot',
                'hint' => 'Definí TEST_BASELINE_SNAPSHOT_FILE o una referencia válida a backupkit.',
            ]);
        }

        try {
            $context->adapter()->reset($pdo);
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo resetear la DB antes de restaurar el snapshot.', [
                'stage' => 'reset',
                'driver' => $context->driver(),
                'db_driver' => $context->driver(),
                'db_name' => $context->currentDatabaseName($pdo),
                'label' => 'snapshot reset',
                'file' => $context->realPathOrOriginal($snapshotFile),
                'hint' => 'Revisá privilegios de borrado de objetos o residuos incompatibles en la base destino.',
            ]);
        }

        try {
            $context->adapter()->restoreSnapshot($snapshotFile);
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo restaurar el snapshot baseline.', [
                'stage' => 'snapshot_restore',
                'driver' => $context->driver(),
                'db_driver' => $context->driver(),
                'db_name' => $context->currentDatabaseName($pdo),
                'label' => 'snapshot restore',
                'file' => $context->realPathOrOriginal($snapshotFile),
                'hint' => 'Revisá integridad del dump, binarios mysql/gzip y permisos del usuario sobre la base destino.',
            ]);
        }

        try {
            $migrationPlan = MigrationPlanResolver::resolve($pdo, $context->seedDir(), 'snapshot');
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo resolver el estado de migraciones después de restaurar el snapshot.', [
                'stage' => 'migration_state',
                'driver' => $context->driver(),
                'db_driver' => $context->driver(),
                'db_name' => $context->currentDatabaseName($pdo),
                'label' => 'snapshot',
                'file' => $context->realPathOrOriginal($snapshotFile),
                'hint' => 'Definí una fuente confiable de estado (TEST_MIGRATION_APPLIED, TEST_MIGRATION_STATE_TABLE o state.json).',
            ]);
        }

        Trace::log('seed.snapshot.plan', [
            'driver' => $context->driver(),
            'project_root' => $context->projectRoot(),
            'seed_dir' => $context->realPathOrOriginal($context->seedDir()),
            'snapshot_file' => $context->realPathOrOriginal($snapshotFile),
            'snapshot_source' => $resolvedSnapshot,
            'db' => $context->connectionSummary(),
            'raw_TEST_SEED_MIGRATIONS' => $migrationPlan->rawMigrations(),
            'parsed_TEST_SEED_MIGRATIONS' => $migrationPlan->migrations(),
            'migration_state' => $migrationPlan->migrationState(),
            'skip_validations_after_extras' => $migrationPlan->skipPostValidations(),
        ]);

        SqlSeedExecutor::applyRequestedMigrations($pdo, $context->seedDir(), $migrationPlan->migrations(), $context->driver());
        SqlSeedExecutor::applyPostValidations(
            $pdo,
            $context->seedDir(),
            $migrationPlan->migrations(),
            $migrationPlan->skipPostValidations(),
            $context->driver()
        );

        echo "Seed pipeline snapshot aplicado correctamente\n";
        return 0;
    }
}
