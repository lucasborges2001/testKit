<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

final class QueryProfileCollector
{
    /** @var array<string,array<string,mixed>> */
    private static array $queries = [];
    /** @var array<int,array<string,mixed>> */
    private static array $findings = [];
    /** @var array<string,bool> */
    private static array $findingKeys = [];
    private static bool $shutdownRegistered = false;
    private static bool $forceEnabled = false;
    private static bool $warningEmitted = false;
    private static bool $bootstrapped = false;
    private static string $startedAt = '';
    private static int $recordCalls = 0;
    private static float $recordOverheadMs = 0.0;

    public static function enableForTests(): void
    {
        self::$forceEnabled = true;
        self::$startedAt = gmdate('Y-m-d\TH:i:s\Z');
    }

    public static function markBootstrapped(): void
    {
        self::$bootstrapped = true;
    }

    public static function resetForTests(): void
    {
        self::$queries = [];
        self::$findings = [];
        self::$findingKeys = [];
        self::$shutdownRegistered = false;
        self::$forceEnabled = false;
        self::$warningEmitted = false;
        self::$bootstrapped = false;
        self::$startedAt = '';
        self::$recordCalls = 0;
        self::$recordOverheadMs = 0.0;
        ConnectionRegistry::resetForTests();
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
        self::$startedAt = self::$startedAt !== '' ? self::$startedAt : gmdate('Y-m-d\TH:i:s\Z');

        register_shutdown_function(static function (): void {
            try {
                MysqlProfileReporter::writeProcessShard(self::snapshot());
            } catch (\Throwable $e) {
                fwrite(
                    STDERR,
                    'WARN[MYSQL_PROFILE_SHARD_FAILED]: '
                    . InstrumentationContext::sanitizeText($e->getMessage(), 240)
                    . PHP_EOL
                );
            }
        });
    }

    /**
     * Existing calls remain valid; the optional context extends the public contract.
     *
     * @param array<string,mixed> $context
     */
    public static function record(
        string $sql,
        float $durationMs,
        string $source = '',
        string $caller = '',
        array $context = []
    ): void {
        if (!self::isEnabled()) {
            return;
        }

        $overheadStarted = microtime(true);
        try {
            self::recordUnsafe($sql, $durationMs, $source, $caller, $context);
        } catch (\Throwable $e) {
            self::addFinding(
                'collector_record_error',
                'warn',
                'El collector no pudo registrar una consulta.',
                ['error' => $e->getMessage()],
                'Revisar el contexto enviado al helper y la configuración de profiling.'
            );
            if (!self::$warningEmitted) {
                self::$warningEmitted = true;
                fwrite(
                    STDERR,
                    'WARN[MYSQL_PROFILE_RECORD_FAILED]: '
                    . InstrumentationContext::sanitizeText($e->getMessage(), 240)
                    . PHP_EOL
                );
            }
        } finally {
            self::$recordCalls++;
            self::$recordOverheadMs += (microtime(true) - $overheadStarted) * 1000;
        }
    }

    /** @param array<string,mixed> $context */
    private static function recordUnsafe(
        string $sql,
        float $durationMs,
        string $source,
        string $caller,
        array $context
    ): void {
        self::$startedAt = self::$startedAt !== '' ? self::$startedAt : gmdate('Y-m-d\TH:i:s\Z');
        $config = MysqlProfileConfig::fromEnv();
        $capture = is_array($config['capture'] ?? null) ? $config['capture'] : [];
        $maxSqlLength = (int)($capture['max_sql_length'] ?? 2000);
        $sampleLimit = (int)($capture['sample_limit'] ?? 256);
        $maxContextValues = (int)($capture['max_context_values'] ?? 20);

        $fingerprint = SqlFingerprint::fingerprint($sql);
        if ($fingerprint === '') {
            self::addFinding(
                'empty_fingerprint',
                'watch',
                'Se descartó una consulta porque el fingerprint quedó vacío.',
                [],
                'Revisar el hook manual o wrapper que envió el SQL.'
            );
            return;
        }

        if ($source !== '') {
            $context['source'] = $source;
        }
        if ($caller !== '') {
            $context['caller'] = $caller;
        }
        if (!isset($context['capture_method'])) {
            $context['capture_method'] = MysqlCaptureMethod::MANUAL_RECORD;
        }

        $ctx = InstrumentationContext::current($context);
        if (!(bool)($capture['context_enabled'] ?? true)) {
            foreach (['test_id', 'test_path', 'module_id', 'scenario_id', 'source', 'caller'] as $disabledKey) {
                $ctx[$disabledKey] = '';
            }
        }

        $method = (string)$ctx['capture_method'];
        if ($method === MysqlCaptureMethod::UNKNOWN) {
            self::addFinding(
                'unknown_capture_method',
                'warn',
                'Una consulta fue registrada con método de captura desconocido.',
                ['fingerprint' => $fingerprint],
                'Usar uno de los métodos estables documentados o un helper público.'
            );
        }
        if ($method === MysqlCaptureMethod::MANUAL_RECORD && $ctx['source'] === '' && $ctx['caller'] === '') {
            self::addFinding(
                'manual_record_missing_origin',
                'watch',
                'Un registro manual no indicó source ni caller.',
                ['fingerprint' => $fingerprint],
                'Enviar source/caller o contexto module_id/scenario_id al helper.'
            );
        }
        if ($ctx['connection_id'] === '') {
            if ((bool)($capture['connections_enabled'] ?? true)) {
                self::addFinding(
                    'query_without_connection',
                    'info',
                    'Hay consultas capturadas sin conexión asociada.',
                    ['capture_method' => $method],
                    'Registrar la conexión cuando el adaptador permita identificarla.'
                );
            }
        } else {
            ConnectionRegistry::query((string)$ctx['connection_id']);
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        if (!isset(self::$queries[$fingerprint])) {
            self::$queries[$fingerprint] = [
                'fingerprint' => $fingerprint,
                'sample_sql' => SqlFingerprint::sampleSql($sql, $maxSqlLength),
                'calls' => 0,
                'min_ms' => max(0.0, $durationMs),
                'max_ms' => max(0.0, $durationMs),
                'total_ms' => 0.0,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
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
                'sample_sequence' => 0,
                'context_truncated' => [],
            ];
        }

        $row =& self::$queries[$fingerprint];
        $row['calls'] = (int)$row['calls'] + 1;
        $row['min_ms'] = min((float)$row['min_ms'], max(0.0, $durationMs));
        $row['max_ms'] = max((float)$row['max_ms'], max(0.0, $durationMs));
        $row['total_ms'] = (float)$row['total_ms'] + max(0.0, $durationMs);
        $row['last_seen_at'] = $now;
        $row['capture_methods'][$method] = (int)($row['capture_methods'][$method] ?? 0) + 1;
        $row['sample_sequence'] = (int)$row['sample_sequence'] + 1;

        $sampleKey = implode('|', [
            (string)$ctx['run_id'],
            (string)$ctx['process_id'],
            $fingerprint,
            (string)$row['sample_sequence'],
            sprintf('%.6f', max(0.0, $durationMs)),
        ]);
        $row['duration_samples'] = BoundedDurationSamples::add(
            is_array($row['duration_samples']) ? $row['duration_samples'] : [],
            $durationMs,
            $sampleKey,
            $sampleLimit
        );

        self::captureContextValue($row, 'sources', (string)$ctx['source'], 'source', $maxContextValues);
        self::captureContextValue($row, 'callers', (string)$ctx['caller'], 'caller', $maxContextValues);
        self::captureContextValue($row, 'tests', (string)$ctx['test_path'], 'test', $maxContextValues);
        self::captureContextValue($row, 'suites', (string)$ctx['suite_id'], null, $maxContextValues);
        self::captureContextValue($row, 'workers', (string)$ctx['worker_id'], null, $maxContextValues);
        self::captureContextValue($row, 'modules', (string)$ctx['module_id'], 'module', $maxContextValues);
        self::captureContextValue($row, 'scenarios', (string)$ctx['scenario_id'], 'scenario', $maxContextValues);
        self::captureContextValue($row, 'connection_ids', (string)$ctx['connection_id'], 'connection', $maxContextValues);

        unset($row);
    }

    /** @return array<string,mixed> */
    public static function snapshot(): array
    {
        $config = MysqlProfileConfig::fromEnv();
        $queries = [];
        foreach (self::$queries as $row) {
            $calls = max(1, (int)$row['calls']);
            $row['avg_ms'] = self::roundMs(((float)$row['total_ms']) / $calls);
            $row['min_ms'] = self::roundMs((float)$row['min_ms']);
            $row['max_ms'] = self::roundMs((float)$row['max_ms']);
            $row['total_ms'] = self::roundMs((float)$row['total_ms']);
            unset($row['sample_sequence']);
            ksort($row['capture_methods']);
            $queries[] = $row;
        }
        usort($queries, static fn(array $a, array $b): int => strcmp((string)$a['fingerprint'], (string)$b['fingerprint']));

        $connections = ConnectionRegistry::snapshot();
        foreach ($connections as $connection) {
            if ((int)($connection['query_count'] ?? 0) === 0) {
                self::addFinding(
                    'connection_without_queries',
                    'info',
                    'Se registró una conexión instrumentada sin consultas.',
                    ['connection_id' => $connection['connection_id'] ?? ''],
                    'Confirmar si la conexión se crea preventivamente o si el escenario no ejercitó acceso SQL.'
                );
            }
        }

        if (!self::$bootstrapped && MysqlProfileConfig::isEnabled()) {
            self::addFinding(
                'bootstrap_not_confirmed',
                'watch',
                'El profiling está habilitado pero el proceso no confirmó el auto-prepend de instrumentación.',
                [],
                'Ejecutar la suite mediante BackPhpSuite o incluir core/php/dbprofiling/public_api.php antes del acceso SQL.'
            );
        }

        $current = InstrumentationContext::current();
        return [
            'report_version' => MysqlProfileConfig::REPORT_VERSION,
            'schema_version' => MysqlProfileConfig::SCHEMA_VERSION,
            'instrumentation_version' => MysqlProfileConfig::INSTRUMENTATION_VERSION,
            'engine' => 'mysql',
            'profile_enabled' => self::isEnabled(),
            'run_id' => (string)$current['run_id'],
            'meta_run_id' => (string)$current['meta_run_id'],
            'suite_id' => (string)$current['suite_id'],
            'test_path' => (string)$current['test_path'],
            'worker_id' => (string)$current['worker_id'],
            'process_id' => (int)$current['process_id'],
            'capture_session_id' => MysqlProfileReporter::readCaptureSessionId($config),
            'config_hash' => hash('sha256', (string)json_encode(MysqlProfileConfig::publicConfig($config), JSON_UNESCAPED_SLASHES)),
            'bootstrapped' => self::$bootstrapped,
            'started_at' => self::$startedAt !== '' ? self::$startedAt : gmdate('Y-m-d\TH:i:s\Z'),
            'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'queries' => $queries,
            'connections' => $connections,
            'instrumentation_findings' => array_values(self::$findings),
            'collector_metrics' => [
                'collector_record_calls' => self::$recordCalls,
                'collector_total_overhead_ms' => self::roundMs(self::$recordOverheadMs),
            ],
        ];
    }

    public static function inferCaller(): string
    {
        $capture = MysqlProfileConfig::fromEnv()['capture'] ?? [];
        if (!is_array($capture) || !(bool)($capture['caller'] ?? true)) {
            return '';
        }
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16);
        foreach ($trace as $frame) {
            $file = str_replace('\\', '/', (string)($frame['file'] ?? ''));
            if ($file === '' || str_contains($file, '/testkit/core/php/dbprofiling/')) {
                continue;
            }
            $line = (int)($frame['line'] ?? 0);
            return InstrumentationContext::normalizeCaller($line > 0 ? ($file . ':' . $line) : $file);
        }
        return '';
    }

    public static function inferSource(): string
    {
        $capture = MysqlProfileConfig::fromEnv()['capture'] ?? [];
        if (!is_array($capture) || !(bool)($capture['source_test'] ?? true)) {
            return '';
        }
        return InstrumentationContext::normalizePath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    }

    /** @param array<string,mixed> $context */
    public static function addFinding(
        string $code,
        string $severity,
        string $message,
        array $context = [],
        string $recommendation = ''
    ): void {
        $finding = InstrumentationFinding::make($code, $severity, $message, $context, $recommendation);
        $key = (string)$finding['code'] . '|' . json_encode($finding['context'], JSON_UNESCAPED_SLASHES);
        if (isset(self::$findingKeys[$key])) {
            return;
        }
        self::$findingKeys[$key] = true;
        self::$findings[] = $finding;
    }

    /** @param array<string,mixed> $row */
    private static function captureContextValue(
        array &$row,
        string $listKey,
        string $value,
        ?string $countKey,
        int $limit
    ): void {
        $value = trim($value);
        if ($value !== '') {
            if ($countKey !== null) {
                $row['context_counts'][$countKey] = (int)($row['context_counts'][$countKey] ?? 0) + 1;
            }
            self::pushUnique($row[$listKey], $value, $limit, $truncated);
            if ($truncated) {
                $row['context_truncated'][$listKey] = true;
            }
        }
    }

    /** @param array<int,string> $target */
    private static function pushUnique(array &$target, string $value, int $limit, ?bool &$truncated = null): void
    {
        $truncated = false;
        $value = trim($value);
        if ($value === '' || in_array($value, $target, true)) {
            return;
        }
        if (count($target) >= max(1, $limit)) {
            $truncated = true;
            return;
        }
        $target[] = $value;
    }

    private static function roundMs(float $value): float
    {
        return round($value, 3);
    }
}
