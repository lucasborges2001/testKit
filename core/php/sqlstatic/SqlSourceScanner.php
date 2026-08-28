<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class SqlSourceScanner
{
    public const DEFAULT_EXCLUDES = [
        '.git', '.testkit', 'vendor', 'node_modules', 'testkit', 'test', 'tests',
    ];

    /** @return array<int,array{path:string,relative:string}> */
    public static function scan(string $root, array $paths = ['.'], array $excludes = []): array
    {
        $root = realpath($root) ?: '';
        if ($root === '' || !is_dir($root)) {
            throw new InvalidArgumentException('SQL audit root does not exist.');
        }
        $root = self::normalize($root);
        $excluded = array_values(array_unique(array_merge(self::DEFAULT_EXCLUDES, $excludes)));
        $files = [];

        foreach ($paths === [] ? ['.'] : $paths as $target) {
            $resolved = realpath($root . '/' . ltrim((string)$target, '/')) ?: '';
            if ($resolved === '' || !self::insideRoot($resolved, $root)) {
                throw new InvalidArgumentException('SQL audit path is missing or outside root: ' . $target);
            }
            if (is_file($resolved)) {
                self::addFile($files, $resolved, $root, $excluded);
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if ($fileInfo instanceof SplFileInfo && $fileInfo->isFile()) {
                    self::addFile($files, $fileInfo->getPathname(), $root, $excluded);
                }
            }
        }

        ksort($files);
        return array_values($files);
    }

    private static function addFile(array &$files, string $path, string $root, array $excludes): void
    {
        $path = self::normalize($path);
        if (!self::insideRoot($path, $root)) {
            return;
        }
        $relative = ltrim(substr($path, strlen($root)), '/');
        if (self::excluded($relative, $excludes) || !self::supported($path)) {
            return;
        }
        $files[$relative] = ['path' => $path, 'relative' => $relative];
    }

    private static function excluded(string $relative, array $excludes): bool
    {
        $relative = trim(self::normalize($relative), '/');
        foreach ($excludes as $exclude) {
            $exclude = trim(self::normalize((string)$exclude), '/');
            if ($exclude !== '' && ($relative === $exclude || str_starts_with($relative, $exclude . '/'))) {
                return true;
            }
        }
        return false;
    }

    private static function supported(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['php', 'phtml', 'inc', 'sql'], true)) {
            return true;
        }
        if ($extension !== '') {
            return false;
        }
        $prefix = file_get_contents($path, false, null, 0, 160);
        return is_string($prefix) && (str_contains($prefix, '<?php') || str_contains($prefix, '/usr/bin/env php'));
    }

    private static function insideRoot(string $path, string $root): bool
    {
        $path = self::normalize($path);
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
