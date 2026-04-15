<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Throwable;
use Testkit\Core\Common\Paths;
use Testkit\Core\Execution\SuiteExecutor;
use Testkit\Core\Reporting\ReportSummary;

final class SuiteOperationalFailure
{
    /**
     * @return array<string,mixed>
     */
    public static function emptyRegressionDelta(): array
    {
        return [
            'new_failures' => [],
            'resolved_failures' => [],
            'status_transitions' => [],
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @param array<int,array<string,mixed>> $tests
     * @param array<string,mixed> $policy
     * @param array<int,array<string,mixed>> $warnings
     * @param array<string,mixed> $admission
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function build(
        array $config,
        array $tests,
        string $reportRoot,
        string $runId,
        string $metaRunId,
        array $policy,
        array $warnings,
        array $admission,
        string $phase,
        Throwable $error,
        array $options = []
    ): array {
        $suiteId = (string)($config['suite_id'] ?? 'suite');
        $moduleScope = SuiteSelection::moduleScope($tests);
        $selectedCommonDir = SuiteSelection::commonDir($tests);
        $selectedTestFiles = array_values(array_map(
            static fn(array $test): string => (string)($test['rel'] ?? ''),
            $tests
        ));

        $defaultFailureKind = (string)($options['default_failure_kind'] ?? 'bootstrap_failure');
        $defaultFailurePhase = (string)($options['default_failure_phase'] ?? 'bootstrap');
        $defaultFailureDomain = (string)($options['default_failure_domain'] ?? 'bootstrap');
        $defaultCauseCode = (string)($options['default_cause_code'] ?? 'bootstrap_failed');
        $includeSelectionManifest = (bool)($options['include_selection_manifest'] ?? true);
        $selectionManifestSource = (string)($options['selection_manifest_source'] ?? 'suite_operational_failure');
        $noTestsDiscoveryFailure = (bool)($options['no_tests_discovery_failure'] ?? true);

        $admissionReason = (string)($admission['reason'] ?? '');
        $failureKind = match (true) {
            in_array($admissionReason, ['shared_store_locked', 'store_resource_locked'], true) => 'environment_conflict',
            $phase === 'discovery' => 'discovery_failure',
            $phase === 'reporting' => 'reporting_failure',
            default => $defaultFailureKind,
        };
        $failurePhase = match (true) {
            $phase === 'discovery' => 'discovery',
            $phase === 'reporting' => 'reporting',
            in_array($admissionReason, ['shared_store_locked', 'store_resource_locked'], true) => 'store_setup',
            default => $defaultFailurePhase,
        };
        $failureDomain = match (true) {
            $phase === 'discovery' => 'discovery',
            $phase === 'reporting' => 'reporting',
            in_array($admissionReason, ['shared_store_locked', 'store_resource_locked'], true) => 'store',
            default => $defaultFailureDomain,
        };
        $causeCode = $admissionReason !== '' ? $admissionReason : match ($failurePhase) {
            'discovery' => 'discovery_failed',
            'reporting' => 'report_write_failed',
            default => $defaultCauseCode,
        };

        $failure = ReportSummary::buildThrowableFailure($error, [
            'test_id' => $suiteId . '.bootstrap',
            'test_name' => $suiteId . '.bootstrap',
            'case' => $suiteId . '.bootstrap',
            'suite_id' => $suiteId,
            'suite' => $suiteId,
            'scope' => (string)($config['scope'] ?? 'all'),
            'category' => (string)($config['category'] ?? 'all'),
            'file' => '',
            'kind' => $failureKind,
            'phase' => $failurePhase,
            'failure_domain' => $failureDomain,
            'cause_code' => $causeCode,
            'artifact_path' => Paths::relativeToRepo($reportRoot),
        ]);

        $suiteStatus = $tests === []
            ? (($phase === 'discovery' && $noTestsDiscoveryFailure) ? 'failed' : 'no_tests')
            : 'failed';

        $result = [
            'suite_id' => $suiteId,
            'language' => (string)($config['language'] ?? ''),
            'scope' => (string)($config['scope'] ?? 'all'),
            'category' => (string)($config['category'] ?? 'all'),
            'tests_total' => count($tests),
            'pass' => 0,
            'fail' => 1,
            'skip' => 0,
            'tests' => [],
            'failed_tests' => [],
            'slow_tests' => [],
            'perf_violations' => [],
            'exit_code' => SuiteExecutor::EXIT_ERROR,
            'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'started_ms' => 0,
            'duration_ms' => 0,
            'list_only' => (bool)($config['list_only'] ?? false),
            'require_tests' => (bool)($config['require_tests'] ?? false),
            'jobs' => (int)($config['jobs'] ?? 1),
            'module_summary' => [],
            'report_contract_version' => (int)($config['report_contract_version'] ?? 2),
            'runner_contract_version' => (int)($config['runner_contract_version'] ?? 1),
            'runner_capabilities' => $config['runner_capabilities'] ?? [],
            'runner_hazards' => $config['runner_hazards'] ?? [],
            'runner_contract' => [
                'version' => (int)($config['runner_contract_version'] ?? 1),
                'capabilities' => $config['runner_capabilities'] ?? [],
                'hazards' => $config['runner_hazards'] ?? [],
            ],
            'report_root' => $reportRoot,
            'report_scope_rel' => Paths::relativeToRepo($reportRoot),
            'match' => (string)($config['match'] ?? ''),
            'selected_common_dir' => $selectedCommonDir,
            'selected_module_scope' => $moduleScope,
            'selected_test_count' => count($tests),
            'selected_test_files' => $selectedTestFiles,
            'suite_status' => $suiteStatus,
            'no_tests_reason' => $tests === []
                ? SuiteSelection::noTestsReason(['suite_status' => 'no_tests'], $config)
                : null,
            'run_id' => $runId,
            'meta_run_id' => $metaRunId,
            'run_kind' => 'suite',
            'report_keep' => (int)($config['report_keep'] ?? 5),
            'runs_index_keep' => (int)($config['runs_index_keep'] ?? $config['report_keep'] ?? 5),
            'filters' => [
                'suite' => $suiteId,
                'scope' => (string)($config['scope'] ?? 'all'),
                'category' => (string)($config['category'] ?? 'all'),
                'match' => (string)($config['match'] ?? ''),
            ],
            'summary' => [
                'total' => count($tests),
                'passed' => 0,
                'failed' => 1,
                'skipped' => 0,
                'duration_ms' => 0,
                'suite_status' => $tests === [] ? 'no_tests' : 'failed',
            ],
            'parallel_policy' => [
                'jobs' => (int)($policy['jobs'] ?? $config['jobs'] ?? 1),
                'db_strategy' => (string)($policy['db_strategy'] ?? 'shared'),
                'has_db_sensitive_tests' => (bool)($policy['has_db_sensitive_tests'] ?? false),
                'has_db_runtime' => (bool)($policy['has_db_runtime'] ?? false),
                'requires_db_isolation' => (bool)($policy['requires_db_isolation'] ?? false),
                'top_level_parallel_supported' => (bool)($policy['top_level_parallel_supported'] ?? true),
                'top_level_parallel_policy' => (string)($policy['top_level_parallel_policy'] ?? ''),
                'intra_suite_parallel_policy' => (string)($policy['intra_suite_parallel_policy'] ?? ''),
                'declared_runner_hazards' => is_array($policy['declared_runner_hazards'] ?? null) ? $policy['declared_runner_hazards'] : [],
                'suite_lock_key' => (string)($policy['suite_lock_key'] ?? ''),
                'warnings' => $warnings,
            ],
            'concurrency_admission' => $admission,
            'evidence_valid' => false,
            'evidence_invalid_reason' => (string)($admission['reason'] ?? 'runner_exception') ?: 'runner_exception',
            'failures' => [$failure],
            'grouped_failures' => ReportSummary::groupFailures([$failure]),
            'failure_contract' => [
                'canonical' => 'failures',
                'legacy_fallback' => 'failed_tests',
            ],
            'first_failure' => ReportSummary::summarizeFailure($failure),
            'history_file' => null,
            'fragility_hints' => [],
            'regression_delta' => self::emptyRegressionDelta(),
        ];

        if ($includeSelectionManifest) {
            $result['selection_manifest'] = SuiteSelection::manifest($tests, $config, $selectionManifestSource);
        }

        return $result;
    }
}
