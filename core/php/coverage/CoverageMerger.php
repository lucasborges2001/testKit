<?php
declare(strict_types=1);

namespace Testkit\Core\Coverage;

final class CoverageMerger
{
    /**
     * @return array<string,array<int,int>>
     */
    public static function mergeFromDir(string $coverageDir): array
    {
        $coverageDir = rtrim(str_replace('\\', '/', $coverageDir), '/');
        if (!is_dir($coverageDir)) {
            return [];
        }

        $files = glob($coverageDir . '/*.json') ?: [];
        $merged = [];

        foreach ($files as $file) {
            $base = strtolower((string)basename($file));
            if (in_array($base, ['coverage.json', 'coverage_diagnostics.json', 'coverage_meta.json'], true)) {
                continue;
            }

            $raw = file_get_contents($file);
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            $json = json_decode($raw, true);
            if (!is_array($json)) {
                continue;
            }

            foreach ($json as $path => $lines) {
                if (!is_array($lines)) {
                    continue;
                }
                $path = str_replace('\\', '/', (string)$path);
                if (!isset($merged[$path])) {
                    $merged[$path] = [];
                }

                foreach ($lines as $line => $hits) {
                    $lineNo = (int)$line;
                    $hitCount = (int)$hits;
                    if ($lineNo <= 0) {
                        continue;
                    }
                    if ($hitCount < 0) {
                        $hitCount = 0;
                    }
                    $merged[$path][$lineNo] = ($merged[$path][$lineNo] ?? 0) + $hitCount;
                }
            }
        }

        foreach ($merged as &$lines) {
            ksort($lines);
        }
        unset($lines);

        ksort($merged);
        return $merged;
    }

    /**
     * @param array<string,array<int,int>> $merged
     */
    public static function writeJson(string $coverageDir, array $merged): string
    {
        @mkdir($coverageDir, 0777, true);
        $path = rtrim(str_replace('\\', '/', $coverageDir), '/') . '/coverage.json';
        file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $path;
    }

    /**
     * @param array<string,array<int,int>> $merged
     */
    public static function writeLcov(string $coverageDir, array $merged, string $repoRoot): string
    {
        @mkdir($coverageDir, 0777, true);
        $path = rtrim(str_replace('\\', '/', $coverageDir), '/') . '/lcov.info';
        $fh = fopen($path, 'wb');
        $repoPrefix = rtrim(str_replace('\\', '/', $repoRoot), '/') . '/';

        foreach ($merged as $file => $lines) {
            $norm = str_replace('\\', '/', $file);
            $rel = str_starts_with($norm, $repoPrefix) ? substr($norm, strlen($repoPrefix)) : $norm;
            fwrite($fh, 'TN:' . PHP_EOL);
            fwrite($fh, 'SF:' . $rel . PHP_EOL);

            $lf = 0;
            $lh = 0;
            foreach ($lines as $line => $hits) {
                $lf++;
                if ($hits > 0) {
                    $lh++;
                }
                fwrite($fh, 'DA:' . $line . ',' . $hits . PHP_EOL);
            }
            fwrite($fh, 'LF:' . $lf . PHP_EOL);
            fwrite($fh, 'LH:' . $lh . PHP_EOL);
            fwrite($fh, 'end_of_record' . PHP_EOL);
        }

        fclose($fh);
        return $path;
    }
}
