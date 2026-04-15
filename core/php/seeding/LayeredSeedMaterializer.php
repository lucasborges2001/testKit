<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

require_once __DIR__ . '/SeedConsoleNarrative.php';
require_once __DIR__ . '/SeedDatabaseLifecycle.php';
require_once __DIR__ . '/SeedFailure.php';
require_once __DIR__ . '/SeedMaterializer.php';
require_once __DIR__ . '/SeedMigrationPlan.php';
require_once __DIR__ . '/SeedMigrationWorkflow.php';
require_once __DIR__ . '/SeedRuntimeContext.php';
require_once __DIR__ . '/SqlSeedExecutor.php';

final class LayeredSeedMaterializer implements SeedMaterializer
{
    public function run(SeedRuntimeContext $context): int
    {
        $fixtures = $context->parseCsvEnv('TEST_SEED_FIXTURES');
        if ($fixtures !== []) {
            throw new SeedFailure('TEST_SEED_FIXTURES no forma parte del lifecycle de testkit en modo layered.', [
                'stage' => 'layered_contract',
                'driver' => $context->driver(),
                'db_driver' => $context->driver(),
                'label' => 'layered',
                'hint' => 'La infraestructura solo aplica schema/base/migrations/validations; los escenarios deben construirse desde test/_support.',
            ]);
        }

        $pdo = SeedDatabaseLifecycle::connect(
            $context,
            'layered',
            'No se pudo conectar a la DB para bootstrap layered.',
            'Revisá credenciales y disponibilidad de la base antes de aplicar schema/base.'
        );

        $migrationPlan = SeedMigrationWorkflow::resolvePlan(
            $context,
            $pdo,
            'layered',
            'No se pudo resolver el plan de migraciones para baseline layered.',
            'Revisá TEST_SEED_MIGRATIONS y el catálogo en test/seeds/<driver>/migrations.'
        );

        SeedMigrationWorkflow::tracePlan('seed.layered.plan', $context, $migrationPlan, [
            'db_env_path' => (string)(getenv('DB_ENV_PATH') ?: ''),
            'TEST_MATCH' => (string)(getenv('TEST_MATCH') ?: ''),
            'TEST_SCOPE' => (string)(getenv('TEST_SCOPE') ?: ''),
            'TEST_TARGET' => (string)(getenv('TEST_TARGET') ?: ''),
        ]);

        SeedDatabaseLifecycle::reset(
            $context,
            $pdo,
            'layered reset',
            'No se pudo resetear la DB antes de aplicar el baseline layered.',
            'Verificá privilegios para dropear objetos o residuos de una corrida previa.'
        );

        SqlSeedExecutor::applySqlDir($pdo, $context->seedDir() . '/schema', 'schema', 'schema', [
            'driver' => $context->driver(),
            'db_driver' => $context->driver(),
        ]);
        SqlSeedExecutor::applySqlDir($pdo, $context->seedDir() . '/base', 'base', 'base', [
            'driver' => $context->driver(),
            'db_driver' => $context->driver(),
        ]);
        SeedMigrationWorkflow::applyPlan($pdo, $context, $migrationPlan);

        SeedConsoleNarrative::printCompletion($context, 'Seed pipeline por capas aplicado correctamente');
        return 0;
    }
}
