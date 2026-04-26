<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

use Testkit\Core\Common\Paths;

final class MysqlProfileReporter
{
    public static function prepareRun(string $runId): void
    {
        if (!MysqlProfileConfig::isEnabled()) {
            return;
        }
        $config = MysqlProfileConfig::fromEnv();
        $dir = (string)($config['output']['shard_dir'] ?? '');
        if ($dir === '') {
            return;
        }
        Paths::ensureDir($dir);
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** @param array<string,mixed> $snapshot */
    public static function writeProcessShard(array $snapshot): void
    {
        if (!MysqlProfileConfig::isEnabled() && empty($snapshot['profile_enabled'])) {
            return;
        }
        $config = MysqlProfileConfig::fromEnv();
        $dir = (string)($config['output']['shard_dir'] ?? '');
        if ($dir === '') {
            return;
        }
        Paths::ensureDir($dir);
        $pid = (string)($snapshot['process_id'] ?? getmypid());
        $name = 'mysql_profile_' . preg_replace('/[^a-z0-9._-]+/i', '_', $pid) . '_' . substr(sha1(uniqid('', true)), 0, 8) . '.json';
        self::writeJsonAtomic($dir . '/' . $name, $snapshot);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public static function writeLatestFromShards(string $runId, array $context = []): array
    {
        $config = MysqlProfileConfig::fromEnv();
        $report = self::buildReportFromShards($runId, $config, $context);
        $reportPath = (string)($config['output']['report_path'] ?? '');
        if ($reportPath !== '') {
            Paths::ensureDir(dirname($reportPath));
            self::writeJsonAtomic($reportPath, $report);
        }

        $historyDir = (string)($config['output']['history_path'] ?? '');
        if ($historyDir !== '') {
            Paths::ensureDir($historyDir);
            self::writeJsonAtomic($historyDir . '/mysql_profile_' . gmdate('Ymd_His') . '.json', $report);
        }

        return $report;
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public static function safeWriteLatestFromShards(string $runId, array $context = []): array
    {
        try {
            return self::writeLatestFromShards($runId, $context);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'WARN[MYSQL_PROFILE_REPORT_FAILED]: ' . $e->getMessage() . PHP_EOL);
            $config = MysqlProfileConfig::fromEnv();
            return self::emptyReport($runId, $config, [
                'report_error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public static function buildReportFromSnapshot(array $snapshot): array
    {
        $config = MysqlProfileConfig::fromEnv();
        return self::buildReportFromRows(
            is_array($snapshot['queries'] ?? null) ? $snapshot['queries'] : [],
            $config,
            [
                'run_id' => (string)($snapshot['run_id'] ?? ''),
                'started_at' => (string)($snapshot['started_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
                'finished_at' => (string)($snapshot['finished_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
            ]
        );
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    public static function suiteAttachment(array $profile): array
    {
        return [
            'profile_enabled' => (bool)($profile['profile_enabled'] ?? false),
            'engine' => 'mysql',
            'report_path' => (string)(MysqlProfileConfig::fromEnv()['output']['report_path'] ?? ''),
            'summary' => is_array($profile['summary'] ?? null) ? $profile['summary'] : [],
            'explain' => [
                'enabled' => (bool)($profile['explain']['enabled'] ?? false),
                'attempted' => (int)($profile['explain']['attempted'] ?? 0),
                'analyzed' => (int)($profile['explain']['analyzed'] ?? 0),
                'skipped' => (int)($profile['explain']['skipped'] ?? 0),
                'failed' => (int)($profile['explain']['failed'] ?? 0),
            ],
            'top_findings' => [
                'by_max_ms' => array_slice(is_array($profile['rankings']['by_max_ms'] ?? null) ? $profile['rankings']['by_max_ms'] : [], 0, 5),
                'by_total_ms' => array_slice(is_array($profile['rankings']['by_total_ms'] ?? null) ? $profile['rankings']['by_total_ms'] : [], 0, 5),
                'by_calls' => array_slice(is_array($profile['rankings']['by_calls'] ?? null) ? $profile['rankings']['by_calls'] : [], 0, 5),
            ],
        ];
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $context @return array<string,mixed> */
    private static function buildReportFromShards(string $runId, array $config, array $context): array
    {
        $dir = (string)($config['output']['shard_dir'] ?? '');
        $rows = [];
        $startedAt = '';
        $finishedAt = gmdate('Y-m-d\TH:i:s\Z');

        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $payload = json_decode((string)file_get_contents($file), true);
            if (!is_array($payload)) {
                continue;
            }
            if ($startedAt === '' || ((string)($payload['started_at'] ?? '')) < $startedAt) {
                $startedAt = (string)($payload['started_at'] ?? '');
            }
            if (((string)($payload['finished_at'] ?? '')) > $finishedAt) {
                $finishedAt = (string)($payload['finished_at'] ?? $finishedAt);
            }
            foreach ((array)($payload['queries'] ?? []) as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        if ($startedAt === '') {
            $startedAt = gmdate('Y-m-d\TH:i:s\Z');
        }

        return self::buildReportFromRows($rows, $config, array_merge($context, [
            'run_id' => $runId,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]));
    }

    /** @param array<int,array<string,mixed>> $rows @param array<string,mixed> $config @param array<string,mixed> $context @return array<string,mixed> */
    private static function buildReportFromRows(array $rows, array $config, array $context): array
    {
        $startedAt = (string)($context['started_at'] ?? gmdate('Y-m-d\TH:i:s\Z'));
        $finishedAt = (string)($context['finished_at'] ?? gmdate('Y-m-d\TH:i:s\Z'));
        $startedTs = strtotime($startedAt) ?: time();
        $finishedTs = strtotime($finishedAt) ?: time();
        $merged = [];

        foreach ($rows as $row) {
            $fingerprint = (string)($row['fingerprint'] ?? '');
            if ($fingerprint === '') {
                continue;
            }
            if (!isset($merged[$fingerprint])) {
                $merged[$fingerprint] = [
                    'fingerprint' => $fingerprint,
                    'sample_sql' => SqlFingerprint::sampleSql((string)($row['sample_sql'] ?? $fingerprint)),
                    'calls' => 0,
                    'min_ms' => null,
                    'max_ms' => 0.0,
                    'total_ms' => 0.0,
                    'first_seen_at' => (string)($row['first_seen_at'] ?? $startedAt),
                    'last_seen_at' => (string)($row['last_seen_at'] ?? $finishedAt),
                    'sources' => [],
                    'callers' => [],
                ];
            }

            $target =& $merged[$fingerprint];
            $calls = max(0, (int)($row['calls'] ?? 0));
            $target['calls'] = (int)$target['calls'] + $calls;
            $target['total_ms'] = (float)$target['total_ms'] + (float)($row['total_ms'] ?? 0.0);
            $target['max_ms'] = max((float)$target['max_ms'], (float)($row['max_ms'] ?? 0.0));
            $minMs = (float)($row['min_ms'] ?? 0.0);
            $target['min_ms'] = $target['min_ms'] === null ? $minMs : min((float)$target['min_ms'], $minMs);
            $target['first_seen_at'] = min((string)$target['first_seen_at'], (string)($row['first_seen_at'] ?? $startedAt));
            $target['last_seen_at'] = max((string)$target['last_seen_at'], (string)($row['last_seen_at'] ?? $finishedAt));
            self::mergeLimited($target['sources'], is_array($row['sources'] ?? null) ? $row['sources'] : [], 20);
            self::mergeLimited($target['callers'], is_array($row['callers'] ?? null) ? $row['callers'] : [], 20);
            unset($target);
        }

        $queries = [];
        foreach ($merged as $row) {
            $calls = max(1, (int)$row['calls']);
            $row['min_ms'] = self::roundMs((float)($row['min_ms'] ?? 0.0));
            $row['avg_ms'] = self::roundMs(((float)$row['total_ms']) / $calls);
            $row['max_ms'] = self::roundMs((float)$row['max_ms']);
            $row['total_ms'] = self::roundMs((float)$row['total_ms']);
            $row['classification'] = self::classify($row, $config);
            $row['recommendation'] = self::recommend($row);
            $queries[] = $row;
        }

        usort($queries, static fn(array $a, array $b): int => ((float)$b['total_ms'] <=> (float)$a['total_ms']));

        $topN = (int)($config['top_n'] ?? 20);
        $rankings = [
            'by_max_ms' => self::ranking($queries, 'max_ms', $topN),
            'by_total_ms' => self::ranking($queries, 'total_ms', $topN),
            'by_calls' => self::ranking($queries, 'calls', $topN),
            'by_avg_ms' => self::ranking($queries, 'avg_ms', $topN),
        ];

        $summary = self::summary($queries);
        $recommendations = self::recommendations($queries);
        $explain = MysqlExplainAnalyzer::fromConfig($config)->analyze($queries);

        return [
            'report_version' => 1,
            'engine' => 'mysql',
            'profile_enabled' => (bool)($config['enabled'] ?? MysqlProfileConfig::isEnabled()),
            'run_id' => (string)($context['run_id'] ?? ''),
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => max(0, ($finishedTs - $startedTs) * 1000),
            'config' => [
                'top_n' => $topN,
                'thresholds' => $config['thresholds'] ?? [],
                'capture' => $config['capture'] ?? [],
                'explain' => $config['explain'] ?? [],
            ],
            'summary' => $summary,
            'rankings' => $rankings,
            'queries' => $queries,
            'recommendations' => $recommendations,
            'explain' => $explain,
            'limitations' => [
                'PHP userland cannot transparently intercept every existing new PDO(...) call. Use ProfiledPDO, tk_profiled_pdo(), tk_mysql_profile_enable_pdo(), or explicit tk_mysql_profile_record() hooks.',
                'EXPLAIN is optional. Queries with placeholders or unsafe/multiple statements are skipped unless declared as executable examples.',
                'No schema changes, CREATE INDEX suggestions, InfluxDB export, or performance gates are implemented in this phase.',
            ],
        ];
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $context @return array<string,mixed> */
    private static function emptyReport(string $runId, array $config, array $context = []): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return [
            'report_version' => 1,
            'engine' => 'mysql',
            'profile_enabled' => (bool)($config['enabled'] ?? false),
            'run_id' => $runId,
            'started_at' => $now,
            'finished_at' => $now,
            'duration_ms' => 0,
            'config' => [
                'top_n' => (int)($config['top_n'] ?? 20),
                'thresholds' => $config['thresholds'] ?? [],
                'capture' => $config['capture'] ?? [],
                'explain' => $config['explain'] ?? [],
            ],
            'summary' => self::summary([]),
            'rankings' => ['by_max_ms' => [], 'by_total_ms' => [], 'by_calls' => [], 'by_avg_ms' => []],
            'queries' => [],
            'recommendations' => [],
            'explain' => MysqlExplainAnalyzer::emptyResult((bool)($config['explain']['enabled'] ?? false)),
            'limitations' => [],
            'report_error' => (string)($context['report_error'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $row */
    private static function classify(array $row, array $config): string
    {
        $thresholds = is_array($config['thresholds'] ?? null) ? $config['thresholds'] : [];
        $slowMaxMs = (float)($thresholds['slow_max_ms'] ?? 500.0);
        $hotspotTotalMs = (float)($thresholds['hotspot_total_ms'] ?? 3000.0);
        $highCalls = (int)($thresholds['high_calls'] ?? 100);
        $watchRatio = (float)($thresholds['watch_ratio'] ?? 0.75);

        $maxMs = (float)($row['max_ms'] ?? 0.0);
        $totalMs = (float)($row['total_ms'] ?? 0.0);
        $calls = (int)($row['calls'] ?? 0);
        $avgMs = (float)($row['avg_ms'] ?? 0.0);

        if ($totalMs >= $hotspotTotalMs) {
            return 'hotspot';
        }
        if ($calls >= $highCalls && $avgMs < max($slowMaxMs, 1.0)) {
            return 'n_plus_one_candidate';
        }
        if ($maxMs >= $slowMaxMs) {
            return 'slow';
        }
        if ($maxMs >= $slowMaxMs * $watchRatio || $totalMs >= $hotspotTotalMs * $watchRatio || $calls >= (int)ceil($highCalls * $watchRatio)) {
            return 'watch';
        }
        return 'ok';
    }

    /** @param array<string,mixed> $row */
    private static function recommend(array $row): string
    {
        return match ((string)($row['classification'] ?? 'ok')) {
            'n_plus_one_candidate' => 'Revisar patrón N+1: muchas llamadas del mismo fingerprint con latencia individual baja/media. Buscar loop PHP que ejecute esta query repetidamente.',
            'hotspot' => 'Priorizar optimización por costo acumulado: revisar filtros, joins, índices existentes y frecuencia de llamada.',
            'slow' => 'Revisar latencia individual: correr EXPLAIN manualmente si TESTKIT_DB_PROFILE_EXPLAIN no pudo analizarla automáticamente.',
            'watch' => 'Monitorear: cerca de umbrales iniciales, buen candidato a guard permanente si se vuelve regresivo.',
            default => 'Sin acción inmediata. Mantener como señal de baseline.',
        };
    }

    /** @param array<int,array<string,mixed>> $queries */
    private static function ranking(array $queries, string $field, int $topN): array
    {
        $copy = $queries;
        usort($copy, static fn(array $a, array $b): int => ((float)($b[$field] ?? 0) <=> (float)($a[$field] ?? 0)));
        return array_map(static function (array $row): array {
            return [
                'fingerprint' => (string)$row['fingerprint'],
                'sample_sql' => (string)$row['sample_sql'],
                'calls' => (int)$row['calls'],
                'min_ms' => (float)$row['min_ms'],
                'avg_ms' => (float)$row['avg_ms'],
                'max_ms' => (float)$row['max_ms'],
                'total_ms' => (float)$row['total_ms'],
                'classification' => (string)$row['classification'],
                'recommendation' => (string)$row['recommendation'],
            ];
        }, array_slice($copy, 0, max(1, $topN)));
    }

    /** @param array<int,array<string,mixed>> $queries */
    private static function summary(array $queries): array
    {
        $totalQueries = 0;
        $totalDbTimeMs = 0.0;
        $slow = 0;
        $hotspot = 0;
        $nPlusOne = 0;
        foreach ($queries as $row) {
            $totalQueries += (int)($row['calls'] ?? 0);
            $totalDbTimeMs += (float)($row['total_ms'] ?? 0.0);
            $class = (string)($row['classification'] ?? 'ok');
            if ($class === 'slow') {
                $slow++;
            } elseif ($class === 'hotspot') {
                $hotspot++;
            } elseif ($class === 'n_plus_one_candidate') {
                $nPlusOne++;
            }
        }
        return [
            'total_queries' => $totalQueries,
            'unique_fingerprints' => count($queries),
            'total_db_time_ms' => self::roundMs($totalDbTimeMs),
            'slow_count' => $slow,
            'hotspot_count' => $hotspot,
            'n_plus_one_candidates' => $nPlusOne,
        ];
    }

    /** @param array<int,array<string,mixed>> $queries */
    private static function recommendations(array $queries): array
    {
        $items = [];
        foreach ($queries as $row) {
            if (($row['classification'] ?? 'ok') === 'ok') {
                continue;
            }
            $items[] = [
                'classification' => (string)$row['classification'],
                'fingerprint' => (string)$row['fingerprint'],
                'recommendation' => (string)$row['recommendation'],
            ];
            if (count($items) >= 20) {
                break;
            }
        }
        return $items;
    }

    /** @param array<int,string> $target @param array<int,mixed> $source */
    private static function mergeLimited(array &$target, array $source, int $limit): void
    {
        foreach ($source as $value) {
            $value = trim((string)$value);
            if ($value === '' || in_array($value, $target, true)) {
                continue;
            }
            if (count($target) >= $limit) {
                return;
            }
            $target[] = $value;
        }
    }

    private static function roundMs(float $value): float
    {
        return round($value, 3);
    }

    /** @param array<string,mixed> $payload */
    private static function writeJsonAtomic(string $path, array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('No se pudo serializar JSON de mysql profiling');
        }
        Paths::ensureDir(dirname($path));
        $tmp = $path . '.tmp.' . getmypid() . '.' . substr(sha1(uniqid('', true)), 0, 8);
        if (file_put_contents($tmp, $json . PHP_EOL) === false) {
            throw new \RuntimeException('No se pudo escribir archivo temporal de mysql profiling: ' . $tmp);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('No se pudo publicar reporte mysql profiling: ' . $path);
        }
    }
}
