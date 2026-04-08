<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;
use Testkit\Core\Config\RunnerConfig;
use Testkit\Core\Execution\ProcessRunner;

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
            $repoRoot . '/test/coverage/python_back',
            'python'
        );
        ContractWorldBootstrap::prepare('back_python', $repoRoot);

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
                    $cmd[] = '-m';
                    $cmd[] = 'trace';
                    $cmd[] = '--count';
                    $cmd[] = '--file';
                    $cmd[] = $traceFile;
                    $cmd[] = (string)$test['file'];
                } else {
                    $cmd[] = (string)$test['file'];
                }

                return ['cmd' => $cmd, 'env' => $env];
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

                $reportCmd = [
                    $python,
                    '-m',
                    'trace',
                    '--report',
                    '--missing',
                    '--summary',
                    '--file',
                    $traceFile,
                    '--coverdir',
                    (string)$config['coverage_dir'],
                ];

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
}
