<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Testkit\Core\Common\Paths;
use Testkit\Core\Reporting\ConsoleReporter;
use Testkit\Core\Reporting\HistoryRepository;
use Testkit\Core\Reporting\ReportSummary;
use Testkit\Core\Reporting\ResultWriter;

final class SqlObservabilitySuite
{
    public static function run(): int
    {
        $script = Paths::testkitRoot() . '/scripts/sql-observability/run.sh';
        $scenario = self::env('TESTKIT_SQL_OBSERVABILITY_SCENARIO', 'all');
        $repetitions = self::env('TESTKIT_SQL_OBSERVABILITY_REPETITIONS', '');
        $config = self::env('TESTKIT_SQL_OBSERVABILITY_CONFIG', 'config/sql-observability/host.json');
        $listOnly = self::env('TEST_LIST', '') === '1';
        $runId = self::env('TEST_RUN_ID', gmdate('Ymd\THis\Z') . '_' . substr(sha1((string)microtime(true)), 0, 6));
        $reportRoot = Paths::repoRoot() . '/.testkit/reports/sql-observability/' . $runId;
        Paths::ensureDir($reportRoot);

        $cmd = ['bash', $script];
        if ($listOnly) {
            $cmd[] = 'list';
        } else {
            $cmd[] = 'verify';
            $cmd[] = '--scenario';
            $cmd[] = $scenario;
            $cmd[] = '--output';
            $cmd[] = $reportRoot;
            if ($repetitions !== '') {
                $cmd[] = '--repetitions';
                $cmd[] = $repetitions;
            }
        }

        $env = [
            'TK_REPO_ROOT' => Paths::repoRoot(),
            'TESTKIT_PROJECT_ROOT' => Paths::repoRoot(),
            'TESTKIT_ROOT' => Paths::testkitRoot(),
            'SQLOBS_HOST_CONFIG' => $config,
        ];
        $result = self::runProcess($cmd, $env);
        $code = $result['code'];
        $selected = self::selectedCount($reportRoot, $listOnly);

        $report = [
            'suite_id' => 'sql_observability',
            'suite_status' => $code === 0 ? 'passed' : 'failed',
            'outcome_status' => $code === 0 ? 'passed' : 'failed',
            'exit_code' => $code,
            'process_exit_code' => $code,
            'run_id' => $runId,
            'meta_run_id' => self::env('TEST_META_RUN_ID', $runId),
            'report_root' => $reportRoot,
            'report_scope_rel' => Paths::relativeToRepo($reportRoot),
            'selected_test_count' => $selected,
            'tests_total' => $selected,
            'pass' => $code === 0 ? $selected : 0,
            'fail' => $code === 0 ? 0 : 1,
            'skip' => 0,
            'duration_ms' => $result['duration_ms'],
            'warnings' => [],
            'failures' => $code === 0 ? [] : [[
                'suite_id' => 'sql_observability',
                'message' => 'SQL Observability finished with exit ' . $code,
                'stdout' => substr($result['stdout'], -4000),
                'stderr' => substr($result['stderr'], -4000),
            ]],
            'summary' => [
                'total' => $selected,
                'passed' => $code === 0 ? $selected : 0,
                'failed' => $code === 0 ? 0 : 1,
                'skipped' => 0,
                'duration_ms' => $result['duration_ms'],
                'suite_status' => $code === 0 ? 'passed' : 'failed',
            ],
            'artifacts' => [
                'suite_report' => $reportRoot . '/suite-report.json',
                'sql_observability_root' => $reportRoot,
            ],
            'recommended_actions' => [],
        ];
        $report = ReportSummary::enrichReport($report);
        file_put_contents($reportRoot . '/suite-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        ConsoleReporter::printSuiteResult($report);
        ResultWriter::writeSuite($report);
        HistoryRepository::recordSuiteMetrics($report);

        return $code;
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /** @param list<string> $cmd @param array<string,string> $env @return array{code:int,stdout:string,stderr:string,duration_ms:int} */
    private static function runProcess(array $cmd, array $env): array
    {
        $started = (int)round(microtime(true) * 1000);
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptor, $pipes, Paths::repoRoot(), array_merge($_ENV, $env));
        if (!is_resource($process)) {
            return ['code' => 2, 'stdout' => '', 'stderr' => 'Unable to start SQL Observability.', 'duration_ms' => 0];
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        echo $stdout;
        fwrite(STDERR, $stderr);
        return ['code' => is_int($code) ? $code : 2, 'stdout' => $stdout, 'stderr' => $stderr, 'duration_ms' => max(0, (int)round(microtime(true) * 1000) - $started)];
    }

    private static function selectedCount(string $reportRoot, bool $listOnly): int
    {
        if ($listOnly) {
            return 0;
        }
        $files = glob($reportRoot . '/scenarios/*/run-*/run-manifest.json') ?: [];
        return count($files);
    }
}
