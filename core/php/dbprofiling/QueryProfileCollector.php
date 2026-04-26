<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

final class QueryProfileCollector
{
    /** @var array<string,array<string,mixed>> */
    private static array $queries = [];
    private static bool $shutdownRegistered = false;
    private static bool $forceEnabled = false;
    private static string $startedAt = '';

    public static function enableForTests(): void
    {
        self::$forceEnabled = true;
        self::$startedAt = gmdate('Y-m-d\TH:i:s\Z');
    }

    public static function resetForTests(): void
    {
        self::$queries = [];
        self::$shutdownRegistered = false;
        self::$forceEnabled = false;
        self::$startedAt = '';
    }

    public static function isEnabled(): bool
    {
        return self::$forceEnabled || MysqlProfileConfig::isEnabled();
    }

    public static function registerShutdown(): void
    {
        if (self::$shutdownRegistered || !self::isEnabled()) {
            return;
        }
        self::$shutdownRegistered = true;
        if (self::$startedAt === '') {
            self::$startedAt = gmdate('Y-m-d\TH:i:s\Z');
        }
        register_shutdown_function(static function (): void {
            try {
                MysqlProfileReporter::writeProcessShard(self::snapshot());
            } catch (\Throwable $e) {
                fwrite(STDERR, 'WARN[MYSQL_PROFILE_SHARD_FAILED]: ' . $e->getMessage() . PHP_EOL);
            }
        });
    }

    public static function record(string $sql, float $durationMs, string $source = '', string $caller = ''): void
    {
        if (!self::isEnabled()) {
            return;
        }
        if (self::$startedAt === '') {
            self::$startedAt = gmdate('Y-m-d\TH:i:s\Z');
        }

        $config = MysqlProfileConfig::fromEnv();
        $maxSqlLength = (int)($config['capture']['max_sql_length'] ?? 2000);
        $fingerprint = SqlFingerprint::fingerprint($sql);
        if ($fingerprint === '') {
            return;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $durationMs = max(0.0, $durationMs);
        if (!isset(self::$queries[$fingerprint])) {
            self::$queries[$fingerprint] = [
                'fingerprint' => $fingerprint,
                'sample_sql' => SqlFingerprint::sampleSql($sql, $maxSqlLength),
                'calls' => 0,
                'min_ms' => $durationMs,
                'max_ms' => $durationMs,
                'total_ms' => 0.0,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'sources' => [],
                'callers' => [],
            ];
        }

        $row =& self::$queries[$fingerprint];
        $row['calls'] = (int)$row['calls'] + 1;
        $row['min_ms'] = min((float)$row['min_ms'], $durationMs);
        $row['max_ms'] = max((float)$row['max_ms'], $durationMs);
        $row['total_ms'] = (float)$row['total_ms'] + $durationMs;
        $row['last_seen_at'] = $now;

        if ($source !== '') {
            self::pushUnique($row['sources'], $source, 20);
        }
        if ($caller !== '') {
            self::pushUnique($row['callers'], $caller, 20);
        }
        unset($row);
    }

    /**
     * @return array<string,mixed>
     */
    public static function snapshot(): array
    {
        $queries = [];
        foreach (self::$queries as $row) {
            $calls = max(1, (int)$row['calls']);
            $row['avg_ms'] = ((float)$row['total_ms']) / $calls;
            $row['min_ms'] = self::roundMs((float)$row['min_ms']);
            $row['avg_ms'] = self::roundMs((float)$row['avg_ms']);
            $row['max_ms'] = self::roundMs((float)$row['max_ms']);
            $row['total_ms'] = self::roundMs((float)$row['total_ms']);
            $queries[] = $row;
        }

        return [
            'report_version' => 1,
            'engine' => 'mysql',
            'profile_enabled' => self::isEnabled(),
            'run_id' => (string)(getenv('TESTKIT_DB_PROFILE_RUN_ID') ?: getenv('TEST_RUN_ID') ?: ''),
            'process_id' => getmypid(),
            'started_at' => self::$startedAt !== '' ? self::$startedAt : gmdate('Y-m-d\TH:i:s\Z'),
            'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'queries' => $queries,
        ];
    }

    public static function inferCaller(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16);
        foreach ($trace as $frame) {
            $file = str_replace('\\', '/', (string)($frame['file'] ?? ''));
            if ($file === '' || str_contains($file, '/testkit/core/php/dbprofiling/')) {
                continue;
            }
            $line = (int)($frame['line'] ?? 0);
            return $line > 0 ? ($file . ':' . $line) : $file;
        }
        return '';
    }

    public static function inferSource(): string
    {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        return $script !== '' ? $script : '';
    }

    /** @param array<int,string> $target */
    private static function pushUnique(array &$target, string $value, int $limit): void
    {
        $value = trim($value);
        if ($value === '' || in_array($value, $target, true)) {
            return;
        }
        if (count($target) < $limit) {
            $target[] = $value;
        }
    }

    private static function roundMs(float $value): float
    {
        return round($value, 3);
    }
}
