<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Baseline;

use Testkit\Core\DbProfiling\InstrumentationContext;
use Testkit\Core\DbProfiling\MysqlProfileConfig;
use Testkit\Core\DbProfiling\SqlFingerprint;

final class MysqlQueryBaselineBuilder
{
    public const DEFAULTS = [
        'time_regression_pct' => 20.0,
        'time_regression_abs_ms' => 5.0,
        'calls_regression_pct' => 10.0,
        'calls_regression_abs' => 1,
        'rows_regression_pct' => 25.0,
        'rows_regression_abs' => 100,
        'minimum_time_ms_for_pct' => 5.0,
        'ignore_metrics' => [],
    ];

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public static function build(array $profile, array $metadata): array
    {
        self::assertCompatibleProfile($profile);
        $baselineId = self::identifier($metadata['baseline_id'] ?? null, 'baseline_id');
        $description = InstrumentationContext::sanitizeText((string)($metadata['description'] ?? ''), 500);
        $profileHash = self::sha256OrCanonical($metadata['profile_hash'] ?? '', $profile);
        $context = self::comparisonContext($profile, $metadata);
        foreach (['dataset_id', 'dataset_version', 'environment_id', 'suite_id'] as $required) {
            if ((string)($context[$required] ?? '') === '') {
                throw new MysqlQueryBaselineException(
                    'Required baseline metadata is missing: ' . $required,
                    '$.baseline.compatibility.' . $required,
                    'baseline_metadata_missing'
                );
            }
        }

        $createdAt = self::utcTimestamp(
            (string)($metadata['created_at'] ?? $profile['finished_at'] ?? $profile['started_at'] ?? '')
        );
        $queries = self::extractQueries($profile);
        if (count($queries) > 10000) {
            throw new MysqlQueryBaselineException(
                'Profile contains more than 10000 queries.',
                '$.queries',
                'baseline_query_limit'
            );
        }

        $defaults = self::normalizeDefaults(
            is_array($metadata['comparison_defaults'] ?? null)
                ? $metadata['comparison_defaults']
                : self::DEFAULTS
        );

        return [
            'schema_version' => MysqlQueryBaselineConfig::BASELINE_SCHEMA_VERSION,
            'baseline' => [
                'id' => $baselineId,
                'description' => $description,
                'created_at' => $createdAt,
                'source' => [
                    'repository' => (string)($context['repository'] ?? ''),
                    'commit_sha' => (string)($context['commit_sha'] ?? ''),
                    'branch' => (string)($context['branch'] ?? ''),
                    'run_id' => InstrumentationContext::sanitizeIdentifier((string)($profile['run_id'] ?? ''), 160),
                    'profile_schema_version' => (string)($profile['schema_version'] ?? ''),
                    'profile_hash' => $profileHash,
                    'policy_hash' => self::hashOrEmpty(
                        (string)($profile['policy_evaluation']['policy_file_hash'] ?? '')
                    ),
                ],
                'compatibility' => [
                    'engine' => (string)($context['engine'] ?? 'mysql'),
                    'engine_version' => (string)($context['engine_version'] ?? ''),
                    'engine_version_mode' => self::engineVersionMode(
                        (string)($metadata['engine_version_mode'] ?? 'major_minor')
                    ),
                    'dataset_id' => (string)($context['dataset_id'] ?? ''),
                    'dataset_version' => (string)($context['dataset_version'] ?? ''),
                    'dataset_hash' => self::hashOrEmpty((string)($context['dataset_hash'] ?? '')),
                    'dataset_hash_mode' => self::datasetHashMode(
                        (string)($metadata['dataset_hash_mode'] ?? 'exact')
                    ),
                    'environment_id' => (string)($context['environment_id'] ?? ''),
                    'suite_id' => (string)($context['suite_id'] ?? ''),
                    'suite_scope' => strtolower((string)($metadata['suite_scope'] ?? 'exact')) === 'global'
                        ? 'global'
                        : 'exact',
                ],
                'comparison_defaults' => $defaults,
                'global' => self::extractGlobal($profile),
                'queries' => $queries,
            ],
        ];
    }

    /** @param array<string,mixed> $profile @return array<int,array<string,mixed>> */
    public static function extractQueries(array $profile): array
    {
        $findings = self::explainFindingsByFingerprint($profile);
        $rows = [];
        foreach ((array)($profile['queries'] ?? []) as $query) {
            if (!is_array($query)) {
                continue;
            }
            $fingerprint = SqlFingerprint::fingerprint(
                (string)($query['fingerprint'] ?? $query['sample_sql'] ?? '')
            );
            if ($fingerprint === '') {
                continue;
            }
            $matchingFindings = $findings[$fingerprint] ?? [];
            $queryIds = [];
            foreach ($matchingFindings as $finding) {
                $id = InstrumentationContext::sanitizeIdentifier((string)($finding['query_id'] ?? ''), 160);
                if ($id !== '') {
                    $queryIds[$id] = true;
                }
            }
            $queryIds = array_keys($queryIds);
            sort($queryIds, SORT_STRING);
            $identity = self::identity($fingerprint, $queryIds);
            $plan = count($matchingFindings) === 1
                ? MysqlQueryPlanNormalizer::normalize($matchingFindings[0])
                : MysqlQueryPlanNormalizer::normalize(null);
            if (count($matchingFindings) > 1) {
                $plan['status'] = 'ambiguous';
            }

            $row = [
                'identity' => $identity['identity'],
                'identity_status' => $identity['status'],
                'query_id' => count($queryIds) === 1 ? $queryIds[0] : '',
                'query_ids' => $queryIds,
                'fingerprint' => $fingerprint,
                'sample_sql' => SqlFingerprint::sampleSql(
                    (string)($query['sample_sql'] ?? $fingerprint),
                    2000
                ),
                'calls' => self::number($query['calls'] ?? null, true),
                'min_ms' => self::number($query['min_ms'] ?? null),
                'avg_ms' => self::number($query['avg_ms'] ?? null),
                'max_ms' => self::number($query['max_ms'] ?? null),
                'total_ms' => self::number($query['total_ms'] ?? null),
                'p50_ms' => self::number($query['p50_ms'] ?? null),
                'p95_ms' => self::number($query['p95_ms'] ?? null),
                'p99_ms' => self::number($query['p99_ms'] ?? null),
                'standard_deviation_ms' => self::number($query['standard_deviation_ms'] ?? null),
                'sample_count' => self::number($query['sample_count'] ?? null, true),
                'percentiles_approximate' => (bool)($query['percentiles_approximate'] ?? false),
                'classification' => InstrumentationContext::sanitizeIdentifier(
                    (string)($query['classification'] ?? ''),
                    80
                ),
                'capture_methods' => self::captureMethods($query['capture_methods'] ?? []),
                'modules' => self::contextList($query['modules'] ?? []),
                'scenarios' => self::contextList($query['scenarios'] ?? []),
                'suites' => self::contextList($query['suites'] ?? []),
                'tests' => self::pathList($query['tests'] ?? []),
                'plan' => $plan,
                'current_policy_status' => InstrumentationContext::sanitizeIdentifier(
                    (string)($query['policy_status'] ?? ''),
                    80
                ),
                'violations_count' => max(0, (int)($query['violations_count'] ?? 0)),
            ];
            $rows[$row['identity']] = $row;
        }
        ksort($rows, SORT_STRING);
        return array_values($rows);
    }

    /** @param array<string,mixed> $profile @return array<string,int|float|null> */
    public static function extractGlobal(array $profile): array
    {
        $summary = is_array($profile['summary'] ?? null) ? $profile['summary'] : [];
        $findings = is_array($profile['instrumentation']['findings'] ?? null)
            ? $profile['instrumentation']['findings']
            : [];
        $uninstrumented = 0;
        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $code = strtolower((string)($finding['code'] ?? ''));
            if (
                str_contains($code, 'uninstrumented')
                || str_contains($code, 'bypass')
                || str_contains($code, 'without_connection')
            ) {
                $uninstrumented++;
            }
        }
        return [
            'total_queries' => self::number(
                $summary['total_queries'] ?? $summary['total_calls'] ?? null,
                true
            ),
            'unique_fingerprints' => self::number(
                $summary['unique_fingerprints'] ?? count((array)($profile['queries'] ?? [])),
                true
            ),
            'total_sql_time_ms' => self::number(
                $summary['total_sql_time_ms'] ?? $summary['total_db_time_ms'] ?? null
            ),
            'instrumentation_findings' => count($findings),
            'uninstrumented_findings' => $uninstrumented,
            'connections_observed' => count((array)($profile['connections'] ?? [])),
        ];
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $metadata @return array<string,string> */
    private static function comparisonContext(array $profile, array $metadata): array
    {
        $profileContext = is_array($profile['comparison_context'] ?? null)
            ? $profile['comparison_context']
            : [];
        $profileContext['engine'] = (string)($profileContext['engine'] ?? $profile['engine'] ?? 'mysql');
        $profileContext['suite_id'] = (string)(
            $profileContext['suite_id']
            ?? $profile['suite_id']
            ?? $profile['run_metadata']['suite_id']
            ?? ''
        );
        $aliases = [
            'baseline_id' => null,
            'description' => null,
            'profile_hash' => null,
            'created_at' => null,
            'engine_version_mode' => null,
            'dataset_hash_mode' => null,
            'suite_scope' => null,
        ];
        foreach ($metadata as $key => $value) {
            $key = (string)$key;
            if (array_key_exists($key, $aliases)) {
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            if ($value === null) {
                continue;
            }
            $profileContext[$key] = $value;
        }
        return MysqlQueryBaselineConfig::sanitizeComparisonContext($profileContext);
    }

    /** @param array<string,mixed> $profile */
    public static function assertCompatibleProfile(array $profile): void
    {
        $version = (int)($profile['report_version'] ?? 0);
        $schema = (string)($profile['schema_version'] ?? '');
        if ($version !== MysqlProfileConfig::REPORT_VERSION || $schema !== MysqlProfileConfig::SCHEMA_VERSION) {
            throw new MysqlQueryBaselineException(
                'Only mysql-query-profile-report-v2 can create a baseline.',
                '$.schema_version',
                'current_profile_incompatible'
            );
        }
        if (strtolower((string)($profile['engine'] ?? '')) !== 'mysql') {
            throw new MysqlQueryBaselineException(
                'Only MySQL profiles are supported.',
                '$.engine',
                'current_profile_incompatible'
            );
        }
        if (!is_array($profile['queries'] ?? null) || !is_array($profile['summary'] ?? null)) {
            throw new MysqlQueryBaselineException(
                'Profile is missing queries or summary.',
                '$',
                'current_profile_incompatible'
            );
        }
    }

    /** @param array<string,mixed> $profile @return array<string,array<int,array<string,mixed>>> */
    private static function explainFindingsByFingerprint(array $profile): array
    {
        $out = [];
        foreach ((array)($profile['explain']['findings'] ?? []) as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $fingerprint = SqlFingerprint::fingerprint(
                (string)($finding['fingerprint'] ?? $finding['sample_sql'] ?? '')
            );
            if ($fingerprint !== '') {
                $out[$fingerprint][] = $finding;
            }
        }
        return $out;
    }

    /** @param array<int,string> $queryIds @return array{identity:string,status:string} */
    private static function identity(string $fingerprint, array $queryIds): array
    {
        if (count($queryIds) === 1) {
            return ['identity' => 'query_id:' . $queryIds[0], 'status' => 'stable_query_id'];
        }
        return [
            'identity' => 'fingerprint:' . hash('sha256', $fingerprint),
            'status' => count($queryIds) > 1 ? 'ambiguous_query_ids' : 'fingerprint_fallback',
        ];
    }

    /** @param mixed $value */
    private static function number(mixed $value, bool $integer = false): int|float|null
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0) {
            return null;
        }
        return $integer ? (int)floor($number) : round($number, 3);
    }

    /** @param mixed $value @return array<string,int> */
    private static function captureMethods(mixed $value): array
    {
        $out = [];
        if (is_array($value)) {
            foreach ($value as $key => $count) {
                if (is_int($key) && is_string($count)) {
                    $key = $count;
                    $count = 1;
                }
                $method = InstrumentationContext::sanitizeIdentifier((string)$key, 160);
                if ($method !== '' && is_numeric($count)) {
                    $out[$method] = max(0, (int)$count);
                }
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @param mixed $value @return array<int,string> */
    private static function contextList(mixed $value): array
    {
        $out = [];
        foreach ((array)$value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $safe = InstrumentationContext::sanitizeIdentifier((string)$item, 160);
            if ($safe !== '') {
                $out[$safe] = true;
            }
            if (count($out) >= 100) {
                break;
            }
        }
        $items = array_keys($out);
        sort($items, SORT_STRING);
        return $items;
    }

    /** @param mixed $value @return array<int,string> */
    private static function pathList(mixed $value): array
    {
        $out = [];
        foreach ((array)$value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $safe = InstrumentationContext::normalizePath((string)$item);
            if ($safe !== '') {
                $out[$safe] = true;
            }
            if (count($out) >= 100) {
                break;
            }
        }
        $items = array_keys($out);
        sort($items, SORT_STRING);
        return $items;
    }

    /** @param array<string,mixed> $defaults @return array<string,mixed> */
    public static function normalizeDefaults(array $defaults): array
    {
        $allowed = array_keys(self::DEFAULTS);
        foreach (array_keys($defaults) as $key) {
            if (!in_array((string)$key, $allowed, true)) {
                throw new MysqlQueryBaselineException(
                    'Unknown comparison default: ' . $key,
                    '$.baseline.comparison_defaults.' . $key,
                    'unknown_baseline_key'
                );
            }
        }
        $out = self::DEFAULTS;
        foreach ($out as $key => $default) {
            if ($key === 'ignore_metrics') {
                $list = [];
                foreach ((array)($defaults[$key] ?? []) as $metric) {
                    $metric = trim((string)$metric);
                    if ($metric !== '' && in_array($metric, self::comparableMetrics(), true)) {
                        $list[$metric] = true;
                    }
                }
                $out[$key] = array_keys($list);
                sort($out[$key], SORT_STRING);
                continue;
            }
            $value = $defaults[$key] ?? $default;
            if (!is_int($value) && !is_float($value)) {
                throw new MysqlQueryBaselineException(
                    'Comparison tolerance must be numeric.',
                    '$.baseline.comparison_defaults.' . $key,
                    'baseline_tolerance_invalid'
                );
            }
            $value = (float)$value;
            if (!is_finite($value) || $value < 0 || $value > 1_000_000) {
                throw new MysqlQueryBaselineException(
                    'Comparison tolerance is out of range.',
                    '$.baseline.comparison_defaults.' . $key,
                    'baseline_tolerance_invalid'
                );
            }
            $out[$key] = in_array($key, ['calls_regression_abs', 'rows_regression_abs'], true)
                ? (int)$value
                : $value;
        }
        return $out;
    }

    /** @return array<int,string> */
    public static function comparableMetrics(): array
    {
        return [
            'calls', 'min_ms', 'avg_ms', 'max_ms', 'total_ms', 'p50_ms', 'p95_ms', 'p99_ms',
            'standard_deviation_ms', 'sample_count', 'estimated_rows', 'total_queries',
            'unique_fingerprints', 'total_sql_time_ms', 'instrumentation_findings',
            'uninstrumented_findings', 'connections_observed',
        ];
    }

    private static function identifier(mixed $value, string $name): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new MysqlQueryBaselineException(
                'Missing ' . $name . '.',
                '$.baseline.id',
                'baseline_metadata_missing'
            );
        }
        $value = trim($value);
        if (strlen($value) > 160 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value) !== 1) {
            throw new MysqlQueryBaselineException('Invalid ' . $name . '.', '$.baseline.id', 'baseline_metadata_invalid');
        }
        return $value;
    }

    private static function utcTimestamp(string $value): string
    {
        $value = trim($value);
        if ($value !== '') {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
            }
        }
        return '1970-01-01T00:00:00Z';
    }

    /** @param array<string,mixed> $profile */
    private static function sha256OrCanonical(mixed $value, array $profile): string
    {
        $hash = self::hashOrEmpty((string)$value);
        if ($hash !== '') {
            return $hash;
        }
        return hash('sha256', self::canonicalJson($profile));
    }

    private static function hashOrEmpty(string $hash): string
    {
        $hash = strtolower(trim($hash));
        return preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $hash : '';
    }

    private static function engineVersionMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['exact', 'major_minor', 'major', 'ignore'], true)) {
            throw new MysqlQueryBaselineException(
                'Unsupported engine_version_mode.',
                '$.baseline.compatibility.engine_version_mode',
                'baseline_metadata_invalid'
            );
        }
        return $mode;
    }

    private static function datasetHashMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['exact', 'warn', 'ignore'], true)) {
            throw new MysqlQueryBaselineException(
                'Unsupported dataset_hash_mode.',
                '$.baseline.compatibility.dataset_hash_mode',
                'baseline_metadata_invalid'
            );
        }
        return $mode;
    }

    /** @param array<string,mixed> $value */
    public static function canonicalJson(array $value): string
    {
        $normalized = self::sortRecursive($value);
        return (string)json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function sortRecursive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'sortRecursive'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursive($item);
        }
        return $value;
    }
}
