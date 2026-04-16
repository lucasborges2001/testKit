<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

final class HistoryRepository
{
    private const SUITE_RUNS_KEEP = 50;

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public static function updateAndAnalyze(array $result, int $window = 10): array
    {
        $file = self::historyFile((string)($result['suite_id'] ?? 'suite'));
        Paths::ensureDir(dirname($file));

        $history = self::load($file);
        if (!isset($history['tests']) || !is_array($history['tests'])) {
            $history['tests'] = [];
        }

        /** @var array<string,mixed> $previousTests */
        $previousTests = is_array($history['tests']) ? $history['tests'] : [];
        $regressionDelta = self::buildRegressionDelta(
            $previousTests,
            is_array($result['tests'] ?? null) ? (array)$result['tests'] : []
        );

        foreach (($result['tests'] ?? []) as $test) {
            $rel = (string)($test['rel'] ?? 'unknown');
            $status = (string)($test['status'] ?? 'fail');
            $duration = (int)($test['duration_ms'] ?? 0);

            if (!isset($history['tests'][$rel]) || !is_array($history['tests'][$rel])) {
                $history['tests'][$rel] = [
                    'pass' => 0,
                    'fail' => 0,
                    'skip' => 0,
                    'timeout' => 0,
                    'last_status' => '',
                    'last_duration_ms' => 0,
                    'recent' => [],
                ];
            }

            if (!isset($history['tests'][$rel][$status])) {
                $history['tests'][$rel][$status] = 0;
            }
            $history['tests'][$rel][$status]++;
            $history['tests'][$rel]['last_status'] = $status;
            $history['tests'][$rel]['last_duration_ms'] = $duration;

            $recent = $history['tests'][$rel]['recent'];
            if (!is_array($recent)) {
                $recent = [];
            }
            $recent[] = $status;
            if (count($recent) > $window) {
                $recent = array_slice($recent, -$window);
            }
            $history['tests'][$rel]['recent'] = $recent;
        }

        $history['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $hints = [];
        foreach ($history['tests'] as $rel => $stat) {
            $pass = (int)($stat['pass'] ?? 0);
            $fail = (int)($stat['fail'] ?? 0);
            $recent = is_array($stat['recent'] ?? null) ? $stat['recent'] : [];
            $uniqueRecent = array_values(array_unique($recent));

            if ($pass > 0 && $fail > 0 && in_array('pass', $uniqueRecent, true) && in_array('fail', $uniqueRecent, true)) {
                $hints[] = [
                    'type' => 'flaky',
                    'test' => (string)$rel,
                    'pass_count' => $pass,
                    'fail_count' => $fail,
                    'recent' => $recent,
                ];
            }
        }

        usort($hints, static fn(array $a, array $b): int => ((int)$b['fail_count']) <=> ((int)$a['fail_count']));
        $hints = self::filterHintsForScope($hints, (string)($result['selected_module_scope'] ?? ''));

        return [
            'history_file' => $file,
            'fragility_hints' => array_slice($hints, 0, 10),
            'regression_delta' => $regressionDelta,
        ];
    }

    /**
     * @param array<string,mixed> $result
     */
    public static function recordSuiteMetrics(array $result, int $keep = self::SUITE_RUNS_KEEP): void
    {
        $file = self::historyFile((string)($result['suite_id'] ?? 'suite'));
        Paths::ensureDir(dirname($file));

        $history = self::load($file);
        if (!isset($history['tests']) || !is_array($history['tests'])) {
            $history['tests'] = [];
        }
        if (!isset($history['suite_runs']) || !is_array($history['suite_runs'])) {
            $history['suite_runs'] = [];
        }

        $history['suite_metrics_contract_version'] = 1;
        $history['suite_runs'][] = self::suiteRunSnapshot($result);
        if (count($history['suite_runs']) > $keep) {
            $history['suite_runs'] = array_slice($history['suite_runs'], -$keep);
        }

        $history['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string,mixed> $previousTests
     * @param array<int,array<string,mixed>> $currentTests
     * @return array<string,mixed>
     */
    private static function buildRegressionDelta(array $previousTests, array $currentTests): array
    {
        $newFailures = [];
        $resolvedFailures = [];
        $statusTransitions = [];

        foreach ($currentTests as $test) {
            if (!is_array($test)) {
                continue;
            }

            $rel = trim((string)($test['rel'] ?? $test['file'] ?? ''));
            if ($rel === '') {
                continue;
            }

            $currentStatus = trim((string)($test['status'] ?? 'fail'));
            $previousRow = $previousTests[$rel] ?? null;
            $previousStatus = is_array($previousRow) ? trim((string)($previousRow['last_status'] ?? '')) : '';

            if ($previousStatus !== '' && $previousStatus !== $currentStatus) {
                $statusTransitions[] = [
                    'test' => $rel,
                    'from' => $previousStatus,
                    'to' => $currentStatus,
                ];
            }

            if (in_array($previousStatus, ['pass', 'skip'], true) && in_array($currentStatus, ['fail', 'timeout'], true)) {
                $newFailures[$rel] = true;
            }

            if (in_array($previousStatus, ['fail', 'timeout'], true) && $currentStatus === 'pass') {
                $resolvedFailures[$rel] = true;
            }
        }

        usort($statusTransitions, static function (array $left, array $right): int {
            $cmp = strcmp((string)($left['test'] ?? ''), (string)($right['test'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp((string)($left['from'] ?? ''), (string)($right['from'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string)($left['to'] ?? ''), (string)($right['to'] ?? ''));
        });

        $newFailures = array_values(array_keys($newFailures));
        sort($newFailures);

        $resolvedFailures = array_values(array_keys($resolvedFailures));
        sort($resolvedFailures);

        return [
            'new_failures' => $newFailures,
            'resolved_failures' => $resolvedFailures,
            'status_transitions' => $statusTransitions,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $hints
     * @return array<int,array<string,mixed>>
     */
    private static function filterHintsForScope(array $hints, string $selectedModuleScope): array
    {
        $selectedModuleScope = trim($selectedModuleScope);
        if ($selectedModuleScope === '') {
            return $hints;
        }

        $filtered = [];
        foreach ($hints as $hint) {
            $test = trim((string)($hint['test'] ?? ''));
            if ($test === '') {
                continue;
            }

            $module = Paths::extractFunctionalModule($test);
            if ($module === $selectedModuleScope) {
                $filtered[] = $hint;
            }
        }

        return $filtered;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function suiteRunSnapshot(array $result): array
    {
        $diagnostics = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : [];

        return [
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'run_id' => self::nullableString($result['run_id'] ?? null),
            'meta_run_id' => self::nullableString($result['meta_run_id'] ?? null),
            'selected_module_scope' => self::nullableString($result['selected_module_scope'] ?? null),
            'suite_status' => self::nullableString($result['suite_status'] ?? null),
            'outcome_status' => self::nullableString($result['outcome_status'] ?? ($diagnostics['outcome_status'] ?? null)),
            'duration_ms' => self::nullableInt($result['duration_ms'] ?? null),
            'phase_timings_ms' => self::normalizePhaseTimings($result['phase_timings_ms'] ?? null),
            'execution_metrics' => self::normalizeExecutionMetrics($result['execution_metrics'] ?? null),
            'progress_policy' => self::normalizeProgressPolicy($result['progress_policy'] ?? null),
        ];
    }

    /**
     * @param mixed $value
     * @return array<string,int>|null
     */
    private static function normalizePhaseTimings(mixed $value): ?array
    {
        if (!is_array($value) || $value === []) {
            return null;
        }

        return [
            'discovery' => max(0, (int)($value['discovery'] ?? 0)),
            'admission' => max(0, (int)($value['admission'] ?? 0)),
            'execution' => max(0, (int)($value['execution'] ?? 0)),
            'reporting' => max(0, (int)($value['reporting'] ?? 0)),
        ];
    }

    /**
     * @param mixed $value
     * @return array<string,int|null>|null
     */
    private static function normalizeExecutionMetrics(mixed $value): ?array
    {
        if (!is_array($value) || $value === []) {
            return null;
        }

        return [
            'selected_test_count' => self::nullableInt($value['selected_test_count'] ?? null),
            'completed_test_count' => self::nullableInt($value['completed_test_count'] ?? null),
            'avg_test_ms' => self::nullableInt($value['avg_test_ms'] ?? null),
            'estimated_total_ms' => self::nullableInt($value['estimated_total_ms'] ?? null),
        ];
    }

    /**
     * @param mixed $value
     * @return array<string,int|string>|null
     */
    private static function normalizeProgressPolicy(mixed $value): ?array
    {
        if (!is_array($value) || $value === []) {
            return null;
        }

        $mode = strtolower(trim((string)($value['mode'] ?? '')));
        if ($mode === '') {
            $mode = 'heartbeat';
        }

        return [
            'mode' => $mode,
            'interval_sec' => max(1, (int)($value['interval_sec'] ?? 15)),
            'long_test_warn_sec' => max(1, (int)($value['long_test_warn_sec'] ?? 60)),
        ];
    }

    private static function historyFile(string $suiteId): string
    {
        return Paths::historyRoot() . '/' . preg_replace('/[^a-z0-9._-]+/i', '_', strtolower($suiteId)) . '.json';
    }

    /**
     * @param mixed $value
     */
    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        return max(0, (int)$value);
    }

    /**
     * @param mixed $value
     */
    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string,mixed>
     */
    private static function load(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $raw = file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }
}
