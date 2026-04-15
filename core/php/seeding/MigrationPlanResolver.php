<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use PDO;
use RuntimeException;
use Testkit\Core\Common\Trace;

require_once __DIR__ . '/MigrationCatalog.php';
require_once __DIR__ . '/MigrationStateResolver.php';
require_once __DIR__ . '/SeedMigrationPlan.php';

final class MigrationPlanResolver
{
    public static function resolve(PDO $pdo, string $seedDir, string $baselineMode): SeedMigrationPlan
    {
        $rawMigrations = (string)(getenv('TEST_SEED_MIGRATIONS') ?: '');
        $requested = MigrationCatalog::normalizeSelectedExecutables($seedDir, self::parseCsvEnv('TEST_SEED_MIGRATIONS'));
        $skipPostValidations = self::envBool('TEST_SEED_SKIP_VALIDATIONS_AFTER_EXTRAS', false);
        $autoPending = self::envBool('TEST_MIGRATION_AUTO_PENDING', $baselineMode === 'snapshot');

        if ($baselineMode === 'layered' && $autoPending) {
            throw new RuntimeException(
                'TEST_MIGRATION_AUTO_PENDING no aplica en TEST_BASELINE_MODE=layered. '
                . 'La DB se resetea antes del seed y el baseline resultante debe derivarse '
                . 'solo desde schema/base y TEST_SEED_MIGRATIONS explícitas.'
            );
        }

        if ($baselineMode === 'layered') {
            $migrationState = MigrationStateResolver::resolveLayeredBaseline($seedDir, $requested);
            $planned = array_values((array)($migrationState['applied'] ?? []));
            Trace::log('seed.migration.state', [
                'baseline_mode' => $baselineMode,
                'requested' => $requested,
                'planned' => $planned,
                'state' => $migrationState,
            ]);

            return new SeedMigrationPlan($planned, $rawMigrations, $skipPostValidations, $migrationState);
        }

        $migrationState = MigrationStateResolver::resolve($pdo, $seedDir);
        $planned = $requested;

        if ($planned === [] && $autoPending) {
            if ($baselineMode === 'snapshot') {
                MigrationStateResolver::assertHasReliableStateSource($seedDir);
            }
            $planned = array_values((array)($migrationState['pending'] ?? []));
            $rawMigrations = implode(',', $planned);
        }

        Trace::log('seed.migration.state', [
            'baseline_mode' => $baselineMode,
            'requested' => $requested,
            'planned' => $planned,
            'state' => $migrationState,
        ]);

        return new SeedMigrationPlan($planned, $rawMigrations, $skipPostValidations, $migrationState);
    }

    /**
     * @return array<int,string>
     */
    private static function parseCsvEnv(string $name): array
    {
        $raw = trim((string)(getenv($name) ?: ''));
        if ($raw === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $raw));
        $parts = array_values(array_filter($parts, static fn(string $value): bool => $value !== ''));
        return array_values(array_unique($parts));
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
