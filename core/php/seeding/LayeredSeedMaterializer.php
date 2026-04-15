<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use Testkit\Core\Common\Trace;

require_once __DIR__ . '/MigrationPlanResolver.php';
require_once __DIR__ . '/SeedFailure.php';
require_once __DIR__ . '/SeedMaterializer.php';
require_once __DIR__ . '/SeedMigrationPlan.php';
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

        try {
            $pdo = $context->adapter()->connect();
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo conectar a la DB para bootstrap layered.', [
                'stage' => 'connect',
                'driver' => $context->driver(),
                'db_driver' => $context->driver(),
                'label' => 'layered',
                'db_name' => $context->connectionSummary()['db'] ?? '',
                'hint' => 'Revisá credenciales y disponibilidad de la base antes de aplicar schema/base.',
            ]);
        }

        try {
            $migrationPlan = MigrationPlanResolver::resolve($pdo, $context->seedDir(), 'layered');
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo resolver el plan de migraciones para baseline layered.', [
                'stage' => 'migration_state',
                'driver' => $context->driver(),
                'db_driver' => $context->driver(),
                'db_name' => $context->currentDatabaseName($pdo),
                'label' => 'layered',
                'hint' => 'Revisá TEST_SEED_MIGRATIONS y el catálogo en test/seeds/<driver>/migrations.',
            ]);
        }

        Trace::log('seed.layered.plan', [
            'driver' => $context->driver(),
            'project_root' => $context->projectRoot(),
            'seed_dir' => $context->realPathOrOriginal($context->seedDir()),
            'db_env_path' => (string)(getenv('DB_ENV_PATH') ?: ''),
            'db' => $context->connectionSummary(),
            'raw_TEST_SEED_MIGRATIONS' => $migrationPlan->rawMigrations(),
            'parsed_TEST_SEED_MIGRATIONS' => $migrationPlan->migrations(),
            'migration_state' => $migrationPlan->migrationState(),
            'skip_validations_after_extras' => $migrationPlan->skipPostValidations(),
            'TEST_MATCH' => (string)(getenv('TEST_MATCH') ?: ''),
            'TEST_SCOPE' => (string)(getenv('TEST_SCOPE') ?: ''),
            'TEST_TARGET' => (string)(getenv('TEST_TARGET') ?: ''),
        ]);

        try {
            $context->adapter()->reset($pdo);
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo resetear la DB antes de aplicar el baseline layered.', [
                'stage' => 'reset',
                'driver' => $context->driver(),
                'db_driver' => $context->driver(),
                'db_name' => $context->currentDatabaseName($pdo),
                'label' => 'layered reset',
                'hint' => 'Verificá privilegios para dropear objetos o residuos de una corrida previa.',
            ]);
        }

        SqlSeedExecutor::applySqlDir($pdo, $context->seedDir() . '/schema', 'schema', 'schema', [
            'driver' => $context->driver(),
            'db_driver' => $context->driver(),
        ]);
        SqlSeedExecutor::applySqlDir($pdo, $context->seedDir() . '/base', 'base', 'base', [
            'driver' => $context->driver(),
            'db_driver' => $context->driver(),
        ]);
        SqlSeedExecutor::applyRequestedMigrations($pdo, $context->seedDir(), $migrationPlan->migrations(), $context->driver());
        SqlSeedExecutor::applyPostValidations(
            $pdo,
            $context->seedDir(),
            $migrationPlan->migrations(),
            $migrationPlan->skipPostValidations(),
            $context->driver()
        );

        echo "Seed pipeline por capas aplicado correctamente\n";
        return 0;
    }
}
