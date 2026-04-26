<?php
declare(strict_types=1);

namespace Testkit\Core\InfluxProfiling;

use Testkit\Core\Common\Paths;

final class InfluxProfileReporter
{
    public static function prepareRun(string $runId): void
    {
        if (!InfluxProfileConfig::isEnabled()) {
            return;
        }
        $config = InfluxProfileConfig::fromEnv();
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
        if (!InfluxProfileConfig::isEnabled() && empty($snapshot['profile_enabled'])) {
            return;
        }
        $config = InfluxProfileConfig::fromEnv();
        $dir = (string)($config['output']['shard_dir'] ?? '');
        if ($dir === '') {
            return;
        }
        Paths::ensureDir($dir);
        $pid = (string)($snapshot['process_id'] ?? getmypid());
        $name = 'influx_profile_' . preg_replace('/[^a-z0-9._-]+/i', '_', $pid) . '_' . substr(sha1(uniqid('', true)), 0, 8) . '.json';
        self::writeJsonAtomic($dir . '/' . $name, $snapshot);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public static function writeLatestFromShards(string $runId, array $context = []): array
    {
        $config = InfluxProfileConfig::fromEnv();
        $report = self::buildReportFromShards($runId, $config, $context);
        $reportPath = (string)($config['output']['report_path'] ?? '');
        if ($reportPath !== '') {
            Paths::ensureDir(dirname($reportPath));
            self::writeJsonAtomic($reportPath, $report);
        }

        $historyDir = (string)($config['output']['history_path'] ?? '');
        if ($historyDir !== '') {
            Paths::ensureDir($historyDir);
            self::writeJsonAtomic($historyDir . '/influx_profile_' . gmdate('Ymd_His') . '.json', $report);
        }

        return $report;
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public static function safeWriteLatestFromShards(string $runId, array $context = []): array
    {
        try {
            return self::writeLatestFromShards($runId, $context);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'WARN[INFLUX_PROFILE_REPORT_FAILED]: ' . $e->getMessage() . PHP_EOL);
            return self::emptyReport($runId, InfluxProfileConfig::fromEnv(), ['report_error' => $e->getMessage()]);
        }
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public static function buildReportFromSnapshot(array $snapshot): array
    {
        return self::buildReportFromRows(
            is_array($snapshot['queries'] ?? null) ? $snapshot['queries'] : [],
            InfluxProfileConfig::fromEnv(),
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
            'engine' => 'influx',
            'report_path' => (string)(InfluxProfileConfig::fromEnv()['output']['report_path'] ?? ''),
            'summary' => is_array($profile['summary'] ?? null) ? $profile['summary'] : [],
            'top_findings' => [
                'by_max_ms' => array_slice(is_array($profile['rankings']['by_max_ms'] ?? null) ? $profile['rankings']['by_max_ms'] : [], 0, 5),
                'by_total_ms' => array_slice(is_array($profile['rankings']['by_total_ms'] ?? null) ? $profile['rankings']['by_total_ms'] : [], 0, 5),
                'by_calls' => array_slice(is_array($profile['rankings']['by_calls'] ?? null) ? $profile['rankings']['by_calls'] : [], 0, 5),
                'by_risk' => array_slice(is_array($profile['rankings']['by_risk'] ?? null) ? $profile['rankings']['by_risk'] : [], 0, 5),
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
                $dialect = (string)($row['dialect'] ?? 'unknown');
                $sample = (string)($row['sample_query'] ?? $fingerprint);
                $merged[$fingerprint] = [
                    'fingerprint' => $fingerprint,
                    'sample_query' => InfluxQueryFingerprint::sampleQuery($sample, (int)($config['capture']['max_query_length'] ?? 4000)),
                    'dialect' => $dialect,
                    'calls' => 0,
                    'min_ms' => null,
                    'max_ms' => 0.0,
                    'total_ms' => 0.0,
                    'first_seen_at' => (string)($row['first_seen_at'] ?? $startedAt),
                    'last_seen_at' => (string)($row['last_seen_at'] ?? $finishedAt),
                    'sources' => [],
                    'callers' => [],
                    'static_analysis' => is_array($row['static_analysis'] ?? null) ? $row['static_analysis'] : InfluxQueryAnalyzer::analyze($sample, $dialect, $config),
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
            $analysis = is_array($row['static_analysis'] ?? null) ? $row['static_analysis'] : [];
            $row['risk_flags'] = array_values(array_filter((array)($analysis['risk_flags'] ?? []), 'is_string'));
            $row['risk_severity'] = self::riskSeverity($analysis);
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
            'by_risk' => self::riskRanking($queries, $topN),
        ];

        return [
            'report_version' => 1,
            'engine' => 'influx',
            'profile_enabled' => (bool)($config['enabled'] ?? InfluxProfileConfig::isEnabled()),
            'run_id' => (string)($context['run_id'] ?? ''),
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => max(0, ($finishedTs - $startedTs) * 1000),
            'config' => [
                'top_n' => $topN,
                'thresholds' => $config['thresholds'] ?? [],
                'capture' => $config['capture'] ?? [],
                'tag_filters' => $config['tag_filters'] ?? [],
            ],
            'summary' => self::summary($queries),
            'rankings' => $rankings,
            'queries' => $queries,
            'recommendations' => self::recommendations($queries),
            'limitations' => [
                'PHP userland cannot transparently intercept every existing Influx client call. Use tk_influx_profile_record(), tk_influx_profile_wrap(), or a small client wrapper at call sites.',
                'Static analysis is heuristic risk detection, not proof of a slow query. It does not execute against InfluxDB and does not inspect bucket schema/cardinality.',
                'There is no MySQL-style EXPLAIN FORMAT=JSON equivalent in this phase. No performance gates or automatic optimizations are implemented.',
            ],
        ];
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $context @return array<string,mixed> */
    private static function emptyReport(string $runId, array $config, array $context = []): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return [
            'report_version' => 1,
            'engine' => 'influx',
            'profile_enabled' => (bool)($config['enabled'] ?? false),
            'run_id' => $runId,
            'started_at' => $now,
            'finished_at' => $now,
            'duration_ms' => 0,
            'config' => [
                'top_n' => (int)($config['top_n'] ?? 20),
                'thresholds' => $config['thresholds'] ?? [],
                'capture' => $config['capture'] ?? [],
                'tag_filters' => $config['tag_filters'] ?? [],
            ],
            'summary' => self::summary([]),
            'rankings' => ['by_max_ms' => [], 'by_total_ms' => [], 'by_calls' => [], 'by_avg_ms' => [], 'by_risk' => []],
            'queries' => [],
            'recommendations' => [],
            'limitations' => [],
            'report_error' => (string)($context['report_error'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $row */
    private static function classify(array $row, array $config): string
    {
        $thresholds = is_array($config['thresholds'] ?? null) ? $config['thresholds'] : [];
        $slowMaxMs = (float)($thresholds['slow_max_ms'] ?? 800.0);
        $hotspotTotalMs = (float)($thresholds['hotspot_total_ms'] ?? 3000.0);
        $highCalls = (int)($thresholds['high_calls'] ?? 100);
        $watchRatio = (float)($thresholds['watch_ratio'] ?? 0.75);

        $maxMs = (float)($row['max_ms'] ?? 0.0);
        $totalMs = (float)($row['total_ms'] ?? 0.0);
        $calls = (int)($row['calls'] ?? 0);
        $avgMs = (float)($row['avg_ms'] ?? 0.0);
        $riskSeverity = (string)($row['risk_severity'] ?? 'info');
        $hasWatchFlags = $riskSeverity === 'watch' || $riskSeverity === 'warn';

        if ($totalMs >= $hotspotTotalMs) {
            return 'hotspot';
        }
        if ($calls >= $highCalls && $avgMs < max($slowMaxMs, 1.0)) {
            return 'n_plus_one_candidate';
        }
        if ($maxMs >= $slowMaxMs) {
            return 'slow';
        }
        if ($riskSeverity === 'warn') {
            return 'risky_query';
        }
        if ($maxMs >= $slowMaxMs * $watchRatio || $totalMs >= $hotspotTotalMs * $watchRatio || $calls >= (int)ceil($highCalls * $watchRatio) || $hasWatchFlags) {
            return 'watch';
        }
        return 'ok';
    }

    /** @param array<string,mixed> $analysis */
    private static function riskSeverity(array $analysis): string
    {
        $severity = (string)($analysis['risk_severity'] ?? 'info');
        return in_array($severity, ['info', 'watch', 'warn'], true) ? $severity : 'info';
    }

    /** @param array<string,mixed> $row */
    private static function recommend(array $row): string
    {
        $flags = array_values(array_filter((array)($row['risk_flags'] ?? []), 'is_string'));
        if (in_array('missing_range', $flags, true)) {
            return 'Add an explicit range()/WHERE time filter. Influx queries without time bounds can scan far more series than intended.';
        }
        if (in_array('pivot_before_filter', $flags, true) || in_array('early_pivot', $flags, true)) {
            return 'Filter by time and high-selectivity tags before pivot(). Pivoting early expands intermediate tables and can become expensive.';
        }
        if (in_array('missing_tag_filter', $flags, true) || in_array('field_filter_primary', $flags, true)) {
            return 'Prefer selective tag filters before field filters. Field-only filtering usually scans more data in Influx workloads.';
        }
        if (in_array('regex_filter', $flags, true) || in_array('contains_filter', $flags, true)) {
            return 'Review broad regex/contains filters on high-cardinality tags; replace with exact tag filters where possible.';
        }
        if (in_array('join_present', $flags, true)) {
            return 'Review join() cardinality and input ranges. Narrow both sides before joining.';
        }
        if (in_array('sort_without_limit', $flags, true)) {
            return 'Add limit() after sort() when only top rows are needed.';
        }

        return match ((string)($row['classification'] ?? 'ok')) {
            'n_plus_one_candidate' => 'Review N+1 pattern: many repeated Influx calls with the same fingerprint. Batch or widen one bounded query where possible.',
            'hotspot' => 'Prioritize by cumulative Influx time. Reduce call frequency, time range, or cardinality scanned.',
            'slow' => 'Review individual latency. Check time bounds, tag selectivity, pivot/join placement, and bucket cardinality.',
            'watch', 'risky_query' => 'Treat as a heuristic risk signal and inspect the query shape before making it a permanent guard.',
            default => 'No immediate action. Keep as baseline.',
        };
    }

    /** @param array<int,array<string,mixed>> $queries */
    private static function ranking(array $queries, string $field, int $topN): array
    {
        $copy = $queries;
        usort($copy, static fn(array $a, array $b): int => ((float)($b[$field] ?? 0) <=> (float)($a[$field] ?? 0)));
        return array_map(static fn(array $row): array => self::rankingRow($row), array_slice($copy, 0, max(1, $topN)));
    }

    /** @param array<int,array<string,mixed>> $queries */
    private static function riskRanking(array $queries, int $topN): array
    {
        $copy = array_values(array_filter($queries, static fn(array $row): bool => in_array((string)($row['risk_severity'] ?? 'info'), ['watch', 'warn'], true)));
        $weight = ['warn' => 3, 'watch' => 2, 'info' => 1];
        usort($copy, static function (array $a, array $b) use ($weight): int {
            $aw = $weight[(string)($a['risk_severity'] ?? 'info')] ?? 0;
            $bw = $weight[(string)($b['risk_severity'] ?? 'info')] ?? 0;
            return ($bw <=> $aw) ?: ((float)($b['total_ms'] ?? 0.0) <=> (float)($a['total_ms'] ?? 0.0));
        });
        return array_map(static fn(array $row): array => self::rankingRow($row), array_slice($copy, 0, max(1, $topN)));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function rankingRow(array $row): array
    {
        return [
            'fingerprint' => (string)$row['fingerprint'],
            'sample_query' => (string)$row['sample_query'],
            'dialect' => (string)$row['dialect'],
            'calls' => (int)$row['calls'],
            'min_ms' => (float)$row['min_ms'],
            'avg_ms' => (float)$row['avg_ms'],
            'max_ms' => (float)$row['max_ms'],
            'total_ms' => (float)$row['total_ms'],
            'classification' => (string)$row['classification'],
            'risk_severity' => (string)($row['risk_severity'] ?? 'info'),
            'risk_flags' => array_values(array_filter((array)($row['risk_flags'] ?? []), 'is_string')),
            'recommendation' => (string)$row['recommendation'],
        ];
    }

    /** @param array<int,array<string,mixed>> $queries */
    private static function summary(array $queries): array
    {
        $totalQueries = 0;
        $totalTimeMs = 0.0;
        $slow = 0;
        $hotspot = 0;
        $nPlusOne = 0;
        $risky = 0;
        foreach ($queries as $row) {
            $totalQueries += (int)($row['calls'] ?? 0);
            $totalTimeMs += (float)($row['total_ms'] ?? 0.0);
            $class = (string)($row['classification'] ?? 'ok');
            if ($class === 'slow') {
                $slow++;
            } elseif ($class === 'hotspot') {
                $hotspot++;
            } elseif ($class === 'n_plus_one_candidate') {
                $nPlusOne++;
            }
            if (($row['risk_severity'] ?? 'info') === 'warn' || $class === 'risky_query') {
                $risky++;
            }
        }
        return [
            'total_queries' => $totalQueries,
            'unique_fingerprints' => count($queries),
            'total_query_time_ms' => self::roundMs($totalTimeMs),
            'slow_count' => $slow,
            'hotspot_count' => $hotspot,
            'n_plus_one_candidates' => $nPlusOne,
            'risky_count' => $risky,
        ];
    }

    /** @param array<int,array<string,mixed>> $queries */
    private static function recommendations(array $queries): array
    {
        $items = [];
        foreach ($queries as $row) {
            if (($row['classification'] ?? 'ok') === 'ok' && !in_array((string)($row['risk_severity'] ?? 'info'), ['watch', 'warn'], true)) {
                continue;
            }
            $items[] = [
                'classification' => (string)$row['classification'],
                'risk_severity' => (string)($row['risk_severity'] ?? 'info'),
                'risk_flags' => array_values(array_filter((array)($row['risk_flags'] ?? []), 'is_string')),
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
            throw new \RuntimeException('No se pudo serializar JSON de influx profiling');
        }
        Paths::ensureDir(dirname($path));
        $tmp = $path . '.tmp.' . getmypid() . '.' . substr(sha1(uniqid('', true)), 0, 8);
        if (file_put_contents($tmp, $json . PHP_EOL) === false) {
            throw new \RuntimeException('No se pudo escribir archivo temporal de influx profiling: ' . $tmp);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('No se pudo publicar reporte influx profiling: ' . $path);
        }
    }
}
