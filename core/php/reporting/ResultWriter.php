<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

final class ResultWriter
{
    /**
     * @param array<string,mixed> $result
     */
    public static function writeSuite(array $result): void
    {
        $suiteId = (string)($result['suite_id'] ?? 'suite');
        $reportsRoot = (string)($result['report_root'] ?? '');
        if ($reportsRoot === '') {
            $reportsRoot = Paths::reportsRoot();
        }
        Paths::ensureDir($reportsRoot);

        $safeSuite = preg_replace('/[^a-z0-9._-]+/i', '_', strtolower($suiteId)) ?: 'suite';
        $timestamp = gmdate('Ymd_His');

        $latestPath = $reportsRoot . '/' . $safeSuite . '_latest.json';
        $tsPath     = $reportsRoot . '/' . $safeSuite . '_' . $timestamp . '.json';

        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        file_put_contents($latestPath, $json);
        file_put_contents($tsPath, $json);
        self::pruneOldRuns($reportsRoot, $safeSuite);
    }

    /**
     * @param array<string,mixed> $meta
     */
    public static function writeMeta(array $meta): void
    {
        $reportsRoot = (string)($meta['report_root'] ?? '');
        if ($reportsRoot === '') {
            $reportsRoot = Paths::reportsRoot();
        }
        Paths::ensureDir($reportsRoot);

        $timestamp = gmdate('Ymd_His');
        $latestPath = $reportsRoot . '/meta_latest.json';
        $tsPath     = $reportsRoot . '/meta_' . $timestamp . '.json';

        $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        file_put_contents($latestPath, $json);
        file_put_contents($tsPath, $json);
        self::pruneOldRuns($reportsRoot, 'meta');
    }

    /**
     * Keep only the $keep most recent timestamped files for a given prefix.
     * Files matching <prefix>_YYYYmmdd_HHmmss.json are pruned; *_latest.json is never touched.
     */
    private static function pruneOldRuns(string $dir, string $prefix, int $keep = 5): void
    {
        $safePfx = preg_replace('/[^a-z0-9._-]+/i', '_', strtolower($prefix)) ?: 'run';
        $pattern = $dir . '/' . $safePfx . '_[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]_[0-9][0-9][0-9][0-9][0-9][0-9].json';
        $files   = glob($pattern) ?: [];
        sort($files); // lexicographic order = chronological for Ymd_His format

        $excess = count($files) - $keep;
        for ($i = 0; $i < $excess; $i++) {
            @unlink($files[$i]);
        }
    }
}
