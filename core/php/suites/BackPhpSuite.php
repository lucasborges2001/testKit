<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Throwable;
use Testkit\Core\Common\AgentMode;
use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;
use Testkit\Core\Common\ProjectEnv;
use Testkit\Core\Config\RunnerConfig;
use Testkit\Core\Discovery\TestDiscovery;
use Testkit\Core\Discovery\TestSeedMetadata;
use Testkit\Core\Execution\SuiteExecutor;
use Testkit\Core\Reporting\ConsoleReporter;
use Testkit\Core\Reporting\HistoryRepository;
use Testkit\Core\Reporting\ReportSummary;
use Testkit\Core\Reporting\ResultWriter;
use Testkit\Core\Seeding\SuiteSeedState;

final class BackPhpSuite
{
    public static function run(): int
    {
        $repoRoot = Paths::repoRoot();
        $testkitRoot = Paths::testkitRoot();

        $testRel = Env::string('TK_BACK_PHP_DIR', 'test/back');
        $testsRoot = $repoRoot . '/' . $testRel;
        $testsDir = is_dir($testsRoot . '/tests') ? ($testsRoot . '/tests') : $testsRoot;

        $config = RunnerConfig::forSuite(
            'back_php',
            $testsDir,
            $repoRoot . '/test/coverage/php_back',
            'php'
        );

        $runId = self::ensureRunId('TEST_RUN_ID');
        $metaRunId = self::ensureRunId('TEST_META_RUN_ID', $runId);

        $selectedTests = [];
        $reportRoot = Paths::resolveReportRoot($selectedTests);
        Paths::ensureDir($reportRoot);
        Paths::recordSuiteReportRoot($reportRoot, 'back_php');

        $setupPhase = 'discovery';
        $resolved = ['db_env_path' => '', 'warnings' => []];

        try {
            $selectedTests = TestDiscovery::discover($testsDir, ['.test.php'], $config);
            $reportRoot = Paths::resolveReportRoot($selectedTests);
            Paths::ensureDir($reportRoot);
            Paths::recordSuiteReportRoot($reportRoot, 'back_php');

            TestSeedMetadata::applySeedEnv($selectedTests, (int)($config['metadata_lines'] ?? 60));

            $setupPhase = 'bootstrap';
            $resolved = ProjectEnv::resolveDbEnv($repoRoot);
            foreach (($resolved['warnings'] ?? []) as $warning) {
                fwrite(STDERR, $warning . PHP_EOL);
            }

            if (($resolved['db_env_path'] ?? '') !== '') {
                putenv('DB_ENV_PATH=' . $resolved['db_env_path']);
            }

            putenv('APP_ENV=test');
            putenv('APP_DEBUG=1');

            if (!(bool)$config['list_only'] && $selectedTests !== []) {
                ContractWorldBootstrap::prepare('back_php', $repoRoot);
            }
        } catch (Throwable $e) {
            return self::writePreSuiteOperationalFailure(
                config: $config,
                tests: $selectedTests,
                reportRoot: $reportRoot,
                runId: $runId,
                metaRunId: $metaRunId,
                phase: $setupPhase,
                error: $e
            );
        }

        $prepend = $testkitRoot . '/utils/php/auto_prepend.php';
        $phpBinary = self::phpBinary();

        if ((bool)$config['coverage']) {
            @mkdir((string)$config['coverage_dir'], 0777, true);
            foreach (glob((string)$config['coverage_dir'] . '/*.json') ?: [] as $file) {
                @unlink($file);
            }
        }

        return SuiteOrchestrator::run(
            $config,
            ['.test.php'],
            static function (array $test, int $workerId) use ($phpBinary, $prepend, $config, $repoRoot, $testkitRoot, $resolved): array {
                $cmd = [$phpBinary];
                $env = [
                    'TEST_SUITE' => 'back',
                    'APP_ENV' => 'test',
                    'APP_DEBUG' => '1',
                    'TESTKIT_ROOT' => $testkitRoot,
                    'TK_REPO_ROOT' => $repoRoot,
                    'TEST_WORKER_ID' => (string)$workerId,
                ];

                if (($resolved['db_env_path'] ?? '') !== '') {
                    $env['DB_ENV_PATH'] = $resolved['db_env_path'];
                }

                if ((bool)$config['coverage']) {
                    $cmd[] = '-d';
                    $cmd[] = 'xdebug.mode=coverage';
                    $cmd[] = '-d';
                    $cmd[] = 'xdebug.start_with_request=no';

                    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)$test['rel']) ?: 'test';
                    $env['TEST_COVERAGE'] = '1';
                    $env['TEST_COVERAGE_FILE'] = (string)$config['coverage_dir'] . '/' . $safe . '.json';
                    $env['TEST_COVERAGE_FORMAT'] = (string)$config['coverage_format'];
                    $env['TEST_COVERAGE_DIR'] = (string)$config['coverage_dir'];
                }

                $cmd[] = '-d';
                $cmd[] = 'auto_prepend_file=' . $prepend;
                $cmd[] = (string)$test['file'];

                return ['cmd' => $cmd, 'env' => $env];
            }
        );
    }

    private static function phpBinary(): string
    {
        $explicit = Env::string('TEST_PHP_BINARY', '');
        if ($explicit !== '' && is_file($explicit)) {
            return $explicit;
        }
        return PHP_BINARY;
    }

    private static function ensureRunId(string $key, string $default = ''): string
    {
        $value = Env::string($key, '');
        if ($value !== '') {
            return $value;
        }

        $value = $default !== '' ? $default : self::buildRunId();
        putenv($key . '=' . $value);
        return $value;
    }

    private static function buildRunId(): string
    {
        try {
            $suffix = bin2hex(random_bytes(3));
        } catch (Throwable $ignored) {
            $suffix = substr((string)sha1(uniqid('', true)), 0, 6);
        }

        return gmdate('Ymd\THis\Z') . '_' . $suffix;
    }

    /**
     * @param array<string,mixed> $config
     * @param array<int,array<string,mixed>> $tests
     */
    private static function writePreSuiteOperationalFailure(
        array $config,
        array $tests,
        string $reportRoot,
        string $runId,
        string $metaRunId,
        string $phase,
        Throwable $error
    ): int {
        $failureDefaults = self::failureDefaultsForPhase($phase);

        $result = SuiteOperationalFailure::build(
            config: $config,
            tests: $tests,
            reportRoot: $reportRoot,
            runId: $runId,
            metaRunId: $metaRunId,
            policy: [],
            warnings: [],
            admission: [
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
            ],
            phase: $phase,
            error: $error,
            options: [
                'default_failure_kind' => $failureDefaults['kind'],
                'default_failure_phase' => $failureDefaults['phase'],
                'default_failure_domain' => $failureDefaults['domain'],
                'default_cause_code' => $failureDefaults['cause_code'],
                'include_selection_manifest' => true,
                'selection_manifest_source' => 'back_php_pre_suite',
                'no_tests_discovery_failure' => true,
            ]
        );

        $result['agent_mode'] = is_array($config['agent_mode'] ?? null)
            ? $config['agent_mode']
            : AgentMode::reportPayload();
        $result['progress_policy'] = SuiteExecutor::progressPolicy();
        $result['execution_metrics'] = SuiteExecutor::executionMetricsSnapshot($result);
        $result = SuiteSeedState::attachToReport($result, Paths::repoRoot());
        $result = ReportSummary::enrichReport($result);

        ConsoleReporter::printSuiteResult($result);
        ResultWriter::writeSuite($result);
        HistoryRepository::recordSuiteMetrics($result);

        return SuiteExecutor::EXIT_ERROR;
    }

    /**
     * @return array{kind:string,phase:string,domain:string,cause_code:string}
     */
    private static function failureDefaultsForPhase(string $phase): array
    {
        if ($phase === 'discovery') {
            return [
                'kind' => 'discovery_failure',
                'phase' => 'discovery',
                'domain' => 'discovery',
                'cause_code' => 'discovery_failed',
            ];
        }

        return [
            'kind' => 'bootstrap_failure',
            'phase' => 'bootstrap',
            'domain' => 'bootstrap',
            'cause_code' => 'bootstrap_failed',
        ];
    }
}
