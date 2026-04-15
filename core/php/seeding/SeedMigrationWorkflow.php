<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use PDO;
use Testkit\Core\Common\Trace;

require_once __DIR__ . '/MigrationPlanResolver.php';
require_once __DIR__ . '/SeedFailure.php';
require_once __DIR__ . '/SeedMigrationPlan.php';
require_once __DIR__ . '/SeedRuntimeContext.php';
require_once __DIR__ . '/SqlSeedExecutor.php';

final class SeedMigrationWorkflow
{
    /**
     * @param array<string,mixed> $extraContext
     */
    public static function resolvePlan(
        SeedRuntimeContext $context,
        PDO $pdo,
        string $baselineMode,
        string $message,
        string $hint,
        array $extraContext = []
    ): SeedMigrationPlan {
        try {
            return MigrationPlanResolver::resolve($pdo, $context->seedDir(), $baselineMode);
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, $message, array_merge($extraContext, [
                'stage' => 'migration_state',
                'driver' => $context->driver(),
                'db_driver' => $context->driver(),
                'db_name' => $context->currentDatabaseName($pdo),
                'label' => $baselineMode,
                'hint' => $hint,
            ]));
        }
    }

    /**
     * @param array<string,mixed> $extraTrace
     */
    public static function tracePlan(
        string $event,
        SeedRuntimeContext $context,
        SeedMigrationPlan $migrationPlan,
        array $extraTrace = []
    ): void {
        Trace::log($event, array_merge([
            'driver' => $context->driver(),
            'project_root' => $context->projectRoot(),
            'seed_dir' => $context->realPathOrOriginal($context->seedDir()),
            'db' => $context->connectionSummary(),
            'raw_TEST_SEED_MIGRATIONS' => $migrationPlan->rawMigrations(),
            'parsed_TEST_SEED_MIGRATIONS' => $migrationPlan->migrations(),
            'migration_state' => $migrationPlan->migrationState(),
            'skip_validations_after_extras' => $migrationPlan->skipPostValidations(),
        ], $extraTrace));
    }

    public static function applyPlan(PDO $pdo, SeedRuntimeContext $context, SeedMigrationPlan $migrationPlan): void
    {
        SqlSeedExecutor::applyRequestedMigrations(
            $pdo,
            $context->seedDir(),
            $migrationPlan->migrations(),
            $context->driver()
        );
        SqlSeedExecutor::applyPostValidations(
            $pdo,
            $context->seedDir(),
            $migrationPlan->migrations(),
            $migrationPlan->skipPostValidations(),
            $context->driver()
        );
    }
}
