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
        $marker = (string)($config['output']['session_marker_path'] ?? '');
        if ($dir === '' || $marker === '') {
            return;
        }

        Paths::ensureDir($dir);
        $sessionId = 'session_' . gmdate('Ymd_His') . '_' . self::randomToken(12);
        self::writeJsonAtomic($marker, [
            'run_id' => $runId,
            'meta_run_id' => (string)($config['meta_run_id'] ?? ''),
            'capture_session_id' => $sessionId,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'process_id' => getmypid(),
            'effective_config' => MysqlProfileConfig::publicConfig($config),
        ]);
    }

    /** @param array<string,mixed> $config */
    public static function readCaptureSessionId(array $config): string
    {
        $marker = (string)($config['output']['session_marker_path'] ?? '');
        if ($marker !== '' && is_file($marker)) {
            $payload = json_decode((string)file_get_contents($marker), true);
            if (is_array($payload)) {
                $id = InstrumentationContext::sanitizeIdentifier((string)($payload['capture_session_id'] ?? ''), 100);
                if ($id !== '') {
                    return $id;
                }
            }
        }

        $runId = (string)($config['run_id'] ?? 'adhoc');
        $dir = (string)($config['output']['shard_dir'] ?? '');
        return 'legacy_' . substr(hash('sha256', $runId . '|' . $dir), 0, 16);
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

        $runId = MysqlProfileConfig::safeRunId((string)($snapshot['run_id'] ?? $config['run_id'] ?? 'adhoc'));
        $session = (string)($snapshot['capture_session_id'] ?? self::readCaptureSessionId($config));
        $pid = preg_replace('/[^a-z0-9._-]+/i', '_', (string)($snapshot['process_id'] ?? getmypid())) ?: '0';
        $name = sprintf(
            'mysql_profile_%s_%s_%s_%s.json',
            $runId,
            substr(hash('sha256', $session), 0, 10),
            $pid,
            self::randomToken(10)
        );

        $snapshot['shard_write_started_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $metrics = is_array($snapshot['collector_metrics'] ?? null) ? $snapshot['collector_metrics'] : [];
        $metrics['shard_write_ms'] = 0.0;
        $snapshot['collector_metrics'] = $metrics;

        // Two-pass write: the first atomic write measures a representative encode+I/O cost;
        // the second publishes the same shard with that diagnostic value embedded.
        $started = microtime(true);
        self::writeJsonAtomic($dir . '/' . $name, $snapshot);
        $metrics['shard_write_ms'] = self::roundMs((microtime(true) - $started) * 1000);
        $snapshot['collector_metrics'] = $metrics;
        self::writeJsonAtomic($dir . '/' . $name, $snapshot);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public static function writeLatestFromShards(string $runId, array $context = []): array
    {
        $config = MysqlProfileConfig::fromEnv();
        $report = self::buildReportFromShards($runId, $config, $context);
        $reportPath = (string)($config['output']['report_path'] ?? '');
        if ($reportPath !== '') {
            self::writeJsonAtomic($reportPath, $report);
        }

        $historyDir = (string)($config['output']['history_path'] ?? '');
        if ($historyDir !== '') {
            $historyName = sprintf(
                'mysql_profile_%s_%s.json',
                gmdate('Ymd_His'),
                self::randomToken(6)
            );
            self::writeJsonAtomic($historyDir . '/' . $historyName, $report);
        }
        return $report;
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public static function safeWriteLatestFromShards(string $runId, array $context = []): array
    {
        try {
            return self::writeLatestFromShards($runId, $context);
        } catch (\Throwable $e) {
            fwrite(
                STDERR,
                'WARN[MYSQL_PROFILE_REPORT_FAILED]: '
                . InstrumentationContext::sanitizeText($e->getMessage(), 240)
                . PHP_EOL
            );
            return self::emptyReport($runId, MysqlProfileConfig::fromEnv(), [
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
            is_array($snapshot['connections'] ?? null) ? $snapshot['connections'] : [],
            is_array($snapshot['instrumentation_findings'] ?? null) ? $snapshot['instrumentation_findings'] : [],
            $config,
            [
                'run_id' => (string)($snapshot['run_id'] ?? ''),
                'meta_run_id' => (string)($snapshot['meta_run_id'] ?? ''),
                'suite_id' => (string)($snapshot['suite_id'] ?? ''),
                'started_at' => (string)($snapshot['started_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
                'finished_at' => (string)($snapshot['finished_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
                'capture_session_id' => (string)($snapshot['capture_session_id'] ?? ''),
                'shards' => [
                    'read' => 1,
                    'accepted' => 1,
                    'corrupt' => 0,
                    'foreign_run' => 0,
                    'foreign_session' => 0,
                ],
                'collector_metrics' => is_array($snapshot['collector_metrics'] ?? null)
                    ? $snapshot['collector_metrics']
                    : [],
            ]
        );
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    public static function suiteAttachment(array $profile): array
    {
        return [
            'profile_enabled' => (bool)($profile['profile_enabled'] ?? false),
            'engine' => 'mysql',
            'report_path' => (string)(MysqlProfileConfig::publicConfig(MysqlProfileConfig::fromEnv())['output']['report_path'] ?? ''),
            'summary' => is_array($profile['summary'] ?? null) ? $profile['summary'] : [],
            'instrumentation' => [
                'status' => (string)($profile['instrumentation']['status'] ?? 'unknown'),
                'warnings' => count(array_filter(
                    (array)($profile['instrumentation']['findings'] ?? []),
                    static fn(mixed $item): bool => is_array($item) && ($item['severity'] ?? '') === 'warn'
                )),
                'instrumented_connections' => (int)($profile['coverage']['facts']['instrumented_connections'] ?? 0),
                'capture_methods' => (array)($profile['coverage']['facts']['queries_by_capture_method'] ?? []),
            ],
            'explain' => [
                'enabled' => (bool)($profile['explain']['enabled'] ?? false),
                'attempted' => (int)($profile['explain']['attempted'] ?? 0),
                'analyzed' => (int)($profile['explain']['analyzed'] ?? 0),
                'skipped' => (int)($profile['explain']['skipped'] ?? 0),
                'failed' => (int)($profile['explain']['failed'] ?? 0),
            ],
            'top_findings' => [
                'by_max_ms' => array_slice((array)($profile['rankings']['by_max_ms'] ?? []), 0, 5),
                'by_total_ms' => array_slice((array)($profile['rankings']['by_total_ms'] ?? []), 0, 5),
                'by_calls' => array_slice((array)($profile['rankings']['by_calls'] ?? []), 0, 5),
            ],
        ];
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $context @return array<string,mixed> */
    private static function buildReportFromShards(string $runId, array $config, array $context): array
    {
        $dir = (string)($config['output']['shard_dir'] ?? '');
        $sessionId = self::readCaptureSessionId($config);
        $rows = [];
        $connections = [];
        $findings = [];
        $startedAt = '';
        $finishedAt = '';
        $collectorMetrics = [
            'collector_record_calls' => 0,
            'collector_total_overhead_ms' => 0.0,
            'shard_write_ms' => 0.0,
        ];
        $configHashes = [];
        $shardStats = [
            'read' => 0,
            'accepted' => 0,
            'corrupt' => 0,
            'foreign_run' => 0,
            'foreign_session' => 0,
        ];

        foreach (glob($dir . '/mysql_profile_*.json') ?: [] as $file) {
            $shardStats['read']++;
            $raw = file_get_contents($file);
            $payload = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($payload)) {
                $shardStats['corrupt']++;
                $findings[] = InstrumentationFinding::make(
                    'corrupt_shard',
                    'warn',
                    'Se encontró un shard JSON inválido.',
                    ['file' => InstrumentationContext::normalizePath($file)],
                    'Aislar el proceso que escribió el shard y revisar terminación abrupta o filesystem.'
                );
                continue;
            }

            $payloadRun = (string)($payload['run_id'] ?? '');
            if ($payloadRun !== '' && $payloadRun !== $runId) {
                $shardStats['foreign_run']++;
                continue;
            }
            if ($payloadRun === '') {
                $findings[] = InstrumentationFinding::make(
                    'legacy_shard_missing_run_id',
                    'watch',
                    'Se aceptó un shard legado sin run_id explícito.',
                    ['file' => InstrumentationContext::normalizePath($file)],
                    'Regenerar el reporte con instrumentación v2.'
                );
            }

            $payloadSession = (string)($payload['capture_session_id'] ?? '');
            if ($payloadSession !== '' && $sessionId !== '' && $payloadSession !== $sessionId) {
                $shardStats['foreign_session']++;
                continue;
            }

            $shardStats['accepted']++;
            $configHash = trim((string)($payload['config_hash'] ?? ''));
            if ($configHash !== '') {
                $configHashes[$configHash] = true;
            }
            $started = (string)($payload['started_at'] ?? '');
            $finished = (string)($payload['finished_at'] ?? '');
            if ($started !== '' && ($startedAt === '' || $started < $startedAt)) {
                $startedAt = $started;
            }
            if ($finished !== '' && ($finishedAt === '' || $finished > $finishedAt)) {
                $finishedAt = $finished;
            }

            foreach ((array)($payload['queries'] ?? []) as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            foreach ((array)($payload['connections'] ?? []) as $connection) {
                if (is_array($connection)) {
                    $connections[] = $connection;
                }
            }
            foreach ((array)($payload['instrumentation_findings'] ?? []) as $finding) {
                if (is_array($finding)) {
                    $findings[] = $finding;
                }
            }

            $metrics = is_array($payload['collector_metrics'] ?? null) ? $payload['collector_metrics'] : [];
            $collectorMetrics['collector_record_calls'] += (int)($metrics['collector_record_calls'] ?? 0);
            $collectorMetrics['collector_total_overhead_ms'] += (float)($metrics['collector_total_overhead_ms'] ?? 0.0);
            $collectorMetrics['shard_write_ms'] += (float)($metrics['shard_write_ms'] ?? 0.0);
        }

        if ($shardStats['read'] === 0) {
            $findings[] = InstrumentationFinding::make(
                'missing_shards',
                'watch',
                'No se encontraron shards para la ejecución.',
                ['shard_dir' => InstrumentationContext::normalizePath($dir)],
                'Confirmar auto_prepend_file, permisos y propagación de TESTKIT_DB_PROFILE.'
            );
        }
        if (count($configHashes) > 1) {
            $findings[] = InstrumentationFinding::make(
                'inconsistent_worker_configuration',
                'warn',
                'Los workers reportaron configuraciones de profiling diferentes.',
                ['config_variants' => count($configHashes)],
                'Propagar las mismas variables TESTKIT_DB_PROFILE_* a todos los workers.'
            );
        }
        if ($shardStats['foreign_run'] > 0 || $shardStats['foreign_session'] > 0) {
            $findings[] = InstrumentationFinding::make(
                'foreign_shards_ignored',
                'info',
                'Se ignoraron shards de otra ejecución o sesión.',
                [
                    'foreign_run' => $shardStats['foreign_run'],
                    'foreign_session' => $shardStats['foreign_session'],
                ],
                'Mantener un run_id único por ejecución; los shards no se mezclaron.'
            );
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        return self::buildReportFromRows(
            $rows,
            $connections,
            $findings,
            $config,
            array_merge($context, [
                'run_id' => $runId,
                'meta_run_id' => (string)($context['meta_run_id'] ?? $config['meta_run_id'] ?? ''),
                'started_at' => $startedAt !== '' ? $startedAt : $now,
                'finished_at' => $finishedAt !== '' ? $finishedAt : $now,
                'capture_session_id' => $sessionId,
                'shards' => $shardStats,
                'collector_metrics' => $collectorMetrics,
            ])
        );
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $connections
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $config
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function buildReportFromRows(
        array $rows,
        array $connections,
        array $findings,
        array $config,
        array $context
    ): array {
        $buildStarted = microtime(true);
        $startedAt = (string)($context['started_at'] ?? gmdate('Y-m-d\TH:i:s\Z'));
        $finishedAt = (string)($context['finished_at'] ?? gmdate('Y-m-d\TH:i:s\Z'));
        $startedTs = strtotime($startedAt) ?: time();
        $finishedTs = strtotime($finishedAt) ?: time();
        $capture = is_array($config['capture'] ?? null) ? $config['capture'] : [];
        $sampleLimit = (int)($capture['sample_limit'] ?? 256);
        $contextLimit = (int)($capture['max_context_values'] ?? 20);
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
                    'tests' => [],
                    'suites' => [],
                    'workers' => [],
                    'modules' => [],
                    'scenarios' => [],
                    'connection_ids' => [],
                    'capture_methods' => [],
                    'context_counts' => [
                        'source' => 0,
                        'caller' => 0,
                        'test' => 0,
                        'connection' => 0,
                        'module' => 0,
                        'scenario' => 0,
                    ],
                    'duration_samples' => [],
                    'context_truncated' => [],
                ];
            }

            $target =& $merged[$fingerprint];
            $calls = max(0, (int)($row['calls'] ?? 0));
            $target['calls'] += $calls;
            $target['total_ms'] += (float)($row['total_ms'] ?? 0.0);
            $target['max_ms'] = max((float)$target['max_ms'], (float)($row['max_ms'] ?? 0.0));
            $minMs = (float)($row['min_ms'] ?? 0.0);
            $target['min_ms'] = $target['min_ms'] === null ? $minMs : min((float)$target['min_ms'], $minMs);
            $target['first_seen_at'] = min((string)$target['first_seen_at'], (string)($row['first_seen_at'] ?? $startedAt));
            $target['last_seen_at'] = max((string)$target['last_seen_at'], (string)($row['last_seen_at'] ?? $finishedAt));

            foreach (['sources', 'callers', 'tests', 'suites', 'workers', 'modules', 'scenarios', 'connection_ids'] as $key) {
                self::mergeLimited($target[$key], is_array($row[$key] ?? null) ? $row[$key] : [], $contextLimit);
            }
            foreach ((array)($row['capture_methods'] ?? []) as $method => $count) {
                $method = MysqlCaptureMethod::normalize((string)$method);
                $target['capture_methods'][$method] = (int)($target['capture_methods'][$method] ?? 0) + max(0, (int)$count);
            }
            foreach ((array)($row['context_counts'] ?? []) as $key => $count) {
                if (array_key_exists((string)$key, $target['context_counts'])) {
                    $target['context_counts'][(string)$key] += max(0, (int)$count);
                }
            }
            $incomingSamples = is_array($row['duration_samples'] ?? null) ? $row['duration_samples'] : [];
            if ($incomingSamples === [] && $calls > 0) {
                foreach ([
                    'min' => (float)($row['min_ms'] ?? 0.0),
                    'avg' => (float)($row['avg_ms'] ?? (($row['total_ms'] ?? 0.0) / max(1, $calls))),
                    'max' => (float)($row['max_ms'] ?? 0.0),
                ] as $legacyKind => $legacyValue) {
                    $incomingSamples[] = [
                        'key' => 'legacy|' . $fingerprint . '|' . $legacyKind . '|' . sprintf('%.6f', $legacyValue),
                        'value_ms' => max(0.0, $legacyValue),
                        'priority' => hash('sha256', 'legacy|' . $fingerprint . '|' . $legacyKind),
                    ];
                }
            }
            $target['duration_samples'] = BoundedDurationSamples::merge(
                is_array($target['duration_samples']) ? $target['duration_samples'] : [],
                $incomingSamples,
                $sampleLimit
            );
            foreach ((array)($row['context_truncated'] ?? []) as $key => $flag) {
                if ($flag) {
                    $target['context_truncated'][(string)$key] = true;
                }
            }
            unset($target);
        }

        $queries = [];
        foreach ($merged as $row) {
            $calls = max(1, (int)$row['calls']);
            $row['min_ms'] = self::roundMs((float)($row['min_ms'] ?? 0.0));
            $row['avg_ms'] = self::roundMs(((float)$row['total_ms']) / $calls);
            $row['max_ms'] = self::roundMs((float)$row['max_ms']);
            $row['total_ms'] = self::roundMs((float)$row['total_ms']);
            $row += BoundedDurationSamples::statistics((array)$row['duration_samples'], $calls);
            unset($row['duration_samples']);
            ksort($row['capture_methods']);
            $row['classification'] = self::classify($row, $config);
            $row['recommendation'] = self::recommend($row);

            if ($row['context_truncated'] !== []) {
                $findings[] = InstrumentationFinding::make(
                    'context_cardinality_truncated',
                    'watch',
                    'Se truncaron valores de contexto por límite de cardinalidad.',
                    [
                        'fingerprint' => $row['fingerprint'],
                        'fields' => array_keys($row['context_truncated']),
                    ],
                    'Aumentar TESTKIT_DB_PROFILE_MAX_CONTEXT_VALUES solo si el reporte sigue siendo acotado.'
                );
            }
            $queries[] = $row;
        }
        usort($queries, static function (array $a, array $b): int {
            $total = ((float)$b['total_ms'] <=> (float)$a['total_ms']);
            return $total !== 0 ? $total : strcmp((string)$a['fingerprint'], (string)$b['fingerprint']);
        });

        $connections = self::mergeConnections($connections);
        foreach ($connections as $connection) {
            if ((int)($connection['query_count'] ?? 0) === 0) {
                $findings[] = InstrumentationFinding::make(
                    'connection_without_queries',
                    'info',
                    'Una conexión observada no ejecutó consultas.',
                    ['connection_id' => $connection['connection_id'] ?? ''],
                    'Verificar si es una conexión preventiva o un escenario no ejercitado.'
                );
            }
        }

        $topN = (int)($config['top_n'] ?? 20);
        $rankings = [
            'by_max_ms' => self::ranking($queries, 'max_ms', $topN),
            'by_total_ms' => self::ranking($queries, 'total_ms', $topN),
            'by_calls' => self::ranking($queries, 'calls', $topN),
            'by_avg_ms' => self::ranking($queries, 'avg_ms', $topN),
        ];
        $summary = self::summary($queries);
        $coverage = self::coverage($queries, $connections);
        $findings = self::normalizeFindings($findings);

        $explainStarted = microtime(true);
        $explain = self::sanitizeRecursive(MysqlExplainAnalyzer::fromConfig($config)->analyze($queries));
        $explainMs = self::roundMs((microtime(true) - $explainStarted) * 1000);

        $status = 'ok';
        foreach ($findings as $finding) {
            if (($finding['severity'] ?? '') === 'warn') {
                $status = 'warn';
                break;
            }
            if (($finding['severity'] ?? '') === 'watch') {
                $status = 'watch';
            }
        }

        $collectorMetrics = is_array($context['collector_metrics'] ?? null) ? $context['collector_metrics'] : [];
        $collectorMetrics['collector_total_overhead_ms'] = self::roundMs((float)($collectorMetrics['collector_total_overhead_ms'] ?? 0.0));
        $collectorMetrics['report_build_ms'] = self::roundMs((microtime(true) - $buildStarted) * 1000);
        $collectorMetrics['explain_analysis_ms'] = $explainMs;
        $collectorMetrics['shard_write_ms'] = self::roundMs((float)($collectorMetrics['shard_write_ms'] ?? 0.0));

        return [
            'report_version' => MysqlProfileConfig::REPORT_VERSION,
            'schema_version' => MysqlProfileConfig::SCHEMA_VERSION,
            'instrumentation_version' => MysqlProfileConfig::INSTRUMENTATION_VERSION,
            'engine' => 'mysql',
            'profile_enabled' => (bool)($config['enabled'] ?? MysqlProfileConfig::isEnabled()),
            'run_id' => (string)($context['run_id'] ?? ''),
            'meta_run_id' => (string)($context['meta_run_id'] ?? ''),
            'suite_id' => (string)($context['suite_id'] ?? ''),
            'capture_session_id' => (string)($context['capture_session_id'] ?? ''),
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => max(0, ($finishedTs - $startedTs) * 1000),
            'run_metadata' => [
                'run_id' => (string)($context['run_id'] ?? ''),
                'meta_run_id' => (string)($context['meta_run_id'] ?? ''),
                'suite_id' => (string)($context['suite_id'] ?? ''),
                'capture_session_id' => (string)($context['capture_session_id'] ?? ''),
                'shards' => is_array($context['shards'] ?? null) ? $context['shards'] : [],
            ],
            'config' => MysqlProfileConfig::publicConfig($config),
            'summary' => $summary,
            'coverage' => $coverage,
            'connections' => $connections,
            'instrumentation' => [
                'status' => $status,
                'capture_methods' => $coverage['facts']['queries_by_capture_method'],
                'findings' => $findings,
                'limitations' => [
                    'PHP userland no puede observar consultas que evitan adaptadores instrumentados.',
                    'overall_capture_coverage_pct permanece unknown sin un denominador externo confiable.',
                    'PDO existente solo permite interceptar execute() de statements preparados después del helper.',
                ],
            ],
            'rankings' => $rankings,
            'queries' => $queries,
            'recommendations' => self::recommendations($queries),
            'explain' => $explain,
            'profiler_metrics' => $collectorMetrics,
            'limitations' => [
                'PHP userland cannot transparently intercept every existing new PDO(...) call.',
                'Overall application-query capture coverage is unknown without an independent denominator.',
                'EXPLAIN is optional and does not execute unsafe or parameterized samples.',
                'No schema changes, CREATE INDEX automation, baselines, regressions, budgets, or performance gates are implemented in phase 1.',
            ],
        ];
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $context @return array<string,mixed> */
    private static function emptyReport(string $runId, array $config, array $context = []): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return [
            'report_version' => MysqlProfileConfig::REPORT_VERSION,
            'schema_version' => MysqlProfileConfig::SCHEMA_VERSION,
            'instrumentation_version' => MysqlProfileConfig::INSTRUMENTATION_VERSION,
            'engine' => 'mysql',
            'profile_enabled' => (bool)($config['enabled'] ?? false),
            'run_id' => $runId,
            'meta_run_id' => (string)($config['meta_run_id'] ?? ''),
            'suite_id' => '',
            'capture_session_id' => self::readCaptureSessionId($config),
            'started_at' => $now,
            'finished_at' => $now,
            'duration_ms' => 0,
            'run_metadata' => ['run_id' => $runId, 'shards' => []],
            'config' => MysqlProfileConfig::publicConfig($config),
            'summary' => self::summary([]),
            'coverage' => self::coverage([], []),
            'connections' => [],
            'instrumentation' => [
                'status' => 'warn',
                'capture_methods' => [],
                'findings' => [
                    InstrumentationFinding::make(
                        'report_build_failed',
                        'warn',
                        'No se pudo construir el reporte.',
                        ['error' => (string)($context['report_error'] ?? '')],
                        'Revisar permisos, shards y contrato JSON.'
                    ),
                ],
                'limitations' => [],
            ],
            'rankings' => ['by_max_ms' => [], 'by_total_ms' => [], 'by_calls' => [], 'by_avg_ms' => []],
            'queries' => [],
            'recommendations' => [],
            'explain' => MysqlExplainAnalyzer::emptyResult((bool)($config['explain']['enabled'] ?? false)),
            'profiler_metrics' => [
                'collector_record_calls' => 0,
                'collector_total_overhead_ms' => 0.0,
                'report_build_ms' => 0.0,
                'explain_analysis_ms' => 0.0,
                'shard_write_ms' => 0.0,
            ],
            'limitations' => [],
            'report_error' => InstrumentationContext::sanitizeText((string)($context['report_error'] ?? ''), 240),
        ];
    }

    /** @param array<int,array<string,mixed>> $connections @return array<int,array<string,mixed>> */
    private static function mergeConnections(array $connections): array
    {
        $merged = [];
        foreach ($connections as $row) {
            $id = InstrumentationContext::sanitizeIdentifier((string)($row['connection_id'] ?? ''), 80);
            if ($id === '') {
                continue;
            }
            if (!isset($merged[$id])) {
                $merged[$id] = [
                    'connection_id' => $id,
                    'adapter' => InstrumentationContext::sanitizeIdentifier((string)($row['adapter'] ?? 'unknown'), 80),
                    'engine' => InstrumentationContext::sanitizeIdentifier((string)($row['engine'] ?? 'mysql'), 40),
                    'capture_capabilities' => [
                        'query' => false,
                        'exec' => false,
                        'prepare_execute' => false,
                        'transactions' => false,
                    ],
                    'created_at' => (string)($row['created_at'] ?? ''),
                    'first_query_at' => $row['first_query_at'] ?? null,
                    'last_query_at' => $row['last_query_at'] ?? null,
                    'query_count' => 0,
                    'prepared_statement_count' => 0,
                    'transaction_count' => 0,
                    'instrumented' => (bool)($row['instrumented'] ?? false),
                ];
            }
            $target =& $merged[$id];
            foreach ($target['capture_capabilities'] as $key => $value) {
                $target['capture_capabilities'][$key] = $value || (bool)($row['capture_capabilities'][$key] ?? false);
            }
            $target['query_count'] += max(0, (int)($row['query_count'] ?? 0));
            $target['prepared_statement_count'] += max(0, (int)($row['prepared_statement_count'] ?? 0));
            $target['transaction_count'] += max(0, (int)($row['transaction_count'] ?? 0));
            $target['instrumented'] = $target['instrumented'] || (bool)($row['instrumented'] ?? false);
            $created = (string)($row['created_at'] ?? '');
            if ($created !== '' && ($target['created_at'] === '' || $created < $target['created_at'])) {
                $target['created_at'] = $created;
            }
            foreach (['first_query_at' => 'min', 'last_query_at' => 'max'] as $key => $op) {
                $value = (string)($row[$key] ?? '');
                if ($value === '') {
                    continue;
                }
                $current = (string)($target[$key] ?? '');
                $target[$key] = $current === '' ? $value : ($op === 'min' ? min($current, $value) : max($current, $value));
            }
            unset($target);
        }

        ksort($merged);
        return array_values($merged);
    }

    /** @param array<int,array<string,mixed>> $queries @param array<int,array<string,mixed>> $connections */
    private static function coverage(array $queries, array $connections): array
    {
        $facts = [
            'captured_queries' => 0,
            'captured_unique_fingerprints' => count($queries),
            'instrumented_connections' => 0,
            'connections_with_queries' => 0,
            'queries_with_source' => 0,
            'queries_with_caller' => 0,
            'queries_with_test_context' => 0,
            'queries_with_connection' => 0,
            'queries_with_module' => 0,
            'queries_with_scenario' => 0,
            'queries_by_capture_method' => [],
        ];

        foreach ($queries as $row) {
            $facts['captured_queries'] += (int)($row['calls'] ?? 0);
            $counts = is_array($row['context_counts'] ?? null) ? $row['context_counts'] : [];
            $facts['queries_with_source'] += (int)($counts['source'] ?? 0);
            $facts['queries_with_caller'] += (int)($counts['caller'] ?? 0);
            $facts['queries_with_test_context'] += (int)($counts['test'] ?? 0);
            $facts['queries_with_connection'] += (int)($counts['connection'] ?? 0);
            $facts['queries_with_module'] += (int)($counts['module'] ?? 0);
            $facts['queries_with_scenario'] += (int)($counts['scenario'] ?? 0);
            foreach ((array)($row['capture_methods'] ?? []) as $method => $count) {
                $facts['queries_by_capture_method'][(string)$method] =
                    (int)($facts['queries_by_capture_method'][(string)$method] ?? 0) + (int)$count;
            }
        }
        ksort($facts['queries_by_capture_method']);

        foreach ($connections as $connection) {
            if (!empty($connection['instrumented'])) {
                $facts['instrumented_connections']++;
            }
            if ((int)($connection['query_count'] ?? 0) > 0) {
                $facts['connections_with_queries']++;
            }
        }

        $denominator = (int)$facts['captured_queries'];
        $percentages = [
            'source_context_coverage_pct' => self::percentage((int)$facts['queries_with_source'], $denominator),
            'caller_context_coverage_pct' => self::percentage((int)$facts['queries_with_caller'], $denominator),
            'test_context_coverage_pct' => self::percentage((int)$facts['queries_with_test_context'], $denominator),
            'connection_context_coverage_pct' => self::percentage((int)$facts['queries_with_connection'], $denominator),
            'module_context_coverage_pct' => self::percentage((int)$facts['queries_with_module'], $denominator),
            'scenario_context_coverage_pct' => self::percentage((int)$facts['queries_with_scenario'], $denominator),
        ];

        return [
            'facts' => $facts,
            'calculable' => $percentages,
            'unknown' => [
                'total_application_queries' => null,
                'overall_capture_coverage_pct' => null,
                'overall_capture_coverage_status' => 'unknown',
                'reason' => 'PHP userland cannot observe queries executed outside instrumented adapters',
            ],
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
        if (
            $maxMs >= $slowMaxMs * $watchRatio
            || $totalMs >= $hotspotTotalMs * $watchRatio
            || $calls >= (int)ceil($highCalls * $watchRatio)
        ) {
            return 'watch';
        }
        return 'ok';
    }

    /** @param array<string,mixed> $row */
    private static function recommend(array $row): string
    {
        return match ((string)($row['classification'] ?? 'ok')) {
            'n_plus_one_candidate' => 'Revisar patrón N+1: muchas llamadas del mismo fingerprint con latencia individual baja/media.',
            'hotspot' => 'Priorizar optimización por costo acumulado: revisar filtros, joins, índices existentes y frecuencia.',
            'slow' => 'Revisar latencia individual y ejecutar EXPLAIN en un entorno de test seguro.',
            'watch' => 'Monitorear: el fingerprint está cerca de los umbrales diagnósticos.',
            default => 'Sin acción inmediata. Mantener la evidencia para fases posteriores.',
        };
    }

    /** @param array<int,array<string,mixed>> $queries */
    private static function ranking(array $queries, string $field, int $topN): array
    {
        $copy = $queries;
        usort($copy, static function (array $a, array $b) use ($field): int {
            $value = ((float)($b[$field] ?? 0) <=> (float)($a[$field] ?? 0));
            return $value !== 0 ? $value : strcmp((string)($a['fingerprint'] ?? ''), (string)($b['fingerprint'] ?? ''));
        });
        return array_map(static function (array $row): array {
            return [
                'fingerprint' => (string)$row['fingerprint'],
                'sample_sql' => (string)$row['sample_sql'],
                'calls' => (int)$row['calls'],
                'min_ms' => (float)$row['min_ms'],
                'avg_ms' => (float)$row['avg_ms'],
                'max_ms' => (float)$row['max_ms'],
                'total_ms' => (float)$row['total_ms'],
                'p50_ms' => (float)($row['p50_ms'] ?? 0.0),
                'p95_ms' => (float)($row['p95_ms'] ?? 0.0),
                'p99_ms' => (float)($row['p99_ms'] ?? 0.0),
                'sample_count' => (int)($row['sample_count'] ?? 0),
                'percentiles_approximate' => (bool)($row['percentiles_approximate'] ?? false),
                'classification' => (string)$row['classification'],
                'recommendation' => (string)$row['recommendation'],
            ];
        }, array_slice($copy, 0, max(1, $topN)));
    }

    /** @param array<int,array<string,mixed>> $queries */
    private static function summary(array $queries): array
    {
        $summary = [
            'total_queries' => 0,
            'unique_fingerprints' => count($queries),
            'total_db_time_ms' => 0.0,
            'slow_count' => 0,
            'hotspot_count' => 0,
            'n_plus_one_candidates' => 0,
        ];
        foreach ($queries as $row) {
            $summary['total_queries'] += (int)($row['calls'] ?? 0);
            $summary['total_db_time_ms'] += (float)($row['total_ms'] ?? 0.0);
            $class = (string)($row['classification'] ?? 'ok');
            if ($class === 'slow') {
                $summary['slow_count']++;
            } elseif ($class === 'hotspot') {
                $summary['hotspot_count']++;
            } elseif ($class === 'n_plus_one_candidate') {
                $summary['n_plus_one_candidates']++;
            }
        }
        $summary['total_db_time_ms'] = self::roundMs((float)$summary['total_db_time_ms']);
        return $summary;
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

    /** @param array<int,array<string,mixed>> $findings @return array<int,array<string,mixed>> */
    private static function normalizeFindings(array $findings): array
    {
        $unique = [];
        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $normalized = InstrumentationFinding::make(
                (string)($finding['code'] ?? 'unknown'),
                (string)($finding['severity'] ?? 'watch'),
                (string)($finding['message'] ?? ''),
                is_array($finding['context'] ?? null) ? $finding['context'] : [],
                (string)($finding['recommendation'] ?? '')
            );
            $key = $normalized['code'] . '|' . json_encode($normalized['context'], JSON_UNESCAPED_SLASHES);
            $unique[$key] = $normalized;
        }

        $rank = ['warn' => 3, 'watch' => 2, 'info' => 1];
        $items = array_values($unique);
        usort($items, static function (array $a, array $b) use ($rank): int {
            $severity = (($rank[(string)$b['severity']] ?? 0) <=> ($rank[(string)$a['severity']] ?? 0));
            return $severity !== 0 ? $severity : strcmp((string)$a['code'], (string)$b['code']);
        });
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
            if (count($target) >= max(1, $limit)) {
                return;
            }
            $target[] = $value;
        }
        sort($target, SORT_STRING);
    }

    private static function percentage(int $numerator, int $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }
        return round(($numerator / $denominator) * 100, 2);
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
        $tmp = $path . '.tmp.' . getmypid() . '.' . self::randomToken(8);
        if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo escribir archivo temporal de mysql profiling');
        }
        @chmod($tmp, 0640);
        if (!rename($tmp, $path)) {
            if (is_file($tmp)) {
                unlink($tmp);
            }
            throw new \RuntimeException('No se pudo publicar reporte mysql profiling');
        }
    }


    private static function sanitizeRecursive(mixed $value): mixed
    {
        if (is_string($value)) {
            return InstrumentationContext::sanitizeText($value, 2000);
        }
        if (!is_array($value)) {
            return $value;
        }

        $isList = self::isList($value);
        $out = [];
        foreach ($value as $key => $item) {
            if (!$isList && preg_match('/pass(word)?|secret|token|api[_-]?key|cookie|authorization|dsn|user(name)?/i', (string)$key) === 1) {
                continue;
            }
            $out[$key] = self::sanitizeRecursive($item);
        }
        return $isList ? array_values($out) : $out;
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }

    private static function randomToken(int $length): string
    {
        try {
            return substr(bin2hex(random_bytes((int)ceil($length / 2))), 0, $length);
        } catch (\Throwable) {
            return substr(hash('sha256', uniqid('', true)), 0, $length);
        }
    }
}
