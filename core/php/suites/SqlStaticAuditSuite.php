<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Testkit\Core\Common\Paths;
use Testkit\Core\Config\ContractRegistry;
use Testkit\Core\Reporting\ConsoleMode;
use Testkit\Core\Reporting\HistoryRepository;
use Testkit\Core\Reporting\ReportSummary;
use Testkit\Core\Reporting\ResultWriter;
use Testkit\Core\Reporting\UI;
use Testkit\Core\SqlStatic\SqlBaselineComparator;
use Testkit\Core\SqlStatic\SqlStaticAuditor;
use Testkit\Core\SqlStatic\SqlStaticConsoleReporter;

final class SqlStaticAuditSuite
{
    public static function run(): int
    {
        $started = microtime(true);
        $runId = self::env('TEST_RUN_ID', gmdate('Ymd\THis\Z') . '_' . substr(sha1((string)microtime(true)), 0, 6));
        $root = Paths::repoRoot();
        $reportRoot = $root . '/.testkit/reports/sql-static-audit/' . $runId;
        $artifact = $reportRoot . '/sql-static-audit.json';
        Paths::ensureDir($reportRoot);
        Paths::recordSuiteReportRoot($reportRoot, 'sql_static_audit');

        try {
            $audit = SqlStaticAuditor::audit($root, self::listEnv('TESTKIT_SQL_STATIC_PATH', ['.']), self::listEnv('TESTKIT_SQL_STATIC_EXCLUDE'));
            $baseline = self::env('TESTKIT_SQL_STATIC_BASELINE', '');
            if ($baseline !== '') $audit['delta'] = SqlBaselineComparator::compare($audit, self::readJson(self::absolute($root, $baseline)));
            self::writeJson($artifact, $audit);
            $report = self::report($audit, $artifact, $reportRoot, $runId, 0, (int)round((microtime(true) - $started) * 1000));
            self::persist($report);
            echo ConsoleMode::isCompact() ? SqlStaticConsoleReporter::compact($audit) : SqlStaticConsoleReporter::human($audit, $artifact);
            return 0;
        } catch (\Throwable $error) {
            $report = self::report([], '', $reportRoot, $runId, 2, (int)round((microtime(true) - $started) * 1000), $error->getMessage());
            self::persist($report);
            fwrite(STDERR, UI::failure('SQL static audit error: ' . $error->getMessage()) . PHP_EOL);
            return 2;
        }
    }

    private static function report(array $audit, string $artifact, string $reportRoot, string $runId, int $code, int $duration, string $error = ''): array
    {
        $summary = (array)($audit['summary'] ?? []);
        $passed = $code === 0;
        $report = [
            'suite_id' => 'sql_static_audit', 'suite_status' => $passed ? 'passed' : 'failed', 'outcome_status' => $passed ? 'passed' : 'failed',
            'exit_code' => $code, 'process_exit_code' => $code, 'run_id' => $runId, 'meta_run_id' => self::env('TEST_META_RUN_ID', $runId),
            'report_root' => $reportRoot, 'report_scope_rel' => Paths::relativeToRepo($reportRoot), 'selected_test_count' => 0,
            'tests_total' => 0, 'pass' => 0, 'fail' => $passed ? 0 : 1, 'skip' => 0, 'duration_ms' => $duration,
            'warnings' => [], 'failures' => $passed ? [] : [['suite_id' => 'sql_static_audit', 'message' => $error, 'cause_code' => 'sql_static_operational_error']],
            'summary' => ['total' => 0, 'passed' => 0, 'failed' => $passed ? 0 : 1, 'skipped' => 0, 'duration_ms' => $duration, 'suite_status' => $passed ? 'passed' : 'failed'],
            'sql_static' => [
                'scanned_files' => (int)($audit['scanned_files'] ?? 0), 'sql_candidates' => (int)($audit['sql_candidates'] ?? 0),
                'extracted_queries' => (int)($audit['extracted_queries'] ?? 0), 'unresolved_candidates' => (int)($audit['unresolved_candidates'] ?? 0),
                'coverage_status' => (string)($audit['coverage_status'] ?? 'unavailable'), 'findings' => (int)($summary['findings'] ?? 0),
                'warn' => (int)($summary['warn'] ?? 0), 'watch' => (int)($summary['watch'] ?? 0),
            ],
            'artifacts' => array_filter(['suite_report' => $reportRoot . '/suite-report.json', 'sql_static_audit' => $artifact]),
            'recommended_actions' => [],
        ];
        $report['runner_capabilities'] = ContractRegistry::suiteContract('sql_static_audit')['capabilities'];
        $report = ReportSummary::enrichReport($report);
        $report['suite_status'] = $passed ? 'passed' : 'failed';
        $report['outcome_status'] = $passed ? 'passed' : 'failed';
        $report['process_exit_code'] = $code;
        return $report;
    }

    private static function persist(array $report): void
    {
        self::writeJson((string)$report['report_root'] . '/suite-report.json', $report);
        ResultWriter::writeSuite($report);
        HistoryRepository::recordSuiteMetrics($report);
    }

    private static function writeJson(string $path, array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json . PHP_EOL) === false) throw new \RuntimeException('Unable to write SQL static audit report.');
    }

    private static function readJson(string $path): array
    {
        if (!is_file($path)) throw new \InvalidArgumentException('SQL static baseline file does not exist.');
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) throw new \InvalidArgumentException('SQL static baseline must be valid JSON.');
        return $decoded;
    }

    private static function listEnv(string $key, array $default = []): array
    {
        $value = self::env($key, '');
        return $value === '' ? $default : array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $part): bool => $part !== ''));
    }

    private static function absolute(string $root, string $path): string
    {
        return str_starts_with($path, '/') ? $path : $root . '/' . $path;
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
}
