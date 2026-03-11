<?php
declare(strict_types=1);

namespace Testkit\Core\Coverage;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;

final class CoverageDiagnostics
{
    /**
     * @param array<string,array<int,int>> $merged
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function analyze(array $merged, array $config): array
    {
        $repoRoot = Paths::repoRoot();
        $lowThreshold = max(1, Env::int('TEST_COVERAGE_LOW_THRESHOLD', 70));
        $criticalThreshold = max(1, Env::int('TEST_COVERAGE_CRITICAL_THRESHOLD', 85));

        $sourceDirs = Env::csv('TEST_COVERAGE_SOURCE_DIRS');
        if (!$sourceDirs) {
            $sourceDirs = [Env::string('TK_BACK_DIR', 'back'), Env::string('TK_PUBLIC_DIR', 'public_html')];
        }

        $coverageByFile = [];
        $coverageByModule = [];

        foreach ($merged as $file => $lines) {
            $total = 0;
            $hit = 0;
            foreach ($lines as $count) {
                $total++;
                if ((int)$count > 0) {
                    $hit++;
                }
            }

            if ($total === 0) {
                continue;
            }

            $rel = Paths::relativeToRepo($file);
            $pct = round(($hit / $total) * 100, 2);
            $module = self::moduleFromRel($rel);

            $coverageByFile[$rel] = [
                'rel' => $rel,
                'module' => $module,
                'lines_total' => $total,
                'lines_hit' => $hit,
                'percent' => $pct,
            ];

            if (!isset($coverageByModule[$module])) {
                $coverageByModule[$module] = ['module' => $module, 'lines_total' => 0, 'lines_hit' => 0, 'percent' => 0.0];
            }
            $coverageByModule[$module]['lines_total'] += $total;
            $coverageByModule[$module]['lines_hit'] += $hit;
        }

        foreach ($coverageByModule as $module => $row) {
            $total = (int)$row['lines_total'];
            $hit = (int)$row['lines_hit'];
            $coverageByModule[$module]['percent'] = $total > 0 ? round(($hit / $total) * 100, 2) : 0.0;
        }

        uasort($coverageByFile, static fn(array $a, array $b): int => ($a['percent'] <=> $b['percent']));
        uasort($coverageByModule, static fn(array $a, array $b): int => ($a['percent'] <=> $b['percent']));

        $criticalPatterns = Env::csv('TEST_COVERAGE_CRITICAL_FILES');
        $criticalFiles = self::resolveCriticalFiles($sourceDirs, $criticalPatterns);

        $criticalMissing = [];
        $criticalLow = [];
        foreach ($criticalFiles as $rel) {
            if (!isset($coverageByFile[$rel])) {
                $criticalMissing[] = $rel;
                continue;
            }
            if ((float)$coverageByFile[$rel]['percent'] < $criticalThreshold) {
                $criticalLow[] = $coverageByFile[$rel];
            }
        }

        $lowFiles = array_values(array_filter(
            $coverageByFile,
            static fn(array $row): bool => (float)$row['percent'] < $lowThreshold
        ));

        $overall = self::overall($coverageByFile);

        return [
            'overall' => $overall,
            'thresholds' => [
                'low_percent' => $lowThreshold,
                'critical_percent' => $criticalThreshold,
            ],
            'files' => array_values($coverageByFile),
            'modules' => array_values($coverageByModule),
            'low_files' => array_slice($lowFiles, 0, 30),
            'critical_missing' => $criticalMissing,
            'critical_low' => array_slice($criticalLow, 0, 30),
            'source_dirs' => $sourceDirs,
        ];
    }

    /**
     * @param array<string,mixed> $diagnostics
     */
    public static function write(string $coverageDir, array $diagnostics): void
    {
        @mkdir($coverageDir, 0777, true);

        $jsonPath = rtrim(str_replace('\\', '/', $coverageDir), '/') . '/coverage_diagnostics.json';
        file_put_contents($jsonPath, json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $mdPath = rtrim(str_replace('\\', '/', $coverageDir), '/') . '/coverage_report.md';
        $lines = [];
        $lines[] = '# Coverage Diagnostics';
        $lines[] = '';

        $overall = $diagnostics['overall'] ?? [];
        $lines[] = '- overall_percent: ' . ($overall['percent'] ?? 0);
        $lines[] = '- lines_hit: ' . ($overall['lines_hit'] ?? 0);
        $lines[] = '- lines_total: ' . ($overall['lines_total'] ?? 0);
        $lines[] = '';

        $lines[] = '## Lowest Coverage Files';
        foreach (($diagnostics['low_files'] ?? []) as $row) {
            $lines[] = '- ' . $row['percent'] . '% - ' . $row['rel'] . ' (' . $row['lines_hit'] . '/' . $row['lines_total'] . ')';
        }
        if (!($diagnostics['low_files'] ?? [])) {
            $lines[] = '- none';
        }
        $lines[] = '';

        $lines[] = '## Critical Files Without Coverage';
        foreach (($diagnostics['critical_missing'] ?? []) as $rel) {
            $lines[] = '- ' . $rel;
        }
        if (!($diagnostics['critical_missing'] ?? [])) {
            $lines[] = '- none';
        }
        $lines[] = '';

        $lines[] = '## Critical Files Under Threshold';
        foreach (($diagnostics['critical_low'] ?? []) as $row) {
            $lines[] = '- ' . $row['percent'] . '% - ' . $row['rel'];
        }
        if (!($diagnostics['critical_low'] ?? [])) {
            $lines[] = '- none';
        }
        $lines[] = '';

        $lines[] = '## Coverage by Module';
        foreach (($diagnostics['modules'] ?? []) as $row) {
            $lines[] = '- ' . $row['percent'] . '% - ' . $row['module'] . ' (' . $row['lines_hit'] . '/' . $row['lines_total'] . ')';
        }

        file_put_contents($mdPath, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    /**
     * @param array<string,array<string,mixed>> $coverageByFile
     * @return array<string,mixed>
     */
    private static function overall(array $coverageByFile): array
    {
        $total = 0;
        $hit = 0;
        foreach ($coverageByFile as $row) {
            $total += (int)$row['lines_total'];
            $hit += (int)$row['lines_hit'];
        }

        return [
            'lines_total' => $total,
            'lines_hit' => $hit,
            'percent' => $total > 0 ? round(($hit / $total) * 100, 2) : 0.0,
        ];
    }

    private static function moduleFromRel(string $rel): string
    {
        $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $rel)), static fn(string $p): bool => $p !== ''));
        if (count($parts) < 2) {
            return $parts[0] ?? 'unknown';
        }
        return $parts[0] . '/' . $parts[1];
    }

    /**
     * @param array<int,string> $sourceDirs
     * @param array<int,string> $patterns
     * @return array<int,string>
     */
    private static function resolveCriticalFiles(array $sourceDirs, array $patterns): array
    {
        if (!$patterns) {
            return [];
        }

        $files = self::collectSourceFiles($sourceDirs);
        $matches = [];
        foreach ($files as $rel) {
            foreach ($patterns as $pattern) {
                $normalizedPattern = str_replace('\\', '/', trim($pattern));
                if ($normalizedPattern === '') {
                    continue;
                }
                if (fnmatch($normalizedPattern, $rel)) {
                    $matches[] = $rel;
                    break;
                }
            }
        }

        $matches = array_values(array_unique($matches));
        sort($matches);
        return $matches;
    }

    /**
     * @param array<int,string> $sourceDirs
     * @return array<int,string>
     */
    private static function collectSourceFiles(array $sourceDirs): array
    {
        $repoRoot = Paths::repoRoot();
        $files = [];

        foreach ($sourceDirs as $dir) {
            $full = Paths::normalize($repoRoot . '/' . trim($dir, '/'));
            if (!is_dir($full)) {
                continue;
            }

            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full));
            foreach ($it as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }

                $path = Paths::normalize($file->getPathname());
                if (!str_ends_with(strtolower($path), '.php')) {
                    continue;
                }
                if (str_contains(strtolower($path), '/vendor/')) {
                    continue;
                }

                $files[] = Paths::relativeToRepo($path);
            }
        }

        $files = array_values(array_unique($files));
        sort($files);
        return $files;
    }
}
