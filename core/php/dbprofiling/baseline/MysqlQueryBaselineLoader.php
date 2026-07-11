<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Baseline;

use Testkit\Core\DbProfiling\InstrumentationContext;
use Testkit\Core\DbProfiling\SqlFingerprint;

final class MysqlQueryBaselineLoader
{
    private const ROOT_KEYS = ['schema_version', 'baseline'];
    private const BASELINE_KEYS = [
        'id', 'description', 'created_at', 'source', 'compatibility',
        'comparison_defaults', 'global', 'queries',
    ];
    private const SOURCE_KEYS = [
        'repository', 'commit_sha', 'branch', 'run_id',
        'profile_schema_version', 'profile_hash', 'policy_hash',
    ];
    private const COMPATIBILITY_KEYS = [
        'engine', 'engine_version', 'engine_version_mode',
        'dataset_id', 'dataset_version', 'dataset_hash', 'dataset_hash_mode',
        'environment_id', 'suite_id', 'suite_scope',
    ];
    private const GLOBAL_KEYS = [
        'total_queries', 'unique_fingerprints', 'total_sql_time_ms',
        'instrumentation_findings', 'uninstrumented_findings', 'connections_observed',
    ];
    private const QUERY_KEYS = [
        'identity', 'identity_status', 'query_id', 'query_ids', 'fingerprint', 'sample_sql',
        'calls', 'min_ms', 'avg_ms', 'max_ms', 'total_ms',
        'p50_ms', 'p95_ms', 'p99_ms', 'standard_deviation_ms',
        'sample_count', 'percentiles_approximate', 'classification',
        'capture_methods', 'modules', 'scenarios', 'suites', 'tests',
        'plan', 'current_policy_status', 'violations_count', 'comparison',
    ];
    private const PLAN_KEYS = [
        'status', 'signature', 'flags', 'access_types', 'keys_used', 'possible_keys',
        'estimated_rows', 'tables', 'severity', 'policy_violations',
    ];
    private const TABLE_KEYS = [
        'table_name', 'access_type', 'key', 'possible_keys', 'estimated_rows',
    ];

    /** @return array<string,mixed> */
    public static function load(string $path): array
    {
        if ($path === '' || !is_file($path)) {
            throw new MysqlQueryBaselineException('Baseline file not found.', '$', 'baseline_file_not_found');
        }
        $size = filesize($path);
        if ($size !== false && $size > 10_000_000) {
            throw new MysqlQueryBaselineException('Baseline file exceeds 10 MB.', '$', 'baseline_file_too_large');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new MysqlQueryBaselineException('Baseline file cannot be read.', '$', 'baseline_file_unreadable');
        }
        try {
            $decoded = json_decode($raw, true, 96, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MysqlQueryBaselineException(
                'Invalid baseline JSON: ' . InstrumentationContext::sanitizeText($e->getMessage(), 200),
                '$',
                'baseline_json_invalid'
            );
        }
        if (!is_array($decoded)) {
            throw new MysqlQueryBaselineException('Baseline root must be an object.', '$');
        }
        $validated = self::validate($decoded);
        $validated['_meta'] = [
            'file' => InstrumentationContext::normalizePath($path),
            'file_hash' => hash('sha256', $raw),
        ];
        return $validated;
    }

    /** @param array<string,mixed> $root @return array<string,mixed> */
    public static function validate(array $root): array
    {
        self::rejectUnknown($root, self::ROOT_KEYS, '$');
        if (($root['schema_version'] ?? null) !== MysqlQueryBaselineConfig::BASELINE_SCHEMA_VERSION) {
            throw new MysqlQueryBaselineException(
                'Unsupported baseline schema_version.',
                '$.schema_version',
                'baseline_schema_invalid'
            );
        }
        $baseline = $root['baseline'] ?? null;
        if (!is_array($baseline)) {
            throw new MysqlQueryBaselineException('baseline must be an object.', '$.baseline');
        }
        self::rejectUnknown($baseline, self::BASELINE_KEYS, '$.baseline');

        $id = self::identifier($baseline['id'] ?? null, '$.baseline.id');
        $description = self::text($baseline['description'] ?? '', 500, '$.baseline.description');
        $createdAt = self::timestamp($baseline['created_at'] ?? null, '$.baseline.created_at');

        $source = self::object($baseline['source'] ?? null, '$.baseline.source');
        self::rejectUnknown($source, self::SOURCE_KEYS, '$.baseline.source');
        $source = [
            'repository' => self::repository($source['repository'] ?? '', '$.baseline.source.repository'),
            'commit_sha' => self::commitSha($source['commit_sha'] ?? '', '$.baseline.source.commit_sha'),
            'branch' => self::identifierOrEmpty($source['branch'] ?? '', '$.baseline.source.branch', 160),
            'run_id' => self::identifierOrEmpty($source['run_id'] ?? '', '$.baseline.source.run_id', 160),
            'profile_schema_version' => self::literal(
                $source['profile_schema_version'] ?? '',
                160,
                '$.baseline.source.profile_schema_version'
            ),
            'profile_hash' => self::hash($source['profile_hash'] ?? null, '$.baseline.source.profile_hash', true),
            'policy_hash' => self::hash($source['policy_hash'] ?? '', '$.baseline.source.policy_hash', false),
        ];
        if ($source['profile_schema_version'] !== 'mysql-query-profile-report-v2') {
            throw new MysqlQueryBaselineException(
                'Baseline source must be report v2.',
                '$.baseline.source.profile_schema_version',
                'baseline_source_incompatible'
            );
        }

        $compatibility = self::object($baseline['compatibility'] ?? null, '$.baseline.compatibility');
        self::rejectUnknown($compatibility, self::COMPATIBILITY_KEYS, '$.baseline.compatibility');
        $compatibility = [
            'engine' => self::enum(
                $compatibility['engine'] ?? '',
                ['mysql'],
                '$.baseline.compatibility.engine'
            ),
            'engine_version' => self::version(
                $compatibility['engine_version'] ?? '',
                '$.baseline.compatibility.engine_version'
            ),
            'engine_version_mode' => self::enum(
                $compatibility['engine_version_mode'] ?? 'major_minor',
                ['exact', 'major_minor', 'major', 'ignore'],
                '$.baseline.compatibility.engine_version_mode'
            ),
            'dataset_id' => self::identifier(
                $compatibility['dataset_id'] ?? null,
                '$.baseline.compatibility.dataset_id'
            ),
            'dataset_version' => self::identifier(
                $compatibility['dataset_version'] ?? null,
                '$.baseline.compatibility.dataset_version'
            ),
            'dataset_hash' => self::hash(
                $compatibility['dataset_hash'] ?? '',
                '$.baseline.compatibility.dataset_hash',
                false
            ),
            'dataset_hash_mode' => self::enum(
                $compatibility['dataset_hash_mode'] ?? 'exact',
                ['exact', 'warn', 'ignore'],
                '$.baseline.compatibility.dataset_hash_mode'
            ),
            'environment_id' => self::identifier(
                $compatibility['environment_id'] ?? null,
                '$.baseline.compatibility.environment_id'
            ),
            'suite_id' => self::identifier(
                $compatibility['suite_id'] ?? null,
                '$.baseline.compatibility.suite_id'
            ),
            'suite_scope' => self::enum(
                $compatibility['suite_scope'] ?? 'exact',
                ['exact', 'global'],
                '$.baseline.compatibility.suite_scope'
            ),
        ];

        $defaults = MysqlQueryBaselineBuilder::normalizeDefaults(
            self::object($baseline['comparison_defaults'] ?? [], '$.baseline.comparison_defaults')
        );
        $global = self::validateGlobal(
            self::object($baseline['global'] ?? null, '$.baseline.global')
        );

        $queries = $baseline['queries'] ?? null;
        if (!is_array($queries) || !array_is_list($queries)) {
            throw new MysqlQueryBaselineException('queries must be a list.', '$.baseline.queries');
        }
        if (count($queries) > 10000) {
            throw new MysqlQueryBaselineException(
                'Baseline contains more than 10000 queries.',
                '$.baseline.queries',
                'baseline_query_limit'
            );
        }

        $validatedQueries = [];
        $identities = [];
        foreach ($queries as $index => $query) {
            if (!is_array($query)) {
                throw new MysqlQueryBaselineException(
                    'Query must be an object.',
                    '$.baseline.queries[' . $index . ']'
                );
            }
            $row = self::validateQuery($query, '$.baseline.queries[' . $index . ']');
            $identity = (string)$row['identity'];
            if (isset($identities[$identity])) {
                throw new MysqlQueryBaselineException(
                    'Duplicate query identity.',
                    '$.baseline.queries[' . $index . '].identity',
                    'baseline_identity_duplicate'
                );
            }
            $identities[$identity] = true;
            $validatedQueries[] = $row;
        }
        usort(
            $validatedQueries,
            static fn(array $a, array $b): int => strcmp((string)$a['identity'], (string)$b['identity'])
        );

        return [
            'schema_version' => MysqlQueryBaselineConfig::BASELINE_SCHEMA_VERSION,
            'baseline' => [
                'id' => $id,
                'description' => $description,
                'created_at' => $createdAt,
                'source' => $source,
                'compatibility' => $compatibility,
                'comparison_defaults' => $defaults,
                'global' => $global,
                'queries' => $validatedQueries,
            ],
        ];
    }

    /** @param array<string,mixed> $global @return array<string,int|float|null> */
    private static function validateGlobal(array $global): array
    {
        self::rejectUnknown($global, self::GLOBAL_KEYS, '$.baseline.global');
        $out = [];
        foreach (self::GLOBAL_KEYS as $key) {
            $out[$key] = self::number(
                $global[$key] ?? null,
                '$.baseline.global.' . $key,
                $key !== 'total_sql_time_ms',
                true
            );
        }
        return $out;
    }

    /** @param array<string,mixed> $query @return array<string,mixed> */
    private static function validateQuery(array $query, string $path): array
    {
        self::rejectUnknown($query, self::QUERY_KEYS, $path);
        $identity = self::identity($query['identity'] ?? null, $path . '.identity');
        $fingerprint = SqlFingerprint::fingerprint((string)($query['fingerprint'] ?? ''));
        if ($fingerprint === '') {
            throw new MysqlQueryBaselineException('Fingerprint is required.', $path . '.fingerprint');
        }
        $queryId = self::identifierOrEmpty($query['query_id'] ?? '', $path . '.query_id', 160);
        $queryIds = self::stringList($query['query_ids'] ?? [], $path . '.query_ids', false);
        if (str_starts_with($identity, 'query_id:')) {
            $idFromIdentity = substr($identity, strlen('query_id:'));
            if ($queryId === '' || $idFromIdentity !== $queryId || count($queryIds) !== 1) {
                throw new MysqlQueryBaselineException(
                    'query_id identity must have exactly one matching query ID.',
                    $path . '.identity',
                    'baseline_identity_invalid'
                );
            }
        }
        if (str_starts_with($identity, 'fingerprint:')) {
            $expected = hash('sha256', $fingerprint);
            if (substr($identity, strlen('fingerprint:')) !== $expected) {
                throw new MysqlQueryBaselineException(
                    'Fingerprint identity hash does not match fingerprint.',
                    $path . '.identity',
                    'baseline_identity_invalid'
                );
            }
        }

        $row = [
            'identity' => $identity,
            'identity_status' => self::enum(
                $query['identity_status'] ?? 'fingerprint_fallback',
                ['stable_query_id', 'fingerprint_fallback', 'ambiguous_query_ids'],
                $path . '.identity_status'
            ),
            'query_id' => $queryId,
            'query_ids' => $queryIds,
            'fingerprint' => $fingerprint,
            'sample_sql' => self::text($query['sample_sql'] ?? '', 2000, $path . '.sample_sql'),
            'calls' => self::number($query['calls'] ?? null, $path . '.calls', true, true),
            'min_ms' => self::number($query['min_ms'] ?? null, $path . '.min_ms', false, true),
            'avg_ms' => self::number($query['avg_ms'] ?? null, $path . '.avg_ms', false, true),
            'max_ms' => self::number($query['max_ms'] ?? null, $path . '.max_ms', false, true),
            'total_ms' => self::number($query['total_ms'] ?? null, $path . '.total_ms', false, true),
            'p50_ms' => self::number($query['p50_ms'] ?? null, $path . '.p50_ms', false, true),
            'p95_ms' => self::number($query['p95_ms'] ?? null, $path . '.p95_ms', false, true),
            'p99_ms' => self::number($query['p99_ms'] ?? null, $path . '.p99_ms', false, true),
            'standard_deviation_ms' => self::number(
                $query['standard_deviation_ms'] ?? null,
                $path . '.standard_deviation_ms',
                false,
                true
            ),
            'sample_count' => self::number(
                $query['sample_count'] ?? null,
                $path . '.sample_count',
                true,
                true
            ),
            'percentiles_approximate' => self::boolean(
                $query['percentiles_approximate'] ?? false,
                $path . '.percentiles_approximate'
            ),
            'classification' => self::identifierOrEmpty(
                $query['classification'] ?? '',
                $path . '.classification',
                80
            ),
            'capture_methods' => self::captureMethods(
                $query['capture_methods'] ?? [],
                $path . '.capture_methods'
            ),
            'modules' => self::stringList($query['modules'] ?? [], $path . '.modules'),
            'scenarios' => self::stringList($query['scenarios'] ?? [], $path . '.scenarios'),
            'suites' => self::stringList($query['suites'] ?? [], $path . '.suites'),
            'tests' => self::pathList($query['tests'] ?? [], $path . '.tests'),
            'plan' => self::validatePlan(
                self::object($query['plan'] ?? [], $path . '.plan'),
                $path . '.plan'
            ),
            'current_policy_status' => self::identifierOrEmpty(
                $query['current_policy_status'] ?? '',
                $path . '.current_policy_status',
                80
            ),
            'violations_count' => self::number(
                $query['violations_count'] ?? 0,
                $path . '.violations_count',
                true,
                false
            ),
        ];
        if (isset($query['comparison'])) {
            $row['comparison'] = MysqlQueryBaselineBuilder::normalizeDefaults(
                self::object($query['comparison'], $path . '.comparison')
            );
        }

        self::coherentMetrics($row, $path);
        return $row;
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private static function validatePlan(array $plan, string $path): array
    {
        self::rejectUnknown($plan, self::PLAN_KEYS, $path);
        $status = self::enum(
            $plan['status'] ?? 'unavailable',
            ['available', 'unavailable', 'ambiguous'],
            $path . '.status'
        );
        $signature = self::hash($plan['signature'] ?? '', $path . '.signature', false);
        if ($status === 'available' && $signature === '') {
            throw new MysqlQueryBaselineException(
                'Available plan requires a signature.',
                $path . '.signature'
            );
        }
        $tables = $plan['tables'] ?? [];
        if (!is_array($tables) || !array_is_list($tables) || count($tables) > 100) {
            throw new MysqlQueryBaselineException('Invalid plan tables.', $path . '.tables');
        }
        $validatedTables = [];
        foreach ($tables as $index => $table) {
            if (!is_array($table)) {
                throw new MysqlQueryBaselineException('Plan table must be an object.', $path . '.tables[' . $index . ']');
            }
            self::rejectUnknown($table, self::TABLE_KEYS, $path . '.tables[' . $index . ']');
            $validatedTables[] = [
                'table_name' => self::identifierOrEmpty(
                    $table['table_name'] ?? '',
                    $path . '.tables[' . $index . '].table_name',
                    160
                ),
                'access_type' => self::identifierOrEmpty(
                    $table['access_type'] ?? '',
                    $path . '.tables[' . $index . '].access_type',
                    40
                ),
                'key' => self::identifierOrEmpty(
                    $table['key'] ?? '',
                    $path . '.tables[' . $index . '].key',
                    160
                ),
                'possible_keys' => self::stringList(
                    $table['possible_keys'] ?? [],
                    $path . '.tables[' . $index . '].possible_keys'
                ),
                'estimated_rows' => self::number(
                    $table['estimated_rows'] ?? null,
                    $path . '.tables[' . $index . '].estimated_rows',
                    false,
                    true
                ),
            ];
        }
        usort(
            $validatedTables,
            static fn(array $a, array $b): int => [$a['table_name'], $a['access_type'], $a['key']]
                <=> [$b['table_name'], $b['access_type'], $b['key']]
        );
        return [
            'status' => $status,
            'signature' => $signature,
            'flags' => self::stringList($plan['flags'] ?? [], $path . '.flags'),
            'access_types' => self::stringList($plan['access_types'] ?? [], $path . '.access_types'),
            'keys_used' => self::stringList($plan['keys_used'] ?? [], $path . '.keys_used'),
            'possible_keys' => self::stringList($plan['possible_keys'] ?? [], $path . '.possible_keys'),
            'estimated_rows' => self::number(
                $plan['estimated_rows'] ?? null,
                $path . '.estimated_rows',
                false,
                true
            ),
            'tables' => $validatedTables,
            'severity' => self::identifierOrEmpty($plan['severity'] ?? '', $path . '.severity', 40),
            'policy_violations' => self::stringList(
                $plan['policy_violations'] ?? [],
                $path . '.policy_violations'
            ),
        ];
    }

    /** @param array<string,mixed> $row */
    private static function coherentMetrics(array $row, string $path): void
    {
        $min = $row['min_ms'];
        $avg = $row['avg_ms'];
        $max = $row['max_ms'];
        if ($min !== null && $avg !== null && $max !== null && !($min <= $avg && $avg <= $max)) {
            throw new MysqlQueryBaselineException(
                'Expected min_ms <= avg_ms <= max_ms.',
                $path,
                'baseline_metrics_incoherent'
            );
        }
        $p50 = $row['p50_ms'];
        $p95 = $row['p95_ms'];
        $p99 = $row['p99_ms'];
        if ($p50 !== null && $p95 !== null && $p99 !== null && !($p50 <= $p95 && $p95 <= $p99)) {
            throw new MysqlQueryBaselineException(
                'Expected p50_ms <= p95_ms <= p99_ms.',
                $path,
                'baseline_metrics_incoherent'
            );
        }
        if (
            $row['calls'] !== null
            && $row['sample_count'] !== null
            && $row['calls'] < $row['sample_count']
        ) {
            throw new MysqlQueryBaselineException(
                'calls must be greater than or equal to sample_count.',
                $path . '.sample_count',
                'baseline_metrics_incoherent'
            );
        }
    }

    /** @param mixed $value */
    private static function object(mixed $value, string $path): array
    {
        if (!is_array($value) || array_is_list($value)) {
            if ($value === []) {
                return [];
            }
            throw new MysqlQueryBaselineException('Expected object.', $path);
        }
        return $value;
    }

    /** @param mixed $value */
    private static function number(
        mixed $value,
        string $path,
        bool $integer,
        bool $nullable
    ): int|float|null {
        if ($value === null && $nullable) {
            return null;
        }
        if (!is_int($value) && !is_float($value)) {
            throw new MysqlQueryBaselineException('Expected numeric value.', $path);
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0 || ($integer && floor($number) !== $number)) {
            throw new MysqlQueryBaselineException(
                'Expected finite non-negative' . ($integer ? ' integer' : '') . '.',
                $path
            );
        }
        return $integer ? (int)$number : round($number, 3);
    }

    /** @param mixed $value */
    private static function boolean(mixed $value, string $path): bool
    {
        if (!is_bool($value)) {
            throw new MysqlQueryBaselineException('Expected boolean.', $path);
        }
        return $value;
    }

    /** @param mixed $value @return array<string,int> */
    private static function captureMethods(mixed $value, string $path): array
    {
        $object = self::object($value, $path);
        if (count($object) > 100) {
            throw new MysqlQueryBaselineException('Too many capture methods.', $path);
        }
        $out = [];
        foreach ($object as $key => $count) {
            $name = self::identifier($key, $path . '.' . $key);
            $out[$name] = (int)self::number($count, $path . '.' . $key, true, false);
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @param mixed $value @return array<int,string> */
    private static function stringList(mixed $value, string $path, bool $identifier = true): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            throw new MysqlQueryBaselineException('Expected list with at most 100 values.', $path);
        }
        $out = [];
        foreach ($value as $index => $item) {
            $safe = $identifier
                ? self::identifier($item, $path . '[' . $index . ']')
                : self::text($item, 160, $path . '[' . $index . ']');
            $out[$safe] = true;
        }
        $items = array_keys($out);
        sort($items, SORT_STRING);
        return $items;
    }

    /** @param mixed $value @return array<int,string> */
    private static function pathList(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            throw new MysqlQueryBaselineException('Expected path list.', $path);
        }
        $out = [];
        foreach ($value as $index => $item) {
            if (!is_string($item)) {
                throw new MysqlQueryBaselineException('Expected path string.', $path . '[' . $index . ']');
            }
            $safe = InstrumentationContext::normalizePath($item);
            if ($safe !== '') {
                $out[$safe] = true;
            }
        }
        $items = array_keys($out);
        sort($items, SORT_STRING);
        return $items;
    }

    private static function identity(mixed $value, string $path): string
    {
        if (!is_string($value)) {
            throw new MysqlQueryBaselineException('Identity is required.', $path);
        }
        $value = trim($value);
        if (preg_match('/^query_id:[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/', $value) === 1) {
            return $value;
        }
        if (preg_match('/^fingerprint:[a-f0-9]{64}$/', $value) === 1) {
            return $value;
        }
        throw new MysqlQueryBaselineException('Invalid query identity.', $path, 'baseline_identity_invalid');
    }

    private static function identifier(mixed $value, string $path): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new MysqlQueryBaselineException('Identifier is required.', $path);
        }
        return self::identifierOrEmpty($value, $path, 160, false);
    }

    private static function identifierOrEmpty(
        mixed $value,
        string $path,
        int $limit,
        bool $allowEmpty = true
    ): string {
        if (!is_string($value)) {
            throw new MysqlQueryBaselineException('Expected string.', $path);
        }
        $value = trim($value);
        if ($value === '') {
            if ($allowEmpty) {
                return '';
            }
            throw new MysqlQueryBaselineException('Identifier is required.', $path);
        }
        if (strlen($value) > $limit || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/', $value) !== 1) {
            throw new MysqlQueryBaselineException('Invalid identifier.', $path);
        }
        return $value;
    }

    private static function repository(mixed $value, string $path): string
    {
        if (!is_string($value)) {
            throw new MysqlQueryBaselineException('Expected repository string.', $path);
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strlen($value) > 240 || preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $value) !== 1) {
            throw new MysqlQueryBaselineException('Invalid repository.', $path);
        }
        return $value;
    }

    private static function commitSha(mixed $value, string $path): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '';
        }
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{7,64}$/', $value) !== 1) {
            throw new MysqlQueryBaselineException('Invalid commit SHA.', $path);
        }
        return $value;
    }

    private static function version(mixed $value, string $path): string
    {
        if (!is_string($value)) {
            throw new MysqlQueryBaselineException('Expected version string.', $path);
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strlen($value) > 80 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*$/', $value) !== 1) {
            throw new MysqlQueryBaselineException('Invalid version.', $path);
        }
        return $value;
    }

    private static function timestamp(mixed $value, string $path): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
            throw new MysqlQueryBaselineException('Expected UTC timestamp.', $path);
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if (!$parsed || $parsed->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new MysqlQueryBaselineException('Invalid UTC timestamp.', $path);
        }
        return $value;
    }

    private static function hash(mixed $value, string $path, bool $required): string
    {
        if (!is_string($value)) {
            throw new MysqlQueryBaselineException('Expected hash string.', $path);
        }
        $value = strtolower(trim($value));
        if ($value === '' && !$required) {
            return '';
        }
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new MysqlQueryBaselineException('Expected SHA-256 hash.', $path, 'baseline_hash_invalid');
        }
        return $value;
    }

    private static function literal(mixed $value, int $limit, string $path): string
    {
        if (!is_string($value)) {
            throw new MysqlQueryBaselineException('Expected string.', $path);
        }
        $value = trim($value);
        if (strlen($value) > $limit || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new MysqlQueryBaselineException('Invalid literal string.', $path);
        }
        return $value;
    }

    private static function text(mixed $value, int $limit, string $path): string
    {
        if (!is_string($value)) {
            throw new MysqlQueryBaselineException('Expected string.', $path);
        }
        if (strlen($value) > $limit * 4) {
            throw new MysqlQueryBaselineException('String exceeds limit.', $path);
        }
        return InstrumentationContext::sanitizeText($value, $limit);
    }

    /** @param array<int,string> $allowed */
    private static function enum(mixed $value, array $allowed, string $path): string
    {
        if (!is_string($value)) {
            throw new MysqlQueryBaselineException('Expected enumerated string.', $path);
        }
        $value = strtolower(trim($value));
        if (!in_array($value, $allowed, true)) {
            throw new MysqlQueryBaselineException('Unsupported value.', $path);
        }
        return $value;
    }

    /** @param array<string,mixed> $value @param array<int,string> $allowed */
    private static function rejectUnknown(array $value, array $allowed, string $path): void
    {
        foreach (array_keys($value) as $key) {
            if (!in_array((string)$key, $allowed, true)) {
                throw new MysqlQueryBaselineException(
                    'Unknown key: ' . $key,
                    $path . '.' . $key,
                    'unknown_baseline_key'
                );
            }
        }
    }
}
