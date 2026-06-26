<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Console;

use Testkit\Core\Reporting\UI;

final class ConsoleTableFormatter
{
    public static function renderOutcome(string $outcome): string
    {
        $normalized = strtoupper(trim($outcome));

        if (in_array($normalized, ['PASSED', 'PASS', 'OK'], true)) {
            return UI::success($normalized);
        }

        if (in_array($normalized, ['TIMEOUT', 'BLOCKED', 'PARTIAL', 'SKIPPED', 'SKIP', 'NO_TESTS', 'LISTED'], true)) {
            return UI::warning($normalized);
        }

        return UI::failure($normalized);
    }

    public static function renderTestStatus(string $status): string
    {
        if (in_array($status, ['PASS', 'DONE'], true)) {
            return UI::success($status);
        }

        if (in_array($status, ['SKIP', 'SKIPPED', 'TIMEOUT'], true)) {
            return UI::warning($status);
        }

        return UI::failure($status);
    }

    public static function formatDurationMs(int $durationMs): string
    {
        $totalSeconds = max(0, (int)floor($durationMs / 1000));
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $seconds = $totalSeconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    public static function formatAvgMs(?int $avgMs): string
    {
        if ($avgMs === null) {
            return 'n/a';
        }

        return sprintf('%.1fs/test', max(0, $avgMs) / 1000);
    }

    public static function formatProgressPath(string $rel, int $maxLen): string
    {
        $normalized = trim(str_replace('\\', '/', $rel));
        if ($normalized === '') {
            return '';
        }

        if (strlen($normalized) <= $maxLen) {
            return $normalized;
        }

        $parts = array_values(array_filter(explode('/', $normalized), static fn(string $part): bool => $part !== ''));
        if (count($parts) >= 4) {
            $variants = [
                implode('/', array_merge(array_slice($parts, 0, 2), ['...'], array_slice($parts, -2))),
                implode('/', array_merge([$parts[0], '...'], array_slice($parts, -2))),
                implode('/', array_merge(['...'], array_slice($parts, -2))),
            ];

            foreach ($variants as $variant) {
                if (strlen($variant) <= $maxLen) {
                    return $variant;
                }
            }

            return self::truncateMiddle((string)$variants[count($variants) - 1], $maxLen);
        }

        return self::truncateMiddle($normalized, $maxLen);
    }

    public static function formatWorkersSummary(mixed $workersValue): string
    {
        if (!is_array($workersValue) || $workersValue === []) {
            return '';
        }

        $rows = [];
        foreach ($workersValue as $worker) {
            if (!is_array($worker)) {
                continue;
            }

            $workerId = (int)($worker['worker'] ?? 0);
            if ($workerId <= 0) {
                continue;
            }

            $rows[] = [
                'worker' => $workerId,
                'rel' => (string)($worker['rel'] ?? ''),
                'elapsed_ms' => max(0, (int)($worker['elapsed_ms'] ?? 0)),
            ];
        }

        if ($rows === []) {
            return '';
        }

        usort($rows, static fn(array $left, array $right): int => ((int)$left['worker']) <=> ((int)$right['worker']));

        $pieces = [];
        $hidden = max(0, count($rows) - 3);
        foreach (array_slice($rows, 0, 3) as $row) {
            $pieces[] = 'w' . (int)$row['worker']
                . ':' . self::formatProgressPath((string)$row['rel'], ConsoleRenderLimits::MAX_PROGRESS_WORKER_PATH_LEN)
                . '@' . self::formatDurationMs((int)$row['elapsed_ms']);
        }

        if ($hidden > 0) {
            $pieces[] = '+' . $hidden;
        }

        return self::truncateMiddle(implode(', ', $pieces), ConsoleRenderLimits::MAX_PROGRESS_WORKERS_LEN);
    }

    public static function truncateMiddle(string $value, int $maxLen): string
    {
        if ($maxLen <= 0 || strlen($value) <= $maxLen) {
            return $value;
        }

        if ($maxLen <= 3) {
            return substr($value, 0, $maxLen);
        }

        $keepLeft = intdiv($maxLen - 3, 2);
        $keepRight = ($maxLen - 3) - $keepLeft;

        return substr($value, 0, $keepLeft) . '...' . substr($value, -$keepRight);
    }

    public static function compareModuleSummaryRows(array $left, array $right): int
    {
        foreach (['fail', 'timeout', 'skip', 'total'] as $field) {
            $cmp = (int)$right[$field] <=> (int)$left[$field];
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return strcmp((string)$left['module'], (string)$right['module']);
    }

    private function __construct()
    {
    }
}
