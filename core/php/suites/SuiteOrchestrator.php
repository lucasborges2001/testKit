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

        ConsoleReporter::printSuiteStart($config, count($tests));
        if ((bool)$config['list_only']) {
            ConsoleReporter::printList($tests);
        }

        $config['repo_root'] = Paths::repoRoot();
        $result = SuiteExecutor::execute($tests, $config, $buildCommand);

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
