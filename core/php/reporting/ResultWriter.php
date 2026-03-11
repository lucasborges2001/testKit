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
        $reportsRoot = Paths::reportsRoot();
        Paths::ensureDir($reportsRoot);

        $safeSuite = preg_replace('/[^a-z0-9._-]+/i', '_', strtolower($suiteId)) ?: 'suite';
        $timestamp = gmdate('Ymd_His');

        $latestPath = $reportsRoot . '/' . $safeSuite . '_latest.json';
        $tsPath = $reportsRoot . '/' . $safeSuite . '_' . $timestamp . '.json';

        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        file_put_contents($latestPath, $json);
        file_put_contents($tsPath, $json);
    }

    /**
     * @param array<string,mixed> $meta
     */
    public static function writeMeta(array $meta): void
    {
        $reportsRoot = Paths::reportsRoot();
        Paths::ensureDir($reportsRoot);

        $timestamp = gmdate('Ymd_His');
        $latestPath = $reportsRoot . '/meta_latest.json';
        $tsPath = $reportsRoot . '/meta_' . $timestamp . '.json';

        $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        file_put_contents($latestPath, $json);
        file_put_contents($tsPath, $json);
    }
}
