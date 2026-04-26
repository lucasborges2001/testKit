<?php
declare(strict_types=1);

namespace Testkit\Core\InfluxProfiling;

final class InfluxQueryAnalyzer
{
    /** @param array<string,mixed>|null $config @return array<string,mixed> */
    public static function analyze(string $query, string $dialect = 'flux', ?array $config = null): array
    {
        $config ??= InfluxProfileConfig::fromEnv();
        $dialect = strtolower(trim($dialect));
        if ($dialect === 'influxql') {
            return self::analyzeInfluxQl($query, $config);
        }
        return self::analyzeFlux($query, $config);
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private static function analyzeFlux(string $query, array $config): array
    {
        $normalized = preg_replace('/\s+/', ' ', trim($query)) ?? trim($query);
        $lower = strtolower($normalized);
        $thresholds = is_array($config['thresholds'] ?? null) ? $config['thresholds'] : [];
        $capture = is_array($config['capture'] ?? null) ? $config['capture'] : [];
        $maxRangeHours = (float)($thresholds['max_range_hours'] ?? 168.0);
        $requireRange = (bool)($capture['require_range'] ?? true);
        $requireTagFilters = (bool)($capture['require_tag_filters'] ?? false);
        $tagNames = is_array($config['tag_filters'] ?? null) ? $config['tag_filters'] : [];

        $rangePos = self::position($lower, '|> range(');
        if ($rangePos === null) {
            $rangePos = self::position($lower, 'range(');
        }
        $filterPos = self::position($lower, '|> filter(');
        if ($filterPos === null) {
            $filterPos = self::position($lower, 'filter(');
        }
        $pivotPos = self::position($lower, '|> pivot(');
        if ($pivotPos === null) {
            $pivotPos = self::position($lower, 'pivot(');
        }

        $estimatedRangeHours = self::estimateFluxRangeHours($normalized);
        $hasFilter = $filterPos !== null;
        $hasRange = $rangePos !== null;
        $usesPivot = $pivotPos !== null;
        $usesJoin = str_contains($lower, '|> join(') || preg_match('/\bjoin\s*\(/', $lower) === 1;
        $usesGroup = str_contains($lower, '|> group(') || preg_match('/\bgroup\s*\(/', $lower) === 1;
        $usesAggregateWindow = str_contains($lower, 'aggregatewindow(');
        $usesWindow = str_contains($lower, '|> window(') || preg_match('/\bwindow\s*\(/', $lower) === 1;
        $usesRegex = str_contains($lower, '=~') || str_contains($lower, '!~');
        $usesContains = preg_match('/\bcontains\s*\(/', $lower) === 1;
        $usesSort = str_contains($lower, '|> sort(') || preg_match('/\bsort\s*\(/', $lower) === 1;
        $usesLimit = str_contains($lower, '|> limit(') || preg_match('/\blimit\s*\(/', $lower) === 1;
        $usesMap = str_contains($lower, '|> map(') || preg_match('/\bmap\s*\(/', $lower) === 1;
        $hasTagFilter = self::hasTagFilter($normalized, $tagNames);
        $fieldFilterPrimary = $hasFilter && !$hasTagFilter && preg_match('/\br\._(?:field|value|measurement)\s*(?:==|=~|!=|!~)/i', $normalized) === 1;
        $wideGroup = $usesGroup && (preg_match('/group\s*\(\s*\)/i', $normalized) === 1 || preg_match('/columns\s*:\s*\[\s*\]/i', $normalized) === 1 || !preg_match('/columns\s*:/i', $normalized));
        $largeWindow = self::hasLargeAggregateWindow($normalized, $maxRangeHours);
        $aggregateWithoutWindow = preg_match('/\b(?:sum|mean|median|max|min|count|spread|integral)\s*\(/i', $normalized) === 1 && !$usesAggregateWindow && !$usesWindow;

        $flags = [];
        if ($requireRange && !$hasRange) {
            $flags['missing_range'] = 'warn';
        }
        if ($estimatedRangeHours !== null && $maxRangeHours > 0 && $estimatedRangeHours > $maxRangeHours) {
            $flags['wide_range'] = $estimatedRangeHours > ($maxRangeHours * 4) ? 'warn' : 'watch';
        }
        if (!$hasFilter) {
            $flags['missing_filter'] = 'watch';
        }
        if ($requireTagFilters && !$hasTagFilter) {
            $flags['missing_tag_filter'] = 'watch';
        }
        if ($fieldFilterPrimary) {
            $flags['field_filter_primary'] = 'watch';
        }
        if ($usesPivot && ($filterPos === null || ($pivotPos !== null && $filterPos !== null && $pivotPos < $filterPos))) {
            $flags['pivot_before_filter'] = 'warn';
        }
        if ($usesPivot && $pivotPos !== null && ($rangePos === null || $pivotPos < $rangePos || ($filterPos !== null && $pivotPos < $filterPos))) {
            $flags['early_pivot'] = 'watch';
        }
        if ($usesJoin) {
            $flags['join_present'] = 'watch';
        }
        if ($wideGroup) {
            $flags['wide_group'] = 'watch';
        }
        if ($aggregateWithoutWindow) {
            $flags['aggregate_without_window'] = 'watch';
        }
        if ($largeWindow) {
            $flags['large_window'] = 'watch';
        }
        if ($usesMap) {
            $flags['map_present'] = 'info';
        }
        if ($usesRegex) {
            $flags['regex_filter'] = 'watch';
        }
        if ($usesContains) {
            $flags['contains_filter'] = 'watch';
        }
        if ($usesSort && !$usesLimit) {
            $flags['sort_without_limit'] = 'watch';
        }
        if (!$usesLimit) {
            $flags['limit_missing'] = 'info';
        }

        return self::result([
            'has_range' => $hasRange,
            'range_value' => self::rangeValue($normalized),
            'estimated_range_hours' => $estimatedRangeHours,
            'has_filter' => $hasFilter,
            'has_tag_filter' => $hasTagFilter,
            'uses_pivot' => $usesPivot,
            'pivot_position' => $pivotPos,
            'filter_position' => $filterPos,
            'uses_join' => $usesJoin,
            'uses_group' => $usesGroup,
            'uses_aggregate_window' => $usesAggregateWindow,
            'uses_regex' => $usesRegex,
            'uses_map' => $usesMap,
            'uses_contains' => $usesContains,
            'uses_sort' => $usesSort,
            'uses_limit' => $usesLimit,
        ], $flags);
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private static function analyzeInfluxQl(string $query, array $config): array
    {
        $normalized = preg_replace('/\s+/', ' ', trim($query)) ?? trim($query);
        $lower = strtolower($normalized);
        $thresholds = is_array($config['thresholds'] ?? null) ? $config['thresholds'] : [];
        $maxRangeHours = (float)($thresholds['max_range_hours'] ?? 168.0);
        $tagNames = is_array($config['tag_filters'] ?? null) ? $config['tag_filters'] : [];
        $requireTagFilters = (bool)((is_array($config['capture'] ?? null) ? $config['capture'] : [])['require_tag_filters'] ?? false);

        $hasWhereTime = preg_match('/\bwhere\b[^;]*\btime\b\s*(?:>=|>|between|=)/i', $normalized) === 1;
        $estimatedRangeHours = self::estimateInfluxQlRangeHours($normalized);
        $hasTagFilter = self::hasTagFilter($normalized, $tagNames);
        $usesRegex = preg_match('/[=!~]\s*\/[^\/]+\//', $normalized) === 1 || str_contains($lower, '=~') || str_contains($lower, '!~');
        $orderByTimeDesc = preg_match('/order\s+by\s+time\s+desc/i', $normalized) === 1;
        $hasLimit = preg_match('/\blimit\s+\d+/i', $normalized) === 1;
        $selectStar = preg_match('/^\s*select\s+\*/i', $normalized) === 1;
        $groupByAll = preg_match('/group\s+by\s+\*/i', $normalized) === 1;

        $flags = [];
        if (!$hasWhereTime) {
            $flags['missing_range'] = 'warn';
        }
        if ($estimatedRangeHours !== null && $maxRangeHours > 0 && $estimatedRangeHours > $maxRangeHours) {
            $flags['wide_range'] = $estimatedRangeHours > ($maxRangeHours * 4) ? 'warn' : 'watch';
        }
        if ($groupByAll) {
            $flags['wide_group'] = 'watch';
        }
        if ($orderByTimeDesc && !$hasLimit) {
            $flags['sort_without_limit'] = 'watch';
        }
        if ($usesRegex) {
            $flags['regex_filter'] = 'watch';
        }
        if ($selectStar) {
            $flags['select_star'] = 'watch';
        }
        if ($requireTagFilters && !$hasTagFilter) {
            $flags['missing_tag_filter'] = 'watch';
        }
        if (!$hasLimit) {
            $flags['limit_missing'] = 'info';
        }

        return self::result([
            'has_range' => $hasWhereTime,
            'range_value' => self::influxQlTimeWhere($normalized),
            'estimated_range_hours' => $estimatedRangeHours,
            'has_filter' => str_contains($lower, ' where '),
            'has_tag_filter' => $hasTagFilter,
            'uses_pivot' => false,
            'pivot_position' => null,
            'filter_position' => self::position($lower, ' where '),
            'uses_join' => false,
            'uses_group' => str_contains($lower, ' group by '),
            'uses_aggregate_window' => str_contains($lower, 'group by time('),
            'uses_regex' => $usesRegex,
        ], $flags);
    }

    private static function position(string $haystack, string $needle): ?int
    {
        $pos = strpos($haystack, $needle);
        return $pos === false ? null : $pos;
    }

    /** @param array<int,mixed> $tagNames */
    private static function hasTagFilter(string $query, array $tagNames): bool
    {
        foreach ($tagNames as $tag) {
            $tag = trim((string)$tag);
            if ($tag === '') {
                continue;
            }
            $quoted = preg_quote($tag, '/');
            if (preg_match('/\br\.' . $quoted . '\s*(?:==|=~|!=|!~|in)\b/i', $query) === 1) {
                return true;
            }
            if (preg_match('/\b' . $quoted . '\s*(?:=|=~|!=|!~)\s*/i', $query) === 1) {
                return true;
            }
        }
        return false;
    }

    private static function estimateFluxRangeHours(string $query): ?float
    {
        if (preg_match('/range\s*\([^\)]*start\s*:\s*-?\s*(\d+(?:\.\d+)?)\s*(ns|us|µs|ms|s|m|h|d|w|mo|y)\b/i', $query, $m) === 1) {
            return self::durationToHours((float)$m[1], strtolower($m[2]));
        }
        if (preg_match('/range\s*\([^\)]*start\s*:\s*(\d{4}-\d{2}-\d{2}T[^,\)]+)[^\)]*stop\s*:\s*(\d{4}-\d{2}-\d{2}T[^,\)]+)/i', $query, $m) === 1) {
            $start = strtotime($m[1]);
            $stop = strtotime($m[2]);
            if ($start !== false && $stop !== false && $stop >= $start) {
                return ($stop - $start) / 3600;
            }
        }
        return null;
    }

    private static function estimateInfluxQlRangeHours(string $query): ?float
    {
        if (preg_match('/time\s*>?=\s*now\(\)\s*-\s*(\d+(?:\.\d+)?)(ns|us|µs|ms|s|m|h|d|w)\b/i', $query, $m) === 1) {
            return self::durationToHours((float)$m[1], strtolower($m[2]));
        }
        return null;
    }

    private static function durationToHours(float $value, string $unit): float
    {
        return match ($unit) {
            'ns', 'us', 'µs', 'ms' => 0.0,
            's' => $value / 3600.0,
            'm' => $value / 60.0,
            'h' => $value,
            'd' => $value * 24.0,
            'w' => $value * 168.0,
            'mo' => $value * 730.0,
            'y' => $value * 8760.0,
            default => $value,
        };
    }

    private static function hasLargeAggregateWindow(string $query, float $maxRangeHours): bool
    {
        if (preg_match('/aggregateWindow\s*\([^\)]*every\s*:\s*(\d+(?:\.\d+)?)(ns|us|µs|ms|s|m|h|d|w|mo|y)\b/i', $query, $m) !== 1) {
            return false;
        }
        $hours = self::durationToHours((float)$m[1], strtolower($m[2]));
        return $maxRangeHours > 0 && $hours >= max(24.0, $maxRangeHours / 2.0);
    }

    private static function rangeValue(string $query): string
    {
        return preg_match('/range\s*\(([^\)]*)\)/i', $query, $m) === 1 ? trim($m[1]) : '';
    }

    private static function influxQlTimeWhere(string $query): string
    {
        return preg_match('/\bwhere\b([^;]*)/i', $query, $m) === 1 ? trim($m[1]) : '';
    }

    /** @param array<string,mixed> $analysis @param array<string,string> $flags @return array<string,mixed> */
    private static function result(array $analysis, array $flags): array
    {
        $severity = 'info';
        foreach ($flags as $flagSeverity) {
            if ($flagSeverity === 'warn') {
                $severity = 'warn';
                break;
            }
            if ($flagSeverity === 'watch') {
                $severity = 'watch';
            }
        }
        $analysis['risk_flags'] = array_keys($flags);
        $analysis['risk_severity'] = $severity;
        $analysis['risk_flag_severities'] = $flags;
        return $analysis;
    }
}
