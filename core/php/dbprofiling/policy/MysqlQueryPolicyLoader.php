<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Policy;

use Testkit\Core\DbProfiling\InstrumentationContext;
use Testkit\Core\DbProfiling\MysqlCaptureMethod;
use Testkit\Core\DbProfiling\SqlFingerprint;

final class MysqlQueryPolicyLoader
{
    private const ROOT_KEYS = ['schema_version', 'policy_set'];
    private const SET_KEYS = ['id', 'description', 'mode', 'defaults', 'policies'];
    private const DEFAULT_KEYS = ['severity', 'on_missing', 'on_insufficient_data'];
    private const POLICY_KEYS = ['id', 'description', 'scope', 'selector', 'budgets', 'plan', 'severity', 'on_missing', 'on_insufficient_data'];
    private const SELECTOR_KEYS = ['fingerprint', 'query_id', 'module_id', 'scenario_id', 'suite_id', 'test_id', 'capture_method', 'classification', 'source', 'caller'];
    private const BUDGET_KEYS = [
        'max_calls', 'max_max_ms', 'max_avg_ms', 'max_total_ms', 'max_p50_ms', 'max_p95_ms', 'max_p99_ms',
        'max_standard_deviation_ms', 'max_rows_examined', 'max_total_queries', 'max_unique_fingerprints',
        'max_total_sql_time_ms', 'max_instrumentation_findings', 'max_uninstrumented_findings',
    ];
    private const PLAN_KEYS = ['forbid_flags', 'require_any_key', 'require_keys', 'forbid_access_types', 'max_estimated_rows'];
    private const QUERY_BUDGETS = [
        'max_calls', 'max_max_ms', 'max_avg_ms', 'max_total_ms', 'max_p50_ms', 'max_p95_ms', 'max_p99_ms',
        'max_standard_deviation_ms', 'max_rows_examined',
    ];
    private const GLOBAL_BUDGETS = [
        'max_total_queries', 'max_unique_fingerprints', 'max_total_sql_time_ms',
        'max_instrumentation_findings', 'max_uninstrumented_findings',
    ];
    private const PLAN_FLAGS = [
        'full_table_scan', 'no_possible_keys', 'no_key_used', 'filesort', 'temporary_table',
        'high_rows_examined', 'dependent_subquery', 'range_or_index_merge', 'invalid_json_plan',
    ];
    private const ACCESS_TYPES = ['system', 'const', 'eq_ref', 'ref', 'fulltext', 'ref_or_null', 'index_merge', 'unique_subquery', 'index_subquery', 'range', 'index', 'all'];
    private const CLASSIFICATIONS = ['ok', 'watch', 'slow', 'hotspot', 'n_plus_one_candidate'];
    private const SEVERITIES = ['info', 'warning', 'error'];
    private const ON_MISSING = ['ignore', 'report'];

    /** @return array<string,mixed> */
    public static function load(string $path): array
    {
        if ($path === '' || !is_file($path)) {
            throw new MysqlQueryPolicyException('Policy file not found.', '$', 'policy_file_not_found');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new MysqlQueryPolicyException('Policy file cannot be read.', '$', 'policy_file_unreadable');
        }
        if (strlen($raw) > 2_000_000) {
            throw new MysqlQueryPolicyException('Policy file exceeds 2 MB.', '$', 'policy_file_too_large');
        }
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MysqlQueryPolicyException('Invalid policy JSON: ' . $e->getMessage(), '$', 'policy_json_invalid');
        }
        if (!is_array($decoded)) {
            throw new MysqlQueryPolicyException('Policy root must be an object.', '$');
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
        if (($root['schema_version'] ?? null) !== MysqlQueryPolicyConfig::SCHEMA_VERSION) {
            throw new MysqlQueryPolicyException('Unsupported schema_version.', '$.schema_version');
        }
        $set = $root['policy_set'] ?? null;
        if (!is_array($set)) {
            throw new MysqlQueryPolicyException('policy_set must be an object.', '$.policy_set');
        }
        self::rejectUnknown($set, self::SET_KEYS, '$.policy_set');
        $id = self::identifier($set['id'] ?? null, '$.policy_set.id');
        $mode = (string)($set['mode'] ?? MysqlQueryPolicyConfig::MODE_REPORT_ONLY);
        if ($mode !== MysqlQueryPolicyConfig::MODE_REPORT_ONLY) {
            throw new MysqlQueryPolicyException('Only report_only mode is supported.', '$.policy_set.mode', 'unsupported_policy_mode');
        }
        $defaults = is_array($set['defaults'] ?? null) ? $set['defaults'] : [];
        self::rejectUnknown($defaults, self::DEFAULT_KEYS, '$.policy_set.defaults');
        $defaults = [
            'severity' => self::enum($defaults['severity'] ?? 'warning', self::SEVERITIES, '$.policy_set.defaults.severity'),
            'on_missing' => self::enum($defaults['on_missing'] ?? 'report', self::ON_MISSING, '$.policy_set.defaults.on_missing'),
            'on_insufficient_data' => self::enum($defaults['on_insufficient_data'] ?? 'report', self::ON_MISSING, '$.policy_set.defaults.on_insufficient_data'),
        ];
        $policies = $set['policies'] ?? null;
        if (!is_array($policies) || !array_is_list($policies)) {
            throw new MysqlQueryPolicyException('policies must be a list.', '$.policy_set.policies');
        }
        if (count($policies) > 500) {
            throw new MysqlQueryPolicyException('Too many policies; maximum is 500.', '$.policy_set.policies');
        }
        $seen = [];
        $validated = [];
        foreach ($policies as $index => $policy) {
            $path = '$.policy_set.policies[' . $index . ']';
            if (!is_array($policy)) {
                throw new MysqlQueryPolicyException('Policy must be an object.', $path);
            }
            self::rejectUnknown($policy, self::POLICY_KEYS, $path);
            $policyId = self::identifier($policy['id'] ?? null, $path . '.id');
            if (isset($seen[$policyId])) {
                throw new MysqlQueryPolicyException('Duplicate policy id.', $path . '.id', 'duplicate_policy_id');
            }
            $seen[$policyId] = true;
            $scope = (string)($policy['scope'] ?? 'query');
            if (!in_array($scope, ['query', 'global'], true)) {
                throw new MysqlQueryPolicyException('scope must be query or global.', $path . '.scope');
            }
            $selector = is_array($policy['selector'] ?? null) ? $policy['selector'] : [];
            self::rejectUnknown($selector, self::SELECTOR_KEYS, $path . '.selector');
            if ($scope === 'query' && $selector === []) {
                throw new MysqlQueryPolicyException('Query policy selector cannot be empty.', $path . '.selector', 'empty_selector');
            }
            if ($scope === 'global' && $selector !== []) {
                throw new MysqlQueryPolicyException('Global policies cannot define a selector.', $path . '.selector');
            }
            $selector = self::selector($selector, $path . '.selector');
            $budgets = is_array($policy['budgets'] ?? null) ? $policy['budgets'] : [];
            self::rejectUnknown($budgets, self::BUDGET_KEYS, $path . '.budgets');
            $budgets = self::budgets($budgets, $scope, $path . '.budgets');
            $plan = is_array($policy['plan'] ?? null) ? $policy['plan'] : [];
            self::rejectUnknown($plan, self::PLAN_KEYS, $path . '.plan');
            $plan = self::plan($plan, $path . '.plan');
            if ($scope === 'global' && $plan !== []) {
                throw new MysqlQueryPolicyException('Global policies cannot define plan constraints.', $path . '.plan');
            }
            if ($budgets === [] && $plan === []) {
                throw new MysqlQueryPolicyException('Policy must define budgets or plan constraints.', $path);
            }
            $validated[] = [
                'id' => $policyId,
                'description' => self::text($policy['description'] ?? '', 500, $path . '.description'),
                'scope' => $scope,
                'selector' => $selector,
                'budgets' => $budgets,
                'plan' => $plan,
                'severity' => self::enum($policy['severity'] ?? $defaults['severity'], self::SEVERITIES, $path . '.severity'),
                'on_missing' => self::enum($policy['on_missing'] ?? $defaults['on_missing'], self::ON_MISSING, $path . '.on_missing'),
                'on_insufficient_data' => self::enum($policy['on_insufficient_data'] ?? $defaults['on_insufficient_data'], self::ON_MISSING, $path . '.on_insufficient_data'),
            ];
        }
        return [
            'schema_version' => MysqlQueryPolicyConfig::SCHEMA_VERSION,
            'policy_set' => [
                'id' => $id,
                'description' => self::text($set['description'] ?? '', 1000, '$.policy_set.description'),
                'mode' => $mode,
                'defaults' => $defaults,
                'policies' => $validated,
            ],
        ];
    }

    /** @param array<string,mixed> $selector @return array<string,array<int,string>> */
    private static function selector(array $selector, string $path): array
    {
        $out = [];
        foreach ($selector as $key => $value) {
            $values = is_array($value) ? $value : [$value];
            if (!array_is_list($values) || $values === [] || count($values) > 50) {
                throw new MysqlQueryPolicyException('Selector value must be a non-empty scalar/list with at most 50 values.', $path . '.' . $key);
            }
            $normalized = [];
            foreach ($values as $index => $item) {
                if (!is_string($item) && !is_int($item)) {
                    throw new MysqlQueryPolicyException('Selector values must be strings.', $path . '.' . $key . '[' . $index . ']');
                }
                $item = trim((string)$item);
                if ($item === '') {
                    throw new MysqlQueryPolicyException('Selector value cannot be empty.', $path . '.' . $key . '[' . $index . ']');
                }
                if ($key === 'fingerprint') {
                    $item = SqlFingerprint::fingerprint($item);
                } elseif ($key === 'capture_method') {
                    $item = MysqlCaptureMethod::normalize($item);
                    if ($item === MysqlCaptureMethod::UNKNOWN) {
                        throw new MysqlQueryPolicyException('Unknown capture_method.', $path . '.' . $key . '[' . $index . ']');
                    }
                } elseif ($key === 'classification') {
                    $item = self::enum($item, self::CLASSIFICATIONS, $path . '.' . $key . '[' . $index . ']');
                } else {
                    $item = InstrumentationContext::sanitizeIdentifier($item, 160);
                }
                if ($item !== '') {
                    $normalized[$item] = true;
                }
            }
            $out[(string)$key] = array_keys($normalized);
        }
        ksort($out);
        return $out;
    }

    /** @param array<string,mixed> $budgets @return array<string,int|float> */
    private static function budgets(array $budgets, string $scope, string $path): array
    {
        $allowed = $scope === 'global' ? self::GLOBAL_BUDGETS : self::QUERY_BUDGETS;
        $out = [];
        foreach ($budgets as $key => $value) {
            if (!in_array((string)$key, $allowed, true)) {
                throw new MysqlQueryPolicyException('Budget is not valid for ' . $scope . ' scope.', $path . '.' . $key);
            }
            $integer = in_array((string)$key, ['max_calls', 'max_rows_examined', 'max_total_queries', 'max_unique_fingerprints', 'max_instrumentation_findings', 'max_uninstrumented_findings'], true);
            $out[(string)$key] = self::number($value, $integer, $path . '.' . $key);
        }
        ksort($out);
        return $out;
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private static function plan(array $plan, string $path): array
    {
        $out = [];
        foreach ($plan as $key => $value) {
            if ($key === 'require_any_key') {
                if (!is_bool($value)) {
                    throw new MysqlQueryPolicyException('require_any_key must be boolean.', $path . '.require_any_key');
                }
                $out[$key] = $value;
                continue;
            }
            if ($key === 'max_estimated_rows') {
                $out[$key] = self::number($value, true, $path . '.' . $key);
                continue;
            }
            if (!is_array($value) || !array_is_list($value)) {
                throw new MysqlQueryPolicyException($key . ' must be a list.', $path . '.' . $key);
            }
            $allowed = $key === 'forbid_flags' ? self::PLAN_FLAGS : ($key === 'forbid_access_types' ? self::ACCESS_TYPES : null);
            $items = [];
            foreach ($value as $index => $item) {
                if (!is_string($item) || trim($item) === '') {
                    throw new MysqlQueryPolicyException('List items must be non-empty strings.', $path . '.' . $key . '[' . $index . ']');
                }
                $item = strtolower(trim($item));
                if ($allowed !== null && !in_array($item, $allowed, true)) {
                    throw new MysqlQueryPolicyException('Unknown catalog value.', $path . '.' . $key . '[' . $index . ']');
                }
                $items[$item] = true;
            }
            $out[$key] = array_keys($items);
        }
        ksort($out);
        return $out;
    }

    private static function number(mixed $value, bool $integer, string $path): int|float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new MysqlQueryPolicyException('Budget value must be numeric.', $path);
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0 || ($integer && floor($number) !== $number)) {
            throw new MysqlQueryPolicyException('Budget value must be finite, non-negative' . ($integer ? ' integer' : '') . '.', $path);
        }
        return $integer ? (int)$number : $number;
    }

    private static function identifier(mixed $value, string $path): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new MysqlQueryPolicyException('Identifier is required.', $path);
        }
        $value = trim($value);
        if (strlen($value) > 160 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value) !== 1) {
            throw new MysqlQueryPolicyException('Invalid identifier.', $path);
        }
        return $value;
    }

    private static function text(mixed $value, int $limit, string $path): string
    {
        if (!is_string($value)) {
            throw new MysqlQueryPolicyException('Value must be a string.', $path);
        }
        return InstrumentationContext::sanitizeText($value, $limit);
    }

    /** @param array<int,string> $allowed */
    private static function enum(mixed $value, array $allowed, string $path): string
    {
        if (!is_string($value) || !in_array(strtolower(trim($value)), $allowed, true)) {
            throw new MysqlQueryPolicyException('Unsupported value.', $path);
        }
        return strtolower(trim($value));
    }

    /** @param array<string,mixed> $value @param array<int,string> $allowed */
    private static function rejectUnknown(array $value, array $allowed, string $path): void
    {
        foreach (array_keys($value) as $key) {
            if (!in_array((string)$key, $allowed, true)) {
                throw new MysqlQueryPolicyException('Unknown key: ' . $key, $path . '.' . $key, 'unknown_policy_key');
            }
        }
    }
}
