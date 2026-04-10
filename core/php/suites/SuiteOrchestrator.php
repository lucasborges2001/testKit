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
        $tests = TestDiscovery::discover((string)$config['tests_dir'], $extensions, $config);

        $reportRoot = Paths::resolveReportRoot($tests);
        Paths::ensureDir($reportRoot);
        Paths::recordSuiteReportRoot($reportRoot, (string)($config['suite_id'] ?? 'suite'));

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
            $policy = ParallelGuard::evaluate($tests, $config, Paths::repoRoot());
            $warnings = is_array($policy['warnings'] ?? null) ? $policy['warnings'] : [];
            $admission = ParallelGuard::admissionState($policy);

            $errors = is_array($policy['errors'] ?? null) ? $policy['errors'] : [];
            if ($errors !== []) {
                $admission = ParallelGuard::rejectedByPolicyState($policy);
                ParallelGuard::assertSafe($policy);
            }

            foreach ($warnings as $warning) {
                fwrite(STDERR, 'WARN: ' . (string)$warning . PHP_EOL);
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
            $result = SuiteExecutor::execute($tests, $config, $buildCommand);

            $moduleScope = self::moduleScope($tests);
            $result['report_contract_version'] = (int)($config['report_contract_version'] ?? 2);
            $result['runner_capabilities']     = $config['runner_capabilities'] ?? [];
            $result['report_root']             = $reportRoot;
            $result['report_scope_rel']        = Paths::relativeToRepo($reportRoot);
            $result['match']                   = (string)($config['match'] ?? '');
            $result['selected_common_dir']     = self::commonDir($tests);
            $result['selected_module_scope']   = $moduleScope;
            $result['selected_test_count']     = count($tests);
            $result['selected_test_files']     = array_map(fn(array $t): string => (string)($t['rel'] ?? ''), $tests);
            $result['suite_status']            = self::suiteStatus($result, $tests, $config);
            $result['no_tests_reason']         = self::noTestsReason($result, $config);
            $result['run_id']                  = $runId;
            $result['meta_run_id']             = $metaRunId;
            $result['run_kind']                = 'suite';
            $result['report_keep']             = (int)($config['report_keep'] ?? 5);
            $result['runs_index_keep']         = (int)($config['runs_index_keep'] ?? $config['report_keep'] ?? 5);
            $result['filters']                 = [
                'suite' => (string)($config['suite_id'] ?? ''),
                'scope' => (string)($config['scope'] ?? 'all'),
                'category' => (string)($config['category'] ?? 'all'),
                'match' => (string)($config['match'] ?? ''),
            ];
            $result['summary']                 = [
                'total'       => (int)$result['tests_total'],
                'passed'      => (int)$result['pass'],
                'failed'      => (int)$result['fail'],
                'skipped'     => (int)$result['skip'],
                'duration_ms' => (int)$result['duration_ms'],
                'suite_status'=> (string)$result['suite_status'],
            ];
            $result['parallel_policy']         = [
                'jobs' => (int)($policy['jobs'] ?? 1),
                'db_strategy' => (string)($policy['db_strategy'] ?? 'shared'),
                'has_db_sensitive_tests' => (bool)($policy['has_db_sensitive_tests'] ?? false),
                'has_db_runtime' => (bool)($policy['has_db_runtime'] ?? false),
                'requires_db_isolation' => (bool)($policy['requires_db_isolation'] ?? false),
                'top_level_parallel_supported' => (bool)($policy['top_level_parallel_supported'] ?? true),
                'suite_lock_key' => (string)($policy['suite_lock_key'] ?? ''),
                'warnings' => $warnings,
            ];
            $result['concurrency_admission'] = $admission;
            $result['evidence_valid'] = true;
            $result['evidence_invalid_reason'] = null;

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

            ConsoleReporter::printSuiteResult($result);
            ResultWriter::writeSuite($result);

            return (int)$result['exit_code'];
        } catch (Throwable $e) {
            $result = self::buildOperationalFailureResult(
                config: $config,
                tests: $tests,
                reportRoot: $reportRoot,
                runId: $runId,
                metaRunId: $metaRunId,
                policy: $policy,
                warnings: $warnings,
                admission: $admission,
                error: $e
            );

            ConsoleReporter::printSuiteResult($result);
            ResultWriter::writeSuite($result);

            return SuiteExecutor::EXIT_ERROR;
        } finally {
            $lockLease?->release();
        }
    }

    /**
     * Return the single functional module scope ("back/auth") if all tests share one, else "".
     *
     * @param array<int,array<string,mixed>> $tests
     */
    private static function moduleScope(array $tests): string
    {
        if (empty($tests)) {
            return '';
        }
        $modules = [];
        foreach ($tests as $t) {
            $m = Paths::extractFunctionalModule((string)($t['rel'] ?? ''));
            if ($m === null) {
                return '';
            }
            $modules[$m] = true;
        }
        return count($modules) === 1 ? (string)array_key_first($modules) : '';
    }

    /**
     * Longest common directory prefix of the selected test rel-paths.
     *
     * @param array<int,array<string,mixed>> $tests
     */
    private static function commonDir(array $tests): string
    {
        if (empty($tests)) {
            return '';
        }
        $dirs = array_unique(array_map(
            fn(array $t): string => dirname(str_replace('\\', '/', (string)($t['rel'] ?? ''))),
            $tests
        ));
        if (count($dirs) === 1) {
            return reset($dirs) ?: '';
        }
        $parts = array_map(
            fn(string $d): array => array_values(array_filter(explode('/', $d), fn(string $p): bool => $p !== '')),
            array_values($dirs)
        );
        $minLen = min(array_map('count', $parts));
        $common = [];
        for ($i = 0; $i < $minLen; $i++) {
            $seg = $parts[0][$i];
            foreach ($parts as $p) {
                if ($p[$i] !== $seg) {
                    break 2;
                }
            }
            $common[] = $seg;
        }
        return implode('/', $common);
    }

    /**
     * @param array<string,mixed> $result
     * @param array<int,array<string,mixed>> $tests
     * @param array<string,mixed> $config
     */
    private static function suiteStatus(array $result, array $tests, array $config): string
    {
        if ((bool)($config['list_only'] ?? false)) {
            return 'listed';
        }

        if ($tests === []) {
            return 'no_tests';
        }

        $fail = (int)($result['fail'] ?? 0);
        $pass = (int)($result['pass'] ?? 0);
        $skip = (int)($result['skip'] ?? 0);

        if ($fail > 0) {
            return 'failed';
        }

        if ($pass === 0 && $skip > 0) {
            return 'all_skipped';
        }

        return 'passed';
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $config
     */
    private static function noTestsReason(array $result, array $config): ?string
    {
        if ((string)($result['suite_status'] ?? '') !== 'no_tests') {
            return null;
        }

        $scope = (string)($config['scope'] ?? 'all');
        $category = (string)($config['category'] ?? 'all');
        $match = trim((string)($config['match'] ?? ''));

        $message = "no tests matched the current filters (scope={$scope}, category={$category}";
        if ($match !== '') {
            $message .= ", match={$match}";
        }
        $message .= ')';

        return $message;
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
     * @param array<string,mixed> $config
     * @param array<int,array<string,mixed>> $tests
     * @param array<string,mixed> $policy
     * @param array<int,string> $warnings
     * @param array<string,mixed> $admission
     * @return array<string,mixed>
     */
    private static function buildOperationalFailureResult(
        array $config,
        array $tests,
        string $reportRoot,
        string $runId,
        string $metaRunId,
        array $policy,
        array $warnings,
        array $admission,
        Throwable $error
    ): array {
        $moduleScope = self::moduleScope($tests);
        $failureKind = (string)($admission['reason'] ?? '') === 'shared_store_locked'
            ? 'environment_conflict'
            : 'setup_failure';

        $failure = ReportSummary::buildThrowableFailure($error, [
            'test_id' => (string)($config['suite_id'] ?? 'suite') . '.bootstrap',
            'test_name' => (string)($config['suite_id'] ?? 'suite') . '.bootstrap',
            'case' => (string)($config['suite_id'] ?? 'suite') . '.bootstrap',
            'suite_id' => (string)($config['suite_id'] ?? 'suite'),
            'suite' => (string)($config['suite_id'] ?? 'suite'),
            'scope' => (string)($config['scope'] ?? 'all'),
            'category' => (string)($config['category'] ?? 'all'),
            'file' => '',
            'kind' => $failureKind,
            'artifact_path' => Paths::relativeToRepo($reportRoot),
        ]);

        $selectedTestFiles = array_map(fn(array $t): string => (string)($t['rel'] ?? ''), $tests);
        $durationMs = 0;

        return [
            'suite_id' => (string)($config['suite_id'] ?? 'suite'),
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
            'duration_ms' => $durationMs,
            'list_only' => (bool)($config['list_only'] ?? false),
            'require_tests' => (bool)($config['require_tests'] ?? false),
            'jobs' => (int)($config['jobs'] ?? 1),
            'module_summary' => [],
            'report_contract_version' => (int)($config['report_contract_version'] ?? 2),
            'runner_capabilities' => $config['runner_capabilities'] ?? [],
            'report_root' => $reportRoot,
            'report_scope_rel' => Paths::relativeToRepo($reportRoot),
            'match' => (string)($config['match'] ?? ''),
            'selected_common_dir' => self::commonDir($tests),
            'selected_module_scope' => $moduleScope,
            'selected_test_count' => count($tests),
            'selected_test_files' => $selectedTestFiles,
            'suite_status' => $tests === [] ? 'no_tests' : 'failed',
            'no_tests_reason' => $tests === [] ? self::noTestsReason(['suite_status' => 'no_tests'], $config) : null,
            'run_id' => $runId,
            'meta_run_id' => $metaRunId,
            'run_kind' => 'suite',
            'report_keep' => (int)($config['report_keep'] ?? 5),
            'runs_index_keep' => (int)($config['runs_index_keep'] ?? $config['report_keep'] ?? 5),
            'filters' => [
                'suite' => (string)($config['suite_id'] ?? ''),
                'scope' => (string)($config['scope'] ?? 'all'),
                'category' => (string)($config['category'] ?? 'all'),
                'match' => (string)($config['match'] ?? ''),
            ],
            'summary' => [
                'total' => count($tests),
                'passed' => 0,
                'failed' => 1,
                'skipped' => 0,
                'duration_ms' => $durationMs,
                'suite_status' => $tests === [] ? 'no_tests' : 'failed',
            ],
            'parallel_policy' => [
                'jobs' => (int)($policy['jobs'] ?? $config['jobs'] ?? 1),
                'db_strategy' => (string)($policy['db_strategy'] ?? 'shared'),
                'has_db_sensitive_tests' => (bool)($policy['has_db_sensitive_tests'] ?? false),
                'has_db_runtime' => (bool)($policy['has_db_runtime'] ?? false),
                'requires_db_isolation' => (bool)($policy['requires_db_isolation'] ?? false),
                'top_level_parallel_supported' => (bool)($policy['top_level_parallel_supported'] ?? true),
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
        ];
    }
}
