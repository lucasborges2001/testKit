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

        // Resolve scope-aware report root from actual discovered tests
        $reportRoot = Paths::resolveReportRoot($tests);
        Paths::recordSuiteReportRoot($reportRoot);

        ConsoleReporter::printSuiteStart($config, count($tests));
        if ((bool)$config['list_only']) {
            ConsoleReporter::printList($tests);
        }

        $config['repo_root'] = Paths::repoRoot();
        $result = SuiteExecutor::execute($tests, $config, $buildCommand);

        // Inject scope metadata derived from the real discovered set
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

        // Build enriched failures from the subset actually executed
        $failedEntries          = (array)($result['failed_tests'] ?? []);
        $result['failures']         = array_map([self::class, 'buildFailureEntry'], $failedEntries);
        $result['grouped_failures'] = self::groupFailures($result['failures']);

        $history = HistoryRepository::updateAndAnalyze(
            $result,
            (int)($config['thresholds']['flake_window'] ?? 20)
        );
        $result['history_file']    = $history['history_file'];
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

    // -------------------------------------------------------------------------
    // Scope helpers
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Failure enrichment
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private static function buildFailureEntry(array $entry): array
    {
        $stdout = (string)($entry['stdout'] ?? '');
        $stderr = (string)($entry['stderr'] ?? '');

        $message      = self::extractFirstMessage($stderr) ?? self::extractFirstMessage($stdout);
        $traceExcerpt = self::extractTrace($stderr !== '' ? $stderr : $stdout, 10);
        $stdoutExcerpt = self::textExcerpt($stdout, 15);
        $stderrExcerpt = self::textExcerpt($stderr, 15);

        $tags         = array_values((array)($entry['tags'] ?? []));
        $scopeTokens  = array_values(array_filter($tags, fn($t) => in_array($t, ['unit', 'integration', 'e2e'], true)));
        $catTokens    = array_values(array_filter($tags, fn($t) => !in_array($t, ['unit', 'integration', 'e2e'], true)));

        return [
            'test_id'        => (string)($entry['rel'] ?? ''),
            'test_name'      => basename((string)($entry['rel'] ?? ''), '.test.php'),
            'suite'          => (string)($entry['module'] ?? ''),
            'scope'          => implode(',', $scopeTokens),
            'file'           => (string)($entry['rel'] ?? ''),
            'line'           => null,
            'category'       => implode(',', $catTokens),
            'status'         => (string)($entry['status'] ?? 'fail'),
            'duration_ms'    => (int)($entry['duration_ms'] ?? 0),
            'error_type'     => 'exit_code_' . (int)($entry['exit_code'] ?? 1),
            'message'        => $message,
            'assertion'      => null,
            'diff_excerpt'   => null,
            'trace_excerpt'  => $traceExcerpt,
            'stdout_excerpt' => $stdoutExcerpt,
            'stderr_excerpt' => $stderrExcerpt,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $failures
     * @return array<string,mixed>
     */
    private static function groupFailures(array $failures): array
    {
        $byFile      = [];
        $byErrorType = [];
        $byMessage   = [];

        foreach ($failures as $f) {
            $testId    = (string)($f['test_id'] ?? $f['file'] ?? 'unknown');
            $file      = (string)($f['file'] ?? 'unknown');
            $errorType = (string)($f['error_type'] ?? 'unknown');
            $msg       = (string)($f['message'] ?? '');

            $byFile[$file][]            = $testId;
            $byErrorType[$errorType][]  = $testId;

            if ($msg !== '') {
                $norm = substr((string)preg_replace('/\s+/', ' ', $msg), 0, 80);
                $byMessage[$norm][] = $testId;
            }
        }

        return [
            'by_file'       => $byFile,
            'by_error_type' => $byErrorType,
            'by_message'    => $byMessage,
        ];
    }

    private static function extractFirstMessage(string $text): ?string
    {
        if ($text === '') {
            return null;
        }
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Skip bare stack-trace lines
            if (preg_match('/^(#\d+\s|Stack trace:|at\s+|\w.*\.php:\d+$)/', $line)) {
                continue;
            }
            return substr($line, 0, 200);
        }
        return null;
    }

    private static function extractTrace(string $text, int $maxLines): ?string
    {
        if ($text === '') {
            return null;
        }
        $traceLines = array_values(array_filter(
            explode("\n", $text),
            fn(string $l): bool => (bool)preg_match('/^\s*(#\d+|Stack trace:|at\s+|\w.*\.php:\d+)/', $l)
        ));
        if (empty($traceLines)) {
            return null;
        }
        return implode("\n", array_slice($traceLines, 0, $maxLines));
    }

    private static function textExcerpt(string $text, int $maxLines): ?string
    {
        if ($text === '') {
            return null;
        }
        $lines = array_values(array_filter(explode("\n", $text), fn(string $l): bool => trim($l) !== ''));
        if (empty($lines)) {
            return null;
        }
        return implode("\n", array_slice($lines, 0, $maxLines));
    }

    // -------------------------------------------------------------------------

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
