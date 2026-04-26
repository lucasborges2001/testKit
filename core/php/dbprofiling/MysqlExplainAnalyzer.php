<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

final class MysqlExplainAnalyzer
{
    /** @var \PDO|null */
    private ?\PDO $pdo;
    /** @var array<string,mixed> */
    private array $config;

    /** @param array<string,mixed> $config */
    public function __construct(?\PDO $pdo, array $config)
    {
        $this->pdo = $pdo;
        $this->config = $config;
    }

    /** @param array<string,mixed> $config */
    public static function fromConfig(array $config): self
    {
        $pdo = null;
        $connection = is_array($config['connection'] ?? null) ? $config['connection'] : [];
        $dsn = trim((string)($connection['dsn'] ?? ''));
        if ($dsn !== '' && stripos($dsn, 'mysql:') === 0 && class_exists(\PDO::class)) {
            try {
                $pdo = new \PDO(
                    $dsn,
                    (string)($connection['user'] ?? ''),
                    (string)($connection['pass'] ?? ''),
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
                );
            } catch (\Throwable) {
                $pdo = null;
            }
        }
        return new self($pdo, $config);
    }

    /**
     * @param array<int,array<string,mixed>> $queries
     * @return array<string,mixed>
     */
    public function analyze(array $queries): array
    {
        $explain = is_array($this->config['explain'] ?? null) ? $this->config['explain'] : [];
        $enabled = (bool)($explain['enabled'] ?? false);
        $result = self::emptyResult($enabled);
        if (!$enabled) {
            return $result;
        }

        $candidates = $this->buildCandidates($queries);
        $result['attempted'] = count($candidates);

        foreach ($candidates as $candidate) {
            $finding = $this->analyzeCandidate($candidate);
            $result['findings'][] = $finding;
            $status = (string)($finding['explain_status'] ?? 'failed');
            if ($status === 'analyzed') {
                $result['analyzed']++;
            } elseif ($status === 'skipped') {
                $result['skipped']++;
            } else {
                $result['failed']++;
            }
        }

        usort($result['findings'], static function (array $a, array $b): int {
            $rank = ['warn' => 3, 'watch' => 2, 'info' => 1];
            return (($rank[(string)($b['severity'] ?? 'info')] ?? 0) <=> ($rank[(string)($a['severity'] ?? 'info')] ?? 0));
        });

        return $result;
    }

    /** @return array<string,mixed> */
    public static function emptyResult(bool $enabled = false): array
    {
        return [
            'enabled' => $enabled,
            'attempted' => 0,
            'analyzed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'findings' => [],
        ];
    }

    public static function isExplainableSql(string $sql): bool
    {
        return SqlFingerprint::isExplainable($sql);
    }

    /**
     * @param array<string,mixed> $candidate
     * @param string|array<mixed> $payload
     * @return array<string,mixed>
     */
    public static function findingFromJsonPlan(array $candidate, string|array $payload, int $highRowsExamined = 10000): array
    {
        return self::findingFromParsed($candidate, MysqlExplainPlanParser::parseJson($payload, $highRowsExamined));
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public static function findingFromTableRows(array $candidate, array $rows, int $highRowsExamined = 10000): array
    {
        return self::findingFromParsed($candidate, MysqlExplainPlanParser::parseTableRows($rows, $highRowsExamined));
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    private static function findingFromParsed(array $candidate, array $parsed): array
    {
        $finding = self::baseFinding($candidate);
        $finding['explain_status'] = 'analyzed';
        $finding['plan_summary'] = is_array($parsed['plan_summary'] ?? null) ? $parsed['plan_summary'] : self::emptyPlanSummary();
        $finding['flags'] = array_values(array_filter((array)($parsed['flags'] ?? []), 'is_string'));
        $finding['severity'] = (string)($parsed['severity'] ?? 'info');
        $finding['recommendation'] = (string)($parsed['recommendation'] ?? '');
        self::applyDeclaredPolicy($finding, $candidate);
        return $finding;
    }

    /** @param array<string,mixed> $candidate */
    private function analyzeCandidate(array $candidate): array
    {
        $sql = trim((string)($candidate['sql'] ?? ''));
        if ($sql === '') {
            return self::skipped($candidate, 'empty_sql');
        }
        if (!SqlFingerprint::isExplainable($sql)) {
            return self::skipped($candidate, 'sample_sql_not_executable');
        }
        if (!$this->pdo instanceof \PDO) {
            return self::skipped($candidate, 'mysql_connection_unavailable');
        }

        $timeoutMs = (int)($this->config['explain']['timeout_ms'] ?? 2000);
        try {
            $this->setStatementTimeout($timeoutMs);
            return $this->runExplain($candidate, $sql);
        } catch (\Throwable $e) {
            $finding = self::baseFinding($candidate);
            $finding['explain_status'] = 'failed';
            $finding['error'] = $e->getMessage();
            $finding['severity'] = 'watch';
            $finding['recommendation'] = 'EXPLAIN falló; revisar si la query depende de sesión, permisos o sintaxis específica.';
            return $finding;
        }
    }

    /** @param array<string,mixed> $candidate */
    private function runExplain(array $candidate, string $sql): array
    {
        try {
            $stmt = $this->pdo?->query('EXPLAIN FORMAT=JSON ' . $sql);
            $row = $stmt instanceof \PDOStatement ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
            $json = is_array($row) ? (string)reset($row) : '';
            if ($json !== '') {
                return self::findingFromJsonPlan($candidate, $json, (int)($this->config['explain']['high_rows_examined'] ?? 10000));
            }
        } catch (\Throwable) {
            // fallback below
        }

        $stmt = $this->pdo?->query('EXPLAIN ' . $sql);
        $rows = $stmt instanceof \PDOStatement ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return self::findingFromTableRows($candidate, is_array($rows) ? $rows : [], (int)($this->config['explain']['high_rows_examined'] ?? 10000));
    }

    private function setStatementTimeout(int $timeoutMs): void
    {
        if (!$this->pdo instanceof \PDO || $timeoutMs <= 0) {
            return;
        }
        try {
            $this->pdo->exec('SET SESSION max_execution_time=' . max(1, $timeoutMs));
        } catch (\Throwable) {
            // Older MySQL/MariaDB variants may not support max_execution_time. Best effort only.
        }
    }

    /**
     * @param array<int,array<string,mixed>> $queries
     * @return array<int,array<string,mixed>>
     */
    private function buildCandidates(array $queries): array
    {
        $explain = is_array($this->config['explain'] ?? null) ? $this->config['explain'] : [];
        $max = max(1, (int)($explain['max_queries'] ?? 20));
        $candidates = $this->declaredCandidates();

        $includeClasses = array_values(array_filter((array)($explain['include_classes'] ?? []), 'is_string'));
        $minTotal = (float)($explain['min_total_ms'] ?? 0.0);
        $minMax = (float)($explain['min_max_ms'] ?? 0.0);

        foreach ($queries as $query) {
            if (count($candidates) >= $max) {
                break;
            }
            if (!is_array($query)) {
                continue;
            }
            $class = (string)($query['classification'] ?? 'ok');
            $total = (float)($query['total_ms'] ?? 0.0);
            $maxMs = (float)($query['max_ms'] ?? 0.0);
            if (!in_array($class, $includeClasses, true) && $total < $minTotal && $maxMs < $minMax) {
                continue;
            }
            $candidates[] = [
                'query_id' => '',
                'source' => 'profile_sample',
                'fingerprint' => (string)($query['fingerprint'] ?? ''),
                'sample_sql' => (string)($query['sample_sql'] ?? ''),
                'sql' => (string)($query['sample_sql'] ?? ''),
                'declared_policy' => [],
            ];
        }

        return array_slice($candidates, 0, $max);
    }

    /** @return array<int,array<string,mixed>> */
    private function declaredCandidates(): array
    {
        $explain = is_array($this->config['explain'] ?? null) ? $this->config['explain'] : [];
        $path = trim((string)($explain['queries_file'] ?? ''));
        if ($path === '' || !is_file($path)) {
            return [];
        }

        $payload = self::loadDeclaredQueriesFile($path);
        $queries = is_array($payload['mysql_profile_explain']['queries'] ?? null)
            ? $payload['mysql_profile_explain']['queries']
            : (is_array($payload['queries'] ?? null) ? $payload['queries'] : []);
        $candidates = [];
        foreach ($queries as $idx => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $sql = trim((string)($entry['sql'] ?? ''));
            if ($sql === '') {
                continue;
            }
            $id = trim((string)($entry['id'] ?? ('declared_' . $idx)));
            $candidates[] = [
                'query_id' => $id,
                'source' => 'declared',
                'fingerprint' => SqlFingerprint::fingerprint($sql),
                'sample_sql' => SqlFingerprint::sampleSql($sql),
                'sql' => $sql,
                'declared_policy' => [
                    'max_rows_examined' => isset($entry['max_rows_examined']) && is_numeric($entry['max_rows_examined']) ? (int)$entry['max_rows_examined'] : null,
                    'forbid' => array_values(array_filter((array)($entry['forbid'] ?? []), 'is_string')),
                ],
            ];
        }
        return $candidates;
    }

    /** @return array<string,mixed> */
    public static function loadDeclaredQueriesFile(string $path): array
    {
        $raw = (string)file_get_contents($path);
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
        return self::parseYamlLite($raw);
    }

    /** @return array<string,mixed> */
    private static function parseYamlLite(string $raw): array
    {
        $queries = [];
        $current = null;
        $inSql = false;
        $sqlIndent = null;
        $sqlLines = [];
        $inForbid = false;

        $flush = static function () use (&$queries, &$current, &$sqlLines): void {
            if (is_array($current)) {
                if ($sqlLines !== []) {
                    $current['sql'] = rtrim(implode("\n", $sqlLines));
                }
                $queries[] = $current;
            }
            $current = null;
            $sqlLines = [];
        };

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (preg_match('/^\s*-\s+id:\s*(.+)$/', $line, $m)) {
                $flush();
                $current = ['id' => trim($m[1], " \t\"'")];
                $inSql = false;
                $inForbid = false;
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (preg_match('/^\s+sql:\s*\|\s*$/', $line)) {
                $inSql = true;
                $inForbid = false;
                $sqlIndent = null;
                $sqlLines = [];
                continue;
            }
            if (preg_match('/^\s+max_rows_examined:\s*(\d+)\s*$/', $line, $m)) {
                $current['max_rows_examined'] = (int)$m[1];
                $inSql = false;
                continue;
            }
            if (preg_match('/^\s+forbid:\s*$/', $line)) {
                $current['forbid'] = [];
                $inSql = false;
                $inForbid = true;
                continue;
            }
            if ($inForbid && preg_match('/^\s*-\s*(.+)$/', $line, $m)) {
                $current['forbid'][] = trim($m[1], " \t\"'");
                continue;
            }
            if ($inSql) {
                if (trim($line) === '') {
                    $sqlLines[] = '';
                    continue;
                }
                preg_match('/^(\s*)/', $line, $m);
                $indent = strlen($m[1] ?? '');
                if ($sqlIndent === null) {
                    $sqlIndent = $indent;
                }
                if ($indent < $sqlIndent) {
                    $inSql = false;
                    continue;
                }
                $sqlLines[] = substr($line, min($indent, $sqlIndent));
            }
        }
        $flush();

        return ['mysql_profile_explain' => ['queries' => $queries]];
    }

    /** @param array<string,mixed> $candidate */
    private static function skipped(array $candidate, string $reason): array
    {
        $finding = self::baseFinding($candidate);
        $finding['explain_status'] = 'skipped';
        $finding['skip_reason'] = $reason;
        $finding['severity'] = 'info';
        $finding['recommendation'] = match ($reason) {
            'sample_sql_not_executable' => 'EXPLAIN omitido: la query tiene placeholders, múltiples statements o no es SELECT/WITH. Declare una query ejecutable para análisis.',
            'mysql_connection_unavailable' => 'EXPLAIN omitido: no hay conexión MySQL disponible. Configurar TEST_DB_DSN/USER/PASS o TESTKIT_DB_PROFILE_EXPLAIN_*.',
            default => 'EXPLAIN omitido: ' . $reason,
        };
        return $finding;
    }

    /** @param array<string,mixed> $candidate */
    private static function baseFinding(array $candidate): array
    {
        return [
            'query_id' => (string)($candidate['query_id'] ?? ''),
            'fingerprint' => (string)($candidate['fingerprint'] ?? ''),
            'sample_sql' => SqlFingerprint::sampleSql((string)($candidate['sample_sql'] ?? $candidate['sql'] ?? '')),
            'explain_status' => 'skipped',
            'skip_reason' => '',
            'error' => '',
            'plan_summary' => self::emptyPlanSummary(),
            'flags' => [],
            'severity' => 'info',
            'recommendation' => '',
        ];
    }

    /** @return array<string,mixed> */
    private static function emptyPlanSummary(): array
    {
        return [
            'tables' => [],
            'access_types' => [],
            'keys_used' => [],
            'possible_keys' => [],
            'estimated_rows' => 0,
            'estimated_cost' => null,
        ];
    }

    /** @param array<string,mixed> $finding @param array<string,mixed> $candidate */
    private static function applyDeclaredPolicy(array &$finding, array $candidate): void
    {
        $policy = is_array($candidate['declared_policy'] ?? null) ? $candidate['declared_policy'] : [];
        $forbid = array_values(array_filter((array)($policy['forbid'] ?? []), 'is_string'));
        $violations = [];
        foreach ($forbid as $flag) {
            if (in_array($flag, (array)$finding['flags'], true)) {
                $violations[] = $flag;
            }
        }
        $maxRows = $policy['max_rows_examined'] ?? null;
        if (is_int($maxRows) && (int)($finding['plan_summary']['estimated_rows'] ?? 0) > $maxRows) {
            $violations[] = 'max_rows_examined';
        }
        if ($violations !== []) {
            $finding['policy_violations'] = array_values(array_unique($violations));
            $finding['severity'] = 'warn';
        }
    }
}
