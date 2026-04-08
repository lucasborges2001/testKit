<?php
declare(strict_types=1);

namespace Testkit\Core\Discovery;

final class TestSeedMetadata
{
    /**
     * @param array<int,array<string,mixed>> $tests
     */
    public static function applySeedEnv(array $tests, int $scanLines = 60): void
    {
        $migrations = self::collectMigrations($tests, $scanLines);
        if ($migrations === []) {
            return;
        }

        $existing = self::parseCsv((string)(getenv('TEST_SEED_MIGRATIONS') ?: ''));
        $merged = array_values(array_unique(array_merge($existing, $migrations)));
        if ($merged === []) {
            return;
        }

        putenv('TEST_SEED_MIGRATIONS=' . implode(',', $merged));
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
}
