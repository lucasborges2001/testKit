<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Baseline;

use Testkit\Core\DbProfiling\InstrumentationContext;
use Testkit\Core\DbProfiling\MysqlProfileConfig;

final class MysqlQueryBaselineComparator
{
    private const TIME_METRICS = [
        'min_ms', 'avg_ms', 'max_ms', 'total_ms',
        'p50_ms', 'p95_ms', 'p99_ms', 'standard_deviation_ms',
    ];
    private const COUNT_METRICS = ['calls', 'sample_count'];
    private const GLOBAL_TIME_METRICS = ['total_sql_time_ms'];
    private const GLOBAL_COUNT_METRICS = [
        'total_queries', 'unique_fingerprints', 'instrumentation_findings',
        'uninstrumented_findings', 'connections_observed',
    ];

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $baselineRoot
     * @param int $maxResults
     * @return array<string,mixed>
     */
    public static function compare(array $current, array $baselineRoot, int $maxResults = 5000): array
    {
        self::assertRecoverableCurrent($current);
        $baseline = is_array($baselineRoot['baseline'] ?? null) ? $baselineRoot['baseline'] : [];
        $baselineId = (string)($baseline['id'] ?? '');
        $baselineHash = (string)($baselineRoot['_meta']['file_hash'] ?? hash(
            'sha256',
            MysqlQueryBaselineBuilder::canonicalJson($baselineRoot)
        ));
        $profileHash = hash('sha256', MysqlQueryBaselineBuilder::canonicalJson($current));
        $currentContext = self::currentContext($current);
        $compatibility = MysqlQueryBaselineCompatibility::evaluate(
            is_array($baseline['compatibility'] ?? null) ? $baseline['compatibility'] : [],
            $currentContext,
            $current
        );
        $defaults = is_array($baseline['comparison_defaults'] ?? null)
            ? $baseline['comparison_defaults']
            : MysqlQueryBaselineBuilder::DEFAULTS;

        $baselineQueries = self::indexByIdentity((array)($baseline['queries'] ?? []));
        $currentRows = MysqlQueryBaselineBuilder::extractQueries($current);
        $currentQueries = self::indexByIdentity($currentRows);
        $allIdentities = array_values(array_unique(array_merge(
            array_keys($baselineQueries),
            array_keys($currentQueries)
        )));
        sort($allIdentities, SORT_STRING);

        $matched = [];
        $new = [];
        $removed = [];
        $ambiguous = [];
        $querySummaries = [];
        $counts = [
            'matched' => 0,
            'new' => 0,
            'removed' => 0,
            'regressed' => 0,
            'improved' => 0,
            'unchanged' => 0,
            'plan_changed' => 0,
            'insufficient_data' => 0,
            'incompatible_context' => 0,
            'structural_only' => 0,
        ];

        foreach ($allIdentities as $identity) {
            $before = $baselineQueries[$identity] ?? null;
            $after = $currentQueries[$identity] ?? null;
            if ($before === null && is_array($after)) {
                $row = self::newQuery($after, $compatibility);
                $new[] = $row;
                $counts['new']++;
                $querySummaries[$identity] = [
                    'baseline_status' => 'new',
                    'baseline_metric_regressions' => 0,
                    'baseline_plan_status' => (string)($row['plan']['status'] ?? 'insufficient_data'),
                ];
                if (($after['identity_status'] ?? '') === 'ambiguous_query_ids') {
                    $ambiguous[] = self::ambiguousRow($after, 'current');
                }
                continue;
            }
            if (is_array($before) && $after === null) {
                $row = self::removedQuery($before, $compatibility);
                $removed[] = $row;
                $counts['removed']++;
                if (($before['identity_status'] ?? '') === 'ambiguous_query_ids') {
                    $ambiguous[] = self::ambiguousRow($before, 'baseline');
                }
                continue;
            }
            if (!is_array($before) || !is_array($after)) {
                continue;
            }

            $comparison = self::compareQuery($before, $after, $defaults, $compatibility);
            $matched[] = $comparison;
            $counts['matched']++;
            $overall = (string)$comparison['overall_status'];
            if (isset($counts[$overall])) {
                $counts[$overall]++;
            }
            if (($before['identity_status'] ?? '') === 'ambiguous_query_ids') {
                $ambiguous[] = self::ambiguousRow($before, 'baseline');
            }
            if (($after['identity_status'] ?? '') === 'ambiguous_query_ids') {
                $ambiguous[] = self::ambiguousRow($after, 'current');
            }
            $metricRegressions = count(array_filter(
                (array)$comparison['metric_results'],
                static fn(array $metric): bool => ($metric['status'] ?? '') === 'regressed'
            ));
            $querySummaries[$identity] = [
                'baseline_status' => $overall,
                'baseline_metric_regressions' => $metricRegressions,
                'baseline_plan_status' => (string)($comparison['plan_result']['status'] ?? 'insufficient_data'),
            ];
        }

        $globalMetrics = self::compareGlobal(
            is_array($baseline['global'] ?? null) ? $baseline['global'] : [],
            MysqlQueryBaselineBuilder::extractGlobal($current),
            $defaults,
            $compatibility
        );

        $policyWarnings = [];
        $baselinePolicyHash = (string)($baseline['source']['policy_hash'] ?? '');
        $currentPolicyHash = (string)($current['policy_evaluation']['policy_file_hash'] ?? '');
        if ($baselinePolicyHash !== '' && $currentPolicyHash !== '' && $baselinePolicyHash !== $currentPolicyHash) {
            $policyWarnings[] = 'Policy hash differs between baseline and current report.';
        }

        $matched = self::sortComparisons($matched);
        $new = self::sortByIdentity($new);
        $removed = self::sortByIdentity($removed);
        $ambiguous = self::uniqueAmbiguous($ambiguous);
        $maxResults = max(1, min(5000, $maxResults));
        $truncated = max(0, count($matched) - $maxResults);

        $generatedAt = self::generatedAt($current, $baseline);
        $comparisonId = 'cmp_' . substr(hash(
            'sha256',
            $baselineId . '|' . $baselineHash . '|' . $profileHash
        ), 0, 24);

        $recommendations = self::recommendations($matched, $new, $removed, $compatibility);
        $limitations = [
            'Comparison is report-only and cannot fail a suite in phase 4.',
            'Removed queries may indicate missing test coverage rather than an improvement.',
            'Latency conclusions require compatible engine, dataset, environment, and suite metadata.',
            'EXPLAIN plan comparison uses normalized structural evidence, not raw JSON plan text.',
        ];

        return [
            'schema_version' => MysqlQueryBaselineConfig::COMPARISON_SCHEMA_VERSION,
            'mode' => MysqlQueryBaselineConfig::MODE_REPORT_ONLY,
            'comparison_id' => $comparisonId,
            'generated_at' => $generatedAt,
            'baseline' => [
                'id' => $baselineId,
                'hash' => $baselineHash,
                'source_commit_sha' => (string)($baseline['source']['commit_sha'] ?? ''),
                'source_profile_hash' => (string)($baseline['source']['profile_hash'] ?? ''),
                'policy_hash' => $baselinePolicyHash,
            ],
            'current' => [
                'run_id' => InstrumentationContext::sanitizeIdentifier((string)($current['run_id'] ?? ''), 160),
                'commit_sha' => (string)($currentContext['commit_sha'] ?? ''),
                'profile_hash' => $profileHash,
                'policy_hash' => $currentPolicyHash,
            ],
            'compatibility' => $compatibility,
            'summary' => $counts + [
                'ambiguous' => count($ambiguous),
                'visible_results' => min(count($matched), $maxResults),
                'truncated_results' => $truncated,
            ],
            'global_metrics' => $globalMetrics,
            'queries' => array_slice($matched, 0, $maxResults),
            'new_queries' => array_slice($new, 0, $maxResults),
            'removed_queries' => array_slice($removed, 0, $maxResults),
            'ambiguous_queries' => array_slice($ambiguous, 0, $maxResults),
            'recommendations' => $recommendations,
            'limitations' => $limitations,
            'warnings' => array_values(array_unique(array_merge(
                (array)($compatibility['warnings'] ?? []),
                $policyWarnings
            ))),
            '_query_summaries' => $querySummaries,
        ];
    }

    /** @param array<string,mixed> $current */
    private static function assertRecoverableCurrent(array $current): void
    {
        $version = (int)($current['report_version'] ?? 0);
        if (!in_array($version, [1, MysqlProfileConfig::REPORT_VERSION], true)) {
            throw new MysqlQueryBaselineException(
                'Current profile report_version is incompatible.',
                '$.report_version',
                'current_profile_incompatible'
            );
        }
        if (strtolower((string)($current['engine'] ?? 'mysql')) !== 'mysql') {
            throw new MysqlQueryBaselineException(
                'Current profile engine is incompatible.',
                '$.engine',
                'current_profile_incompatible'
            );
        }
        if (!is_array($current['queries'] ?? null) || !is_array($current['summary'] ?? null)) {
            throw new MysqlQueryBaselineException(
                'Current profile is missing queries or summary.',
                '$',
                'current_profile_incompatible'
            );
        }
    }

    /** @param array<string,mixed> $current @return array<string,string> */
    private static function currentContext(array $current): array
    {
        $context = is_array($current['comparison_context'] ?? null)
            ? $current['comparison_context']
            : [];
        $context['engine'] = (string)($context['engine'] ?? $current['engine'] ?? 'mysql');
        $context['suite_id'] = (string)(
            $context['suite_id']
            ?? $current['suite_id']
            ?? $current['run_metadata']['suite_id']
            ?? ''
        );
        return MysqlQueryBaselineConfig::sanitizeComparisonContext($context);
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param array<string,mixed> $defaults
     * @param array<string,mixed> $compatibility
     * @return array<string,mixed>
     */
    private static function compareQuery(
        array $before,
        array $after,
        array $defaults,
        array $compatibility
    ): array {
        $tolerances = is_array($before['comparison'] ?? null)
            ? array_replace($defaults, $before['comparison'])
            : $defaults;
        $ignore = array_flip((array)($tolerances['ignore_metrics'] ?? []));
        $metrics = [];
        foreach (array_merge(self::COUNT_METRICS, self::TIME_METRICS) as $metric) {
            if (isset($ignore[$metric])) {
                continue;
            }
            $timingMetric = in_array($metric, self::TIME_METRICS, true);
            $metrics[] = self::metricResult(
                $metric,
                $before[$metric] ?? null,
                $after[$metric] ?? null,
                $timingMetric ? 'time' : 'calls',
                $tolerances,
                $timingMetric && empty($compatibility['timing_comparable']),
                (bool)($before['percentiles_approximate'] ?? false),
                (bool)($after['percentiles_approximate'] ?? false)
            );
        }

        $plan = MysqlQueryPlanNormalizer::compare(
            is_array($before['plan'] ?? null) ? $before['plan'] : [],
            is_array($after['plan'] ?? null) ? $after['plan'] : [],
            $tolerances
        );

        $overall = self::overallStatus($metrics, $plan, $compatibility);
        return [
            'identity' => (string)$after['identity'],
            'query_id' => (string)($after['query_id'] ?? ''),
            'query_ids' => array_values((array)($after['query_ids'] ?? [])),
            'fingerprint' => (string)($after['fingerprint'] ?? ''),
            'sample_sql' => (string)($after['sample_sql'] ?? ''),
            'overall_status' => $overall,
            'metric_results' => $metrics,
            'plan_result' => $plan,
            'classification' => [
                'baseline' => (string)($before['classification'] ?? ''),
                'current' => (string)($after['classification'] ?? ''),
                'changed' => (string)($before['classification'] ?? '') !== (string)($after['classification'] ?? ''),
            ],
            'capture_methods' => [
                'baseline' => (array)($before['capture_methods'] ?? []),
                'current' => (array)($after['capture_methods'] ?? []),
                'changed' => (array)($before['capture_methods'] ?? []) !== (array)($after['capture_methods'] ?? []),
            ],
            'contexts' => [
                'modules' => self::listDelta((array)($before['modules'] ?? []), (array)($after['modules'] ?? [])),
                'scenarios' => self::listDelta((array)($before['scenarios'] ?? []), (array)($after['scenarios'] ?? [])),
                'suites' => self::listDelta((array)($before['suites'] ?? []), (array)($after['suites'] ?? [])),
                'tests' => self::listDelta((array)($before['tests'] ?? []), (array)($after['tests'] ?? [])),
            ],
            'policy' => [
                'baseline_status' => (string)($before['current_policy_status'] ?? ''),
                'current_status' => (string)($after['current_policy_status'] ?? ''),
                'baseline_violations_count' => (int)($before['violations_count'] ?? 0),
                'current_violations_count' => (int)($after['violations_count'] ?? 0),
            ],
            'tolerances' => $tolerances,
        ];
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param array<string,mixed> $defaults
     * @param array<string,mixed> $compatibility
     * @return array<int,array<string,mixed>>
     */
    private static function compareGlobal(
        array $before,
        array $after,
        array $defaults,
        array $compatibility
    ): array {
        $out = [];
        foreach (array_merge(self::GLOBAL_COUNT_METRICS, self::GLOBAL_TIME_METRICS) as $metric) {
            $timingMetric = in_array($metric, self::GLOBAL_TIME_METRICS, true);
            $out[] = self::metricResult(
                $metric,
                $before[$metric] ?? null,
                $after[$metric] ?? null,
                $timingMetric ? 'time' : 'calls',
                $defaults,
                $timingMetric && empty($compatibility['timing_comparable']),
                false,
                false
            );
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $tolerances
     * @return array<string,mixed>
     */
    private static function metricResult(
        string $metric,
        mixed $baseline,
        mixed $current,
        string $kind,
        array $tolerances,
        bool $notComparable,
        bool $baselineApproximate,
        bool $currentApproximate
    ): array {
        if (!is_numeric($baseline) || !is_numeric($current)) {
            return [
                'metric' => $metric,
                'baseline' => is_numeric($baseline) ? (float)$baseline : null,
                'current' => is_numeric($current) ? (float)$current : null,
                'delta' => null,
                'delta_pct' => null,
                'direction' => 'unknown',
                'status' => 'insufficient_data',
                'confidence' => 'none',
                'reason' => 'missing_metric',
                'baseline_approximate' => $baselineApproximate,
                'current_approximate' => $currentApproximate,
            ];
        }

        $before = (float)$baseline;
        $after = (float)$current;
        $delta = $after - $before;
        $deltaPct = $before == 0.0 ? null : round(($delta / $before) * 100, 3);
        if ($notComparable) {
            return [
                'metric' => $metric,
                'baseline' => $before,
                'current' => $after,
                'delta' => round($delta, 3),
                'delta_pct' => $deltaPct,
                'direction' => self::direction($delta),
                'status' => 'structural_only',
                'confidence' => 'none',
                'reason' => 'incompatible_timing_context',
                'baseline_approximate' => $baselineApproximate,
                'current_approximate' => $currentApproximate,
            ];
        }

        $absThreshold = $kind === 'time'
            ? (float)($tolerances['time_regression_abs_ms'] ?? 5.0)
            : (float)($tolerances['calls_regression_abs'] ?? 1);
        $pctThreshold = $kind === 'time'
            ? (float)($tolerances['time_regression_pct'] ?? 20.0)
            : (float)($tolerances['calls_regression_pct'] ?? 10.0);
        $minimum = $kind === 'time'
            ? (float)($tolerances['minimum_time_ms_for_pct'] ?? 5.0)
            : 0.0;

        $largeEnough = abs($delta) >= $absThreshold;
        if ($kind === 'time' && max($before, $after) < $minimum) {
            $largeEnough = false;
        }
        $pctEnough = $deltaPct === null || abs($deltaPct) >= $pctThreshold;
        $significant = $largeEnough && $pctEnough;

        $status = 'unchanged';
        if ($significant && $delta > 0) {
            $status = 'regressed';
        } elseif ($significant && $delta < 0) {
            $status = 'improved';
        }

        $confidence = $baselineApproximate || $currentApproximate ? 'approximate' : 'measured';
        return [
            'metric' => $metric,
            'baseline' => $before,
            'current' => $after,
            'delta' => round($delta, 3),
            'delta_pct' => $deltaPct,
            'direction' => self::direction($delta),
            'status' => $status,
            'confidence' => $confidence,
            'reason' => $before == 0.0 ? 'baseline_zero' : ($significant ? 'threshold_exceeded' : 'within_tolerance'),
            'thresholds' => [
                'absolute' => $absThreshold,
                'percent' => $pctThreshold,
                'minimum_time_ms_for_pct' => $minimum,
            ],
            'baseline_approximate' => $baselineApproximate,
            'current_approximate' => $currentApproximate,
        ];
    }

    /** @param array<int,array<string,mixed>> $metrics @param array<string,mixed> $plan @param array<string,mixed> $compatibility */
    private static function overallStatus(array $metrics, array $plan, array $compatibility): string
    {
        if (($compatibility['status'] ?? '') === 'incompatible' && ($compatibility['comparison_scope'] ?? '') === 'none') {
            return 'incompatible_context';
        }
        $statuses = array_column($metrics, 'status');
        $planStatus = (string)($plan['status'] ?? 'insufficient_data');
        if (in_array('regressed', $statuses, true) || $planStatus === 'regressed') {
            return 'regressed';
        }
        if ($planStatus === 'plan_changed') {
            return 'plan_changed';
        }
        if (in_array('improved', $statuses, true) || $planStatus === 'improved') {
            return 'improved';
        }
        if (in_array('structural_only', $statuses, true)) {
            return 'structural_only';
        }
        if (in_array('insufficient_data', $statuses, true) || $planStatus === 'insufficient_data') {
            return 'insufficient_data';
        }
        return 'unchanged';
    }

    /** @param array<string,mixed> $query @param array<string,mixed> $compatibility */
    private static function newQuery(array $query, array $compatibility): array
    {
        $severity = 'info';
        $classification = (string)($query['classification'] ?? '');
        $flags = (array)($query['plan']['flags'] ?? []);
        $violations = (int)($query['violations_count'] ?? 0);
        if (in_array($classification, ['slow', 'hotspot'], true) || in_array('full_table_scan', $flags, true) || $violations > 0) {
            $severity = 'warning';
        }
        return [
            'identity' => (string)$query['identity'],
            'query_id' => (string)($query['query_id'] ?? ''),
            'fingerprint' => (string)($query['fingerprint'] ?? ''),
            'sample_sql' => (string)($query['sample_sql'] ?? ''),
            'status' => 'new',
            'severity' => $severity,
            'classification' => $classification,
            'plan' => (array)($query['plan'] ?? []),
            'contexts' => [
                'modules' => (array)($query['modules'] ?? []),
                'scenarios' => (array)($query['scenarios'] ?? []),
                'suites' => (array)($query['suites'] ?? []),
                'tests' => (array)($query['tests'] ?? []),
            ],
            'reason' => ($compatibility['comparison_scope'] ?? '') === 'none'
                ? 'incompatible_context'
                : 'not_present_in_baseline',
        ];
    }

    /** @param array<string,mixed> $query @param array<string,mixed> $compatibility */
    private static function removedQuery(array $query, array $compatibility): array
    {
        return [
            'identity' => (string)$query['identity'],
            'query_id' => (string)($query['query_id'] ?? ''),
            'fingerprint' => (string)($query['fingerprint'] ?? ''),
            'sample_sql' => (string)($query['sample_sql'] ?? ''),
            'status' => 'removed',
            'severity' => 'info',
            'contexts' => [
                'modules' => (array)($query['modules'] ?? []),
                'scenarios' => (array)($query['scenarios'] ?? []),
                'suites' => (array)($query['suites'] ?? []),
                'tests' => (array)($query['tests'] ?? []),
            ],
            'reason' => ($compatibility['comparison_scope'] ?? '') === 'none'
                ? 'incompatible_context'
                : 'not_observed_in_current_run',
            'limitations' => [
                'The query may be absent because its test or scenario was not executed.',
                'Removal is not classified as an automatic improvement.',
            ],
        ];
    }

    /** @param array<string,mixed> $query */
    private static function ambiguousRow(array $query, string $side): array
    {
        return [
            'identity' => (string)($query['identity'] ?? ''),
            'side' => $side,
            'fingerprint' => (string)($query['fingerprint'] ?? ''),
            'query_ids' => array_values((array)($query['query_ids'] ?? [])),
            'status' => 'ambiguous',
            'reason' => 'multiple_query_ids_for_fingerprint',
        ];
    }

    /** @param array<int,string> $before @param array<int,string> $after */
    private static function listDelta(array $before, array $after): array
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));
        sort($added, SORT_STRING);
        sort($removed, SORT_STRING);
        return [
            'baseline' => array_values($before),
            'current' => array_values($after),
            'added' => $added,
            'removed' => $removed,
            'changed' => $added !== [] || $removed !== [],
        ];
    }

    private static function direction(float $delta): string
    {
        return $delta > 0 ? 'increased' : ($delta < 0 ? 'decreased' : 'unchanged');
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,array<string,mixed>> */
    private static function indexByIdentity(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && (string)($row['identity'] ?? '') !== '') {
                $out[(string)$row['identity']] = $row;
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function sortComparisons(array $rows): array
    {
        $rank = [
            'regressed' => 0,
            'plan_changed' => 1,
            'improved' => 2,
            'insufficient_data' => 3,
            'structural_only' => 4,
            'unchanged' => 5,
            'incompatible_context' => 6,
        ];
        usort($rows, static function (array $a, array $b) use ($rank): int {
            $left = $rank[(string)($a['overall_status'] ?? '')] ?? 99;
            $right = $rank[(string)($b['overall_status'] ?? '')] ?? 99;
            return $left <=> $right ?: strcmp((string)$a['identity'], (string)$b['identity']);
        });
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function sortByIdentity(array $rows): array
    {
        usort($rows, static fn(array $a, array $b): int => strcmp((string)$a['identity'], (string)$b['identity']));
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function uniqueAmbiguous(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = (string)($row['side'] ?? '') . '|' . (string)($row['identity'] ?? '');
            $out[$key] = $row;
        }
        ksort($out, SORT_STRING);
        return array_values($out);
    }

    /**
     * @param array<int,array<string,mixed>> $matched
     * @param array<int,array<string,mixed>> $new
     * @param array<int,array<string,mixed>> $removed
     * @param array<string,mixed> $compatibility
     * @return array<int,array<string,string>>
     */
    private static function recommendations(
        array $matched,
        array $new,
        array $removed,
        array $compatibility
    ): array {
        $out = [];
        if (empty($compatibility['timing_comparable'])) {
            $out[] = [
                'code' => 'restore_compatible_context',
                'message' => 'Align engine, dataset, environment, and suite metadata before interpreting latency deltas.',
            ];
        }
        foreach ($matched as $row) {
            if (($row['overall_status'] ?? '') === 'regressed') {
                $out[] = [
                    'code' => 'review_regressed_query',
                    'message' => 'Review metrics and normalized plan evidence for ' . (string)$row['identity'] . '.',
                ];
            }
            if (count($out) >= 20) {
                break;
            }
        }
        if ($new !== []) {
            $out[] = [
                'code' => 'review_new_queries',
                'message' => 'Review new queries before deciding whether a new baseline is appropriate.',
            ];
        }
        if ($removed !== []) {
            $out[] = [
                'code' => 'verify_removed_query_coverage',
                'message' => 'Confirm that removed queries were intentionally eliminated and not skipped by test coverage.',
            ];
        }
        return array_slice($out, 0, 20);
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $baseline */
    private static function generatedAt(array $current, array $baseline): string
    {
        foreach ([
            $current['finished_at'] ?? '',
            $current['started_at'] ?? '',
            $baseline['created_at'] ?? '',
        ] as $value) {
            $timestamp = strtotime((string)$value);
            if ($timestamp !== false) {
                return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
            }
        }
        return '1970-01-01T00:00:00Z';
    }
}
