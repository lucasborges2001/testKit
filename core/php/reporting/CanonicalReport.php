<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class CanonicalReport
{
    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public static function enrich(array $report): array
    {
        $kind = strtolower(trim((string)($report['run_kind'] ?? 'suite')));
        if ($kind === '') {
            $kind = 'suite';
        }

        $report['canonical_report'] = [
            'report_version' => 1,
            'report_kind' => $kind,
            'final_status' => self::finalStatus($report),
            'selection' => self::selection($report, $kind),
            'selection_manifest' => self::selectionManifest($report),
            'summary' => is_array($report['summary'] ?? null) ? $report['summary'] : [],
            'diagnostics' => ReportSummary::diagnostics($report),
            'phase_timeline' => self::phaseTimeline($report),
            'evidence' => self::evidence($report),
            'artifacts' => self::artifacts($report),
            'normalized_artifacts' => self::normalizedArtifacts($report),
            'seed_state' => self::seedState($report),
            'regression_delta' => self::regressionDelta($report),
            'recommended_actions' => self::recommendedActions($report),
            'agent_summary' => self::agentSummary($report),
            'agent_mode' => self::agentMode($report),
            'warnings' => self::warnings($report),
            'runner' => self::runner($report),
        ];

        return $report;
    }

    /** @param array<string,mixed> $report */
    private static function finalStatus(array $report): string
    {
        $status = strtolower(trim((string)($report['outcome_status'] ?? '')));
        if ($status === '') {
            $status = strtolower(trim((string)($report['suite_status'] ?? '')));
        }
        if ($status === '') {
            $status = strtolower(trim((string)($report['final_status'] ?? '')));
        }

        return match ($status) {
            'passed' => 'PASS',
            'failed' => 'FAIL',
            'partial' => 'PARTIAL',
            'timeout' => 'TIMEOUT',
            'contention' => 'BLOCKED',
            'infra_error', 'bootstrap_error', 'discovery_error', 'reporting_error' => 'ERROR',
            'skipped', 'all_skipped' => 'SKIP',
            'no_tests' => 'NO_TESTS',
            'listed' => 'LISTED',
            default => ((int)($report['exit_code'] ?? 0) === 0 ? 'PASS' : 'FAIL'),
        };
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private static function selection(array $report, string $kind): array
    {
        return [
            'suite_id' => $kind === 'suite' ? ((string)($report['suite_id'] ?? '')) : null,
            'target' => $kind === 'meta' ? ((string)($report['target'] ?? '')) : null,
            'scope' => (string)($report['scope'] ?? ($report['filters']['scope'] ?? '')),
            'category' => (string)($report['category'] ?? ($report['filters']['category'] ?? '')),
            'match' => (string)($report['match'] ?? ($report['filters']['match'] ?? '')),
            'selected_test_count' => (int)($report['selected_test_count'] ?? $report['tests_total'] ?? 0),
            'selected_test_files' => array_values((array)($report['selected_test_files'] ?? [])),
            'selected_module_scope' => (string)($report['selected_module_scope'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private static function selectionManifest(array $report): array
    {
        return is_array($report['selection_manifest'] ?? null)
            ? $report['selection_manifest']
            : ReportSummary::selectionManifest($report);
    }

    /** @param array<string,mixed> $report @return array<int,array<string,mixed>> */
    private static function phaseTimeline(array $report): array
    {
        return is_array($report['phase_timeline'] ?? null)
            ? array_values(array_filter($report['phase_timeline'], 'is_array'))
            : ReportSummary::phaseTimeline($report);
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private static function evidence(array $report): array
    {
        $firstFailure = $report['first_failure'] ?? null;
        if (!is_array($firstFailure)) {
            $firstFailure = ReportSummary::firstFailure($report);
        }

        return [
            'valid' => (bool)($report['evidence_valid'] ?? true),
            'invalid_reason' => $report['evidence_invalid_reason'] ?? null,
            'first_failure' => $firstFailure,
        ];
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private static function artifacts(array $report): array
    {
        return [
            'report_root' => (string)($report['report_root'] ?? ''),
            'report_scope_rel' => (string)($report['report_scope_rel'] ?? ''),
            'report_links' => is_array($report['report_links'] ?? null) ? $report['report_links'] : [],
            'history_file' => $report['history_file'] ?? null,
            'manifest_path' => $report['manifest_path'] ?? null,
            'snapshot_file' => $report['snapshot_file'] ?? null,
        ];
    }

    /** @param array<string,mixed> $report @return array<int,array<string,mixed>> */
    private static function normalizedArtifacts(array $report): array
    {
        return is_array($report['normalized_artifacts'] ?? null)
            ? array_values(array_filter($report['normalized_artifacts'], 'is_array'))
            : ReportSummary::normalizedArtifacts($report);
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>|null
     */
    private static function seedState(array $report): ?array
    {
        $migrationState = $report['migration_state'] ?? null;
        $hasExplicitSeedState = is_array($migrationState)
            || array_key_exists('baseline_mode', $report)
            || array_key_exists('snapshot_file', $report)
            || array_key_exists('manifest_path', $report)
            || array_key_exists('seed_state', $report);

        if (is_array($report['seed_state'] ?? null)) {
            return self::normalizeSeedState($report['seed_state']);
        }

        if (!$hasExplicitSeedState) {
            return null;
        }

        return self::normalizeSeedState([
            'available' => true,
            'contract_version' => 1,
            'source' => 'report_top_level_legacy',
            'driver' => (string)($report['seed_driver'] ?? $report['baseline'] ?? ''),
            'db_name' => (string)($report['seed_db_name'] ?? ''),
            'baseline_mode' => (string)($report['baseline_mode'] ?? ''),
            'snapshot_file' => (string)($report['snapshot_file'] ?? ''),
            'manifest_path' => (string)($report['manifest_path'] ?? ''),
            'migration_state' => is_array($migrationState) ? $migrationState : null,
            'applied_migrations' => array_values((array)($report['applied_migrations'] ?? [])),
            'pending_migrations' => array_values((array)($report['pending_migrations'] ?? [])),
            'resolved_snapshot' => is_array($report['resolved_snapshot'] ?? null) ? $report['resolved_snapshot'] : null,
            'warnings' => $report['seed_warnings'] ?? [],
        ]);
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private static function normalizeSeedState(array $state): array
    {
        $migrationState = is_array($state['migration_state'] ?? null) ? $state['migration_state'] : [];
        $applied = self::arrayOfStrings($state['applied_migrations'] ?? ($migrationState['applied'] ?? []));
        $pending = self::arrayOfStrings($state['pending_migrations'] ?? ($migrationState['pending'] ?? []));

        return [
            'available' => (bool)($state['available'] ?? true),
            'contract_version' => max(1, (int)($state['contract_version'] ?? 1)),
            'source' => (string)($state['source'] ?? $state['source_kind'] ?? ''),
            'driver' => (string)($state['driver'] ?? $state['baseline'] ?? ''),
            'db_name' => (string)($state['db_name'] ?? $state['database'] ?? ''),
            'baseline' => (string)($state['baseline'] ?? $state['driver'] ?? ''),
            'baseline_mode' => (string)($state['baseline_mode'] ?? ''),
            'profile' => (string)($state['profile'] ?? ''),
            'source_kind' => (string)($state['source_kind'] ?? $state['source'] ?? ''),
            'reason' => (string)($state['reason'] ?? ''),
            'reason_summary' => (string)($state['reason_summary'] ?? ''),
            'store_strategy' => (string)($state['store_strategy'] ?? ''),
            'manifest_path' => (string)($state['manifest_path'] ?? ''),
            'snapshot_file' => (string)($state['snapshot_file'] ?? ''),
            'requested_migrations' => self::arrayOfStrings($state['requested_migrations'] ?? []),
            'applied_migrations' => $applied,
            'pending_migrations' => $pending,
            'historical_absorbed' => self::arrayOfStrings($state['historical_absorbed'] ?? ($migrationState['historical_absorbed'] ?? [])),
            'migration_state' => [
                'source' => (string)($migrationState['source'] ?? $state['source'] ?? ''),
                'mode' => (string)($migrationState['mode'] ?? ''),
                'available' => self::arrayOfStrings($migrationState['available'] ?? []),
                'applied' => self::arrayOfStrings($migrationState['applied'] ?? $applied),
                'pending' => self::arrayOfStrings($migrationState['pending'] ?? $pending),
                'target' => self::arrayOfStrings($migrationState['target'] ?? []),
                'historical_absorbed' => self::arrayOfStrings($migrationState['historical_absorbed'] ?? []),
            ],
            'resolved_snapshot' => is_array($state['resolved_snapshot'] ?? null) ? $state['resolved_snapshot'] : null,
            'warnings' => StructuredWarnings::canonicalize($state['warnings'] ?? []),
        ];
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private static function regressionDelta(array $report): array
    {
        return is_array($report['regression_delta'] ?? null)
            ? $report['regression_delta']
            : ReportSummary::regressionDelta($report);
    }

    /** @param array<string,mixed> $report @return array<int,array<string,mixed>> */
    private static function recommendedActions(array $report): array
    {
        return is_array($report['recommended_actions'] ?? null)
            ? array_values(array_filter($report['recommended_actions'], 'is_array'))
            : ReportSummary::recommendedActions($report);
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private static function agentSummary(array $report): array
    {
        return is_array($report['agent_summary'] ?? null)
            ? $report['agent_summary']
            : ReportSummary::agentSummary($report);
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private static function agentMode(array $report): array
    {
        return is_array($report['agent_mode'] ?? null)
            ? $report['agent_mode']
            : [
                'enabled' => false,
                'mode' => 'standard',
                'enforced' => [],
            ];
    }

    /** @param array<string,mixed> $report @return array<int,array<string,mixed>> */
    private static function warnings(array $report): array
    {
        $rows = [];

        if (is_array($report['warnings'] ?? null)) {
            $rows = array_merge($rows, $report['warnings']);
        }

        $parallel = $report['parallel_policy'] ?? null;
        if (is_array($parallel) && array_key_exists('warnings', $parallel) && is_array($parallel['warnings'])) {
            $rows = array_merge($rows, $parallel['warnings']);
        }

        $seed = self::seedState($report);
        if (is_array($seed) && is_array($seed['warnings'] ?? null)) {
            $rows = array_merge($rows, $seed['warnings']);
        }

        $canonical = StructuredWarnings::canonicalize($rows);
        $seen = [];
        $deduped = [];
        foreach ($canonical as $warning) {
            $key = (string)($warning['code'] ?? '') . '|' . (string)($warning['summary'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $warning;
        }

        return $deduped;
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private static function runner(array $report): array
    {
        $agentMode = self::agentMode($report);

        return [
            'contract_version' => (int)($report['runner_contract_version'] ?? 1),
            'capabilities' => is_array($report['runner_capabilities'] ?? null) ? $report['runner_capabilities'] : [],
            'hazards' => is_array($report['runner_hazards'] ?? null) ? $report['runner_hazards'] : [],
            'mode' => (string)($agentMode['mode'] ?? 'standard'),
        ];
    }

    /** @param mixed $value @return array<int,string> */
    private static function arrayOfStrings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $out[$item] = true;
            }
        }
        $rows = array_keys($out);
        natcasesort($rows);
        return array_values($rows);
    }
}
