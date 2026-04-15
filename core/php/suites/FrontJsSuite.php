<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Throwable;
use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;
use Testkit\Core\Config\RunnerConfig;
use Testkit\Core\Discovery\TestDiscovery;
use Testkit\Core\Execution\ParallelGuard;
use Testkit\Core\Execution\ProcessRunner;
use Testkit\Core\Execution\SuiteExecutor;
use Testkit\Core\Reporting\HistoryRepository;
use Testkit\Core\Reporting\ReportSummary;
use Testkit\Core\Reporting\ResultWriter;
use Testkit\Core\Reporting\StructuredWarnings;
use Testkit\Core\Seeding\SuiteSeedState;

final class FrontJsSuite
{
    public static function run(): int
    {
        $repoRoot = Paths::repoRoot();
        $testsRel = Env::string('TK_FRONT_JS_DIR', 'test/front');
        $testsRoot = $repoRoot . '/' . $testsRel;
        $testsDir = is_dir($testsRoot . '/tests') ? ($testsRoot . '/tests') : $testsRoot;

        $config = RunnerConfig::forSuite(
            'front_js',
            $testsDir,
            $repoRoot . '/test/coverage/js_front',
            'js'
        );

        $discovered = [];
        $reportRoot = Paths::reportsRoot();
        $moduleScope = '';
        $currentPhase = 'discovery';

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
        $resultPayloadFile = self::createTempFile('tk_front_js_result_');

        try {
            $discovered = TestDiscovery::discover((string)$config['tests_dir'], ['.test.mjs'], $config);
            $reportRoot = Paths::resolveReportRoot($discovered);
            $moduleScope = self::moduleScope($discovered);
            Paths::ensureDir($reportRoot);
            Paths::recordSuiteReportRoot($reportRoot, 'front_js');

            $currentPhase = 'bootstrap';
            ContractWorldBootstrap::prepare('front_js', $repoRoot);

            $currentPhase = 'admission';
            $policy = ParallelGuard::evaluate($discovered, $config, Paths::repoRoot());
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
                fwrite(STDERR, 'WARN[' . $code . '] ' . $summary . PHP_EOL);
            }

            $runner = Paths::testkitRoot() . '/runners/runFrontTest.mjs';
            if (!is_file($runner)) {
                throw new \RuntimeException('Falta runner JS: ' . $runner);
            }

            $nodePath = self::findBin((string)$config['node_binary']);
            if ($nodePath === null) {
                $requireNode = (bool)$config['js_require_node'];
                throw new \RuntimeException(($requireNode ? 'FAIL' : 'SKIP') . ': no se encontro ' . "'" . (string)$config['node_binary'] . "' en PATH.");
            }

            $selectedFile = self::writeSelectedTestsFile($discovered);
            try {
                try {
                    $lockLease = ParallelGuard::acquireSuiteStoreLock($policy);
                } catch (Throwable $e) {
                    $admission = ParallelGuard::rejectedByLockState($policy);
                    throw $e;
                }

                $env = self::baseEnv();
                $env['TESTKIT_ROOT'] = Paths::testkitRoot();
                $env['TK_REPO_ROOT'] = $repoRoot;
                $env['TESTKIT_REPORT_ROOT'] = $reportRoot;
                $env['TESTKIT_REPORT_SCOPE_REL'] = Paths::relativeToRepo($reportRoot);
                $env['TESTKIT_SELECTED_MODULE_SCOPE'] = $moduleScope;
                $env['TESTKIT_SELECTED_TESTS_FILE'] = $selectedFile;
                $env['TESTKIT_FRONT_JS_RESULT_FILE'] = $resultPayloadFile;
                $env['TEST_SCOPE'] = (string)$config['scope'];
                $env['TEST_CATEGORY'] = (string)$config['category'];
                $env['TEST_MATCH'] = (string)$config['match'];
                $env['TEST_LIST'] = (bool)$config['list_only'] ? '1' : '0';
                $env['TEST_FAIL_FAST'] = (bool)$config['fail_fast'] ? '1' : '0';
                $env['TEST_JOBS'] = (string)$config['jobs'];
                $env['TEST_REQUIRE_TESTS'] = (bool)$config['require_tests'] ? '1' : '0';
                $env['TEST_JS_REQUIRE_TESTS'] = (bool)$config['require_tests'] ? '1' : '0';
                $env['TEST_SLOW_THRESHOLD_MS'] = (string)($config['thresholds']['slow_ms'] ?? 1500);
                $env['TEST_SLOW_TOP'] = (string)($config['thresholds']['slow_top'] ?? 10);
                $env['TEST_PERF_MAX_MS'] = (string)($config['thresholds']['perf_max_ms'] ?? 0);
                $env['TEST_PERF_WARN_MS'] = (string)($config['thresholds']['perf_warn_ms'] ?? 0);
                $env['TEST_RUN_ID'] = $runId;
                $env['TEST_META_RUN_ID'] = $metaRunId;

                $currentPhase = 'execution';
                $job = ProcessRunner::start([$nodePath, $runner], $repoRoot, $env);
                $done = ProcessRunner::finish($job);
            } finally {
                @unlink($selectedFile);
            }

            $stdout = (string)($done['stdout'] ?? '');
            $stderr = (string)($done['stderr'] ?? '');
            if ($stdout !== '') {
                fwrite(STDOUT, $stdout);
            }
            if ($stderr !== '') {
                fwrite(STDERR, $stderr);
            }

            $currentPhase = 'reporting';
            $report = self::loadRunnerReportPayload($resultPayloadFile);
            $report = self::decorateRunnerReport($report, $config, $policy, $warnings, $admission, $runId, $metaRunId);
            self::safeWriteSuite($report, 'front_js.persistReport');

            return (int)($done['code'] ?? 1);
        } catch (Throwable $e) {
            $result = self::buildOperationalFailureResult(
                config: $config,
                tests: $discovered,
                reportRoot: $reportRoot,
                runId: $runId,
                metaRunId: $metaRunId,
                policy: $policy,
                warnings: $warnings,
                admission: $admission,
                phase: $currentPhase,
                error: $e
            );
            $result = SuiteSeedState::attachToReport($result, Paths::repoRoot());
            $result = ReportSummary::enrichReport($result);
            self::safeWriteSuite($result, 'front_js.operational_failure');
            return SuiteExecutor::EXIT_ERROR;
        } finally {
            @unlink($resultPayloadFile);
            $lockLease?->release();
        }
    }

    /**
     * @param array<int,array<string,mixed>> $tests
     */
    private static function writeSelectedTestsFile(array $tests): string
    {
        $file = self::createTempFile('tk_front_js_');

        $json = json_encode(array_values($tests), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('No se pudo serializar seleccion de tests JS.');
        }

        file_put_contents($file, $json);
        return $file;
    }

    private static function createTempFile(string $prefix): string
    {
        $file = tempnam(sys_get_temp_dir(), $prefix);
        if ($file === false) {
            throw new \RuntimeException('No se pudo crear archivo temporal: ' . $prefix);
        }

        return $file;
    }

    /**
     * @return array<string,mixed>
     */
    private static function loadRunnerReportPayload(string $file): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException('El runner JS no emitio payload de reporte.');
        }

        $json = trim((string)file_get_contents($file));
        if ($json === '') {
            throw new \RuntimeException('El payload de reporte JS esta vacio.');
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('El payload de reporte JS no es JSON valido: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('El payload de reporte JS no tiene forma de objeto.');
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $config
     * @param array<string,mixed> $policy
     * @param array<int,array<string,mixed>> $warnings
     * @param array<string,mixed> $admission
     * @return array<string,mixed>
     */
    private static function decorateRunnerReport(array $report, array $config, array $policy, array $warnings, array $admission, string $runId, string $metaRunId): array
    {
        $report['report_contract_version'] = (int)($config['report_contract_version'] ?? $report['report_contract_version'] ?? 2);
        $report['runner_contract_version'] = (int)($config['runner_contract_version'] ?? 1);
        $report['runner_capabilities'] = $config['runner_capabilities'] ?? ($report['runner_capabilities'] ?? []);
        $report['runner_hazards'] = $config['runner_hazards'] ?? ($report['runner_hazards'] ?? []);
        $report['runner_contract'] = [
            'version' => (int)($config['runner_contract_version'] ?? 1),
            'capabilities' => $config['runner_capabilities'] ?? [],
            'hazards' => $config['runner_hazards'] ?? [],
        ];
        $report['suite_id'] = (string)($report['suite_id'] ?? 'front_js');
        $report['language'] = (string)($report['language'] ?? 'js');
        $report['suite_status'] = self::suiteStatus($report);
        $report['no_tests_reason'] = self::noTestsReason($report);
        $report['run_id'] = $runId;
        $report['meta_run_id'] = $metaRunId;
        $report['run_kind'] = 'suite';
        $report['parallel_policy'] = [
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
        ];
        $report['concurrency_admission'] = $admission;
        $report['evidence_valid'] = true;
        $report['evidence_invalid_reason'] = null;

        $history = HistoryRepository::updateAndAnalyze(
            $report,
            (int)($config['thresholds']['flake_window'] ?? 10)
        );
        $report['history_file'] = $history['history_file'];
        $report['fragility_hints'] = $history['fragility_hints'];

        $report = SuiteSeedState::attachToReport($report, Paths::repoRoot());
        return ReportSummary::enrichReport($report);
    }

    /**
     * @param array<string,mixed> $report
     */
    private static function suiteStatus(array $report): string
    {
        if (!empty($report['suite_status']) && is_string($report['suite_status'])) {
            return (string)$report['suite_status'];
        }

        if ((bool)($report['list_only'] ?? false)) {
            return 'listed';
        }

        $total = (int)($report['selected_test_count'] ?? $report['tests_total'] ?? 0);
        $pass = (int)($report['pass'] ?? 0);
        $fail = (int)($report['fail'] ?? 0);
        $skip = (int)($report['skip'] ?? 0);

        if ($total === 0) {
            return 'no_tests';
        }
        if ($fail > 0) {
            return 'failed';
        }
        if ($pass === 0 && $skip > 0) {
            return 'all_skipped';
        }

        return 'passed';
    }

    /**
     * @param array<string,mixed> $report
     */
    private static function noTestsReason(array $report): ?string
    {
        if ((string)($report['suite_status'] ?? '') !== 'no_tests' && self::suiteStatus($report) !== 'no_tests') {
            return null;
        }

        $reason = trim((string)($report['no_tests_reason'] ?? ''));
        if ($reason !== '') {
            return $reason;
        }

        $scope = (string)($report['scope'] ?? 'all');
        $category = (string)($report['category'] ?? 'all');
        $match = trim((string)($report['match'] ?? ''));
        $msg = "no tests matched the current filters (scope={$scope}, category={$category}";
        if ($match !== '') {
            $msg .= ", match={$match}";
        }
        $msg .= ')';
        return $msg;
    }

    /**
     * @param array<int,array<string,mixed>> $tests
     */
    private static function moduleScope(array $tests): string
    {
        if ($tests === []) {
            return '';
        }

        $modules = [];
        foreach ($tests as $test) {
            $module = Paths::extractFunctionalModule((string)($test['rel'] ?? ''));
            if ($module === null) {
                return '';
            }
            $modules[$module] = true;
        }

        return count($modules) === 1 ? (string)array_key_first($modules) : '';
    }

    private static function findBin(string $bin): ?string
    {
        $bin = trim($bin);
        if ($bin === '') {
            return null;
        }

        if (strpbrk($bin, '/\\') !== false) {
            return (is_file($bin) && is_executable($bin)) ? $bin : null;
        }

        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return null;
        }

        $candidates = [$bin];
        if (PHP_OS_FAMILY === 'Windows' && pathinfo($bin, PATHINFO_EXTENSION) === '') {
            $candidates = [$bin . '.exe', $bin . '.cmd', $bin . '.bat', $bin];
        }

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $dir = trim($dir);
            if ($dir === '') {
                continue;
            }

            foreach ($candidates as $candidate) {
                $file = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $candidate;
                if (is_file($file) && is_executable($file)) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string,string>
     */
    private static function baseEnv(): array
    {
        $env = [];
        $raw = getenv();
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                if (!is_string($k) || $k === '' || !is_scalar($v)) {
                    continue;
                }
                $env[$k] = (string)$v;
            }
        }
        return $env;
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
     * @param array<string,mixed> $report
     */
    private static function safeWriteSuite(array $report, string $context): void
    {
        try {
            ResultWriter::writeSuite($report);
        } catch (Throwable $e) {
            $root = trim((string)($report['report_root'] ?? ''));
            $scope = $root !== '' ? ' root=' . $root : '';
            fwrite(STDERR, 'WARN[REPORT_WRITE_FAILED] ' . $context . $scope . ': ' . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * @param array<string,mixed> $config
     * @param array<int,array<string,mixed>> $tests
     * @param array<string,mixed> $policy
     * @param array<int,array<string,mixed>> $warnings
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
        string $phase,
        Throwable $error
    ): array {
        $moduleScope = self::moduleScope($tests);
        $admissionReason = (string)($admission['reason'] ?? '');
        $failureKind = match (true) {
            in_array($admissionReason, ['shared_store_locked', 'store_resource_locked'], true) => 'environment_conflict',
            $phase === 'discovery' => 'discovery_failure',
            $phase === 'reporting' => 'reporting_failure',
            $phase === 'bootstrap' => 'bootstrap_failure',
            default => 'bootstrap_failure',
        };
        $failurePhase = match (true) {
            $phase === 'discovery' => 'discovery',
            $phase === 'reporting' => 'reporting',
            in_array($admissionReason, ['shared_store_locked', 'store_resource_locked'], true) => 'store_setup',
            $phase === 'bootstrap' => 'bootstrap',
            default => 'execution',
        };
        $failureDomain = match (true) {
            $phase === 'discovery' => 'discovery',
            $phase === 'reporting' => 'reporting',
            in_array($admissionReason, ['shared_store_locked', 'store_resource_locked'], true) => 'store',
            $phase === 'bootstrap' => 'bootstrap',
            default => 'runner',
        };
        $causeCode = $admissionReason !== '' ? $admissionReason : match ($failurePhase) {
            'discovery' => 'discovery_failed',
            'reporting' => 'report_write_failed',
            'bootstrap' => 'bootstrap_failed',
            default => 'runner_exception',
        };

        $failure = ReportSummary::buildThrowableFailure($error, [
            'test_id' => 'front_js.bootstrap',
            'test_name' => 'front_js.bootstrap',
            'case' => 'front_js.bootstrap',
            'suite_id' => 'front_js',
            'suite' => 'front_js',
            'scope' => (string)($config['scope'] ?? 'all'),
            'category' => (string)($config['category'] ?? 'all'),
            'file' => '',
            'kind' => $failureKind,
            'phase' => $failurePhase,
            'failure_domain' => $failureDomain,
            'cause_code' => $causeCode,
            'artifact_path' => Paths::relativeToRepo($reportRoot),
        ]);

        $selectedTestFiles = array_map(static fn(array $t): string => (string)($t['rel'] ?? ''), $tests);
        $durationMs = 0;

        return [
            'suite_id' => 'front_js',
            'language' => 'js',
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
            'selected_common_dir' => '',
            'selected_module_scope' => $moduleScope,
            'selected_test_count' => count($tests),
            'selected_test_files' => $selectedTestFiles,
            'suite_status' => $tests === [] ? 'no_tests' : 'failed',
            'no_tests_reason' => $tests === [] ? self::noTestsReason(['suite_status' => 'no_tests']) : null,
            'run_id' => $runId,
            'meta_run_id' => $metaRunId,
            'run_kind' => 'suite',
            'report_keep' => (int)($config['report_keep'] ?? 5),
            'runs_index_keep' => (int)($config['runs_index_keep'] ?? $config['report_keep'] ?? 5),
            'filters' => [
                'suite' => 'front_js',
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
        ];
    }
}
