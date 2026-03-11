<?php
declare(strict_types=1);

namespace Testkit\Core\Discovery;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Testkit\Core\Common\Paths;

final class TestDiscovery
{
    /**
     * @param array<int,string> $extensions
     * @param array<string,mixed> $config
     * @return array<int,array<string,mixed>>
     */
    public static function discover(string $testsDir, array $extensions, array $config): array
    {
        if (!is_dir($testsDir)) {
            return [];
        }

        $scope = (string)($config['scope'] ?? 'all');
        $category = (string)($config['category'] ?? 'all');
        $match = strtolower((string)($config['match'] ?? ''));
        $scanLines = (int)($config['metadata_lines'] ?? 60);
        $tagsFromFilename = (bool)($config['tags_from_filename'] ?? true);

        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsDir));
        foreach ($it as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $fullPath = Paths::normalize($file->getPathname());
            if (self::shouldIgnore($fullPath)) {
                continue;
            }

            if (!self::hasExtension($fullPath, $extensions)) {
                continue;
            }

            $rel = Paths::relativeToRepo($fullPath);
            if ($match !== '' && stripos($rel, $match) === false) {
                continue;
            }

            $tags = TestTagger::tagsFor($fullPath, $scanLines, $tagsFromFilename);

            if (!self::scopeMatch($rel, $tags, $scope)) {
                continue;
            }
            if (!self::categoryMatch($tags, $category)) {
                continue;
            }

            $out[] = [
                'file' => $fullPath,
                'rel' => $rel,
                'module' => self::moduleFromRel($rel),
                'tags' => $tags,
            ];
        }

        usort(
            $out,
            static fn(array $a, array $b): int => strcmp((string)$a['rel'], (string)$b['rel'])
        );

        return $out;
    }

    /**
     * @param array<int,string> $extensions
     */
    private static function hasExtension(string $file, array $extensions): bool
    {
        foreach ($extensions as $ext) {
            if ($ext !== '' && str_ends_with(strtolower($file), strtolower($ext))) {
                return true;
            }
        }
        return false;
    }

    private static function shouldIgnore(string $file): bool
    {
        $normalized = strtolower($file);
        $ignored = ['/__pycache__/', '/_coverage/', '/_out/', '/vendor/', '/node_modules/'];
        foreach ($ignored as $token) {
            if (str_contains($normalized, $token)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int,string> $tags
     */
    private static function scopeMatch(string $rel, array $tags, string $scope): bool
    {
        $scope = strtolower(trim($scope));
        if ($scope === '' || $scope === 'all') {
            return true;
        }

        if (in_array($scope, $tags, true)) {
            return true;
        }

        $normalized = strtolower(str_replace('\\', '/', $rel));
        return str_contains($normalized, '/' . $scope . '/');
    }

    /**
     * @param array<int,string> $tags
     */
    private static function categoryMatch(array $tags, string $category): bool
    {
        $category = strtolower(trim($category));
        if ($category === '' || $category === 'all') {
            return true;
        }

        return in_array($category, $tags, true);
    }

    private static function moduleFromRel(string $rel): string
    {
        $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $rel)), static fn(string $p): bool => $p !== ''));
        if (count($parts) < 3) {
            return $parts[0] ?? 'unknown';
        }

        if ($parts[0] === 'test' && isset($parts[1], $parts[2])) {
            return $parts[1] . '/' . $parts[2];
        }

        return $parts[0] . '/' . $parts[1];
    }
}
