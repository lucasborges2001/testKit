<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

final class HistoryRepository
{
    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public static function updateAndAnalyze(array $result, int $window = 20): array
    {
        $suiteId = (string)($result['suite_id'] ?? 'suite');
        $file = Paths::historyRoot() . '/' . preg_replace('/[^a-z0-9._-]+/i', '_', strtolower($suiteId)) . '.json';
        Paths::ensureDir(dirname($file));

        $history = self::load($file);
        if (!isset($history['tests']) || !is_array($history['tests'])) {
            $history['tests'] = [];
        }

        foreach (($result['tests'] ?? []) as $test) {
            $rel = (string)($test['rel'] ?? 'unknown');
            $status = (string)($test['status'] ?? 'fail');
            $duration = (int)($test['duration_ms'] ?? 0);

            if (!isset($history['tests'][$rel]) || !is_array($history['tests'][$rel])) {
                $history['tests'][$rel] = [
                    'pass' => 0,
                    'fail' => 0,
                    'skip' => 0,
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