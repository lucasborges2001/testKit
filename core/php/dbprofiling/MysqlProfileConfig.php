<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

use Testkit\Core\Common\Paths;

final class MysqlProfileConfig
{
    public const ENABLED_ENV = 'TESTKIT_DB_PROFILE';
    public const EXPLAIN_ENABLED_ENV = 'TESTKIT_DB_PROFILE_EXPLAIN';
    public const REPORT_VERSION = 2;
    public const SCHEMA_VERSION = 'mysql-query-profile-report-v2';
    public const INSTRUMENTATION_VERSION = '1.0';

    public static function isEnabled(): bool
    {
        return self::envBool(self::ENABLED_ENV, false);
    }

    public static function isExplainEnabled(): bool
    {
        return self::envBool(self::EXPLAIN_ENABLED_ENV, false);
    }

    /** @return array<string,mixed> */
    public static function fromEnv(): array
    {
        $runId = self::envString('TESTKIT_DB_PROFILE_RUN_ID', self::envString('TEST_RUN_ID', 'adhoc'));
        $safeRunId = self::safeRunId($runId);
        $reportPath = self::envString('TESTKIT_DB_PROFILE_REPORT_PATH', Paths::reportsRoot() . '/mysql_profile_latest.json');
        $historyPath = self::envString('TESTKIT_DB_PROFILE_HISTORY_PATH', Paths::historyRoot() . '/mysql_profile');
        $configuredShard = self::envString('TESTKIT_DB_PROFILE_SHARD_DIR', Paths::outRoot() . '/mysql_profile/shards');
        $shardDir = self::isRunScopedPath($configuredShard, $safeRunId)
            ? $configuredShard
            : rtrim($configuredShard, '/\\') . '/' . $safeRunId;

        $config = [
            'engine' => 'mysql',
            'enabled_env' => self::ENABLED_ENV,
            'enabled' => self::isEnabled(),
            'run_id' => $runId,
            'meta_run_id' => self::envString('TESTKIT_DB_PROFILE_META_RUN_ID', self::envString('TEST_META_RUN_ID', '')),
            'top_n' => self::envInt('TESTKIT_DB_PROFILE_TOP_N', 20, 1, 500),
            'thresholds' => [
                'slow_max_ms' => self::envFloat('TESTKIT_DB_PROFILE_SLOW_MAX_MS', 500.0, 0.0),
                'hotspot_total_ms' => self::envFloat('TESTKIT_DB_PROFILE_HOTSPOT_TOTAL_MS', 3000.0, 0.0),
                'high_calls' => self::envInt('TESTKIT_DB_PROFILE_HIGH_CALLS', 100, 1, PHP_INT_MAX),
                'watch_ratio' => self::envFloat('TESTKIT_DB_PROFILE_WATCH_RATIO', 0.75, 0.0, 1.0),
            ],
            'capture' => [
                'context_enabled' => self::envBool('TESTKIT_DB_PROFILE_CONTEXT', true),
                'connections_enabled' => self::envBool('TESTKIT_DB_PROFILE_CONNECTIONS', true),
                'caller' => self::envBool('TESTKIT_DB_PROFILE_CAPTURE_CALLER', true),
                'source_test' => self::envBool('TESTKIT_DB_PROFILE_CAPTURE_SOURCE_TEST', true),
                'max_sql_length' => self::envInt('TESTKIT_DB_PROFILE_MAX_SQL_LENGTH', 2000, 120, 20000),
                'sample_limit' => self::envInt('TESTKIT_DB_PROFILE_SAMPLE_LIMIT', 256, 8, 4096),
                'max_context_values' => self::envInt('TESTKIT_DB_PROFILE_MAX_CONTEXT_VALUES', 20, 1, 200),
            ],
            'output' => [
                'report_path' => Paths::normalize($reportPath),
                'history_path' => Paths::normalize($historyPath),
                'shard_root' => Paths::normalize($configuredShard),
                'shard_dir' => Paths::normalize($shardDir),
                'session_marker_path' => Paths::normalize($shardDir . '/.session.json'),
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

        return self::applySessionDefaults($config);
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public static function publicConfig(array $config): array
    {
        return [
            'top_n' => (int)($config['top_n'] ?? 20),
            'thresholds' => is_array($config['thresholds'] ?? null) ? $config['thresholds'] : [],
            'capture' => is_array($config['capture'] ?? null) ? $config['capture'] : [],
            'output' => [
                'report_path' => InstrumentationContext::normalizePath((string)($config['output']['report_path'] ?? '')),
                'history_path' => InstrumentationContext::normalizePath((string)($config['output']['history_path'] ?? '')),
                'shard_dir' => InstrumentationContext::normalizePath((string)($config['output']['shard_dir'] ?? '')),
            ],
            'explain' => self::publicExplainConfig(
                is_array($config['explain'] ?? null) ? $config['explain'] : []
            ),
        ];
    }

    /** @param array<string,mixed> $explain @return array<string,mixed> */
    private static function publicExplainConfig(array $explain): array
    {
        return [
            'enabled_env' => (string)($explain['enabled_env'] ?? self::EXPLAIN_ENABLED_ENV),
            'enabled' => (bool)($explain['enabled'] ?? false),
            'max_queries' => (int)($explain['max_queries'] ?? 20),
            'min_total_ms' => (float)($explain['min_total_ms'] ?? 0.0),
            'min_max_ms' => (float)($explain['min_max_ms'] ?? 0.0),
            'include_classes' => array_values(array_filter((array)($explain['include_classes'] ?? []), 'is_string')),
            'format' => (string)($explain['format'] ?? 'json'),
            'timeout_ms' => (int)($explain['timeout_ms'] ?? 2000),
            'queries_file' => InstrumentationContext::normalizePath((string)($explain['queries_file'] ?? '')),
            'high_rows_examined' => (int)($explain['high_rows_examined'] ?? 10000),
        ];
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private static function applySessionDefaults(array $config): array
    {
        $worker = self::envString('TESTKIT_DB_PROFILE_WORKER_ID', self::envString('TEST_WORKER_ID', ''));
        $marker = (string)($config['output']['session_marker_path'] ?? '');
        if ($worker === '' || $marker === '' || !is_file($marker)) {
            return $config;
        }

        $payload = json_decode((string)file_get_contents($marker), true);
        $effective = is_array($payload['effective_config'] ?? null) ? $payload['effective_config'] : [];
        if ((string)($payload['run_id'] ?? '') !== (string)($config['run_id'] ?? '') || $effective === []) {
            return $config;
        }

        if (!self::envIsSet('TESTKIT_DB_PROFILE_TOP_N')) {
            $config['top_n'] = (int)($effective['top_n'] ?? $config['top_n']);
        }
        if (!self::envIsSet('TESTKIT_DB_PROFILE_META_RUN_ID') && !self::envIsSet('TEST_META_RUN_ID')) {
            $config['meta_run_id'] = (string)($payload['meta_run_id'] ?? $config['meta_run_id']);
        }

        $thresholdMap = [
            'slow_max_ms' => 'TESTKIT_DB_PROFILE_SLOW_MAX_MS',
            'hotspot_total_ms' => 'TESTKIT_DB_PROFILE_HOTSPOT_TOTAL_MS',
            'high_calls' => 'TESTKIT_DB_PROFILE_HIGH_CALLS',
            'watch_ratio' => 'TESTKIT_DB_PROFILE_WATCH_RATIO',
        ];
        foreach ($thresholdMap as $key => $env) {
            if (!self::envIsSet($env) && isset($effective['thresholds'][$key])) {
                $config['thresholds'][$key] = $effective['thresholds'][$key];
            }
        }

        $captureMap = [
            'context_enabled' => 'TESTKIT_DB_PROFILE_CONTEXT',
            'connections_enabled' => 'TESTKIT_DB_PROFILE_CONNECTIONS',
            'caller' => 'TESTKIT_DB_PROFILE_CAPTURE_CALLER',
            'source_test' => 'TESTKIT_DB_PROFILE_CAPTURE_SOURCE_TEST',
            'max_sql_length' => 'TESTKIT_DB_PROFILE_MAX_SQL_LENGTH',
            'sample_limit' => 'TESTKIT_DB_PROFILE_SAMPLE_LIMIT',
            'max_context_values' => 'TESTKIT_DB_PROFILE_MAX_CONTEXT_VALUES',
        ];
        foreach ($captureMap as $key => $env) {
            if (!self::envIsSet($env) && isset($effective['capture'][$key])) {
                $config['capture'][$key] = $effective['capture'][$key];
            }
        }

        $explainMap = [
            'enabled' => 'TESTKIT_DB_PROFILE_EXPLAIN',
            'max_queries' => 'TESTKIT_DB_PROFILE_EXPLAIN_MAX_QUERIES',
            'min_total_ms' => 'TESTKIT_DB_PROFILE_EXPLAIN_MIN_TOTAL_MS',
            'min_max_ms' => 'TESTKIT_DB_PROFILE_EXPLAIN_MIN_MAX_MS',
            'include_classes' => 'TESTKIT_DB_PROFILE_EXPLAIN_INCLUDE_CLASSES',
            'format' => 'TESTKIT_DB_PROFILE_EXPLAIN_FORMAT',
            'timeout_ms' => 'TESTKIT_DB_PROFILE_EXPLAIN_TIMEOUT_MS',
            'queries_file' => 'TESTKIT_DB_PROFILE_EXPLAIN_QUERIES_FILE',
            'high_rows_examined' => 'TESTKIT_DB_PROFILE_EXPLAIN_HIGH_ROWS',
        ];
        foreach ($explainMap as $key => $env) {
            if (!self::envIsSet($env) && isset($effective['explain'][$key])) {
                $config['explain'][$key] = $effective['explain'][$key];
            }
        }

        return $config;
    }

    private static function envIsSet(string $key): bool
    {
        $value = getenv($key);
        return is_string($value) && trim($value) !== '';
    }

    public static function safeRunId(string $runId): string
    {
        $safe = preg_replace('/[^a-z0-9._-]+/i', '_', trim($runId));
        return is_string($safe) && $safe !== '' ? $safe : 'adhoc';
    }

    private static function isRunScopedPath(string $path, string $safeRunId): bool
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        return basename($normalized) === $safeRunId;
    }

    private static function envString(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return (!is_string($value) || trim($value) === '') ? $default : trim($value);
    }

    private static function envBool(string $key, bool $default): bool
    {
        $value = getenv($key);
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @param array<int,string> $default @return array<int,string> */
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
