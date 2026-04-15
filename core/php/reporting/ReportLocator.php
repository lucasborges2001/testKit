<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

final class ReportLocator
{
    /**
     * @param array<int,string> $roots
     * @return array<string,mixed>|null
     */
    public static function loadLatestSuiteReport(string $suiteId, array $roots = []): ?array
    {
        $safeSuite = preg_replace('/[^a-z0-9._-]+/i', '_', strtolower($suiteId)) ?: 'suite';
        $candidateRoots = [];

        foreach ($roots as $root) {
            $root = trim($root);
            if ($root !== '') {
                $candidateRoots[$root] = true;
            }
        }

        foreach (Paths::suiteReportRoots() as $root) {
            if ($root !== '') {
                $candidateRoots[$root] = true;
            }
        }

        $candidateRoots[Paths::reportsRoot()] = true;

        foreach (array_keys($candidateRoots) as $root) {
            $canonicalFile = rtrim($root, '/\\') . '/' . $safeSuite . '_latest.json';
            $loaded = self::loadReportFile($canonicalFile);
            if ($loaded !== null) {
                return $loaded;
            }

            $pattern = rtrim($root, '/\\') . '/' . $safeSuite . '__*_latest.json';
            $matches = glob($pattern) ?: [];
            usort($matches, static fn(string $a, string $b): int => @filemtime($b) <=> @filemtime($a));

            foreach ($matches as $file) {
                $loaded = self::loadReportFile($file);
                if ($loaded !== null) {
                    return $loaded;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function loadReportFile(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return null;
        }

        $json['_source_file'] = $file;
        return $json;
    }
}
