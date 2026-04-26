<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;

final class MysqlProfileConfig
{
    public const ENABLED_ENV = 'TESTKIT_DB_PROFILE';

    public static function isEnabled(): bool
    {
        $value = strtolower(trim((string)(getenv(self::ENABLED_ENV) ?: '')));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
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
