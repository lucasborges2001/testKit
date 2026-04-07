<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Testkit\Core\Common\Paths;
use Testkit\Core\Coverage\CoverageDiagnostics;
use Testkit\Core\Coverage\CoverageMerger;
use Testkit\Core\Discovery\TestDiscovery;
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
        Paths::recordSuiteReportRoot($reportRoot, (string)($config['suite_id'] ?? 'suite'));

        ConsoleReporter::printSuiteStart($config, count($tests));
        if ((bool)$config['list_only']) {
            ConsoleReporter::printList($tests);
        }

        $config['repo_root'] = Paths::repoRoot();
        $result = SuiteExecutor::execute($tests, $config, $buildCommand);

        $moduleScope = self::moduleScope($tests);
        $result['report_root']           = $reportRoot;
        $result['report_scope_rel']      = Paths::relativeToRepo($reportRoot);
        $result['match']                 = (string)($config['match'] ?? '');
        $result['selected_common_dir']   = self::commonDir($tests);
        $result['selected_module_scope'] = $moduleScope;
        $result['selected_test_count']   = count($tests);
        $result['selected_test_files']   = array_map(fn(array $t): string => (string)($t['rel'] ?? ''), $tests);
        $result['summary']               = [
            'total'       => (int)$result['tests_total'],
            'passed'      => (int)$result['pass'],
            'failed'      => (int)$result['fail'],
            'skipped'     => (int)$result['skip'],
            'duration_ms' => (int)$result['duration_ms'],
        ];

        $result['failures'] = ReportSummary::canonicalFailures($result);
        $result['grouped_failures'] = ReportSummary::groupFailures($result['failures']);
        $result['failure_contract'] = [
            'canonical' => 'failures',
            'legacy_fallback' => 'failed_tests',
        ];

        $history = HistoryRepository::updateAndAnalyze(
            $result,
            (int)($config['thresholds']['flake_window'] ?? 20)
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
}