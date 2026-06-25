<?php
declare(strict_types=1);

namespace Testkit\Core\Execution;

use Testkit\Core\Common\Paths;
use Testkit\Core\Reporting\ReportSummary;

final class IsolatedRerun
{
    private const COVERAGE_POLICY = 'disabled_for_isolated_rerun';

    /**
     * @param array<string,mixed> $batchResult
     * @param array<int,array<string,mixed>> $selectedTests
     * @param array<string,mixed> $config
     * @param callable $buildCommand
     * @return array<string,mixed>
     */
    public static function run(array $batchResult, array $selectedTests, array $config, callable $buildCommand): array
    {
        $enabled = (bool)($config['rerun_failed_isolated'] ?? false);
        $activeGuard = self::isActiveGuard();
        $base = [
            'enabled' => $enabled,
            'attempted' => false,
            'active_guard' => $activeGuard,
            'affects_exit_code' => false,
            'coverage_policy' => self::COVERAGE_POLICY,
            'failed_files_count' => 0,
            'results' => [],
            'summary' => [
                'confirmed_failures' => 0,
                'interference_suspected' => 0,
                'inconclusive' => 0,
            ],
        ];

        if (!$enabled) {
            $base['reason'] = 'disabled';
            return $base;
        }

        if ($activeGuard) {
            $base['reason'] = 'isolated_rerun_already_active';
            return $base;
        }

        if ((bool)($batchResult['list_only'] ?? false)) {
            $base['reason'] = 'list_only';
            return $base;
        }

        $failedFiles = self::failedFiles($batchResult);
        $base['failed_files_count'] = count($failedFiles);
        if ($failedFiles === []) {
            $base['reason'] = 'no_failed_files';
            return $base;
        }

        $testsByRel = self::testsByRel($selectedTests);
        $batchStatuses = self::batchStatusesByRel($batchResult);
        $isolatedConfig = $config;
        $isolatedConfig['jobs'] = 1;
        $isolatedConfig['fail_fast'] = false;
        $isolatedConfig['list_only'] = false;
        $isolatedConfig['require_tests'] = true;
        $isolatedConfig['coverage'] = false;
        $isolatedConfig['rerun_failed_isolated'] = false;

        $isolatedBuildCommand = self::isolatedBuildCommand($buildCommand);
        $base['attempted'] = true;

        foreach ($failedFiles as $file) {
            $rel = self::normalizeRel($file);
            $test = $testsByRel[$rel] ?? null;
            if (!is_array($test)) {
                $row = [
                    'file' => $rel,
                    'batch_status' => $batchStatuses[$rel] ?? 'fail',
                    'isolated_status' => 'skip',
                    'diagnosis' => 'inconclusive',
                    'duration_ms' => 0,
                    'reason' => 'failed file was not present in selected test set',
                ];
                $base['results'][] = $row;
                $base['summary']['inconclusive']++;
                continue;
            }

            $isolatedResult = SuiteExecutor::execute([$test], $isolatedConfig, $isolatedBuildCommand);
            $isolatedStatus = self::isolatedStatus($isolatedResult);
            $batchStatus = $batchStatuses[$rel] ?? 'fail';
            $diagnosis = self::diagnosis($batchStatus, $isolatedStatus);
            $durationMs = (int)($isolatedResult['duration_ms'] ?? 0);

            $base['results'][] = [
                'file' => $rel,
                'batch_status' => $batchStatus,
                'isolated_status' => $isolatedStatus,
                'diagnosis' => $diagnosis,
                'duration_ms' => $durationMs,
                'isolated_duration_ms' => $durationMs,
                'isolated_exit_code' => (int)($isolatedResult['exit_code'] ?? SuiteExecutor::EXIT_ERROR),
            ];
            $base['summary'][$diagnosis === 'confirmed_failure' ? 'confirmed_failures' : ($diagnosis === 'interference_suspected' ? 'interference_suspected' : 'inconclusive')]++;
        }

        return $base;
    }

    private static function isActiveGuard(): bool
    {
        $value = strtolower(trim((string)(getenv('TEST_ISOLATED_RERUN_ACTIVE') ?: '')));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private static function isolatedBuildCommand(callable $buildCommand): callable
    {
        return static function (array $test, int $workerId) use ($buildCommand): array {
            $launch = $buildCommand($test, $workerId);
            $env = is_array($launch['env'] ?? null) ? $launch['env'] : [];

            // Hard safety contract: isolated rerun must not recursively trigger another rerun
            // and must not write coverage into the primary batch coverage directory.
            $env['TEST_ISOLATED_RERUN_ACTIVE'] = '1';
            $env['TEST_RERUN_FAILED_ISOLATED'] = '0';
            $env['TEST_COVERAGE'] = '0';
            $env['TEST_COVERAGE_FILE'] = '';

            $launch['env'] = $env;
            return $launch;
        };
    }

    /** @param array<string,mixed> $batchResult @return array<int,string> */
    private static function failedFiles(array $batchResult): array
    {
        $failures = is_array($batchResult['failures'] ?? null)
            ? $batchResult['failures']
            : ReportSummary::canonicalFailures($batchResult);
        $files = ReportSummary::failedFiles($failures);

        if ($files === [] && is_array($batchResult['failed_tests'] ?? null)) {
            foreach ($batchResult['failed_tests'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $rel = trim((string)($entry['rel'] ?? $entry['file'] ?? ''));
                if ($rel !== '') {
                    $files[] = self::normalizeRel($rel);
                }
            }
        }

        $files = array_values(array_unique(array_map([self::class, 'normalizeRel'], $files)));
        sort($files);
        return $files;
    }

    /** @param array<int,array<string,mixed>> $tests @return array<string,array<string,mixed>> */
    private static function testsByRel(array $tests): array
    {
        $out = [];
        foreach ($tests as $test) {
            $rel = self::normalizeRel((string)($test['rel'] ?? $test['file'] ?? ''));
            if ($rel !== '') {
                $out[$rel] = $test;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $batchResult @return array<string,string> */
    private static function batchStatusesByRel(array $batchResult): array
    {
        $out = [];
        $entries = is_array($batchResult['tests'] ?? null) ? $batchResult['tests'] : [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $rel = self::normalizeRel((string)($entry['rel'] ?? $entry['file'] ?? ''));
            if ($rel === '') {
                continue;
            }
            $status = strtolower(trim((string)($entry['status'] ?? 'fail')));
            $out[$rel] = $status !== '' ? $status : 'fail';
        }

        return $out;
    }

    /** @param array<string,mixed> $result */
    private static function isolatedStatus(array $result): string
    {
        if ((int)($result['tests_total'] ?? 0) === 0) {
            return 'no_tests';
        }

        $tests = is_array($result['tests'] ?? null) ? $result['tests'] : [];
        $entry = $tests[0] ?? null;
        if (is_array($entry)) {
            $status = strtolower(trim((string)($entry['status'] ?? '')));
            if (in_array($status, ['pass', 'fail', 'timeout', 'skip'], true)) {
                return $status;
            }
        }

        $exitCode = (int)($result['exit_code'] ?? SuiteExecutor::EXIT_ERROR);
        return match ($exitCode) {
            SuiteExecutor::EXIT_PASS => 'pass',
            SuiteExecutor::EXIT_SKIP => 'skip',
            default => 'fail',
        };
    }

    private static function diagnosis(string $batchStatus, string $isolatedStatus): string
    {
        $batchStatus = strtolower(trim($batchStatus));
        $isolatedStatus = strtolower(trim($isolatedStatus));

        if (in_array($batchStatus, ['fail', 'timeout'], true) && $isolatedStatus === 'pass') {
            return 'interference_suspected';
        }

        if ($batchStatus === 'fail' && $isolatedStatus === 'fail') {
            return 'confirmed_failure';
        }

        if ($batchStatus === 'timeout' && $isolatedStatus === 'timeout') {
            return 'confirmed_failure';
        }

        if (in_array($isolatedStatus, ['skip', 'no_tests'], true)) {
            return 'inconclusive';
        }

        return 'inconclusive';
    }

    private static function normalizeRel(string $path): string
    {
        $path = Paths::relativeToRepo($path);
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?: $path;
        $path = preg_replace('#^\./+#', '', $path) ?: $path;
        return trim($path, '/');
    }
}
