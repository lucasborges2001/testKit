<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Gate;

final class MysqlQueryBaselineApprovalEvaluator
{
    /**
     * @param array<string,mixed> $gateReport
     * @param array<string,mixed> $comparison
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $criteria
     * @return array<string,mixed>
     */
    public static function evaluate(array $gateReport, array $comparison = [], array $profile = [], array $criteria = []): array
    {
        $defaults = [
            'minimum_successful_runs' => 1,
            'minimum_sample_count' => 20,
            'maximum_policy_severity' => 'warning',
            'require_full_comparison' => true,
            'require_source_commit' => true,
            'require_dataset_identity' => true,
            'require_environment_identity' => true,
            'require_no_suppressions' => true,
            'require_stability' => true,
        ];
        $criteria = array_replace($defaults, array_intersect_key($criteria, $defaults));
        $checks = [];
        $blocking = 0;
        $pending = 0;
        $insufficient = 0;
        $incompatible = 0;

        $add = static function (string $id, bool $pass, string $status, string $reason, array $evidence = []) use (&$checks, &$blocking, &$pending, &$insufficient, &$incompatible): void {
            $checks[] = [
                'id' => $id,
                'status' => $pass ? 'pass' : $status,
                'reason' => $pass ? 'criterion_satisfied' : $reason,
                'evidence' => MysqlQueryGateArtifactWriter::sanitizeRecursive($evidence),
            ];
            if ($pass) {
                return;
            }
            if ($status === 'pending_stability') {
                $pending++;
            } elseif ($status === 'insufficient_evidence') {
                $insufficient++;
            } elseif ($status === 'incompatible') {
                $incompatible++;
            } else {
                $blocking++;
            }
        };

        $gateDecision = (string)($gateReport['decision']['status'] ?? 'disabled');
        $add('gate_not_blocked', $gateDecision !== 'blocked', 'fail', 'gate_has_blocking_findings', ['decision' => $gateDecision]);
        $add('gate_contract_valid', !in_array($gateDecision, ['invalid_configuration', 'operational_error'], true), 'fail', 'gate_report_invalid', ['decision' => $gateDecision]);
        $add('no_blocking_suppressions', !$criteria['require_no_suppressions'] || (int)($gateReport['summary']['suppressed_blocking'] ?? 0) === 0,
            'fail', 'blocking_findings_were_suppressed', ['suppressed' => (int)($gateReport['summary']['suppressed'] ?? 0)]);
        $add('no_expired_allowlist', count((array)($gateReport['allowlist']['expired'] ?? [])) === 0,
            'fail', 'expired_allowlist_entries_present', ['expired' => (array)($gateReport['allowlist']['expired'] ?? [])]);

        $compatibility = self::compatibility($comparison, $profile);
        $full = (string)($compatibility['comparison_scope'] ?? '') === 'full'
            && in_array((string)($compatibility['status'] ?? ''), ['compatible', 'compatible_with_warnings'], true);
        $add('comparison_compatible_full', !$criteria['require_full_comparison'] || $full,
            'incompatible', 'comparison_not_full_and_compatible', $compatibility);

        $stability = (array)($gateReport['stability'] ?? []);
        $stabilityOk = (int)($stability['pending_stability'] ?? 0) === 0
            && (int)($stability['incompatible_evidence'] ?? 0) === 0;
        $add('stability_satisfied', !$criteria['require_stability'] || $stabilityOk,
            'pending_stability', 'gate_findings_pending_stability', $stability);

        $successfulRuns = max(0, (int)($stability['evidence_runs'] ?? 0));
        $add('minimum_successful_runs', $successfulRuns >= (int)$criteria['minimum_successful_runs'],
            'pending_stability', 'minimum_successful_runs_not_met', [
                'actual' => $successfulRuns,
                'required' => (int)$criteria['minimum_successful_runs'],
            ]);

        $sampleCount = self::minimumSampleCount($comparison, $profile);
        $add('minimum_sample_count', $sampleCount >= (int)$criteria['minimum_sample_count'],
            'insufficient_evidence', 'minimum_sample_count_not_met', [
                'actual' => $sampleCount,
                'required' => (int)$criteria['minimum_sample_count'],
            ]);

        $instrumentationBlocking = self::hasCategory($gateReport, ['instrumentation.integrity', 'instrumentation.bypass'], ['block']);
        $add('instrumentation_integrity', !$instrumentationBlocking, 'fail', 'instrumentation_has_blocking_findings');
        $policyBlocking = self::hasPolicySeverity($gateReport, (string)$criteria['maximum_policy_severity']);
        $add('policy_acceptance', !$policyBlocking, 'fail', 'policy_violations_exceed_approval_severity');

        $context = is_array($profile['comparison_context'] ?? null)
            ? $profile['comparison_context']
            : (array)($comparison['current']['comparison_context'] ?? $comparison['comparison_context'] ?? []);
        $compatibilityChecks = is_array($comparison['compatibility']['checks'] ?? null)
            ? $comparison['compatibility']['checks']
            : (array)($comparison['comparison']['compatibility']['checks'] ?? []);
        $sourceCommit = (string)($context['commit_sha'] ?? $comparison['current']['commit_sha'] ?? $comparison['current']['source']['commit_sha'] ?? '');
        $datasetId = (string)($context['dataset_id'] ?? self::currentCheck($compatibilityChecks, 'dataset_id'));
        $environmentId = (string)($context['environment_id'] ?? self::currentCheck($compatibilityChecks, 'environment_id'));
        $add('source_commit_present', !$criteria['require_source_commit'] || preg_match('/^[a-f0-9]{7,64}$/i', $sourceCommit) === 1,
            'insufficient_evidence', 'source_commit_missing');
        $add('dataset_identity_present', !$criteria['require_dataset_identity'] || $datasetId !== '',
            'insufficient_evidence', 'dataset_identity_missing');
        $add('environment_identity_present', !$criteria['require_environment_identity'] || $environmentId !== '',
            'insufficient_evidence', 'environment_identity_missing');

        $status = 'eligible';
        $reason = 'all_approval_criteria_satisfied';
        if ($incompatible > 0) {
            $status = 'incompatible';
            $reason = 'comparison_context_incompatible';
        } elseif ($blocking > 0) {
            $status = 'ineligible';
            $reason = 'approval_criteria_failed';
        } elseif ($pending > 0) {
            $status = 'pending_stability';
            $reason = 'more_stable_evidence_required';
        } elseif ($insufficient > 0) {
            $status = 'insufficient_evidence';
            $reason = 'required_approval_evidence_missing';
        }

        return [
            'schema_version' => MysqlQueryGateConfig::APPROVAL_SCHEMA_VERSION,
            'generated_at' => (string)($gateReport['generated_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
            'gate_id' => (string)($gateReport['gate_id'] ?? ''),
            'status' => $status,
            'reason' => $reason,
            'eligible' => $status === 'eligible',
            'checks' => $checks,
            'summary' => [
                'checks' => count($checks),
                'passed' => count(array_filter($checks, static fn(array $check): bool => $check['status'] === 'pass')),
                'failed' => $blocking,
                'pending_stability' => $pending,
                'insufficient_evidence' => $insufficient,
                'incompatible' => $incompatible,
            ],
            'inputs' => [
                'gate_report_hash' => MysqlQueryGateArtifactWriter::payloadHash($gateReport),
                'comparison_hash' => $comparison === [] ? '' : MysqlQueryGateArtifactWriter::payloadHash($comparison),
                'profile_hash' => $profile === [] ? '' : MysqlQueryGateArtifactWriter::payloadHash($profile),
            ],
            'criteria' => $criteria,
            'limitations' => [
                'This report does not create, replace, promote, or commit a baseline.',
                'Baseline approval remains an explicit human-controlled operation.',
            ],
        ];
    }

    /** @param array<string,mixed> $checks */
    private static function currentCheck(array $checks, string $key): string
    {
        return is_array($checks[$key] ?? null) ? (string)($checks[$key]['current'] ?? '') : '';
    }

    /** @param array<string,mixed> $comparison @param array<string,mixed> $profile @return array<string,mixed> */
    private static function compatibility(array $comparison, array $profile): array
    {
        if (is_array($comparison['compatibility'] ?? null)) {
            return $comparison['compatibility'];
        }
        if (is_array($comparison['comparison']['compatibility'] ?? null)) {
            return $comparison['comparison']['compatibility'];
        }
        if (is_array($profile['baseline_comparison']['compatibility'] ?? null)) {
            return $profile['baseline_comparison']['compatibility'];
        }
        return ['status' => 'insufficient_metadata', 'comparison_scope' => 'none', 'timing_comparable' => false];
    }

    /** @param array<string,mixed> $comparison @param array<string,mixed> $profile */
    private static function minimumSampleCount(array $comparison, array $profile): int
    {
        $samples = [];
        $queries = is_array($comparison['queries'] ?? null) ? $comparison['queries'] : [];
        if ($queries === [] && is_array($comparison['comparison']['queries'] ?? null)) {
            $queries = $comparison['comparison']['queries'];
        }
        foreach ($queries as $query) {
            if (!is_array($query)) {
                continue;
            }
            $metrics = (array)($query['metric_results'] ?? $query['metrics'] ?? []);
            $candidate = $metrics['sample_count']['current'] ?? $metrics['sample_count'] ?? null;
            if (is_numeric($candidate)) {
                $samples[] = max(0, (int)$candidate);
            }
        }
        if ($samples === []) {
            foreach ((array)($profile['queries'] ?? []) as $query) {
                if (is_array($query) && is_numeric($query['sample_count'] ?? null)) {
                    $samples[] = max(0, (int)$query['sample_count']);
                }
            }
        }
        return $samples === [] ? 0 : min($samples);
    }

    /** @param array<string,mixed> $gateReport @param array<int,string> $categories @param array<int,string> $decisions */
    private static function hasCategory(array $gateReport, array $categories, array $decisions): bool
    {
        foreach ((array)($gateReport['findings'] ?? []) as $finding) {
            if (is_array($finding)
                && in_array((string)($finding['category'] ?? ''), $categories, true)
                && in_array((string)($finding['decision_effective'] ?? ''), $decisions, true)
                && empty($finding['suppressed'])) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $gateReport */
    private static function hasPolicySeverity(array $gateReport, string $maximum): bool
    {
        $rank = ['info' => 0, 'warning' => 1, 'error' => 2];
        $max = $rank[$maximum] ?? 1;
        foreach ((array)($gateReport['findings'] ?? []) as $finding) {
            if (is_array($finding)
                && (string)($finding['category'] ?? '') === 'policy.violation'
                && ($rank[(string)($finding['severity'] ?? 'warning')] ?? 1) > $max
                && empty($finding['suppressed'])) {
                return true;
            }
        }
        return false;
    }
}
