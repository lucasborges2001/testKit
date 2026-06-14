<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Throwable;
use Testkit\Core\Common\AgentMode;
use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;
use Testkit\Core\Common\ProjectEnv;
use Testkit\Core\Config\RunnerConfig;
use Testkit\Core\DbProfiling\MysqlProfileConfig;
use Testkit\Core\DbProfiling\MysqlProfileReporter;
use Testkit\Core\Discovery\PhpDiscoveryConfig;
use Testkit\Core\Discovery\TestDiscovery;
use Testkit\Core\Discovery\TestSeedMetadata;
use Testkit\Core\Execution\SuiteExecutor;
use Testkit\Core\InfluxProfiling\InfluxProfileConfig;
use Testkit\Core\InfluxProfiling\InfluxProfileReporter;
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

        $discoveryConfig = PhpDiscoveryConfig::backPhpFromEnv($repoRoot);
        $testsDir = (string)$discoveryConfig['tests_dir'];

        $config = RunnerConfig::forSuite(
            'back_php',
            $testsDir,
            Paths::legacyCoverageDirForSuite('back_php'),
            'php'
        );

        if (isset($discoveryConfig['discovery_options']) && is_array($discoveryConfig['discovery_options'])) {
            $config['discovery_options'] = $discoveryConfig['discovery_options'];
        }
        self::attachDiscoveryWarnings($config, $discoveryConfig['warnings'] ?? []);

        $runId = self::ensureRunId('TEST_RUN_ID');
        $metaRunId = self::ensureRunId('TEST_META_RUN_ID', $runId);
        $mysqlProfileEnabled = MysqlProfileConfig::isEnabled();
        $mysqlProfileEnv = [];
        if ($mysqlProfileEnabled) {
            putenv('TESTKIT_DB_PROFILE_RUN_ID=' . $runId);
            $_ENV['TESTKIT_DB_PROFILE_RUN_ID'] = $runId;
            $_SERVER['TESTKIT_DB_PROFILE_RUN_ID'] = $runId;

            $mysqlProfileConfig = MysqlProfileConfig::fromEnv();
            $mysqlProfileEnv = [
                'TESTKIT_DB_PROFILE' => '1',
                'TESTKIT_DB_PROFILE_RUN_ID' => $runId,
                'TESTKIT_DB_PROFILE_REPORT_PATH' => (string)($mysqlProfileConfig['output']['report_path'] ?? ''),
                'TESTKIT_DB_PROFILE_HISTORY_PATH' => (string)($mysqlProfileConfig['output']['history_path'] ?? ''),
                'TESTKIT_DB_PROFILE_SHARD_DIR' => (string)($mysqlProfileConfig['output']['shard_dir'] ?? ''),
            ];
            if ((bool)($mysqlProfileConfig['explain']['enabled'] ?? false)) {
                $mysqlProfileEnv['TESTKIT_DB_PROFILE_EXPLAIN'] = '1';
                $mysqlProfileEnv['TESTKIT_DB_PROFILE_EXPLAIN_MAX_QUERIES'] = (string)($mysqlProfileConfig['explain']['max_queries'] ?? 20);
                $mysqlProfileEnv['TESTKIT_DB_PROFILE_EXPLAIN_TIMEOUT_MS'] = (string)($mysqlProfileConfig['explain']['timeout_ms'] ?? 2000);
                $queriesFile = (string)($mysqlProfileConfig['explain']['queries_file'] ?? '');
                if ($queriesFile !== '') {
                    $mysqlProfileEnv['TESTKIT_DB_PROFILE_EXPLAIN_QUERIES_FILE'] = $queriesFile;
                }
            }
            MysqlProfileReporter::prepareRun($runId);
        }

        $influxProfileEnabled = InfluxProfileConfig::isEnabled();
        $influxProfileEnv = [];
        if ($influxProfileEnabled) {
            putenv('TESTKIT_INFLUX_PROFILE_RUN_ID=' . $runId);
            $_ENV['TESTKIT_INFLUX_PROFILE_RUN_ID'] = $runId;
            $_SERVER['TESTKIT_INFLUX_PROFILE_RUN_ID'] = $runId;

            $influxProfileConfig = InfluxProfileConfig::fromEnv();
            $influxProfileEnv = [
                'TESTKIT_INFLUX_PROFILE' => '1',
                'TESTKIT_INFLUX_PROFILE_RUN_ID' => $runId,
                'TESTKIT_INFLUX_PROFILE_REPORT_PATH' => (string)($influxProfileConfig['output']['report_path'] ?? ''),
                'TESTKIT_INFLUX_PROFILE_HISTORY_PATH' => (string)($influxProfileConfig['output']['history_path'] ?? ''),
                'TESTKIT_INFLUX_PROFILE_SHARD_DIR' => (string)($influxProfileConfig['output']['shard_dir'] ?? ''),
            ];
            InfluxProfileReporter::prepareRun($runId);
        }

        $profileEnv = array_merge($mysqlProfileEnv, $influxProfileEnv);

        $selectedTests = [];
        $reportRoot = Paths::resolveReportRoot($selectedTests);
        Paths::ensureDir($reportRoot);
        Paths::recordSuiteReportRoot($reportRoot, 'back_php');

        $setupPhase = 'discovery';
        $resolved = ['db_env_path' => '', 'warnings' => []];

        try {
            $selectedTests = SuiteOrchestrator::discoverTests($config, ['.test.php']);
            $reportRoot = Paths::resolveReportRoot($selectedTests);
            Paths::ensureDir($reportRoot);
            Paths::recordSuiteReportRoot($reportRoot, 'back_php');

            self::attachLegacySeedMetadataWarnings(
                $config,
                TestSeedMetadata::applySeedEnvIfLegacyEnabled($selectedTests, (int)($config['metadata_lines'] ?? 60))
            );

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

        if ($mysqlProfileEnabled && $influxProfileEnabled) {
            $prepend = $testkitRoot . '/utils/php/auto_prepend_query_profiles.php';
        } elseif ($mysqlProfileEnabled) {
            $prepend = $testkitRoot . '/utils/php/auto_prepend_mysql_profile.php';
        } elseif ($influxProfileEnabled) {
            $prepend = $testkitRoot . '/utils/php/auto_prepend_influx_profile.php';
        } else {
            $prepend = $testkitRoot . '/utils/php/auto_prepend.php';
        }
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
            static function (array $test, int $workerId) use ($phpBinary, $prepend, $config, $repoRoot, $testkitRoot, $resolved, $profileEnv): array {
                $cmd = [$phpBinary];
                $env = array_merge([
                    'TEST_SUITE' => 'back',
                    'APP_ENV' => 'test',
                    'APP_DEBUG' => '1',
                    'TESTKIT_ROOT' => $testkitRoot,
                    'TK_REPO_ROOT' => $repoRoot,
                    'TEST_WORKER_ID' => (string)$workerId,
                ], $profileEnv);

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
                    $env['TEST_COVERAGE_ROOT'] = (string)$config['coverage_root'];
                    $env['TEST_COVERAGE_DIR'] = (string)$config['coverage_dir'];
                }

                $cmd[] = '-d';
                $cmd[] = 'auto_prepend_file=' . $prepend;
                $cmd[] = (string)$test['file'];

                return ['cmd' => $cmd, 'env' => $env];
            },
            static function (array &$result, array $config) use ($mysqlProfileEnabled, $influxProfileEnabled, $runId): void {
                if ($mysqlProfileEnabled) {
                    $profile = MysqlProfileReporter::safeWriteLatestFromShards($runId, [
                        'suite_id' => (string)($config['suite_id'] ?? 'back_php'),
                    ]);
                    $result['mysql_profile'] = MysqlProfileReporter::suiteAttachment($profile);
                }

                if ($influxProfileEnabled) {
                    $profile = InfluxProfileReporter::safeWriteLatestFromShards($runId, [
                        'suite_id' => (string)($config['suite_id'] ?? 'back_php'),
                    ]);
                    $result['influx_profile'] = InfluxProfileReporter::suiteAttachment($profile);
                }
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
            warnings: is_array($config['env_warnings'] ?? null) ? $config['env_warnings'] : [],
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

    /** @return array{kind:string,phase:string,domain:string,cause_code:string} */
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

    /**
     * @param array<string,mixed> $config
     * @param array<int,array<string,mixed>> $warnings
     */
    private static function attachDiscoveryWarnings(array &$config, array $warnings): void
    {
        if ($warnings === []) {
            return;
        }
        $existing = is_array($config['env_warnings'] ?? null) ? $config['env_warnings'] : [];
        $config['env_warnings'] = array_values(array_merge($existing, $warnings));
    }

    /**
     * @param array<string,mixed> $config
     * @param array<int,array<string,mixed>> $warnings
     */
    private static function attachLegacySeedMetadataWarnings(array &$config, array $warnings): void
    {
        if ($warnings === []) {
            return;
        }

        $existing = is_array($config['env_warnings'] ?? null) ? $config['env_warnings'] : [];
        $config['env_warnings'] = array_values(array_merge($existing, $warnings));

        foreach ($warnings as $warning) {
            $code = (string)($warning['code'] ?? 'LEGACY_TEST_SEED_METADATA_USED');
            $summary = (string)($warning['summary'] ?? 'legacy seed metadata used');
            fwrite(STDERR, 'WARN[' . $code . ']: ' . $summary . PHP_EOL);
        }
    }
}
