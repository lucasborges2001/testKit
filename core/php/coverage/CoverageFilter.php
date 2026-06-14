<?php
declare(strict_types=1);

namespace Testkit\Core\Coverage;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;

final class CoverageFilter
{
    /** @var array<int,string> */
    public const DEFAULT_EXCLUDE_DIRS = ['test', 'testkit', 'docker', 'vendor', 'logs', 'storage'];

    /**
     * @return array<int,string>
     */
    public static function sourceDirsFromEnv(): array
    {
        $sourceDirs = Env::csv('TEST_COVERAGE_SOURCE_DIRS');
        if ($sourceDirs === []) {
            $sourceDirs = [
                Env::string('TK_BACK_DIR', 'back'),
                Env::string('TK_PUBLIC_DIR', 'public_html'),
            ];
        }

        return self::normalizeRelativeDirs($sourceDirs);
    }

    /**
     * @return array<int,string>
     */
    public static function excludeDirsFromEnv(): array
    {
        return self::normalizeDirNames(
            Env::csv('TEST_COVERAGE_EXCLUDE_DIRS', implode(',', self::DEFAULT_EXCLUDE_DIRS))
        );
    }

    /**
     * @param array<int,string> $sourceDirs
     * @param array<int,string> $excludeDirs
     */
    public static function shouldInclude(string $file, string $repoRoot, array $sourceDirs, array $excludeDirs): bool
    {
        $repoRoot = rtrim(Paths::normalize($repoRoot), '/') . '/';
        $file = Paths::normalize($file);

        if ($file === '') {
            return false;
        }

        $rel = str_starts_with($file, $repoRoot)
            ? substr($file, strlen($repoRoot))
            : ltrim($file, '/');

        $rel = self::normalizeRelativePath($rel);
        if ($rel === '' || str_starts_with($rel, '../') || $rel === '..') {
            return false;
        }

        if (!self::matchesSourceDir($rel, $sourceDirs)) {
            return false;
        }

        if (self::hasExcludedSegment($rel, $excludeDirs)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int,string> $sourceDirs
     * @param array<int,string> $excludeDirs
     * @return array<int,string>
     */
    public static function collectPhpFiles(string $repoRoot, array $sourceDirs, array $excludeDirs): array
    {
        $repoRoot = rtrim(Paths::normalize($repoRoot), '/');
        $files = [];

        foreach (self::normalizeRelativeDirs($sourceDirs) as $dir) {
            $full = $dir === '.' ? $repoRoot : Paths::normalize($repoRoot . '/' . $dir);
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
                if (!self::shouldInclude($path, $repoRoot, $sourceDirs, $excludeDirs)) {
                    continue;
                }

                $files[] = Paths::relativeToRepo($path);
            }
        }

        $files = array_values(array_unique($files));
        sort($files);
        return $files;
    }

    /**
     * @param array<int,string> $dirs
     * @return array<int,string>
     */
    public static function normalizeRelativeDirs(array $dirs): array
    {
        $out = [];
        foreach ($dirs as $dir) {
            $dir = self::normalizeRelativePath((string)$dir);
            if ($dir === '' || $dir === '..' || str_starts_with($dir, '../')) {
                continue;
            }
            $out[] = $dir;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<int,string> $dirs
     * @return array<int,string>
     */
    public static function normalizeDirNames(array $dirs): array
    {
        $out = [];
        foreach ($dirs as $dir) {
            $dir = trim(strtolower(str_replace('\\', '/', (string)$dir)), '/ ');
            if ($dir === '') {
                continue;
            }
            $parts = array_values(array_filter(explode('/', $dir), static fn(string $p): bool => $p !== ''));
            foreach ($parts as $part) {
                if ($part !== '.' && $part !== '..') {
                    $out[] = $part;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<int,string> $sourceDirs
     */
    private static function matchesSourceDir(string $rel, array $sourceDirs): bool
    {
        $sourceDirs = self::normalizeRelativeDirs($sourceDirs);
        if ($sourceDirs === []) {
            return true;
        }

        foreach ($sourceDirs as $dir) {
            if ($dir === '.') {
                return true;
            }
            if ($rel === $dir || str_starts_with($rel, $dir . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,string> $excludeDirs
     */
    private static function hasExcludedSegment(string $rel, array $excludeDirs): bool
    {
        $exclude = self::normalizeDirNames($excludeDirs);
        if ($exclude === []) {
            return false;
        }

        $parts = array_map('strtolower', array_values(array_filter(
            explode('/', self::normalizeRelativePath($rel)),
            static fn(string $part): bool => $part !== ''
        )));

        foreach ($parts as $part) {
            if (in_array($part, $exclude, true)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = preg_replace('#/+#', '/', $path) ?: '';
        return trim($path, '/');
    }
}
