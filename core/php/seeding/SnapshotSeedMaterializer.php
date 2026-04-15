<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

require_once __DIR__ . '/BackupkitArtifactResolver.php';
require_once __DIR__ . '/SeedConsoleNarrative.php';
require_once __DIR__ . '/SeedDatabaseLifecycle.php';
require_once __DIR__ . '/SeedFailure.php';
require_once __DIR__ . '/SeedMaterializer.php';
require_once __DIR__ . '/SeedMigrationPlan.php';
require_once __DIR__ . '/SeedMigrationWorkflow.php';
require_once __DIR__ . '/SeedRuntimeContext.php';

final class SnapshotSeedMaterializer implements SeedMaterializer
{
    public function run(SeedRuntimeContext $context): int
    {
        $pdo = SeedDatabaseLifecycle::connect(
            $context,
            'snapshot',
            'No se pudo conectar a la DB para bootstrap snapshot.',
            'Revisá credenciales y que la base objetivo exista o pueda provisionarse.'
        );

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

        SeedDatabaseLifecycle::reset(
            $context,
            $pdo,
            'snapshot reset',
            'No se pudo resetear la DB antes de restaurar el snapshot.',
            'Revisá privilegios de borrado de objetos o residuos incompatibles en la base destino.',
            [
                'file' => $context->realPathOrOriginal($snapshotFile),
            ]
        );

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

        $migrationPlan = SeedMigrationWorkflow::resolvePlan(
            $context,
            $pdo,
            'snapshot',
            'No se pudo resolver el estado de migraciones después de restaurar el snapshot.',
            'Definí una fuente confiable de estado (TEST_MIGRATION_APPLIED, TEST_MIGRATION_STATE_TABLE o state.json).',
            [
                'file' => $context->realPathOrOriginal($snapshotFile),
            ]
        );

        SeedMigrationWorkflow::tracePlan('seed.snapshot.plan', $context, $migrationPlan, [
            'snapshot_file' => $context->realPathOrOriginal($snapshotFile),
            'snapshot_source' => $resolvedSnapshot,
        ]);

        SeedMigrationWorkflow::applyPlan($pdo, $context, $migrationPlan);

        SeedConsoleNarrative::printCompletion($context, 'Seed pipeline snapshot aplicado correctamente');
        return 0;
    }
}
