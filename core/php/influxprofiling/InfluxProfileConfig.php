<?php
declare(strict_types=1);

namespace Testkit\Core\InfluxProfiling;

use Testkit\Core\Common\Paths;

final class InfluxProfileConfig
{
    public const ENABLED_ENV = 'TESTKIT_INFLUX_PROFILE';

    public static function isEnabled(): bool
    {
        return self::envBool(self::ENABLED_ENV, false);
    }

    /** @return array<string,mixed> */
    public static function fromEnv(): array
    {
        $runId = self::envString('TESTKIT_INFLUX_PROFILE_RUN_ID', self::envString('TEST_RUN_ID', 'adhoc'));
        $reportPath = self::envString('TESTKIT_INFLUX_PROFILE_REPORT_PATH', Paths::reportsRoot() . '/influx_profile_latest.json');
        $historyPath = self::envString('TESTKIT_INFLUX_PROFILE_HISTORY_PATH', Paths::historyRoot() . '/influx_profile');
        $shardDir = self::envString('TESTKIT_INFLUX_PROFILE_SHARD_DIR', Paths::outRoot() . '/influx_profile/shards/' . self::safeRunId($runId));

        return [
            'engine' => 'influx',
            'enabled_env' => self::ENABLED_ENV,
            'enabled' => self::isEnabled(),
            'run_id' => $runId,
            'top_n' => self::envInt('TESTKIT_INFLUX_PROFILE_TOP_N', 20, 1, 500),
            'thresholds' => [
                'slow_max_ms' => self::envFloat('TESTKIT_INFLUX_PROFILE_SLOW_MAX_MS', 800.0, 0.0),
                'hotspot_total_ms' => self::envFloat('TESTKIT_INFLUX_PROFILE_HOTSPOT_TOTAL_MS', 3000.0, 0.0),
                'high_calls' => self::envInt('TESTKIT_INFLUX_PROFILE_HIGH_CALLS', 100, 1, PHP_INT_MAX),
                'watch_ratio' => self::envFloat('TESTKIT_INFLUX_PROFILE_WATCH_RATIO', 0.75, 0.0, 1.0),
                'max_range_hours' => self::envFloat('TESTKIT_INFLUX_PROFILE_MAX_RANGE_HOURS', 168.0, 0.0),
            ],
            'capture' => [
                'max_query_length' => self::envInt('TESTKIT_INFLUX_PROFILE_MAX_QUERY_LENGTH', 4000, 120, 50000),
                'require_range' => self::envBool('TESTKIT_INFLUX_PROFILE_REQUIRE_RANGE', true),
                'require_tag_filters' => self::envBool('TESTKIT_INFLUX_PROFILE_REQUIRE_TAG_FILTERS', false),
            ],
            'tag_filters' => self::envCsv('TESTKIT_INFLUX_PROFILE_TAG_FILTERS', ['charger_id', 'station_id', 'connector_id', 'site_id', 'organization_id', 'device_id', 'host']),
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

    /** @return array<int,string> */
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
