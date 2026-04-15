<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

require_once __DIR__ . '/MigrationCatalog.php';
require_once __DIR__ . '/MigrationStateResolver.php';
require_once __DIR__ . '/SeedManifestPlanInput.php';
require_once __DIR__ . '/SeedRuntimeContext.php';

final class SeedManifestPlanInputResolver
{
    public static function resolve(SeedRuntimeContext $context): SeedManifestPlanInput
    {
        $requestedMigrations = MigrationCatalog::normalizeSelectedExecutables(
            $context->seedDir(),
            $context->parseCsvEnv('TEST_SEED_MIGRATIONS')
        );
        $skipPostValidations = self::envBool('TEST_SEED_SKIP_VALIDATIONS_AFTER_EXTRAS', false);
        $migrationState = null;

        if ($context->baselineMode() === 'layered') {
            $migrationState = MigrationStateResolver::resolveLayeredBaseline(
                $context->seedDir(),
                $requestedMigrations
            );
            $requestedMigrations = array_values((array)($migrationState['applied'] ?? []));
        }

        return new SeedManifestPlanInput(
            $requestedMigrations,
            $skipPostValidations,
            is_array($migrationState) ? $migrationState : null
        );
    }

    private static function envBool(string $name, bool $default = false): bool
    {
        $raw = getenv($name);
        if ($raw === false) {
            return $default;
        }

        return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
    }
}
