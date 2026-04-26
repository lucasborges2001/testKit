<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

use Testkit\Core\Common\Paths;

final class MysqlProfileConfig
{
    public const ENABLED_ENV = 'TESTKIT_DB_PROFILE';
    public const EXPLAIN_ENABLED_ENV = 'TESTKIT_DB_PROFILE_EXPLAIN';

    public static function isEnabled(): bool
    {
        return self::envBool(self::ENABLED_ENV, false);
    }

    public static function isExplainEnabled(): bool
    {
        return self::envBool(self::EXPLAIN_ENABLED_ENV, false);
    }

    /**
     * @return array<string,mixed>
     */
    public static function fromEnv(): array
    {
        $runId = self::envString('TESTKIT_DB_PROFILE_RUN_ID', self::envString('TEST_RUN_ID', 'adhoc'));
        $reportPath = self::envString('TESTKIT_DB_PROFILE_REPORT_PATH', Paths::reportsRoot() . '/mysql_profile_latest.json');
        $historyPath = self::envString('TESTKIT_DB_PROFILE_HISTORY_PATH', Paths::historyRoot() . '/mysql_profile');
        $shardDir = self::envString('TESTKIT_DB_PROFILE_SHARD_DIR', Paths::outRoot() . '/mysql_profile/shards/' . self::safeRunId($runId));

        return [
            'engine' => 'mysql',
            'enabled_env' => self::ENABLED_ENV,
            'enabled' => self::isEnabled(),
            'run_id' => $runId,
            'top_n' => self::envInt('TESTKIT_DB_PROFILE_TOP_N', 20, 1, 500),
            'thresholds' => [
                'slow_max_ms' => self::envFloat('TESTKIT_DB_PROFILE_SLOW_MAX_MS', 500.0, 0.0),
                'hotspot_total_ms' => self::envFloat('TESTKIT_DB_PROFILE_HOTSPOT_TOTAL_MS', 3000.0, 0.0),
                'high_calls' => self::envInt('TESTKIT_DB_PROFILE_HIGH_CALLS', 100, 1, PHP_INT_MAX),
                'watch_ratio' => self::envFloat('TESTKIT_DB_PROFILE_WATCH_RATIO', 0.75, 0.0, 1.0),
            ],
            'capture' => [
                'caller' => self::envBool('TESTKIT_DB_PROFILE_CAPTURE_CALLER', true),
                'source_test' => self::envBool('TESTKIT_DB_PROFILE_CAPTURE_SOURCE_TEST', true),
                'max_sql_length' => self::envInt('TESTKIT_DB_PROFILE_MAX_SQL_LENGTH', 2000, 120, 20000),
            ],
            'output' => [
                'report_path' => Paths::normalize($reportPath),
                'history_path' => Paths::normalize($historyPath),
                'shard_dir' => Paths::normalize($shardDir),
            ],
            'connection' => [
                'dsn' => self::envString('TESTKIT_DB_PROFILE_EXPLAIN_DSN', self::envString('TEST_DB_DSN', '')),
                'user' => self::envString('TESTKIT_DB_PROFILE_EXPLAIN_USER', self::envString('TEST_DB_USER', '')),
                'pass' => self::envString('TESTKIT_DB_PROFILE_EXPLAIN_PASS', self::envString('TEST_DB_PASS', '')),
            ],
            'explain' => [
                'enabled_env' => self::EXPLAIN_ENABLED_ENV,
                'enabled' => self::isExplainEnabled(),
                'max_queries' => self::envInt('TESTKIT_DB_PROFILE_EXPLAIN_MAX_QUERIES', 20, 1, 500),
                'min_total_ms' => self::envFloat('TESTKIT_DB_PROFILE_EXPLAIN_MIN_TOTAL_MS', 0.0, 0.0),
                'min_max_ms' => self::envFloat('TESTKIT_DB_PROFILE_EXPLAIN_MIN_MAX_MS', 0.0, 0.0),
                'include_classes' => self::envCsv('TESTKIT_DB_PROFILE_EXPLAIN_INCLUDE_CLASSES', [
                    'slow',
                    'hotspot',
                    'watch',
                    'n_plus_one_candidate',
                ]),
                'format' => strtolower(self::envString('TESTKIT_DB_PROFILE_EXPLAIN_FORMAT', 'json')) === 'table' ? 'table' : 'json',
                'timeout_ms' => self::envInt('TESTKIT_DB_PROFILE_EXPLAIN_TIMEOUT_MS', 2000, 100, 60000),
                'queries_file' => self::envString('TESTKIT_DB_PROFILE_EXPLAIN_QUERIES_FILE', ''),
                'high_rows_examined' => self::envInt('TESTKIT_DB_PROFILE_EXPLAIN_HIGH_ROWS', 10000, 1, PHP_INT_MAX),
            ],
        ];
    }

    public static function safeRunId(string $runId): string
    {
        $safe = preg_replace('/[^a-z0-9._-]+/i', '_', trim($runId));
        return is_string($safe) && $safe !== '' ? $safe : 'adhoc';
    }

    private static function envString(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }
        return trim($value);
    }

    private static function envBool(string $key, bool $default): bool
    {
        $value = getenv($key);
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @param array<int,string> $default */
    private static function envCsv(string $key, array $default): array
    {
        $value = self::envString($key, '');
        if ($value === '') {
            return array_values($default);
        }
        $items = array_map(static fn(string $item): string => trim($item), explode(',', $value));
        $items = array_values(array_filter($items, static fn(string $item): bool => $item !== ''));
        return $items === [] ? array_values($default) : $items;
    }

    private static function envInt(string $key, int $default, int $min, int $max): int
    {
        $value = getenv($key);
        if (!is_string($value) || !preg_match('/^-?\d+$/', trim($value))) {
            return $default;
        }
        return max($min, min($max, (int)$value));
    }

    private static function envFloat(string $key, float $default, float $min, ?float $max = null): float
    {
        $value = getenv($key);
        if (!is_string($value) || !is_numeric(trim($value))) {
            return $default;
        }
        $parsed = max($min, (float)$value);
        return $max === null ? $parsed : min($max, $parsed);
    }
}
