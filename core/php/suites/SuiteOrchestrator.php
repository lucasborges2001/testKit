<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Throwable;
use Testkit\Core\Common\Paths;
use Testkit\Core\Coverage\CoverageDiagnostics;
use Testkit\Core\Coverage\CoverageMerger;
use Testkit\Core\Discovery\TestDiscovery;
use Testkit\Core\Execution\ParallelGuard;
use Testkit\Core\Execution\SuiteExecutor;
use Testkit\Core\Reporting\ConsoleReporter;
use Testkit\Core\Reporting\HistoryRepository;
use Testkit\Core\Reporting\ReportSummary;
use Testkit\Core\Reporting\ResultWriter;
use Testkit\Core\Reporting\StructuredWarnings;
use Testkit\Core\Seeding\SuiteSeedState;

final class SuiteOrchestrator
{
    /**
     * @param array<string,mixed> $config
     * @param array<int,string> $extensions
     * @param callable $buildCommand fn(array $test, int $workerId): array{cmd:array<int,string>, env:array<string,string>}
     * @param callable|null $postRun fn(array<string,mixed> &$result, array<string,mixed> $config): void
     */
    public static function run(array $config, array $extensions, callable $buildCommand, ?callable $postRun = null): int
    {
        $tests = [];
        $reportRoot = Paths::reportsRoot();
        $currentPhase = 'discovery';
        $phaseStartedMs = self::nowMs();
        $phaseTimings = self::emptyPhaseTimings();

        $runId = self::envString('TEST_RUN_ID');
        if ($runId === '') {
            $runId = self::buildRunId();
            putenv('TEST_RUN_ID=' . $runId);
        }

        $metaRunId = self::envString('TEST_META_RUN_ID', $runId);

        $policy = [];
        $warnings = [];
        $admission = [
            'store_mode' => 'shared',
            'concurrency_policy' => 'not_applicable',
            'run_admitted' => true,
            'reason' => null,
            'resource' => '',
            'lock_key' => '',
            'lock_owner_run_id' => null,
            'lock_owner_meta_run_id' => null,
            'lock_owner_hostname' => null,
            'lock_acquired_at' => null,
        ];

        $lockLease = null;

        try {
            $tests = TestDiscovery::discover((string)$config['tests_dir'], $extensions, $config);
            $reportRoot = Paths::resolveReportRoot($tests);
            Paths::ensureDir($reportRoot);
            Paths::recordSuiteReportRoot($reportRoot, (string)($config['suite_id'] ?? 'suite'));

            self::transitionPhase($currentPhase, 'admission', $phaseStartedMs, $phaseTimings);

            $policy = ParallelGuard::evaluate($tests, $config, Paths::repoRoot());
            $warnings = StructuredWarnings::canonicalize($policy['warnings'] ?? []);
            $admission = ParallelGuard::admissionState($policy);

            $errors = StructuredWarnings::canonicalize($policy['errors'] ?? []);
            if ($errors !== []) {
                $admission = ParallelGuard::rejectedByPolicyState($policy);
                ParallelGuard::assertSafe($policy);
            }

            foreach ($warnings as $warning) {
                $code = (string)($warning['code'] ?? 'GENERIC_WARNING');
                $summary = (string)($warning['summary'] ?? 'warning');
                fwrite(STDERR, 'WARN[' . $code . ']: ' . $summary . PHP_EOL);
            }

            ConsoleReporter::printSuiteStart($config, count($tests));
            if ((bool)$config['list_only']) {
                ConsoleReporter::printList($tests);
            }

            try {
                $lockLease = ParallelGuard::acquireSuiteStoreLock($policy);
            } catch (Throwable $e) {
                $admission = ParallelGuard::rejectedByLockState($policy);
                throw $e;
            }

            $config['repo_root'] = Paths::repoRoot();
            self::transitionPhase($currentPhase, 'execution', $phaseStartedMs, $phaseTimings);

            $result = SuiteExecutor::execute($tests, $config, $buildCommand);

            $moduleScope = SuiteSelection::moduleScope($tests);
            $selectionManifest = SuiteSelection::manifest($tests, $config);

            $result['report_contract_version'] = (int)($config['report_contract_version'] ?? 2);
            $result['runner_contract_version'] = (int)($config['runner_contract_version'] ?? 1);
            $result['runner_capabilities'] = $config['runner_capabilities'] ?? [];
            $result['runner_hazards'] = $config['runner_hazards'] ?? [];
            $result['runner_contract'] = [
                'version' => (int)($config['runner_contract_version'] ?? 1),
                'capabilities' => $config['runner_capabilities'] ?? [],
                'hazards' => $config['runner_hazards'] ?? [],
            ];
            $result['report_root'] = $reportRoot;
            $result['report_scope_rel'] = Paths::relativeToRepo($reportRoot);
            $result['match'] = (string)($config['match'] ?? '');
            $result['selected_common_dir'] = SuiteSelection::commonDir($tests);
            $result['selected_module_scope'] = $moduleScope;
            $result['selected_test_count'] = count($tests);
            $result['selected_test_files'] = array_map(static fn(array $test): string => (string)($test['rel'] ?? ''), $tests);
            $result['selection_manifest'] = $selectionManifest;
            $result['suite_status'] = SuiteSelection::suiteStatus($result, $tests, $config);
            $result['no_tests_reason'] = SuiteSelection::noTestsReason($result, $config);
            $result['run_id'] = $runId;
            $result['meta_run_id'] = $metaRunId;
            $result['run_kind'] = 'suite';
            $result['report_keep'] = (int)($config['report_keep'] ?? 5);
            $result['runs_index_keep'] = (int)($config['runs_index_keep'] ?? $config['report_keep'] ?? 5);
            $result['filters'] = [
                'suite' => (string)($config['suite_id'] ?? ''),
                'scope' => (string)($config['scope'] ?? 'all'),
                'category' => (string)($config['category'] ?? 'all'),
                'match' => (string)($config['match'] ?? ''),
            ];
            $result['summary'] = [
                'total' => (int)$result['tests_total'],
                'passed' => (int)$result['pass'],
                'failed' => (int)$result['fail'],
                'skipped' => (int)$result['skip'],
                'duration_ms' => (int)$result['duration_ms'],
                'suite_status' => (string)$result['suite_status'],
            ];
            $result['parallel_policy'] = [
                'jobs' => (int)($policy['jobs'] ?? 1),
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
            ];
            $result['concurrency_admission'] = $admission;
            $result['evidence_valid'] = true;
            $result['evidence_invalid_reason'] = null;
            self::attachObservabilityDefaults($result, count($tests));

            $result['failures'] = ReportSummary::canonicalFailures($result);
            $result['grouped_failures'] = ReportSummary::groupFailures($result['failures']);
            $result['failure_contract'] = [
                'canonical' => 'failures',
                'legacy_fallback' => 'failed_tests',
            ];
            $result['first_failure'] = ReportSummary::firstFailure($result);

            $history = HistoryRepository::updateAndAnalyze(
                $result,
                (int)($config['thresholds']['flake_window'] ?? 10)
            );
            $result['history_file'] = $history['history_file'];
            $result['fragility_hints'] = $history['fragility_hints'];
            $result['regression_delta'] = is_array($history['regression_delta'] ?? null)
                ? $history['regression_delta']
                : SuiteOperationalFailure::emptyRegressionDelta();

            $isPhpSuite = ((string)($config['language'] ?? '') === 'php') || self::extensionsContainPhp($extensions);
            if ((bool)$config['coverage'] && $isPhpSuite) {
                $merged = CoverageMerger::mergeFromDir((string)$config['coverage_dir']);
                if ($merged) {
                    $format = (string)$config['coverage_format'];
                    if ($format === 'json' || $format === 'both') {
                        $result['coverage_json'] = CoverageMerger::writeJson((string)$config['coverage_dir'], $merged);
                    }
                    if ($format === 'lcov' || $format === 'both') {
                        $result['coverage_lcov'] = CoverageMerger::writeLcov((string)$config['coverage_dir'], $merged, Paths::repoRoot());
                    }

                    $diagnostics = CoverageDiagnostics::analyze($merged, $config);
                    CoverageDiagnostics::write((string)$config['coverage_dir'], $diagnostics);
                    $result['coverage_diagnostics'] = $diagnostics;
                } else {
                    $result['coverage_error'] = 'Coverage habilitado pero no se generaron archivos por test.';
                    if ((int)$result['exit_code'] === SuiteExecutor::EXIT_PASS) {
                        $result['exit_code'] = SuiteExecutor::EXIT_ERROR;
                    }
                }
            }

            if ($postRun !== null) {
                $postRun($result, $config);
            }

            $result = SuiteSeedState::attachToReport($result, Paths::repoRoot());
            $result = ReportSummary::enrichReport($result);

            self::transitionPhase($currentPhase, 'reporting', $phaseStartedMs, $phaseTimings);
            $result['phase_timings_ms'] = self::phaseTimingsSnapshot($phaseTimings, $currentPhase, $phaseStartedMs);

            ConsoleReporter::printSuiteResult($result);
            ResultWriter::writeSuite($result);
            HistoryRepository::recordSuiteMetrics($result);
            ConsoleReporter::printPhaseTimings($result['phase_timings_ms']);

            return (int)$result['exit_code'];
        } catch (Throwable $e) {
            $failedPhase = $currentPhase;
            self::transitionPhase($currentPhase, 'reporting', $phaseStartedMs, $phaseTimings);

            $result = SuiteOperationalFailure::build(
                config: $config,
                tests: $tests,
                reportRoot: $reportRoot,
                runId: $runId,
                metaRunId: $metaRunId,
                policy: $policy,
                warnings: $warnings,
                admission: $admission,
                phase: $failedPhase,
                error: $e,
                options: [
                    'include_selection_manifest' => true,
                    'selection_manifest_source' => 'suite_orchestrator',
                    'no_tests_discovery_failure' => true,
                ]
            );

            self::attachObservabilityDefaults($result, count($tests));
            $result = SuiteSeedState::attachToReport($result, Paths::repoRoot());
            $result = ReportSummary::enrichReport($result);
            $result['phase_timings_ms'] = self::phaseTimingsSnapshot($phaseTimings, $currentPhase, $phaseStartedMs);

            ConsoleReporter::printSuiteResult($result);
            ResultWriter::writeSuite($result);
            HistoryRepository::recordSuiteMetrics($result);
            ConsoleReporter::printPhaseTimings($result['phase_timings_ms']);

            return SuiteExecutor::EXIT_ERROR;
        } finally {
            $lockLease?->release();
        }
    }

    /**
     * @param array<int,string> $extensions
     */
    private static function extensionsContainPhp(array $extensions): bool
    {
        foreach ($extensions as $ext) {
            if (str_ends_with(strtolower($ext), '.php')) {
                return true;
            }
        }

        return false;
    }

    private static function envString(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }

    private static function buildRunId(): string
    {
        try {
            $suffix = bin2hex(random_bytes(3));
        } catch (\Throwable) {
            $suffix = substr((string)sha1(uniqid('', true)), 0, 6);
        }

        return gmdate('Ymd\THis\Z') . '_' . $suffix;
    }

    /**
     * @return array<string,int>
     */
    private static function emptyPhaseTimings(): array
    {
        return [
            'discovery' => 0,
            'admission' => 0,
            'execution' => 0,
            'reporting' => 0,
        ];
    }

    /**
     * @param array<string,int> &$phaseTimings
     */
    private static function transitionPhase(string &$currentPhase, string $nextPhase, int &$phaseStartedMs, array &$phaseTimings): void
    {
        $nowMs = self::nowMs();
        if (isset($phaseTimings[$currentPhase])) {
            $phaseTimings[$currentPhase] += max(0, $nowMs - $phaseStartedMs);
        }

        $currentPhase = $nextPhase;
        $phaseStartedMs = $nowMs;
    }

    /**
     * @param array<string,int> $phaseTimings
     * @return array<string,int>
     */
    private static function phaseTimingsSnapshot(array $phaseTimings, string $currentPhase, int $phaseStartedMs): array
    {
        $snapshot = self::emptyPhaseTimings();
        foreach ($snapshot as $phase => $_) {
            $snapshot[$phase] = max(0, (int)($phaseTimings[$phase] ?? 0));
        }

        if (isset($snapshot[$currentPhase])) {
            $snapshot[$currentPhase] += max(0, self::nowMs() - $phaseStartedMs);
        }

        return $snapshot;
    }

    /**
     * @param array<string,mixed> &$result
     */
    private static function attachObservabilityDefaults(array &$result, int $selectedTestCount): void
    {
        if (!is_array($result['progress_policy'] ?? null)) {
            $result['progress_policy'] = SuiteExecutor::progressPolicy();
        }

        if (!is_array($result['execution_metrics'] ?? null)) {
            $result['tests_total'] = (int)($result['tests_total'] ?? $selectedTestCount);
            $result['execution_metrics'] = SuiteExecutor::executionMetricsSnapshot($result);
        }
    }

    private static function nowMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }
}
