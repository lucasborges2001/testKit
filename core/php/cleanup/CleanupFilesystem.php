<?php
declare(strict_types=1);

namespace Testkit\Core\Cleanup;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use Testkit\Core\Common\Paths;

final class CleanupFilesystem
{
    /**
     * @return array<int,string>
     */
    public static function listChildDirs(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $dirs = [];
        $items = @scandir($root);
        if (!is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = Paths::normalize($root . '/' . $item);
            if (is_dir($path)) {
                $dirs[] = $path;
            }
        }

        return $dirs;
    }

    /**
     * @return array<int,string>
     */
    public static function listFiles(string $root, string $pattern): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $info) {
            $path = Paths::normalize($info->getPathname());
            if ($info->isFile() && preg_match($pattern, basename($path)) === 1) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * @return array<int,string>
     */
    public static function findTimestampedJson(string $root, bool $skipRunDirs): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $runsRoot = Paths::normalize($root . '/runs');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $info) {
            $path = Paths::normalize($info->getPathname());
            if ($skipRunDirs && str_starts_with($path . '/', $runsRoot . '/')) {
                continue;
            }
            if (!$info->isFile()) {
                continue;
            }
            $base = basename($path);
            if (str_ends_with($base, '_latest.json') || $base === 'latest_run.json') {
                continue;
            }
            if (preg_match('/_(?:\d{8}_\d{6}|\d{8}T\d{6}Z(?:_[a-z0-9]+)?).*\.json$/i', $base) === 1) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * @param array<int,string> $paths
     * @return array<int,string>
     */
    public static function sortByMtimeDesc(array $paths): array
    {
        usort($paths, static function (string $a, string $b): int {
            return ((int)@filemtime($b)) <=> ((int)@filemtime($a));
        });
        return $paths;
    }

    public static function ageDays(string $path, int $now): int
    {
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return PHP_INT_MAX;
        }
        return (int)floor(max(0, $now - $mtime) / 86400);
    }

    public static function nullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        return max(0, (int)$value);
    }

    public static function jsonPrefix(string $file): string
    {
        $base = basename($file);
        $prefix = preg_replace('/_(?:\d{8}_\d{6}|\d{8}T\d{6}Z(?:_[a-z0-9]+)?).*\.json$/i', '', $base);
        return is_string($prefix) && $prefix !== '' ? dirname($file) . '/' . $prefix : dirname($file) . '/' . $base;
    }

    public static function resolvePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, '/')) {
            return Paths::normalize($path);
        }
        return Paths::normalize(Paths::repoRoot() . '/' . $path);
    }

    public static function pathSize(string $path): int
    {
        if (!file_exists($path)) {
            return 0;
        }
        if (is_file($path) || is_link($path)) {
            $size = @filesize($path);
            return $size === false ? 0 : max(0, (int)$size);
        }

        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $info) {
            if ($info->isFile() || $info->isLink()) {
                $fileSize = @filesize($info->getPathname());
                if ($fileSize !== false) {
                    $size += max(0, (int)$fileSize);
                }
            }
        }
        return $size;
    }

    public static function deletePath(string $path): bool
    {
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }
        if (is_file($path) || is_link($path)) {
            return @unlink($path);
        }
        if (!is_dir($path)) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $info) {
            $item = $info->getPathname();
            if ($info->isDir() && !$info->isLink()) {
                if (!@rmdir($item)) {
                    return false;
                }
            } else {
                if (!@unlink($item)) {
                    return false;
                }
            }
        }

        return @rmdir($path);
    }

    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float)max(0, $bytes);
        $unit = 0;
        while ($value >= 1024.0 && $unit < count($units) - 1) {
            $value /= 1024.0;
            $unit++;
        }
        if ($unit === 0) {
            return (string)((int)$value) . ' ' . $units[$unit];
        }
        return number_format($value, 2, '.', '') . ' ' . $units[$unit];
    }
}
