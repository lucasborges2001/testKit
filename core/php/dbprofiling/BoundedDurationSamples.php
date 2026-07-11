<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

final class BoundedDurationSamples
{
    /**
     * Deterministic bounded sampling: keep entries with the lowest SHA-256 priority.
     * Merge order does not affect the final retained sample set.
     *
     * @param array<int,array{key:string,value_ms:float,priority:string}> $samples
     * @return array<int,array{key:string,value_ms:float,priority:string}>
     */
    public static function add(array $samples, float $durationMs, string $sampleKey, int $limit): array
    {
        $entry = [
            'key' => substr($sampleKey, 0, 160),
            'value_ms' => max(0.0, $durationMs),
            'priority' => hash('sha256', $sampleKey),
        ];
        return self::merge($samples, [$entry], $limit);
    }

    /**
     * @param array<int,array{key:string,value_ms:float,priority:string}> $left
     * @param array<int,mixed> $right
     * @return array<int,array{key:string,value_ms:float,priority:string}>
     */
    public static function merge(array $left, array $right, int $limit): array
    {
        $byKey = [];
        foreach (array_merge($left, $right) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = trim((string)($entry['key'] ?? ''));
            $value = $entry['value_ms'] ?? null;
            if ($key === '' || !is_numeric($value)) {
                continue;
            }
            $byKey[$key] = [
                'key' => substr($key, 0, 160),
                'value_ms' => max(0.0, (float)$value),
                'priority' => (string)($entry['priority'] ?? hash('sha256', $key)),
            ];
        }
        return self::limit(array_values($byKey), $limit);
    }

    /**
     * @param array<int,array{key:string,value_ms:float,priority:string}> $samples
     * @return array<string,mixed>
     */
    public static function statistics(array $samples, int $totalCalls): array
    {
        $values = [];
        foreach ($samples as $entry) {
            if (isset($entry['value_ms']) && is_numeric($entry['value_ms'])) {
                $values[] = max(0.0, (float)$entry['value_ms']);
            }
        }
        sort($values, SORT_NUMERIC);
        $count = count($values);
        if ($count === 0) {
            return [
                'p50_ms' => 0.0,
                'p95_ms' => 0.0,
                'p99_ms' => 0.0,
                'standard_deviation_ms' => 0.0,
                'sample_count' => 0,
                'percentiles_approximate' => false,
            ];
        }

        $mean = array_sum($values) / $count;
        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }
        $variance /= $count;

        return [
            'p50_ms' => self::roundMs(self::percentile($values, 0.50)),
            'p95_ms' => self::roundMs(self::percentile($values, 0.95)),
            'p99_ms' => self::roundMs(self::percentile($values, 0.99)),
            'standard_deviation_ms' => self::roundMs(sqrt($variance)),
            'sample_count' => $count,
            'percentiles_approximate' => $totalCalls > $count,
        ];
    }

    /**
     * @param array<int,array{key:string,value_ms:float,priority:string}> $samples
     * @return array<int,array{key:string,value_ms:float,priority:string}>
     */
    private static function limit(array $samples, int $limit): array
    {
        $limit = max(1, $limit);
        usort($samples, static function (array $a, array $b): int {
            $priority = strcmp((string)$a['priority'], (string)$b['priority']);
            return $priority !== 0 ? $priority : strcmp((string)$a['key'], (string)$b['key']);
        });
        return array_slice($samples, 0, $limit);
    }

    /** @param array<int,float> $sorted */
    private static function percentile(array $sorted, float $p): float
    {
        $count = count($sorted);
        if ($count === 1) {
            return (float)$sorted[0];
        }
        $position = ($count - 1) * min(1.0, max(0.0, $p));
        $lower = (int)floor($position);
        $upper = (int)ceil($position);
        if ($lower === $upper) {
            return (float)$sorted[$lower];
        }
        $weight = $position - $lower;
        return ((float)$sorted[$lower] * (1.0 - $weight)) + ((float)$sorted[$upper] * $weight);
    }

    private static function roundMs(float $value): float
    {
        return round($value, 3);
    }
}
