<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Policy;

use Testkit\Core\DbProfiling\InstrumentationContext;

final class MysqlQueryPolicyEvaluator
{
    private const QUERY_METRICS = [
        'max_calls' => ['field' => 'calls', 'unit' => 'calls'],
        'max_max_ms' => ['field' => 'max_ms', 'unit' => 'ms'],
        'max_avg_ms' => ['field' => 'avg_ms', 'unit' => 'ms'],
        'max_total_ms' => ['field' => 'total_ms', 'unit' => 'ms'],
        'max_p50_ms' => ['field' => 'p50_ms', 'unit' => 'ms'],
        'max_p95_ms' => ['field' => 'p95_ms', 'unit' => 'ms'],
        'max_p99_ms' => ['field' => 'p99_ms', 'unit' => 'ms'],
        'max_standard_deviation_ms' => ['field' => 'standard_deviation_ms', 'unit' => 'ms'],
    ];
    private const GLOBAL_METRICS = [
        'max_total_queries' => ['path' => ['summary', 'total_queries'], 'unit' => 'queries'],
        'max_unique_fingerprints' => ['path' => ['summary', 'unique_fingerprints'], 'unit' => 'fingerprints'],
        'max_total_sql_time_ms' => ['path' => ['summary', 'total_db_time_ms'], 'unit' => 'ms'],
    ];
    private const UNINSTRUMENTED_CODES = [
        'query_without_connection', 'unknown_capture_method', 'bootstrap_not_confirmed',
        'existing_pdo_partial_capture', 'mysqli_statement_sql_missing', 'collector_record_error',
    ];

    /** @param array<string,mixed> $profile @param array<string,mixed> $policyDocument @return array<string,mixed> */
    public static function evaluate(array $profile, array $policyDocument, int $maxResults = 500): array
    {
        self::assertProfileCompatible($profile);
        $set = is_array($policyDocument['policy_set'] ?? null) ? $policyDocument['policy_set'] : [];
        $policies = is_array($set['policies'] ?? null) ? $set['policies'] : [];
        $queries = array_values(array_filter((array)($profile['queries'] ?? []), 'is_array'));
        $explainMap = self::explainMap($profile);
        $queryIdMap = self::queryIdMap($profile);
        $results = [];
        $effectiveRows = [];
        $used = [];
        $conflicts = [];
        $querySummaries = [];

        foreach ($policies as $policy) {
            if (($policy['scope'] ?? 'query') !== 'global') {
                continue;
            }
            $used[(string)$policy['id']] = true;
            foreach (self::evaluateGlobalPolicy($profile, $policy) as $result) {
                $results[] = $result;
            }
        }

        foreach ($queries as $queryIndex => $query) {
            $query['_profile_report_version'] = (int)($profile['report_version'] ?? 1);
            $matches = [];
            foreach ($policies as $policy) {
                if (($policy['scope'] ?? 'query') !== 'query') {
                    continue;
                }
                if (self::matches($query, $policy, $queryIdMap)) {
                    $policy['_rank'] = self::rank((array)$policy['selector']);
                    $matches[] = $policy;
                    $used[(string)$policy['id']] = true;
                }
            }
            if ($matches === []) {
                $querySummaries[$queryIndex] = [
                    'policy_status' => 'not_applicable',
                    'applied_policy_ids' => [],
                    'violations_count' => 0,
                ];
                continue;
            }
            usort($matches, static function (array $a, array $b): int {
                $rank = ((int)$a['_rank'] <=> (int)$b['_rank']);
                return $rank !== 0 ? $rank : strcmp((string)$a['id'], (string)$b['id']);
            });
            [$effective, $origins, $policyIds, $localConflicts] = self::resolveEffective($matches, $query);
            $conflicts = array_merge($conflicts, $localConflicts);
            $effectiveRows[] = [
                'fingerprint' => (string)($query['fingerprint'] ?? ''),
                'applied_policy_ids' => $policyIds,
                'budgets' => $effective['budgets'],
                'plan' => $effective['plan'],
                'origins' => $origins,
            ];
            foreach (self::evaluateQuery($query, $queryIndex, $effective, $origins, $explainMap, $queryIdMap) as $result) {
                $result['query_index'] = $queryIndex;
                $results[] = $result;
            }
            $queryResults = array_values(array_filter($results, static fn(array $r): bool => ($r['query_index'] ?? null) === $queryIndex));
            $violations = count(array_filter($queryResults, static fn(array $r): bool => ($r['status'] ?? '') === 'violation'));
            $insufficient = count(array_filter($queryResults, static fn(array $r): bool => ($r['status'] ?? '') === 'insufficient_data'));
            $querySummaries[$queryIndex] = [
                'policy_status' => $violations > 0 ? 'violation' : ($insufficient > 0 ? 'insufficient_data' : 'pass'),
                'applied_policy_ids' => $policyIds,
                'violations_count' => $violations,
            ];
        }

        if ($conflicts !== []) {
            $first = $conflicts[0];
            throw new MysqlQueryPolicyException(
                'Irresolvable policy conflict for budget ' . (string)($first['budget_key'] ?? 'unknown') . '.',
                '$.policy_set.policies',
                'policy_precedence_conflict'
            );
        }

        $unused = [];
        foreach ($policies as $policy) {
            $id = (string)($policy['id'] ?? '');
            if (isset($used[$id])) {
                continue;
            }
            $unused[] = [
                'policy_id' => $id,
                'status' => self::unusedStatus($profile, $policy),
                'selector' => (array)($policy['selector'] ?? []),
                'action' => (string)($policy['on_missing'] ?? 'report'),
            ];
        }

        $statusCounts = [];
        foreach ($results as $result) {
            $status = (string)($result['status'] ?? 'not_evaluated');
            $statusCounts[$status] = (int)($statusCounts[$status] ?? 0) + 1;
        }
        ksort($statusCounts);
        $evaluatedBudgetCount = count($results);
        $maxResults = max(1, min(5000, $maxResults));
        $truncated = $evaluatedBudgetCount > $maxResults;
        $results = array_slice($results, 0, $maxResults);

        return [
            'enabled' => true,
            'mode' => (string)($set['mode'] ?? MysqlQueryPolicyConfig::MODE_REPORT_ONLY),
            'schema_version' => (string)($policyDocument['schema_version'] ?? MysqlQueryPolicyConfig::SCHEMA_VERSION),
            'policy_set_id' => (string)($set['id'] ?? ''),
            'policy_file' => (string)($policyDocument['_meta']['file'] ?? ''),
            'policy_file_hash' => (string)($policyDocument['_meta']['file_hash'] ?? ''),
            'profile_schema_version' => (string)($profile['schema_version'] ?? 'legacy-v1'),
            'profile_report_version' => (int)($profile['report_version'] ?? 1),
            'profile_compatibility_status' => (int)($profile['report_version'] ?? 1) === 1 ? 'legacy_report' : 'current_report',
            'loaded_policies' => count($policies),
            'applicable_policies' => count($used),
            'unused_policies' => $unused,
            'evaluated_budgets' => $evaluatedBudgetCount,
            'passed_budgets' => (int)($statusCounts['pass'] ?? 0),
            'violated_budgets' => (int)($statusCounts['violation'] ?? 0),
            'insufficient_data_budgets' => (int)($statusCounts['insufficient_data'] ?? 0),
            'status_counts' => $statusCounts,
            'results' => $results,
            'effective_policies' => $effectiveRows,
            'query_summaries' => $querySummaries,
            'conflicts' => [],
            'warnings' => $truncated ? ['results_truncated'] : [],
        ];
    }

    /** @param array<string,mixed> $profile */
    public static function assertProfileCompatible(array $profile): void
    {
        $version = (int)($profile['report_version'] ?? 1);
        if ($version < 1 || $version > 2) {
            throw new MysqlQueryPolicyException('Unsupported profile report version.', '$.report_version', 'profile_incompatible');
        }
        if (isset($profile['engine']) && (string)$profile['engine'] !== 'mysql') {
            throw new MysqlQueryPolicyException('Only MySQL profile reports are supported.', '$.engine', 'profile_incompatible');
        }
        if (!isset($profile['summary']) || !is_array($profile['summary'])) {
            throw new MysqlQueryPolicyException('Profile summary is required.', '$.summary', 'profile_incompatible');
        }
        if (!isset($profile['queries']) || !is_array($profile['queries'])) {
            throw new MysqlQueryPolicyException('Profile queries are required.', '$.queries', 'profile_incompatible');
        }
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $policy @return array<int,array<string,mixed>> */
    private static function evaluateGlobalPolicy(array $profile, array $policy): array
    {
        $results = [];
        foreach ((array)($policy['budgets'] ?? []) as $key => $expected) {
            if ($key === 'max_instrumentation_findings') {
                $actual = count(array_filter((array)($profile['instrumentation']['findings'] ?? []), 'is_array'));
                $unit = 'findings';
                $path = '$.instrumentation.findings';
            } elseif ($key === 'max_uninstrumented_findings') {
                $actual = count(array_filter((array)($profile['instrumentation']['findings'] ?? []), static function (mixed $finding): bool {
                    return is_array($finding) && in_array((string)($finding['code'] ?? ''), self::UNINSTRUMENTED_CODES, true);
                }));
                $unit = 'findings';
                $path = '$.instrumentation.findings';
            } else {
                $spec = self::GLOBAL_METRICS[$key] ?? null;
                if (!is_array($spec)) {
                    continue;
                }
                $actual = self::pathValue($profile, (array)$spec['path']);
                $unit = (string)$spec['unit'];
                $path = '$.' . implode('.', (array)$spec['path']);
            }
            $results[] = self::numericResult($policy, $key, $actual, $expected, $unit, $path, 'scope_global', (string)$policy['id'], 0);
        }
        return $results;
    }

    /** @return array{0:array<string,mixed>,1:array<string,array<string,mixed>>,2:array<int,string>,3:array<int,array<string,mixed>>} */
    private static function resolveEffective(array $matches, array $query): array
    {
        $effective = ['id' => '', 'selector' => [], 'budgets' => [], 'plan' => [], 'severity' => 'warning', 'on_missing' => 'report', 'on_insufficient_data' => 'report'];
        $origins = [];
        $ids = [];
        $conflicts = [];
        $rankOwners = [];
        foreach ($matches as $policy) {
            $id = (string)$policy['id'];
            $rank = (int)$policy['_rank'];
            $ids[] = $id;
            $effective['id'] = $id;
            $effective['selector'] = (array)($policy['selector'] ?? []);
            $effective['severity'] = (string)($policy['severity'] ?? $effective['severity']);
            $effective['on_missing'] = (string)($policy['on_missing'] ?? $effective['on_missing']);
            $effective['on_insufficient_data'] = (string)($policy['on_insufficient_data'] ?? $effective['on_insufficient_data']);
            foreach (['budgets', 'plan'] as $section) {
                foreach ((array)($policy[$section] ?? []) as $key => $value) {
                    $ownerKey = $section . '.' . $key;
                    if (isset($rankOwners[$ownerKey]) && $rankOwners[$ownerKey]['rank'] === $rank && $rankOwners[$ownerKey]['value'] !== $value) {
                        $conflicts[] = [
                            'fingerprint' => (string)($query['fingerprint'] ?? ''),
                            'budget_key' => $key,
                            'policy_ids' => [$rankOwners[$ownerKey]['policy_id'], $id],
                            'precedence_rank' => $rank,
                        ];
                        continue;
                    }
                    $effective[$section][$key] = $value;
                    $origins[$ownerKey] = [
                        'source_policy_id' => $id,
                        'effective_policy_id' => $id,
                        'precedence_rank' => $rank,
                    ];
                    $rankOwners[$ownerKey] = ['rank' => $rank, 'value' => $value, 'policy_id' => $id];
                }
            }
        }
        return [$effective, $origins, array_values(array_unique($ids)), $conflicts];
    }

    /** @param array<string,mixed> $query @param array<string,mixed> $effective @param array<string,array<string,mixed>> $origins @return array<int,array<string,mixed>> */
    private static function evaluateQuery(array $query, int $queryIndex, array $effective, array $origins, array $explainMap, array $queryIdMap): array
    {
        $results = [];
        $fingerprint = (string)($query['fingerprint'] ?? '');
        $evidencePath = '$.queries[' . $queryIndex . ']';
        foreach ((array)$effective['budgets'] as $key => $expected) {
            $origin = $origins['budgets.' . $key] ?? ['source_policy_id' => '', 'effective_policy_id' => '', 'precedence_rank' => 0];
            if ($key === 'max_rows_examined') {
                $finding = $explainMap[$fingerprint] ?? null;
                if (!is_array($finding) || ($finding['explain_status'] ?? '') !== 'analyzed') {
                    $results[] = self::insufficientResult($effective, $origin, $key, $expected, 'rows', '$.explain.findings', 'explain_not_enabled');
                    continue;
                }
                $actual = $finding['plan_summary']['estimated_rows'] ?? null;
                $results[] = self::numericResult($effective, $key, $actual, $expected, 'rows', '$.explain.findings', 'fingerprint', (string)$origin['source_policy_id'], (int)$origin['precedence_rank'], $origin);
                continue;
            }
            $spec = self::QUERY_METRICS[$key] ?? null;
            if (!is_array($spec)) {
                continue;
            }
            $field = (string)$spec['field'];
            if (!array_key_exists($field, $query) || !is_numeric($query[$field])) {
                $reason = ((int)($query['_profile_report_version'] ?? 2) === 1) ? 'legacy_report_field_missing' : 'missing_metric';
                $results[] = self::insufficientResult($effective, $origin, $key, $expected, (string)$spec['unit'], $evidencePath . '.' . $field, $reason);
                continue;
            }
            $results[] = self::numericResult($effective, $key, $query[$field], $expected, (string)$spec['unit'], $evidencePath . '.' . $field, 'fingerprint', (string)$origin['source_policy_id'], (int)$origin['precedence_rank'], $origin, $fingerprint, $queryIdMap[$fingerprint] ?? []);
        }

        $finding = $explainMap[$fingerprint] ?? null;
        foreach ((array)$effective['plan'] as $key => $expected) {
            $origin = $origins['plan.' . $key] ?? ['source_policy_id' => '', 'effective_policy_id' => '', 'precedence_rank' => 0];
            if (!is_array($finding) || ($finding['explain_status'] ?? '') !== 'analyzed') {
                $results[] = self::insufficientResult($effective, $origin, $key, $expected, 'plan', '$.explain.findings', 'explain_not_enabled');
                continue;
            }
            $results[] = self::planResult($effective, $origin, $key, $expected, $finding, $fingerprint, $queryIdMap[$fingerprint] ?? []);
        }
        return $results;
    }

    /** @param array<string,mixed> $policy */
    private static function matches(array $query, array $policy, array $queryIdMap): bool
    {
        $selector = (array)($policy['selector'] ?? []);
        foreach ($selector as $key => $expected) {
            $actual = match ($key) {
                'fingerprint' => [(string)($query['fingerprint'] ?? '')],
                'query_id' => $queryIdMap[(string)($query['fingerprint'] ?? '')] ?? [],
                'module_id' => (array)($query['modules'] ?? []),
                'scenario_id' => (array)($query['scenarios'] ?? []),
                'suite_id' => (array)($query['suites'] ?? []),
                'test_id' => (array)($query['tests'] ?? []),
                'capture_method' => array_keys((array)($query['capture_methods'] ?? [])),
                'classification' => [(string)($query['classification'] ?? '')],
                'source' => (array)($query['sources'] ?? []),
                'caller' => (array)($query['callers'] ?? []),
                default => [],
            };
            if (array_intersect((array)$expected, array_map('strval', $actual)) === []) {
                return false;
            }
        }
        return true;
    }

    private static function rank(array $selector): int
    {
        $weights = ['query_id' => 1000, 'fingerprint' => 900, 'test_id' => 80, 'suite_id' => 70, 'scenario_id' => 60, 'module_id' => 50, 'capture_method' => 30, 'classification' => 20, 'source' => 10, 'caller' => 10];
        $rank = 0;
        foreach (array_keys($selector) as $key) {
            $rank += $weights[(string)$key] ?? 1;
        }
        return $rank;
    }

    private static function numericResult(array $policy, string $key, mixed $actual, mixed $expected, string $unit, string $path, string $matchedBy, string $sourcePolicyId, int $rank, array $origin = [], string $fingerprint = '', array $queryIds = []): array
    {
        if (!is_numeric($actual)) {
            return self::insufficientResult($policy, $origin, $key, $expected, $unit, $path, 'missing_metric');
        }
        $actualNumber = (float)$actual;
        $expectedNumber = (float)$expected;
        $status = $actualNumber <= $expectedNumber ? 'pass' : 'violation';
        return [
            'policy_id' => $sourcePolicyId !== '' ? $sourcePolicyId : (string)($policy['id'] ?? ''),
            'effective_policy_id' => (string)($origin['effective_policy_id'] ?? $sourcePolicyId),
            'source_policy_id' => (string)($origin['source_policy_id'] ?? $sourcePolicyId),
            'precedence_rank' => $rank,
            'budget_key' => $key,
            'status' => $status,
            'severity' => (string)($policy['severity'] ?? 'warning'),
            'selector' => (array)($policy['selector'] ?? []),
            'matched_by' => $matchedBy,
            'actual' => $actualNumber,
            'expected' => $expectedNumber,
            'operator' => '<=',
            'delta' => $actualNumber - $expectedNumber,
            'unit' => $unit,
            'evidence_path' => $path,
            'fingerprint' => $fingerprint,
            'query_ids' => array_values($queryIds),
            'message' => $status === 'pass' ? 'Budget satisfied.' : 'Budget exceeded.',
        ];
    }

    private static function insufficientResult(array $policy, array $origin, string $key, mixed $expected, string $unit, string $path, string $reason): array
    {
        $status = (string)($policy['on_insufficient_data'] ?? 'report') === 'ignore'
            ? 'not_evaluated'
            : 'insufficient_data';
        return [
            'policy_id' => (string)($origin['source_policy_id'] ?? $policy['id'] ?? ''),
            'effective_policy_id' => (string)($origin['effective_policy_id'] ?? $origin['source_policy_id'] ?? ''),
            'source_policy_id' => (string)($origin['source_policy_id'] ?? ''),
            'precedence_rank' => (int)($origin['precedence_rank'] ?? 0),
            'budget_key' => $key,
            'status' => $status,
            'severity' => (string)($policy['severity'] ?? 'warning'),
            'selector' => (array)($policy['selector'] ?? []),
            'matched_by' => 'insufficient_evidence',
            'actual' => null,
            'expected' => $expected,
            'operator' => '<=',
            'delta' => null,
            'unit' => $unit,
            'evidence_path' => $path,
            'reason' => $reason,
            'message' => 'Budget was not evaluated because evidence is insufficient.',
        ];
    }

    private static function planResult(array $policy, array $origin, string $key, mixed $expected, array $finding, string $fingerprint, array $queryIds): array
    {
        $summary = is_array($finding['plan_summary'] ?? null) ? $finding['plan_summary'] : [];
        $flags = array_values(array_map('strval', (array)($finding['flags'] ?? [])));
        $keys = array_values(array_map('strval', (array)($summary['keys_used'] ?? [])));
        $access = array_values(array_map(static fn($v): string => strtolower((string)$v), (array)($summary['access_types'] ?? [])));
        $actual = null;
        $pass = false;
        $unit = 'plan';
        if ($key === 'forbid_flags') {
            $actual = array_values(array_intersect($flags, (array)$expected));
            $pass = $actual === [];
        } elseif ($key === 'require_any_key') {
            $actual = $keys;
            $pass = $expected === false || $keys !== [];
        } elseif ($key === 'require_keys') {
            $actual = $keys;
            $pass = array_diff((array)$expected, $keys) === [];
        } elseif ($key === 'forbid_access_types') {
            $actual = array_values(array_intersect($access, (array)$expected));
            $pass = $actual === [];
        } elseif ($key === 'max_estimated_rows') {
            $actual = $summary['estimated_rows'] ?? null;
            $unit = 'rows';
            if (!is_numeric($actual)) {
                return self::insufficientResult($policy, $origin, $key, $expected, $unit, '$.explain.findings.plan_summary.estimated_rows', 'missing_metric');
            }
            $pass = (float)$actual <= (float)$expected;
        }
        return [
            'policy_id' => (string)($origin['source_policy_id'] ?? ''),
            'effective_policy_id' => (string)($origin['effective_policy_id'] ?? ''),
            'source_policy_id' => (string)($origin['source_policy_id'] ?? ''),
            'precedence_rank' => (int)($origin['precedence_rank'] ?? 0),
            'budget_key' => $key,
            'status' => $pass ? 'pass' : 'violation',
            'severity' => (string)($policy['severity'] ?? 'warning'),
            'selector' => (array)($policy['selector'] ?? []),
            'matched_by' => 'explain_plan',
            'actual' => $actual,
            'expected' => $expected,
            'operator' => $key === 'max_estimated_rows' ? '<=' : 'constraint',
            'delta' => $key === 'max_estimated_rows' && is_numeric($actual) ? (float)$actual - (float)$expected : null,
            'unit' => $unit,
            'evidence_path' => '$.explain.findings',
            'fingerprint' => $fingerprint,
            'query_ids' => array_values($queryIds),
            'message' => $pass ? 'Plan constraint satisfied.' : 'Plan constraint violated.',
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function explainMap(array $profile): array
    {
        $map = [];
        foreach ((array)($profile['explain']['findings'] ?? []) as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $fingerprint = (string)($finding['fingerprint'] ?? '');
            if ($fingerprint !== '') {
                $map[$fingerprint] = $finding;
            }
        }
        return $map;
    }

    /** @return array<string,array<int,string>> */
    private static function queryIdMap(array $profile): array
    {
        $map = [];
        foreach ((array)($profile['explain']['findings'] ?? []) as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $fingerprint = (string)($finding['fingerprint'] ?? '');
            $queryId = InstrumentationContext::sanitizeIdentifier((string)($finding['query_id'] ?? ''), 160);
            if ($fingerprint !== '' && $queryId !== '') {
                $map[$fingerprint][$queryId] = true;
            }
        }
        foreach ($map as $fingerprint => $ids) {
            $map[$fingerprint] = array_keys($ids);
        }
        return $map;
    }

    private static function unusedStatus(array $profile, array $policy): string
    {
        if ((int)($profile['report_version'] ?? 1) === 1) {
            return 'unused_report_legacy';
        }
        $selector = (array)($policy['selector'] ?? []);
        foreach (['module_id' => 'modules', 'scenario_id' => 'scenarios', 'suite_id' => 'suites', 'test_id' => 'tests'] as $selectorKey => $queryKey) {
            if (!isset($selector[$selectorKey])) {
                continue;
            }
            $hasAny = false;
            foreach ((array)($profile['queries'] ?? []) as $query) {
                if (is_array($query) && (array)($query[$queryKey] ?? []) !== []) {
                    $hasAny = true;
                    break;
                }
            }
            if (!$hasAny) {
                return 'unused_missing_context';
            }
        }
        if ((array)($policy['plan'] ?? []) !== [] && empty($profile['explain']['enabled'])) {
            return 'unused_explain_unavailable';
        }
        return 'unused_no_match';
    }

    private static function pathValue(array $payload, array $path): mixed
    {
        $cursor = $payload;
        foreach ($path as $part) {
            if (!is_array($cursor) || !array_key_exists((string)$part, $cursor)) {
                return null;
            }
            $cursor = $cursor[(string)$part];
        }
        return $cursor;
    }

}
