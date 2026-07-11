<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Gate;

final class MysqlQueryGateFindingNormalizer
{
    private const TIME_METRICS = [
        'min_ms', 'avg_ms', 'max_ms', 'total_ms', 'total_sql_time_ms',
        'p50_ms', 'p95_ms', 'p99_ms', 'standard_deviation_ms',
    ];

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $policyReport
     * @param array<int,array<string,mixed>> $comparisons
     * @return array{findings:array<int,array<string,mixed>>,evidence_runs:array<int,array<string,mixed>>,inputs:array<string,mixed>,limitations:array<int,string>}
     */
    public static function normalize(array $profile, array $policyReport = [], array $comparisons = []): array
    {
        self::assertProfile($profile);
        $profileHash = (string)($profile['_artifact_hash'] ?? MysqlQueryGateArtifactWriter::payloadHash($profile));
        $profilePath = (string)($profile['_artifact_path'] ?? 'mysql_profile_latest.json');
        $profileRun = self::profileRun($profile, $profileHash);
        $findings = [];
        $limitations = [];

        foreach ((array)($profile['instrumentation']['findings'] ?? []) as $index => $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $findings[] = self::instrumentationFinding($finding, $index, $profile, $profilePath, $profileRun);
        }

        $evaluation = self::policyEvaluation($profile, $policyReport);
        if ($evaluation !== []) {
            foreach ((array)($evaluation['results'] ?? []) as $index => $result) {
                if (!is_array($result)) {
                    continue;
                }
                $status = (string)($result['status'] ?? '');
                if ($status === 'violation') {
                    $findings[] = self::policyViolation($result, $index, $evaluation, $profile, $profilePath, $profileRun);
                } elseif (in_array($status, ['insufficient_data', 'not_evaluated', 'legacy_report'], true)) {
                    $findings[] = self::insufficientPolicyFinding($result, $index, $evaluation, $profile, $profilePath, $profileRun);
                } elseif ($status === 'invalid_policy') {
                    $findings[] = self::invalidEvidenceFinding(
                        'policy_evaluation',
                        (string)($result['error_code'] ?? 'invalid_policy'),
                        (string)($result['message'] ?? 'Embedded policy evidence is invalid.'),
                        $profilePath,
                        $profileRun
                    );
                }
            }
        }

        $evidenceRuns = [];
        foreach ($comparisons as $index => $comparison) {
            if (!is_array($comparison)) {
                continue;
            }
            $normalizedComparison = self::unwrapComparison($comparison);
            if ($normalizedComparison === []) {
                $findings[] = self::invalidEvidenceFinding(
                    'baseline_comparison',
                    'invalid_comparison_artifact',
                    'Comparison artifact is missing mysql-query-comparison-report-v1.',
                    (string)($comparison['_artifact_path'] ?? ''),
                    $profileRun
                );
                continue;
            }
            $run = self::comparisonRun($normalizedComparison, $comparison, $index);
            $evidenceRuns[] = $run;
            $findings = array_merge($findings, self::comparisonFindings($normalizedComparison, $run));
        }

        if ($comparisons === [] && is_array($profile['baseline_comparison'] ?? null) && !empty($profile['baseline_comparison']['enabled'])) {
            $embedded = (array)$profile['baseline_comparison'];
            $status = (string)($embedded['status'] ?? '');
            if (in_array($status, ['invalid_baseline', 'artifact_error'], true)) {
                $findings[] = self::invalidEvidenceFinding(
                    'baseline_comparison',
                    $status,
                    'Embedded baseline comparison is invalid.',
                    (string)($embedded['report_path'] ?? ''),
                    $profileRun
                );
            } elseif ($status === 'incompatible_context') {
                $findings[] = self::makeContextFinding($embedded, $profilePath, $profileRun);
            } else {
                $limitations[] = 'Embedded baseline_comparison is compact; pass a full comparison artifact for per-query enforcement.';
            }
        }

        if ($evidenceRuns === []) {
            $evidenceRuns[] = $profileRun;
        }

        usort($findings, static function (array $a, array $b): int {
            return [
                (string)($a['finding_id'] ?? ''),
                (string)($a['evidence']['artifact_hash'] ?? ''),
            ] <=> [
                (string)($b['finding_id'] ?? ''),
                (string)($b['evidence']['artifact_hash'] ?? ''),
            ];
        });

        return [
            'findings' => $findings,
            'evidence_runs' => self::uniqueRuns($evidenceRuns),
            'inputs' => [
                'profile_hash' => $profileHash,
                'policy_hash' => (string)($evaluation['policy_file_hash'] ?? $profile['policy_evaluation']['policy_file_hash'] ?? ''),
                'baseline_hash' => self::firstBaselineHash($evidenceRuns),
                'comparison_hashes' => array_values(array_filter(array_map(
                    static fn(array $run): string => (string)($run['artifact_hash'] ?? ''),
                    $evidenceRuns
                ))),
            ],
            'limitations' => array_values(array_unique($limitations)),
        ];
    }

    /** @param array<string,mixed> $profile */
    private static function assertProfile(array $profile): void
    {
        $schema = (string)($profile['schema_version'] ?? '');
        $version = (int)($profile['report_version'] ?? 0);
        if ($schema !== 'mysql-query-profile-report-v2' && $version !== 1) {
            throw new MysqlQueryGateException(
                'Profile input is not a recoverable MySQL query profile.',
                '$.schema_version',
                'incompatible_profile_schema',
                MysqlQueryGateConfig::EXIT_INCOMPATIBLE_INPUT
            );
        }
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    private static function profileRun(array $profile, string $profileHash): array
    {
        $context = is_array($profile['comparison_context'] ?? null) ? $profile['comparison_context'] : [];
        return [
            'artifact_hash' => $profileHash,
            'artifact_path' => (string)($profile['_artifact_path'] ?? 'mysql_profile_latest.json'),
            'source' => 'profile',
            'run_id' => (string)($profile['run_id'] ?? ''),
            'generated_at' => (string)($profile['finished_at'] ?? $profile['started_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
            'baseline_hash' => '',
            'policy_hash' => (string)($profile['policy_evaluation']['policy_file_hash'] ?? ''),
            'dataset_id' => (string)($context['dataset_id'] ?? ''),
            'dataset_version' => (string)($context['dataset_version'] ?? ''),
            'dataset_hash' => (string)($context['dataset_hash'] ?? ''),
            'environment_id' => (string)($context['environment_id'] ?? ''),
            'suite_id' => (string)($context['suite_id'] ?? $profile['suite_id'] ?? ''),
            'compatibility_status' => 'profile_only',
            'comparison_scope' => 'none',
            'timing_comparable' => false,
        ];
    }

    /** @param array<string,mixed> $finding @param array<string,mixed> $profile @param array<string,mixed> $run */
    private static function instrumentationFinding(array $finding, int $index, array $profile, string $artifact, array $run): array
    {
        $code = (string)($finding['code'] ?? $finding['id'] ?? 'instrumentation_finding_' . $index);
        $category = preg_match('/bypass|uninstrumented|without_connection|direct_query|legacy_connection/i', $code) === 1
            ? 'instrumentation.bypass'
            : 'instrumentation.integrity';
        $context = is_array($finding['context'] ?? null) ? $finding['context'] : [];
        $location = self::locationFrom($finding, $context);
        return MysqlQueryGateFinding::make([
            'category' => $category,
            'subcategory' => $code,
            'source' => 'instrumentation',
            'source_artifact' => $artifact,
            'source_finding_id' => $code,
            'query_identity' => (string)($context['query_identity'] ?? ''),
            'query_id' => (string)($context['query_id'] ?? ''),
            'fingerprint_hash' => self::fingerprintHash((string)($context['fingerprint'] ?? '')),
            'module_id' => (string)($context['module_id'] ?? ''),
            'scenario_id' => (string)($context['scenario_id'] ?? ''),
            'suite_id' => (string)($context['suite_id'] ?? $profile['suite_id'] ?? ''),
            'test_id' => (string)($context['test_id'] ?? ''),
            'severity' => self::severity((string)($finding['severity'] ?? ($category === 'instrumentation.bypass' ? 'error' : 'warning'))),
            'confidence' => 'high',
            'stability_type' => 'structural',
            'message' => (string)($finding['message'] ?? $finding['summary'] ?? 'Instrumentation finding.'),
            'location' => $location,
            'evidence' => self::evidence($run, [
                'code' => $code,
                'recommendation' => (string)($finding['recommendation'] ?? ''),
            ]),
        ]);
    }

    /** @param array<string,mixed> $result @param array<string,mixed> $evaluation @param array<string,mixed> $profile @param array<string,mixed> $run */
    private static function policyViolation(array $result, int $index, array $evaluation, array $profile, string $artifact, array $run): array
    {
        $selector = is_array($result['selector'] ?? null) ? $result['selector'] : [];
        $queryIds = array_values(array_filter((array)($result['query_ids'] ?? []), 'is_string'));
        $queryId = (string)($queryIds[0] ?? '');
        $fingerprint = (string)($result['fingerprint'] ?? '');
        $metric = (string)($result['budget_key'] ?? '');
        $temporal = self::isTimeMetric($metric);
        $sampleCount = self::sampleCountFor($profile, $fingerprint);
        return MysqlQueryGateFinding::make([
            'category' => 'policy.violation',
            'subcategory' => $metric,
            'source' => 'policy_evaluation',
            'source_artifact' => $artifact,
            'source_finding_id' => (string)($result['policy_id'] ?? 'policy') . ':' . $metric . ':' . $index,
            'query_identity' => $queryId !== '' ? 'query_id:' . $queryId : self::queryIdentity($fingerprint),
            'query_id' => $queryId,
            'fingerprint_hash' => self::fingerprintHash($fingerprint),
            'policy_id' => (string)($result['policy_id'] ?? $result['source_policy_id'] ?? ''),
            'module_id' => self::firstSelector($selector, 'module_id'),
            'scenario_id' => self::firstSelector($selector, 'scenario_id'),
            'suite_id' => self::firstSelector($selector, 'suite_id') ?: (string)($profile['suite_id'] ?? ''),
            'test_id' => self::firstSelector($selector, 'test_id'),
            'metric' => $metric,
            'plan_flag' => in_array($metric, ['forbid_flags', 'forbid_access_types', 'require_any_key', 'require_keys'], true)
                ? self::firstScalar($result['actual'] ?? '')
                : '',
            'severity' => self::severity((string)($result['severity'] ?? 'warning')),
            'confidence' => 'high',
            'stability_type' => $temporal ? 'temporal' : 'structural',
            'message' => 'Policy ' . (string)($result['policy_id'] ?? '') . ' violated ' . $metric . '.',
            'location' => ['path' => self::firstSelector($selector, 'test_id')],
            'evidence' => self::evidence($run, [
                'policy_hash' => (string)($evaluation['policy_file_hash'] ?? ''),
                'actual' => $result['actual'] ?? null,
                'expected' => $result['expected'] ?? null,
                'operator' => (string)($result['operator'] ?? ''),
                'delta' => $result['delta'] ?? null,
                'unit' => (string)($result['unit'] ?? ''),
                'evidence_path' => (string)($result['evidence_path'] ?? ''),
                'sample_count' => $sampleCount,
            ]),
        ]);
    }

    /** @param array<string,mixed> $result @param array<string,mixed> $evaluation @param array<string,mixed> $profile @param array<string,mixed> $run */
    private static function insufficientPolicyFinding(array $result, int $index, array $evaluation, array $profile, string $artifact, array $run): array
    {
        $selector = is_array($result['selector'] ?? null) ? $result['selector'] : [];
        $fingerprint = (string)($result['fingerprint'] ?? '');
        return MysqlQueryGateFinding::make([
            'category' => 'evidence.insufficient',
            'subcategory' => (string)($result['reason'] ?? 'policy_insufficient_data'),
            'source' => 'policy_evaluation',
            'source_artifact' => $artifact,
            'source_finding_id' => 'policy_insufficient_' . $index,
            'query_identity' => self::queryIdentity($fingerprint),
            'fingerprint_hash' => self::fingerprintHash($fingerprint),
            'policy_id' => (string)($result['policy_id'] ?? ''),
            'module_id' => self::firstSelector($selector, 'module_id'),
            'scenario_id' => self::firstSelector($selector, 'scenario_id'),
            'suite_id' => self::firstSelector($selector, 'suite_id') ?: (string)($profile['suite_id'] ?? ''),
            'test_id' => self::firstSelector($selector, 'test_id'),
            'metric' => (string)($result['budget_key'] ?? ''),
            'severity' => 'warning',
            'confidence' => 'high',
            'stability_type' => 'none',
            'message' => 'Policy evidence is insufficient for ' . (string)($result['budget_key'] ?? 'budget') . '.',
            'evidence' => self::evidence($run, [
                'policy_hash' => (string)($evaluation['policy_file_hash'] ?? ''),
                'reason' => (string)($result['reason'] ?? ''),
                'evidence_path' => (string)($result['evidence_path'] ?? ''),
            ]),
        ]);
    }

    /** @param array<string,mixed> $comparison @param array<string,mixed> $run @return array<int,array<string,mixed>> */
    private static function comparisonFindings(array $comparison, array $run): array
    {
        $out = [];
        $compatibility = is_array($comparison['compatibility'] ?? null) ? $comparison['compatibility'] : [];
        $scope = (string)($compatibility['comparison_scope'] ?? 'none');
        if (($compatibility['status'] ?? '') !== 'compatible' || $scope !== 'full') {
            $out[] = self::makeContextFinding($comparison, (string)($run['artifact_path'] ?? ''), $run);
        }

        foreach ((array)($comparison['global_metrics'] ?? []) as $index => $metric) {
            if (is_array($metric) && ($metric['status'] ?? '') === 'regressed') {
                $out[] = self::metricRegression($metric, ['identity' => 'global', 'contexts' => []], $index, $run, $scope);
            }
        }
        foreach ((array)($comparison['queries'] ?? []) as $queryIndex => $query) {
            if (!is_array($query)) {
                continue;
            }
            foreach ((array)($query['metric_results'] ?? []) as $metricIndex => $metric) {
                if (!is_array($metric)) {
                    continue;
                }
                if (($metric['status'] ?? '') === 'regressed') {
                    $out[] = self::metricRegression($metric, $query, $metricIndex, $run, $scope);
                } elseif (in_array((string)($metric['status'] ?? ''), ['insufficient_data', 'structural_only'], true)) {
                    $out[] = self::insufficientComparisonFinding($metric, $query, $metricIndex, $run);
                }
            }
            $plan = is_array($query['plan_result'] ?? null) ? $query['plan_result'] : [];
            if (($plan['status'] ?? '') === 'regressed') {
                $out = array_merge($out, self::planRegressions($plan, $query, $run, $scope));
            } elseif (($plan['status'] ?? '') === 'insufficient_data') {
                $out[] = self::insufficientComparisonFinding(
                    ['metric' => 'plan', 'reason' => (string)($plan['reason'] ?? 'explain_unavailable')],
                    $query,
                    0,
                    $run
                );
            }
        }
        foreach ((array)($comparison['new_queries'] ?? []) as $index => $query) {
            if (!is_array($query)) {
                continue;
            }
            $out[] = self::queryPresenceFinding('baseline.new_query', $query, $index, $run);
        }
        foreach ((array)($comparison['removed_queries'] ?? []) as $index => $query) {
            if (!is_array($query)) {
                continue;
            }
            $out[] = self::queryPresenceFinding('baseline.removed_query', $query, $index, $run);
        }
        return $out;
    }

    /** @param array<string,mixed> $metric @param array<string,mixed> $query @param array<string,mixed> $run */
    private static function metricRegression(array $metric, array $query, int $index, array $run, string $scope): array
    {
        $name = (string)($metric['metric'] ?? 'metric');
        $identity = (string)($query['identity'] ?? 'global');
        $queryId = (string)($query['query_id'] ?? '');
        $fingerprint = (string)($query['fingerprint'] ?? '');
        $contexts = self::currentContexts($query);
        $sampleCount = self::queryCurrentSampleCount($query, $name);
        return MysqlQueryGateFinding::make([
            'category' => self::isTimeMetric($name) ? 'baseline.temporal_regression' : 'baseline.structural_regression',
            'subcategory' => $name,
            'source' => 'baseline_comparison',
            'source_artifact' => (string)($run['artifact_path'] ?? ''),
            'source_finding_id' => 'metric:' . $identity . ':' . $name . ':' . $index,
            'query_identity' => $identity,
            'query_id' => $queryId,
            'fingerprint_hash' => self::fingerprintHash($fingerprint),
            'module_id' => (string)($contexts['module_id'] ?? ''),
            'scenario_id' => (string)($contexts['scenario_id'] ?? ''),
            'suite_id' => (string)($contexts['suite_id'] ?? $run['suite_id'] ?? ''),
            'test_id' => (string)($contexts['test_id'] ?? ''),
            'metric' => $name,
            'severity' => self::isTimeMetric($name) ? 'warning' : 'warning',
            'confidence' => ($metric['confidence'] ?? '') === 'measured' ? 'high' : 'medium',
            'stability_type' => self::isTimeMetric($name) ? 'temporal' : 'structural',
            'message' => $identity . ' regressed for ' . $name . '.',
            'location' => ['path' => (string)($contexts['test_id'] ?? '')],
            'evidence' => self::evidence($run, [
                'baseline' => $metric['baseline'] ?? null,
                'current' => $metric['current'] ?? null,
                'delta' => $metric['delta'] ?? null,
                'delta_pct' => $metric['delta_pct'] ?? null,
                'thresholds' => is_array($metric['thresholds'] ?? null) ? $metric['thresholds'] : [],
                'sample_count' => $sampleCount,
                'comparison_scope' => $scope,
            ]),
        ]);
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $query @param array<string,mixed> $run @return array<int,array<string,mixed>> */
    private static function planRegressions(array $plan, array $query, array $run, string $scope): array
    {
        $events = [];
        foreach ((array)($plan['added_flags'] ?? []) as $flag) {
            $events[] = ['subcategory' => 'added_flag', 'plan_flag' => (string)$flag, 'message' => 'Plan flag added: ' . (string)$flag];
        }
        foreach ((array)($plan['removed_keys'] ?? []) as $key) {
            $events[] = ['subcategory' => 'removed_key', 'plan_flag' => (string)$key, 'message' => 'Index removed from plan: ' . (string)$key];
        }
        foreach ((array)($plan['access_type_changes'] ?? []) as $change) {
            if (is_array($change) && ($change['direction'] ?? '') === 'regressed') {
                $events[] = [
                    'subcategory' => 'access_type_regression',
                    'plan_flag' => (string)($change['baseline'] ?? '') . '_to_' . (string)($change['current'] ?? ''),
                    'message' => 'Access type regressed from ' . (string)($change['baseline'] ?? '') . ' to ' . (string)($change['current'] ?? '') . '.',
                ];
            }
        }
        $rows = is_array($plan['estimated_rows'] ?? null) ? $plan['estimated_rows'] : [];
        if (is_numeric($rows['delta'] ?? null) && (float)$rows['delta'] > 0) {
            $events[] = ['subcategory' => 'estimated_rows_regression', 'plan_flag' => 'estimated_rows', 'message' => 'Estimated rows increased.'];
        }
        if ($events === []) {
            $events[] = ['subcategory' => 'plan_regression', 'plan_flag' => '', 'message' => 'Normalized execution plan regressed.'];
        }
        $out = [];
        $contexts = self::currentContexts($query);
        foreach ($events as $index => $event) {
            $out[] = MysqlQueryGateFinding::make([
                'category' => 'baseline.plan_regression',
                'subcategory' => $event['subcategory'],
                'source' => 'baseline_comparison',
                'source_artifact' => (string)($run['artifact_path'] ?? ''),
                'source_finding_id' => 'plan:' . (string)($query['identity'] ?? '') . ':' . $event['subcategory'] . ':' . $index,
                'query_identity' => (string)($query['identity'] ?? ''),
                'query_id' => (string)($query['query_id'] ?? ''),
                'fingerprint_hash' => self::fingerprintHash((string)($query['fingerprint'] ?? '')),
                'module_id' => (string)($contexts['module_id'] ?? ''),
                'scenario_id' => (string)($contexts['scenario_id'] ?? ''),
                'suite_id' => (string)($contexts['suite_id'] ?? $run['suite_id'] ?? ''),
                'test_id' => (string)($contexts['test_id'] ?? ''),
                'metric' => $event['subcategory'],
                'plan_flag' => $event['plan_flag'],
                'severity' => 'error',
                'confidence' => 'high',
                'stability_type' => 'structural',
                'message' => $event['message'],
                'location' => ['path' => (string)($contexts['test_id'] ?? '')],
                'evidence' => self::evidence($run, [
                    'comparison_scope' => $scope,
                    'baseline_signature' => (string)($plan['baseline_signature'] ?? ''),
                    'current_signature' => (string)($plan['current_signature'] ?? ''),
                    'added_flags' => (array)($plan['added_flags'] ?? []),
                    'removed_keys' => (array)($plan['removed_keys'] ?? []),
                    'access_type_changes' => (array)($plan['access_type_changes'] ?? []),
                    'estimated_rows' => $rows,
                ]),
            ]);
        }
        return $out;
    }

    /** @param array<string,mixed> $query @param array<string,mixed> $run */
    private static function queryPresenceFinding(string $category, array $query, int $index, array $run): array
    {
        $contexts = self::simpleContexts($query);
        return MysqlQueryGateFinding::make([
            'category' => $category,
            'subcategory' => (string)($query['reason'] ?? ''),
            'source' => 'baseline_comparison',
            'source_artifact' => (string)($run['artifact_path'] ?? ''),
            'source_finding_id' => ($category === 'baseline.new_query' ? 'new:' : 'removed:') . (string)($query['identity'] ?? '') . ':' . $index,
            'query_identity' => (string)($query['identity'] ?? ''),
            'query_id' => (string)($query['query_id'] ?? ''),
            'fingerprint_hash' => self::fingerprintHash((string)($query['fingerprint'] ?? '')),
            'module_id' => (string)($contexts['module_id'] ?? ''),
            'scenario_id' => (string)($contexts['scenario_id'] ?? ''),
            'suite_id' => (string)($contexts['suite_id'] ?? $run['suite_id'] ?? ''),
            'test_id' => (string)($contexts['test_id'] ?? ''),
            'severity' => (string)($query['severity'] ?? ($category === 'baseline.new_query' ? 'warning' : 'info')),
            'confidence' => 'high',
            'stability_type' => 'structural',
            'message' => $category === 'baseline.new_query' ? 'New query identity observed.' : 'Baseline query identity was not observed.',
            'location' => ['path' => (string)($contexts['test_id'] ?? '')],
            'evidence' => self::evidence($run, [
                'reason' => (string)($query['reason'] ?? ''),
                'classification' => (string)($query['classification'] ?? ''),
            ]),
        ]);
    }

    /** @param array<string,mixed> $metric @param array<string,mixed> $query @param array<string,mixed> $run */
    private static function insufficientComparisonFinding(array $metric, array $query, int $index, array $run): array
    {
        $contexts = self::currentContexts($query);
        return MysqlQueryGateFinding::make([
            'category' => 'evidence.insufficient',
            'subcategory' => (string)($metric['reason'] ?? 'comparison_insufficient_data'),
            'source' => 'baseline_comparison',
            'source_artifact' => (string)($run['artifact_path'] ?? ''),
            'source_finding_id' => 'comparison_insufficient:' . (string)($query['identity'] ?? 'global') . ':' . (string)($metric['metric'] ?? '') . ':' . $index,
            'query_identity' => (string)($query['identity'] ?? 'global'),
            'query_id' => (string)($query['query_id'] ?? ''),
            'fingerprint_hash' => self::fingerprintHash((string)($query['fingerprint'] ?? '')),
            'module_id' => (string)($contexts['module_id'] ?? ''),
            'scenario_id' => (string)($contexts['scenario_id'] ?? ''),
            'suite_id' => (string)($contexts['suite_id'] ?? $run['suite_id'] ?? ''),
            'test_id' => (string)($contexts['test_id'] ?? ''),
            'metric' => (string)($metric['metric'] ?? ''),
            'severity' => 'warning',
            'confidence' => 'high',
            'stability_type' => 'none',
            'message' => 'Comparison evidence is insufficient.',
            'evidence' => self::evidence($run, [
                'reason' => (string)($metric['reason'] ?? ''),
                'status' => (string)($metric['status'] ?? ''),
            ]),
        ]);
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $run */
    private static function makeContextFinding(array $payload, string $artifact, array $run): array
    {
        $compatibility = is_array($payload['compatibility'] ?? null) ? $payload['compatibility'] : [];
        return MysqlQueryGateFinding::make([
            'category' => 'baseline.incompatible_context',
            'subcategory' => (string)($compatibility['status'] ?? $payload['status'] ?? 'incompatible_context'),
            'source' => 'baseline_comparison',
            'source_artifact' => $artifact,
            'source_finding_id' => 'compatibility:' . (string)($payload['comparison_id'] ?? $run['artifact_hash'] ?? ''),
            'suite_id' => (string)($run['suite_id'] ?? ''),
            'severity' => 'warning',
            'confidence' => 'high',
            'stability_type' => 'none',
            'message' => 'Baseline comparison context is not fully compatible.',
            'evidence' => self::evidence($run, [
                'status' => (string)($compatibility['status'] ?? ''),
                'comparison_scope' => (string)($compatibility['comparison_scope'] ?? ''),
                'timing_comparable' => (bool)($compatibility['timing_comparable'] ?? false),
                'reasons' => (array)($compatibility['reasons'] ?? []),
            ]),
        ]);
    }

    /** @param array<string,mixed> $run */
    private static function invalidEvidenceFinding(string $source, string $code, string $message, string $artifact, array $run): array
    {
        return MysqlQueryGateFinding::make([
            'category' => 'evidence.invalid',
            'subcategory' => $code,
            'source' => $source,
            'source_artifact' => $artifact,
            'source_finding_id' => $code,
            'suite_id' => (string)($run['suite_id'] ?? ''),
            'severity' => 'error',
            'confidence' => 'high',
            'stability_type' => 'none',
            'message' => $message,
            'evidence' => self::evidence($run, ['error_code' => $code]),
        ]);
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $policyReport @return array<string,mixed> */
    private static function policyEvaluation(array $profile, array $policyReport): array
    {
        if (is_array($policyReport['policy_evaluation'] ?? null)) {
            return $policyReport['policy_evaluation'];
        }
        return is_array($profile['policy_evaluation'] ?? null) ? $profile['policy_evaluation'] : [];
    }

    /** @param array<string,mixed> $comparison @return array<string,mixed> */
    private static function unwrapComparison(array $comparison): array
    {
        if (($comparison['schema_version'] ?? '') === 'mysql-query-comparison-report-v1') {
            return $comparison;
        }
        if (is_array($comparison['baseline_comparison'] ?? null)
            && ($comparison['baseline_comparison']['schema_version'] ?? '') === 'mysql-query-comparison-report-v1') {
            return $comparison['baseline_comparison'];
        }
        return [];
    }

    /** @param array<string,mixed> $comparison @param array<string,mixed> $raw @return array<string,mixed> */
    private static function comparisonRun(array $comparison, array $raw, int $index): array
    {
        $compatibility = is_array($comparison['compatibility'] ?? null) ? $comparison['compatibility'] : [];
        $checks = is_array($compatibility['checks'] ?? null) ? $compatibility['checks'] : [];
        $hash = (string)($raw['_artifact_hash'] ?? MysqlQueryGateArtifactWriter::payloadHash($comparison));
        return [
            'artifact_hash' => $hash,
            'artifact_path' => (string)($raw['_artifact_path'] ?? 'mysql_comparison_' . $index . '.json'),
            'source' => 'comparison',
            'run_id' => (string)($comparison['current']['run_id'] ?? ''),
            'generated_at' => (string)($comparison['generated_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
            'baseline_hash' => (string)($comparison['baseline']['hash'] ?? ''),
            'policy_hash' => (string)($comparison['current']['policy_hash'] ?? ''),
            'dataset_id' => self::currentCheck($checks, 'dataset_id'),
            'dataset_version' => self::currentCheck($checks, 'dataset_version'),
            'dataset_hash' => self::currentCheck($checks, 'dataset_hash'),
            'environment_id' => self::currentCheck($checks, 'environment_id'),
            'suite_id' => self::currentCheck($checks, 'suite_id'),
            'compatibility_status' => (string)($compatibility['status'] ?? ''),
            'comparison_scope' => (string)($compatibility['comparison_scope'] ?? 'none'),
            'timing_comparable' => (bool)($compatibility['timing_comparable'] ?? false),
        ];
    }

    /** @param array<string,mixed> $checks */
    private static function currentCheck(array $checks, string $key): string
    {
        return is_array($checks[$key] ?? null) ? (string)($checks[$key]['current'] ?? '') : '';
    }

    /** @param array<string,mixed> $run @param array<string,mixed> $extra @return array<string,mixed> */
    private static function evidence(array $run, array $extra): array
    {
        return array_merge([
            'artifact_hash' => (string)($run['artifact_hash'] ?? ''),
            'run_id' => (string)($run['run_id'] ?? ''),
            'generated_at' => (string)($run['generated_at'] ?? ''),
            'baseline_hash' => (string)($run['baseline_hash'] ?? ''),
            'policy_hash' => (string)($run['policy_hash'] ?? ''),
            'dataset_id' => (string)($run['dataset_id'] ?? ''),
            'dataset_version' => (string)($run['dataset_version'] ?? ''),
            'dataset_hash' => (string)($run['dataset_hash'] ?? ''),
            'environment_id' => (string)($run['environment_id'] ?? ''),
            'suite_id' => (string)($run['suite_id'] ?? ''),
            'compatibility_status' => (string)($run['compatibility_status'] ?? ''),
            'comparison_scope' => (string)($run['comparison_scope'] ?? ''),
            'timing_comparable' => (bool)($run['timing_comparable'] ?? false),
        ], $extra);
    }

    /** @param array<string,mixed> $query @return array<string,string> */
    private static function currentContexts(array $query): array
    {
        $contexts = is_array($query['contexts'] ?? null) ? $query['contexts'] : [];
        $out = [];
        foreach (['modules' => 'module_id', 'scenarios' => 'scenario_id', 'suites' => 'suite_id', 'tests' => 'test_id'] as $key => $target) {
            $section = is_array($contexts[$key] ?? null) ? $contexts[$key] : [];
            $current = (array)($section['current'] ?? $section);
            $out[$target] = (string)($current[0] ?? '');
        }
        return $out;
    }

    /** @param array<string,mixed> $query @return array<string,string> */
    private static function simpleContexts(array $query): array
    {
        $contexts = is_array($query['contexts'] ?? null) ? $query['contexts'] : [];
        return [
            'module_id' => (string)($contexts['modules'][0] ?? ''),
            'scenario_id' => (string)($contexts['scenarios'][0] ?? ''),
            'suite_id' => (string)($contexts['suites'][0] ?? ''),
            'test_id' => (string)($contexts['tests'][0] ?? ''),
        ];
    }

    /** @param array<string,mixed> $profile */
    private static function sampleCountFor(array $profile, string $fingerprint): int
    {
        foreach ((array)($profile['queries'] ?? []) as $query) {
            if (is_array($query) && (string)($query['fingerprint'] ?? '') === $fingerprint) {
                return max(0, (int)($query['sample_count'] ?? 0));
            }
        }
        return 0;
    }

    /** @param array<string,mixed> $query */
    private static function queryCurrentSampleCount(array $query, string $metric): int
    {
        foreach ((array)($query['metric_results'] ?? []) as $result) {
            if (is_array($result) && ($result['metric'] ?? '') === 'sample_count' && is_numeric($result['current'] ?? null)) {
                return max(0, (int)$result['current']);
            }
        }
        if ($metric === 'sample_count') {
            foreach ((array)($query['metric_results'] ?? []) as $result) {
                if (is_array($result) && ($result['metric'] ?? '') === 'sample_count' && is_numeric($result['current'] ?? null)) {
                    return max(0, (int)$result['current']);
                }
            }
        }
        return 0;
    }

    private static function queryIdentity(string $fingerprint): string
    {
        return $fingerprint === '' ? '' : 'fingerprint:' . self::fingerprintHash($fingerprint);
    }

    private static function fingerprintHash(string $fingerprint): string
    {
        $fingerprint = trim($fingerprint);
        return $fingerprint === '' ? '' : hash('sha256', $fingerprint);
    }

    /** @param array<string,mixed> $selector */
    private static function firstSelector(array $selector, string $key): string
    {
        $value = $selector[$key] ?? [];
        if (is_array($value)) {
            return (string)($value[0] ?? '');
        }
        return is_scalar($value) ? (string)$value : '';
    }

    private static function firstScalar(mixed $value): string
    {
        if (is_array($value)) {
            return self::firstScalar($value[0] ?? '');
        }
        return is_scalar($value) ? (string)$value : '';
    }

    private static function isTimeMetric(string $metric): bool
    {
        return in_array($metric, self::TIME_METRICS, true)
            || preg_match('/(?:^max_|^min_|^avg_|p\d+|total_).*_ms$/', $metric) === 1;
    }

    private static function severity(string $severity): string
    {
        $severity = strtolower($severity);
        return $severity === 'warn' ? 'warning' : $severity;
    }

    /** @param array<string,mixed> $finding @param array<string,mixed> $context @return array<string,mixed> */
    private static function locationFrom(array $finding, array $context): array
    {
        $candidate = (string)($finding['source'] ?? $finding['caller'] ?? $context['source'] ?? $context['caller'] ?? $context['test_id'] ?? '');
        $line = null;
        if (preg_match('/^(.*):(\d+)$/', $candidate, $match) === 1) {
            $candidate = (string)$match[1];
            $line = (int)$match[2];
        }
        return ['path' => $candidate, 'line' => $line];
    }

    /** @param array<int,array<string,mixed>> $runs @return array<int,array<string,mixed>> */
    private static function uniqueRuns(array $runs): array
    {
        $out = [];
        foreach ($runs as $run) {
            $key = (string)($run['artifact_hash'] ?? '') . '|' . (string)($run['run_id'] ?? '');
            $out[$key] = $run;
        }
        ksort($out, SORT_STRING);
        return array_values($out);
    }

    /** @param array<int,array<string,mixed>> $runs */
    private static function firstBaselineHash(array $runs): string
    {
        foreach ($runs as $run) {
            $hash = (string)($run['baseline_hash'] ?? '');
            if ($hash !== '') {
                return $hash;
            }
        }
        return '';
    }
}
