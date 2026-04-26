<?php
declare(strict_types=1);

namespace Testkit\Core\Discovery;

/**
 * @deprecated Compatibility bridge for legacy tests that declared optional seed
 * migrations in file headers. New projects must consume report.seed_state /
 * canonical_report.seed_state instead of making domain tests influence bootstrap.
 */
final class TestSeedMetadata
{
    public const LEGACY_CONTRACT = 'deprecated_test_seed_metadata';
    public const LEGACY_ENABLE_ENV = 'TESTKIT_LEGACY_TEST_SEED_METADATA';

    /**
     * Legacy opt-in adapter. By default this method is intentionally inert so
     * discovery cannot mutate bootstrap inputs. Set TESTKIT_LEGACY_TEST_SEED_METADATA=1
     * only while migrating old suites away from `// SEEDS: TEST_SEED_MIGRATIONS=...`.
     *
     * @param array<int,array<string,mixed>> $tests
     * @return array<int,array<string,mixed>> structured deprecation warnings
     */
    public static function applySeedEnvIfLegacyEnabled(array $tests, int $scanLines = 60): array
    {
        if (!self::legacyEnabled()) {
            return [];
        }

        $migrations = self::collectMigrations($tests, $scanLines);
        self::mergeIntoSeedEnv($migrations);

        return [self::legacyWarning($migrations)];
    }

    /**
     * @deprecated Use canonical report seed_state. This method is kept only for
     * external compatibility and should not be called by suite runners as the
     * primary bootstrap contract.
     *
     * @param array<int,array<string,mixed>> $tests
     */
    public static function applySeedEnv(array $tests, int $scanLines = 60): void
    {
        self::mergeIntoSeedEnv(self::collectMigrations($tests, $scanLines));
    }

    /**
     * @param array<int,string> $migrations
     */
    private static function mergeIntoSeedEnv(array $migrations): void
    {
        if ($migrations === []) {
            return;
        }

        $existing = self::parseCsv((string)(getenv('TEST_SEED_MIGRATIONS') ?: ''));
        $merged = array_values(array_unique(array_merge($existing, $migrations)));
        if ($merged === []) {
            return;
        }

        putenv('TEST_SEED_MIGRATIONS=' . implode(',', $merged));
        $_ENV['TEST_SEED_MIGRATIONS'] = implode(',', $merged);
        $_SERVER['TEST_SEED_MIGRATIONS'] = implode(',', $merged);
    }

    /**
     * @param array<int,array<string,mixed>> $tests
     * @return array<int,string>
     */
    private static function collectMigrations(array $tests, int $scanLines): array
    {
        $migrations = [];

        foreach ($tests as $test) {
            $file = (string)($test['file'] ?? '');
            if ($file === '' || !is_file($file)) {
                continue;
            }

            foreach (self::extractSeedAssignments($file, $scanLines) as $key => $value) {
                if ($key !== 'TEST_SEED_MIGRATIONS') {
                    continue;
                }

                $migrations = array_merge($migrations, self::parseCsv($value));
            }
        }

        $migrations = array_values(array_unique(array_filter($migrations, static fn(string $item): bool => $item !== '')));
        sort($migrations);

        return $migrations;
    }

    /**
     * @return array<string,string>
     */
    private static function extractSeedAssignments(string $file, int $scanLines): array
    {
        $fh = @fopen($file, 'rb');
        if (!is_resource($fh)) {
            return [];
        }

        $assignments = [];
        $lineNo = 0;
        while (($line = fgets($fh)) !== false) {
            $lineNo++;
            if ($lineNo > $scanLines) {
                break;
            }

            $trimmed = trim((string)$line);
            if ($trimmed === '' || stripos($trimmed, 'SEEDS:') === false) {
                continue;
            }

            $matches = [];
            if (preg_match_all('/\b(TEST_SEED_[A-Z0-9_]+)=([A-Za-z0-9_,.-]+)/', $trimmed, $matches, \PREG_SET_ORDER) > 0) {
                foreach ($matches as $match) {
                    $assignments[(string)$match[1]] = trim((string)$match[2]);
                }
            }
        }

        fclose($fh);
        return $assignments;
    }

    /**
     * @return array<int,string>
     */
    private static function parseCsv(string $value): array
    {
        $parts = array_map('trim', explode(',', $value));
        return array_values(array_filter($parts, static fn(string $item): bool => $item !== ''));
    }

    private static function legacyEnabled(): bool
    {
        $raw = strtolower(trim((string)(getenv(self::LEGACY_ENABLE_ENV) ?: '')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<int,string> $migrations
     * @return array<string,mixed>
     */
    private static function legacyWarning(array $migrations): array
    {
        return [
            'code' => 'LEGACY_TEST_SEED_METADATA_USED',
            'severity' => 'warn',
            'classification' => 'configuration',
            'blocking' => false,
            'summary' => 'Ruta legacy/deprecated: TestSeedMetadata escaneó headers SEEDS y mutó TEST_SEED_MIGRATIONS. Migrá a seed_state/canonical_report.seed_state como contrato canónico.',
            'count' => 1,
            'context' => [
                'legacy_contract' => self::LEGACY_CONTRACT,
                'enable_env' => self::LEGACY_ENABLE_ENV,
                'migrations' => array_values($migrations),
            ],
        ];
    }
}
