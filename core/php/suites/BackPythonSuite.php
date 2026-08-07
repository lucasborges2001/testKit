<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;
use Testkit\Core\Config\RunnerConfig;
use Testkit\Core\Discovery\TestDiscovery;
use Testkit\Core\Execution\ProcessRunner;
use Testkit\Core\Store\StoreRegistry;

final class BackPythonSuite
{
    public static function run(): int
    {
        $repoRoot = Paths::repoRoot();
        $testkitRoot = Paths::testkitRoot();
        $testRel = Env::string('TK_BACK_PYTHON_DIR', 'test/back');
        $testsRoot = $repoRoot . '/' . $testRel;

        $config = RunnerConfig::forSuite(
            'back_python',
            $testsRoot,
            Paths::legacyCoverageDirForSuite('back_python'),
            'python'
        );
        $selectedTests = TestDiscovery::discover((string)$config['tests_dir'], ['_unittest.py', '.test.py'], $config);
        if (!(bool)$config['list_only'] && $selectedTests === [] && trim((string)($config['match'] ?? '')) !== '') {
            $config['require_tests'] = false;
        }
        if ((bool)$config['list_only'] && StoreRegistry::detectDriver() === 'none') {
            ContractWorldBootstrap::prepare('back_python', $repoRoot, 'none');
        }
        if (!(bool)$config['list_only'] && $selectedTests !== []) {
            ContractWorldBootstrap::prepare('back_python', $repoRoot);
        }

        $python = (string)$config['python_binary'];

        if ((bool)$config['coverage']) {
            @mkdir((string)$config['coverage_dir'], 0777, true);
            @unlink((string)$config['coverage_dir'] . '/trace_counts.dat');
            // trace accumula en un archivo; para evitar corrupción, forzamos secuencial.
            $config['jobs'] = 1;
        }

        return SuiteOrchestrator::run(
            $config,
            ['_unittest.py', '.test.py'],
            static function (array $test, int $workerId) use ($python, $config, $repoRoot, $testkitRoot): array {
                return self::buildTestLaunch($test, $workerId, $python, $config, $repoRoot, $testkitRoot);
            },
            static function (array &$result, array $config) use ($python, $repoRoot): void {
                if (!(bool)$config['coverage']) {
                    return;
                }

                $traceFile = (string)$config['coverage_dir'] . '/trace_counts.dat';
                if (!is_file($traceFile)) {
                    $result['coverage_error'] = 'Python coverage habilitado pero no se genero trace_counts.dat.';
                    return;
                }

                $reportCmd = self::buildCoverageReportCommand(
                    $python,
                    $traceFile,
                    (string)$config['coverage_dir']
                );

                $reportJob = ProcessRunner::start($reportCmd, $repoRoot, []);
                $reportDone = ProcessRunner::finish($reportJob);

                $result['python_coverage_trace_file'] = $traceFile;
                $result['python_coverage_report_stdout'] = (string)($reportDone['stdout'] ?? '');
                $result['python_coverage_report_stderr'] = (string)($reportDone['stderr'] ?? '');
                $result['python_coverage_report_exit'] = (int)($reportDone['code'] ?? 1);

                if ((int)($reportDone['code'] ?? 1) !== 0) {
                    $result['coverage_error'] = 'Fallo la generacion del reporte trace para Python.';
                }
            }
        );
    }

    /**
     * @param array<string,mixed> $test
     * @param array<string,mixed> $config
     * @return array{cmd:array<int,string>, env:array<string,string>}
     */
    private static function buildTestLaunch(
        array $test,
        int $workerId,
        string $python,
        array $config,
        string $repoRoot,
        string $testkitRoot
    ): array {
        $cmd = [$python];
        $env = [
            'TEST_SUITE' => 'back_python',
            'APP_ENV' => 'test',
            'APP_DEBUG' => '1',
            'TESTKIT_ROOT' => $testkitRoot,
            'TK_REPO_ROOT' => $repoRoot,
            'TEST_WORKER_ID' => (string)$workerId,
        ];

        if ((bool)$config['coverage']) {
            $traceFile = (string)$config['coverage_dir'] . '/trace_counts.dat';
            $cmd = self::buildCoverageCountCommand($python, $traceFile, (string)$config['coverage_dir'], (string)$test['file']);
        } else {
            $cmd[] = (string)$test['file'];
        }

        return ['cmd' => $cmd, 'env' => $env];
    }

    /**
     * Build the per-test Python trace accumulation command.
     *
     * Important: Python trace only persists --file counts from write_results().
     * Using --no-report would suppress trace_counts.dat on supported Python
     * versions, so the per-test count phase is redirected with --coverdir
     * instead. That keeps annotated *.cover files inside TestKit artifacts while
     * preserving the consolidated trace_counts.dat report source.
     *
     * @return array<int,string>
     */
    private static function buildCoverageCountCommand(
        string $python,
        string $traceFile,
        string $coverageDir,
        string $testFile
    ): array {
        return [
            $python,
            '-m',
            'trace',
            '--count',
            '--file',
            $traceFile,
            '--coverdir',
            $coverageDir,
            $testFile,
        ];
    }

    /**
     * Build the consolidated Python trace report command.
     *
     * @return array<int,string>
     */
    private static function buildCoverageReportCommand(string $python, string $traceFile, string $coverageDir): array
    {
        return [
            $python,
            '-m',
            'trace',
            '--report',
            '--missing',
            '--summary',
            '--file',
            $traceFile,
            '--coverdir',
            $coverageDir,
        ];
    }
}
