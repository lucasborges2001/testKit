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
use Testkit\Core\Reporting\ConsoleReporter;
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
        $warnings = StructuredWarnings::canonicalize($config['env_warnings'] ?? []);
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
            $discovered = TestDiscovery::discover((string)$config['tests_dir'], ['.test.mjs'], $config);
            if (!(bool)($config['list_only'] ?? false) && $discovered === [] && trim((string)($config['match'] ?? '')) !== '') {
                $config['require_tests'] = false;
            }
            $reportRoot = Paths::resolveReportRoot($discovered);
            $moduleScope = SuiteSelection::moduleScope($discovered);
            Paths::ensureDir($reportRoot);
            Paths::recordSuiteReportRoot($reportRoot, 'front_js');

            $currentPhase = 'admission';
            $policy = ParallelGuard::evaluate($discovered, $config, $repoRoot);
            $warnings = StructuredWarnings::canonicalize(array_merge(
                $warnings,
                StructuredWarnings::canonicalize($policy['warnings'] ?? [])
            ));
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

            ConsoleReporter::printSuiteStart($config, count($discovered));
            if ((bool)($config['list_only'] ?? false)) {
                ConsoleReporter::printList($discovered);
            }
            if ($discovered === []) {
                $currentPhase = 'reporting';
                $exitCode = (bool)($config['list_only'] ?? false)
                    ? SuiteExecutor::EXIT_PASS
                    : ((bool)($config['require_tests'] ?? false) ? SuiteExecutor::EXIT_FAIL : SuiteExecutor::EXIT_SKIP);
                $report = self::emptySelectionReport($config, $reportRoot, $policy, $warnings, $admission, $runId, $metaRunId, $exitCode);
                ConsoleReporter::printSuiteResult($report);
                self::safeWriteSuite($report, 'front_js.emptySelectionReport');
                return $exitCode;
            }

            $currentPhase = 'execution';
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
            $resultFile = self::createTempJsonFile('tk_front_js_result_');
            try {
                $requiresStoreBootstrap = !(bool)($config['list_only'] ?? false) && $discovered !== [];
                if ($requiresStoreBootstrap) {
                    $currentPhase = 'store_setup';
                    try {
                        $lockLease = ParallelGuard::acquireSuiteStoreLock($policy);
                    } catch (Throwable $e) {
                        $admission = ParallelGuard::rejectedByLockState($policy);
                        throw $e;
                    }

                    $currentPhase = 'bootstrap';
                    ContractWorldBootstrap::prepare('front_js', $repoRoot);
                }

                $env = self::baseEnv();
                $env['TESTKIT_ROOT'] = Paths::testkitRoot();
                $env['TK_REPO_ROOT'] = $repoRoot;
                $env['TESTKIT_REPORT_ROOT'] = $reportRoot;
                $env['TESTKIT_REPORT_SCOPE_REL'] = Paths::relativeToRepo($reportRoot);
                $env['TESTKIT_SELECTED_MODULE_SCOPE'] = $moduleScope;
                $env['TESTKIT_SELECTED_TESTS_FILE'] = $selectedFile;
                $env['TESTKIT_FRONT_JS_RESULT_FILE'] = $resultFile;
                $env['TESTKIT_EXTERNAL_REPORTER'] = '1';
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
                $report = self::loadNodeResultPayload($resultFile);
            } finally {
                @unlink($selectedFile);
                @unlink($resultFile);
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
            $report = self::decorateNodeReport($report, $config, $policy, $warnings, $admission, $runId, $metaRunId);
            ConsoleReporter::printSuiteResult($report);
            self::safeWriteSuite($report, 'front_js.decorateNodeReport');

            return (int)($done['code'] ?? 1);
        } catch (Throwable $e) {
            $result = SuiteOperationalFailure::build(
                config: $config,
                tests: $discovered,
                reportRoot: $reportRoot,
                runId: $runId,
                metaRunId: $metaRunId,
                policy: $policy,
                warnings: $warnings,
                admission: $admission,
                phase: $currentPhase,
                error: $e,
                options: self::operationalFailureOptions($currentPhase, $admission)
            );
            $result = SuiteSeedState::attachToReport($result, Paths::repoRoot());
            $result = ReportSummary::enrichReport($result);
            ConsoleReporter::printSuiteResult($result);
            self::safeWriteSuite($result, 'front_js.operational_failure');
            return SuiteExecutor::EXIT_ERROR;
        } finally {
            $lockLease?->release();
        }
    }

    /**
     * @param array<string,mixed> $admission
     * @return array<string,mixed>
     */
    private static function operationalFailureOptions(string $phase, array $admission): array
    {
        $reason = trim((string)($admission['reason'] ?? ''));
        $options = [
            'include_selection_manifest' => false,
            'no_tests_discovery_failure' => false,
            'default_failure_kind' => 'bootstrap_failure',
            'default_failure_phase' => 'execution',
            'default_failure_domain' => 'runner',
            'default_cause_code' => 'runner_exception',
        ];

        if ($phase === 'admission' || $reason === 'unsafe_parallel_db_configuration') {
            $options['default_failure_kind'] = 'configuration_failure';
            $options['default_failure_phase'] = 'admission';
            $options['default_failure_domain'] = 'configuration';
            $options['default_cause_code'] = $reason !== '' ? $reason : 'admission_rejected';
            return $options;
        }

        if ($phase === 'bootstrap' || $phase === 'store_setup') {
            $options['default_failure_kind'] = 'bootstrap_failure';
            $options['default_failure_phase'] = 'bootstrap';
            $options['default_failure_domain'] = 'bootstrap';
            $options['default_cause_code'] = 'bootstrap_failed';
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $policy
     * @param array<int,array<string,mixed>> $warnings
     * @param array<string,mixed> $admission
     * @return array<string,mixed>
     */
    private static function emptySelectionReport(
        array $config,
        string $reportRoot,
        array $policy,
        array $warnings,
        array $admission,
        string $runId,
        string $metaRunId,
        int $exitCode
    ): array {
        $report = [
            'suite_id' => 'front_js',
            'language' => 'js',
            'scope' => (string)($config['scope'] ?? 'all'),
            'category' => (string)($config['category'] ?? 'all'),
            'tests_total' => 0,
            'pass' => 0,
            'fail' => 0,
            'skip' => 0,
            'timeout' => 0,
            'tests' => [],
            'failed_tests' => [],
            'slow_tests' => [],
            'perf_violations' => [],
            'exit_code' => $exitCode,
            'duration_ms' => 0,
            'list_only' => (bool)($config['list_only'] ?? false),
            'require_tests' => (bool)($config['require_tests'] ?? false),
            'jobs' => (int)($config['jobs'] ?? 1),
            'report_root' => $reportRoot,
            'report_scope_rel' => Paths::relativeToRepo($reportRoot),
            'selected_common_dir' => '',
            'selected_module_scope' => '',
            'selected_test_count' => 0,
            'selected_test_files' => [],
            'warnings' => $warnings,
            'filters' => [
                'suite' => 'front_js',
                'scope' => (string)($config['scope'] ?? 'all'),
                'category' => (string)($config['category'] ?? 'all'),
                'match' => (string)($config['match'] ?? ''),
            ],
        ];

        return self::decorateNodeReport($report, $config, $policy, $warnings, $admission, $runId, $metaRunId);
    }

    /**
     * @param array<int,array<string,mixed>> $tests
     */
    private static function writeSelectedTestsFile(array $tests): string
    {
        $file = tempnam(sys_get_temp_dir(), 'tk_front_js_');
        if ($file === false) {
            throw new \RuntimeException('No se pudo crear archivo temporal para seleccion JS.');
        }

        $json = json_encode(array_values($tests), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('No se pudo serializar seleccion de tests JS.');
        }

        file_put_contents($file, $json);
        return $file;
    }

    private static function createTempJsonFile(string $prefix): string
    {
        $file = tempnam(sys_get_temp_dir(), $prefix);
        if ($file === false) {
            throw new \RuntimeException('No se pudo crear archivo temporal para el resultado JS.');
        }

        return $file;
    }

    /**
     * @return array<string,mixed>
     */
    private static function loadNodeResultPayload(string $file): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException('El runner JS no emitio el payload esperado.');
        }

        $raw = trim((string)file_get_contents($file));
        if ($raw === '') {
            throw new \RuntimeException('El runner JS emitio un payload vacio.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('El payload del runner JS no es JSON valido.');
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
    private static function decorateNodeReport(array $report, array $config, array $policy, array $warnings, array $admission, string $runId, string $metaRunId): array
    {
        $report['report_contract_version'] = (int)($config['report_contract_version'] ?? 2);
        $report['runner_contract_version'] = (int)($config['runner_contract_version'] ?? 1);
        $report['runner_capabilities'] = $config['runner_capabilities'] ?? [];
        $report['runner_hazards'] = $config['runner_hazards'] ?? [];
        $report['runner_contract'] = [
            'version' => (int)($config['runner_contract_version'] ?? 1),
            'capabilities' => $config['runner_capabilities'] ?? [],
            'hazards' => $config['runner_hazards'] ?? [],
        ];
        $report['suite_status'] = SuiteSelection::suiteStatus(
            $report,
            self::selectedTestsFromReport($report),
            $config
        );
        $report['no_tests_reason'] = SuiteSelection::noTestsReason($report, $config);
        $report['run_id'] = $runId;
        $report['meta_run_id'] = $metaRunId;
        $report['run_kind'] = 'suite';
        $report['report_keep'] = (int)($config['report_keep'] ?? 5);
        $report['runs_index_keep'] = (int)($config['runs_index_keep'] ?? $config['report_keep'] ?? 5);
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
     * @return array<int,array<string,mixed>>
     */
    private static function selectedTestsFromReport(array $report): array
    {
        $files = is_array($report['selected_test_files'] ?? null) ? $report['selected_test_files'] : [];
        $tests = [];
        foreach ($files as $file) {
            if (!is_string($file) || trim($file) === '') {
                continue;
            }
            $tests[] = ['rel' => $file];
        }
        return $tests;
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

        return gmdate('Ymd\\THis\\Z') . '_' . $suffix;
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
}
